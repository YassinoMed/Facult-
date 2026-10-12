<?php

namespace App\Modules\Attendance\Services;

use App\Models\Etudiant;
use App\Modules\Attendance\Events\PresenceEnregistree;
use App\Modules\Attendance\Models\Presence;
use App\Modules\Attendance\Models\SeanceAppel;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use Carbon\Carbon;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Appel de présence des étudiants par l'enseignant (séance × date).
 * L'appel ne devine jamais : sans ligne, l'étudiant est « non renseigné »
 * (jamais absent). Persistance via `AttendanceService::enregistrer`
 * (upsert verrouillé + audit), rattachée à la séance officielle.
 */
class AppelPresenceService
{
    public function __construct(
        private readonly AttendanceService $releves,
    ) {}

    /**
     * Séances appelables d'un enseignant à une date (passée ou courante,
     * jamais future) : slots possédés non annulés + état d'appel + effectif.
     *
     * @return Collection<int, array{slot: ScheduleSlot, appel: ?SeanceAppel, effectif: int}>
     */
    public function seancesAppelables(Teacher $enseignant, string $date): Collection
    {
        $jour = strtolower(Carbon::parse($date)->format('l'));

        $slots = ScheduleSlot::with(['matiere', 'room', 'schedule.groupe'])
            ->where('teacher_id', $enseignant->id)
            ->where('day_of_week', $jour)
            ->where('status', '!=', 'cancelled')
            ->orderBy('start_time')
            ->get();

        $appels = SeanceAppel::whereIn('schedule_slot_id', $slots->pluck('id'))
            ->whereDate('date', $date)
            ->get()
            ->keyBy('schedule_slot_id');

        return $slots->map(fn ($slot) => [
            'slot' => $slot,
            'appel' => $appels->get($slot->id),
            'effectif' => $this->effectifSeance($slot),
        ])->values();
    }

    /**
     * Ouvre (ou reprend) l'appel : crée la fiche `en_cours` si absente.
     * Refuse les séances annulées et les dates futures.
     */
    public function ouvrir(ScheduleSlot $slot, string $date, Teacher $enseignant): SeanceAppel
    {
        abort_if((int) $slot->teacher_id !== (int) $enseignant->id, 404);
        abort_if($slot->schedule === null || $slot->schedule->groupe_id === null, 404);

        $slot->loadMissing('schedule.groupe');
        if ($enseignant->faculteId() !== null && $slot->schedule?->groupe?->faculte_id !== null) {
            if ((int) $enseignant->faculteId() !== (int) $slot->schedule->groupe->faculte_id) {
                abort(403, 'Séance hors de votre faculté de rattachement.');
            }
        }

        if ($slot->status === 'cancelled' || $this->annuleParException($slot, $date)) {
            throw new DomainException('Séance annulée : aucun appel possible.');
        }

        if ($date > Carbon::today()->toDateString()) {
            throw new DomainException("Appel impossible pour une séance future ({$date}).");
        }

        try {
            return SeanceAppel::firstOrCreate(
                ['schedule_slot_id' => $slot->id, 'date' => $date],
                ['teacher_id' => $enseignant->id, 'statut' => 'en_cours']
            );
        } catch (QueryException) {
            // Course à la création (double-clic) : la ligne gagnante fait foi.
            return SeanceAppel::where('schedule_slot_id', $slot->id)->whereDate('date', $date)->firstOrFail();
        }
    }

    /**
     * Étudiants appelables (groupe de la séance, dossiers rejetés exclus)
     * + relevés existants indexés par étudiant.
     *
     * @return array{etudiants: Collection<int, Etudiant>, releves: Collection<int, Presence>}
     */
    public function feuille(SeanceAppel $appel): array
    {
        $appel->loadMissing('slot.schedule.groupe');

        $etudiants = $this->etudiantsAppelables((int) $appel->slot->schedule->groupe_id);

        $releves = Presence::with('etudiant')
            ->where('schedule_slot_id', $appel->schedule_slot_id)
            ->whereDate('date', $appel->date->toDateString())
            ->get()
            ->keyBy('etudiant_id');

        return ['etudiants' => $etudiants, 'releves' => $releves];
    }

