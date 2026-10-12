<?php

namespace App\Modules\Evaluation\Services;

use App\Models\IntegrationLog;
use App\Modules\Evaluation\Events\EvaluationChanged;
use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Schedule\Models\Room;
use App\Services\AuditService;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Service unique d'affectation et de répartition des salles d'examen (§8, §10).
 *
 * Gère :
 * - Affectation unitaire transactionnelle et sécurisée
 * - Simulation pure avant validation (proposition non persistée)
 * - Répartition multi-salles pour les grands effectifs avec numérotation des places
 * - Respect des capacités, des conflits d'horaires et de l'isolation
 */
class ExamRoomAssignmentService
{
    public function __construct(
        private ConflitEpreuveService $conflits,
        private AuditService $audit,
    ) {}

    /**
     * Affecte une salle principale à une épreuve de manière transactionnelle.
     */
    public function affecterSalle(Epreuve $epreuve, int $salleId, int $auteurId): Epreuve
    {
        return DB::transaction(function () use ($epreuve, $salleId) {
            /** @var Epreuve $verrouillee */
            $verrouillee = Epreuve::whereKey($epreuve->id)->lockForUpdate()->firstOrFail();

            if ($verrouillee->estVerrouillee()) {
                throw new DomainException('Épreuve verrouillée : affectation de salle interdite.');
            }

            $salle = Room::findOrFail($salleId);
            $totalCandidats = $verrouillee->candidats()->count();
            $capaciteRequise = $verrouillee->capacite_necessaire > 0 ? (int) $verrouillee->capacite_necessaire : $totalCandidats;

            $listeConflits = $this->conflits->detecter([
                'date' => $verrouillee->date->format('Y-m-d'),
                'heure_debut' => substr((string) $verrouillee->heure_debut, 0, 5),
                'heure_fin' => substr((string) $verrouillee->heure_fin, 0, 5),
                'salle_id' => $salleId,
                'capacite_necessaire' => $capaciteRequise,
            ], $verrouillee->id);

            $bloquants = $this->conflits->bloquants($listeConflits);
            if ($bloquants !== []) {
                throw new DomainException($bloquants[0]['message']);
            }

            $ancienneSalleId = $verrouillee->salle_id;
            $verrouillee->update(['salle_id' => $salleId]);

            // Met à jour les candidats n'ayant pas de salle spécifique
            $verrouillee->candidats()
                ->where(function ($q) use ($ancienneSalleId) {
                    $q->whereNull('salle_id')
                        ->orWhere('salle_id', $ancienneSalleId);
                })
                ->update(['salle_id' => $salleId]);

            IntegrationLog::tracer(
                'evaluation',
                'internal',
                'exam.room.assigned',
                'completed',
                null,
                200,
                null,
                null,
                (string) Str::uuid(),
                'EPR-'.$verrouillee->id
            );

            EvaluationChanged::dispatch('epreuve.salle_affectee', $verrouillee->id);

            return $verrouillee->fresh(['salle']);
        });
    }