    /**
     * Enregistre un relevé complet (tout-ou-rien) : chaque étudiant doit
     * appartenir au groupe, chaque statut est validé. Appel `en_cours`
     * exigé. Idempotent (ré-enregistrement identique = mêmes lignes).
     *
     * @param  array<int, array{etudiant_id: int, statut: string}>  $releves
     * @return array{enregistres: int}
     */
    public function enregistrer(SeanceAppel $appel, array $releves, Teacher $auteur): array
    {
        $verrouille = SeanceAppel::whereKey($appel->id)->lockForUpdate()->firstOrFail();

        if ($verrouille->estCloture()) {
            throw new DomainException('Appel clôturé : utilisez la correction motivée.');
        }

        $appel->loadMissing('slot.schedule.groupe');
        $groupeId = (int) $appel->slot->schedule->groupe_id;
        $idsAutorises = $this->etudiantsAppelables($groupeId)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $codeSeance = $this->codeSeance($appel);

        $enregistres = DB::transaction(function () use ($appel, $releves, $auteur, $idsAutorises, $codeSeance) {
            $compteur = 0;

            foreach ($releves as $ligne) {
                $etudiantId = (int) ($ligne['etudiant_id'] ?? 0);
                $statut = (string) ($ligne['statut'] ?? '');

                if ($statut === 'non_renseigne' || $statut === '') {
                    Presence::where('schedule_slot_id', $appel->schedule_slot_id)
                        ->whereDate('date', $appel->date->toDateString())
                        ->where('etudiant_id', $etudiantId)
                        ->delete();

                    continue;
                }

                if (! in_array($statut, Presence::STATUTS_APPEL, true)) {
                    throw new DomainException("Statut « {$statut} » interdit à l'appel (présent, absent, retard).");
                }

                if (! in_array($etudiantId, $idsAutorises, true)) {
                    throw new DomainException("Étudiant #{$etudiantId} hors du groupe de la séance.");
                }

                $etudiant = Etudiant::findOrFail($etudiantId);

                /** @var Presence $presence */
                $presence = $this->releves->enregistrer(
                    $etudiant,
                    $appel->date->toDateString(),
                    $statut,
                    $codeSeance
                );

                $presence->update([
                    'schedule_slot_id' => $appel->schedule_slot_id,
                    'teacher_id' => $auteur->id,
                ]);
                $presence->ajouterTrace('enregistrement_appel', null, null, [
                    'appel_id' => $appel->id,
                    'enseignant' => $auteur->nomComplet(),
                ]);
                $presence->save();

                $compteur++;
            }

            return $compteur;
        });

        $verrouille->update($this->compteurs($verrouille));

        return ['enregistres' => $enregistres];
    }

    /**
     * Clôture explicite : refuse un appel incomplet sauf confirmation
     * expresse (les non-renseignés restent non-renseignés, jamais absents).
     * Snapshot des compteurs, horodatage, idempotent.
     *
     * @return array{presents: int, absents: int, retards: int, non_renseignes: int}
     */
    public function cloturer(SeanceAppel $appel, bool $confirmerIncomplet = false): array
    {
        return DB::transaction(function () use ($appel, $confirmerIncomplet) {
            /** @var SeanceAppel $verrouille */
            $verrouille = SeanceAppel::whereKey($appel->id)->lockForUpdate()->firstOrFail();

            if ($verrouille->estCloture()) {
                return $this->compteurs($verrouille);
            }

            $totaux = $this->compteurs($verrouille);

            if ($totaux['non_renseignes'] > 0 && ! $confirmerIncomplet) {
                throw new DomainException(
                    "Appel incomplet : {$totaux['non_renseignes']} étudiant(s) non renseigné(s). Complétez ou confirmez explicitement."
                );
            }

            $verrouille->update([...$totaux, 'statut' => 'cloture', 'cloture_le' => now()]);

            return $totaux;
        });
    }

    /**
     * Correction motivée post-clôture : motif obligatoire, trace
     * avant/après, auteur enseignant propriétaire (ou admin via son
     * propre circuit). Appel `en_cours` = ré-enregistrement libre.
     */
    /**
     * Pointage étudiant via QR temporaire (LOOP 21) : enregistre `present`
     * pour l'étudiant lui-même sur un appel EN COURS. Mêmes gardes que la
     * feuille manuelle (groupe, clôture, verrou) + idempotence : un pointage
     * existant `present` est renvoyé tel quel (anti double-scan). Le QR ne
     * prouve pas à lui seul la présence physique : l'enseignant conserve la
     * correction manuelle motivée.
     */
    public function pointerQr(SeanceAppel $appel, Etudiant $etudiant): Presence
    {
        return DB::transaction(function () use ($appel, $etudiant) {
            /** @var SeanceAppel $verrouille */
            $verrouille = SeanceAppel::whereKey($appel->id)->lockForUpdate()->firstOrFail();

            if ($verrouille->estCloture()) {
                throw new DomainException('Appel clôturé : pointage QR impossible.');
            }

            $verrouille->loadMissing('slot.schedule.groupe');
            $groupeId = (int) $verrouille->slot->schedule->groupe_id;

            abort_unless(
                Etudiant::whereKey($etudiant->id)->where('groupe_id', $groupeId)->whereIn('statut', ['en_attente', 'valide'])->exists(),
                404
            );

            /** @var Presence|null $existant */
            $existant = Presence::where('schedule_slot_id', $verrouille->schedule_slot_id)
                ->whereDate('date', $verrouille->date->toDateString())
                ->where('etudiant_id', $etudiant->id)
                ->lockForUpdate()
                ->first();

            if ($existant !== null && $existant->statut === 'present') {
                return $existant;
            }

            /** @var Presence $presence */
            $presence = $this->releves->enregistrer(
                $etudiant,
                $verrouille->date->toDateString(),
                'present',
                $this->codeSeance($verrouille)
            );
            $presence->update([
                'schedule_slot_id' => $verrouille->schedule_slot_id,
                'teacher_id' => $verrouille->teacher_id,
            ]);
            $presence->ajouterTrace('pointage_qr', null, null, [
                'appel_id' => $verrouille->id,
            ]);
            $presence->save();

            $verrouille->update($this->compteurs($verrouille));

            PresenceEnregistree::dispatch($presence->fresh('etudiant'));

            return $presence;
        });
    }