    /**
     * Simulation pure (lecture seule) de la répartition multi-salles (§10).
     *
     * @param  array<int, int>  $salleIds
     * @return array{valide: bool, total_candidats: int, capacite_totale: int, repartition: array<int, array{candidat_id: int, etudiant_nom: string, salle_id: int, salle_code: string, place: string}>, conflits: array<int, array{type: string, critique: bool, message: string}>}
     */
    public function simulerRepartition(Epreuve $epreuve, array $salleIds, string $modePrefixe = 'Place '): array
    {
        if ($epreuve->estVerrouillee()) {
            throw new DomainException('Épreuve verrouillée : simulation interdite.');
        }

        $epreuve->loadMissing(['candidats.etudiant']);
        $candidats = $epreuve->candidats->sortBy(fn ($c) => ($c->etudiant->nom ?? '').' '.($c->etudiant->prenom ?? ''))->values();

        if ($candidats->isEmpty()) {
            throw new DomainException('Aucun candidat convoqué pour cette épreuve.');
        }

        /** @var Collection<int, Room> $salles */
        $salles = Room::whereIn('id', $salleIds)->where('actif', true)->get();
        if ($salles->isEmpty()) {
            throw new DomainException('Aucune salle valide sélectionnée.');
        }

        $conflits = [];
        $capaciteTotale = (int) $salles->sum('capacite');
        $totalCandidats = $candidats->count();

        if ($capaciteTotale < $totalCandidats) {
            $conflits[] = [
                'type' => 'capacite',
                'critique' => true,
                'message' => "Capacité totale insuffisante : {$capaciteTotale} places pour {$totalCandidats} candidats.",
            ];
        }

        // Vérification des conflits pour chaque salle
        foreach ($salles as $salle) {
            $detectes = $this->conflits->detecter([
                'date' => $epreuve->date->format('Y-m-d'),
                'heure_debut' => substr((string) $epreuve->heure_debut, 0, 5),
                'heure_fin' => substr((string) $epreuve->heure_fin, 0, 5),
                'salle_id' => $salle->id,
            ], $epreuve->id);

            foreach ($this->conflits->bloquants($detectes) as $bloquant) {
                $conflits[] = $bloquant;
            }
        }

        $repartition = [];
        $indexCandidat = 0;

        foreach ($salles as $salle) {
            $capacite = max(1, (int) ($salle->capacite ?? 30));
            $placeDansSalle = 1;

            while ($indexCandidat < $totalCandidats && $placeDansSalle <= $capacite) {
                $candidat = $candidats[$indexCandidat];
                $placeStr = $salle->code.'-'.str_pad((string) $placeDansSalle, 2, '0', STR_PAD_LEFT);

                $repartition[] = [
                    'candidat_id' => $candidat->id,
                    'etudiant_nom' => $candidat->etudiant->nom_complet ?? 'Candidat #'.$candidat->id,
                    'salle_id' => $salle->id,
                    'salle_code' => $salle->code,
                    'place' => $placeStr,
                ];

                $placeDansSalle++;
                $indexCandidat++;
            }

            if ($indexCandidat >= $totalCandidats) {
                break;
            }
        }

        $critiques = array_filter($conflits, fn ($c) => ($c['critique'] ?? false) === true);

        return [
            'valide' => empty($critiques) && $indexCandidat >= $totalCandidats,
            'total_candidats' => $totalCandidats,
            'capacite_totale' => $capaciteTotale,
            'repartition' => $repartition,
            'conflits' => $conflits,
        ];
    }

    /**
     * Applique une proposition de répartition validée de manière transactionnelle.
     *
     * @param  array<int, array{candidat_id: int, salle_id: int, place: string}>  $repartition
     */
    public function appliquerRepartition(Epreuve $epreuve, array $repartition, int $auteurId): int
    {
        return DB::transaction(function () use ($epreuve, $repartition) {
            /** @var Epreuve $verrouillee */
            $verrouillee = Epreuve::whereKey($epreuve->id)->lockForUpdate()->firstOrFail();

            if ($verrouillee->estVerrouillee()) {
                throw new DomainException('Épreuve verrouillée : répartition interdite.');
            }

            // Garde multi-salles (point 3) : chaque salle de la répartition
            // doit être libre sur le créneau (principale ET secondaires des
            // autres épreuves). Fail-closed avant toute écriture.
            $sallesRepartition = collect($repartition)
                ->pluck('salle_id')->map(fn ($id) => (int) $id)
                ->filter(fn (int $id) => $id > 0)->unique()->values()->all();

            if ($sallesRepartition !== []) {
                $detectes = $this->conflits->detecter([
                    'date' => $verrouillee->date->format('Y-m-d'),
                    'heure_debut' => substr((string) $verrouillee->heure_debut, 0, 5),
                    'heure_fin' => substr((string) $verrouillee->heure_fin, 0, 5),
                    'salle_ids' => $sallesRepartition,
                ], $verrouillee->id);

                $occupation = array_values(array_filter(
                    $this->conflits->bloquants($detectes),
                    fn (array $c) => $c['type'] === 'salle'
                ));

                if ($occupation !== []) {
                    throw new DomainException($occupation[0]['message']);
                }
            }

            $modifies = 0;
            $premiereSalleId = null;

            foreach ($repartition as $item) {
                $candidatId = (int) $item['candidat_id'];
                $salleId = (int) $item['salle_id'];
                $place = (string) $item['place'];

                $candidat = Candidat::where('id', $candidatId)
                    ->where('epreuve_id', $verrouillee->id)
                    ->lockForUpdate()
                    ->first();

                if ($candidat !== null) {
                    $candidat->update([
                        'salle_id' => $salleId,
                        'place' => $place,
                    ]);
                    $modifies++;

                    if ($premiereSalleId === null) {
                        $premiereSalleId = $salleId;
                    }
                }
            }

            if ($verrouillee->salle_id === null && $premiereSalleId !== null) {
                $verrouillee->update(['salle_id' => $premiereSalleId]);
            }

            IntegrationLog::tracer(
                'evaluation',
                'internal',
                'exam.room.repartition_applied',
                'completed',
                null,
                200,
                null,
                null,
                (string) Str::uuid(),
                'EPR-'.$verrouillee->id
            );

            EvaluationChanged::dispatch('epreuve.repartition_appliquee', $verrouillee->id);

            return $modifies;
        });
    }
}