    public function corriger(SeanceAppel $appel, Etudiant $etudiant, string $statut, string $motif, Teacher $auteur): Presence
    {
        if (! in_array($statut, Presence::STATUTS_APPEL, true)) {
            throw new DomainException("Statut « {$statut} » interdit à l'appel.");
        }

        if (trim($motif) === '') {
            throw new DomainException('Un motif explicite est obligatoire pour corriger un relevé clôturé.');
        }

        return DB::transaction(function () use ($appel, $etudiant, $statut, $motif, $auteur) {
            /** @var SeanceAppel $verrouille */
            $verrouille = SeanceAppel::whereKey($appel->id)->lockForUpdate()->firstOrFail();

            if (! $verrouille->estCloture()) {
                throw new DomainException('Appel non clôturé : ré-enregistrez la feuille.');
            }

            /** @var Presence $presence */
            $presence = Presence::where('schedule_slot_id', $verrouille->schedule_slot_id)
                ->whereDate('date', $verrouille->date->toDateString())
                ->where('etudiant_id', $etudiant->id)
                ->lockForUpdate()
                ->first();

            // Post-clôture, un oubli se renseigne aussi par correction
            // motivée (création tracée, jamais d'absent automatique).
            if ($presence === null) {
                $groupeId = (int) $verrouille->slot->schedule->groupe_id;
                abort_unless(
                    Etudiant::whereKey($etudiant->id)->where('groupe_id', $groupeId)->whereIn('statut', ['en_attente', 'valide'])->exists(),
                    404
                );

                $presence = $this->releves->enregistrer(
                    $etudiant,
                    $verrouille->date->toDateString(),
                    $statut,
                    $this->codeSeance($verrouille)
                );
                $presence->update([
                    'schedule_slot_id' => $verrouille->schedule_slot_id,
                    'teacher_id' => $auteur->id,
                ]);
                $ancien = null;
            } else {
                $ancien = $presence->statut;
                $presence->update(['statut' => $statut, 'motif' => $motif, 'teacher_id' => $auteur->id]);
            }

            $presence->ajouterTrace('correction_appel', $motif, null, [
                'appel_id' => $verrouille->id,
                'enseignant' => $auteur->nomComplet(),
                'ancien_statut' => $ancien,
                'nouveau_statut' => $statut,
            ]);
            $presence->save();

            $verrouille->update($this->compteurs($verrouille));

            PresenceEnregistree::dispatch($presence->fresh('etudiant'));

            return $presence;
        });
    }

    /**
     * @return array{presents: int, absents: int, retards: int, non_renseignes: int}
     */
    public function compteurs(SeanceAppel $appel): array
    {
        $appel->loadMissing('slot.schedule.groupe');
        $groupeId = (int) $appel->slot->schedule->groupe_id;

        $effectif = $this->etudiantsAppelables($groupeId)->pluck('id')->map(fn ($id) => (int) $id)->all();

        $releves = Presence::where('schedule_slot_id', $appel->schedule_slot_id)
            ->whereDate('date', $appel->date->toDateString())
            ->whereIn('etudiant_id', $effectif)
            ->get()
            ->keyBy('etudiant_id');

        $totaux = ['presents' => 0, 'absents' => 0, 'retards' => 0, 'non_renseignes' => 0];

        foreach ($effectif as $etudiantId) {
            $statut = $releves->get($etudiantId)?->statut;
            match ($statut) {
                'present' => $totaux['presents']++,
                'absent' => $totaux['absents']++,
                'retard' => $totaux['retards']++,
                'excuse' => null,
                default => $totaux['non_renseignes']++,
            };
        }

        return $totaux;
    }

    /**
     * @return Collection<int, Etudiant>
     */
    private function etudiantsAppelables(int $groupeId)
    {
        return Etudiant::select(['id', 'nom', 'prenom', 'num_inscription', 'groupe_id'])
            ->where('groupe_id', $groupeId)
            ->whereIn('statut', ['en_attente', 'valide'])
            ->orderBy('nom')
            ->orderBy('prenom')
            ->orderBy('id')
            ->get();
    }

    private function effectifSeance(ScheduleSlot $slot): int
    {
        $groupeId = $slot->schedule?->groupe_id;

        if ($groupeId === null) {
            return 0;
        }

        return Etudiant::where('groupe_id', $groupeId)->whereIn('statut', ['en_attente', 'valide'])->count();
    }

    private function codeSeance(SeanceAppel $appel): string
    {
        return 'APPEL-'.$appel->schedule_slot_id.'-'.$appel->date->format('Ymd');
    }

    private function annuleParException(ScheduleSlot $slot, string $date): bool
    {
        return $slot->exceptions()->whereDate('exception_date', $date)->where('status', 'cancelled')->exists();
    }
}
