<?php

namespace App\Modules\Evaluation\Services;

use App\Models\Matiere;
use App\Modules\Evaluation\Events\EvaluationChanged;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Models\Surveillance;
use App\Modules\Referentiel\Models\Maquette;
use App\Modules\Referentiel\Models\MaquetteUeEcue;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Assistant de préparation de session (§13.3-13.9) : génération
 * d'épreuves depuis la maquette active, recherche de salles,
 * affectation équitable des surveillants, le tout idempotent et
 * validé par l'administrateur avant publication.
 */
class SessionPreparationService
{
    public function __construct(
        private EpreuveService $epreuves,
        private SurveillanceService $surveillances,
        private ConflitEpreuveService $conflits,
    ) {}

    /**
     * @return array{examen_id: int, creees: int, ignorees: array<int, array{matiere: string, groupe: string, raison: string}>}
     */
    public function genererEpreuves(
        SessionExamen $session,
        int $programmeId,
        ?string $niveau,
        string $dateDebut,
        string $heureDebut = '09:00',
        string $heureFin = '11:00',
    ): array {
        return DB::transaction(function () use ($session, $programmeId, $niveau, $dateDebut, $heureDebut, $heureFin) {
            if ($session->estVerrouillee()) {
                throw new DomainException('Session verrouillée.');
            }

            $maquette = Maquette::where('programme_id', $programmeId)
                ->where('annee_universitaire_id', $session->annee_universitaire_id)
                ->where('statut', 'active')
                ->first();

            if ($maquette === null) {
                throw new DomainException('Aucune maquette active pour ce programme et cette année.');
            }

            $ueIds = $maquette->unitesEnseignement()->pluck('unites_enseignement.id');

            // Vague 3 (audit 08/10/2026) : le plan actif fait foi —
            // pour chaque UE, les ECUE du plan (maquette_ue_ecue) si le
            // plan les définit, sinon repli sur le rattachement UE
            // (matieres.ue_id, FALLBACK documenté). Jamais de mutation
            // de la matière canonique.
            $occurrences = MaquetteUeEcue::whereHas('maquetteUe', fn ($q) => $q->where('maquette_id', $maquette->id))
                ->with(['matiere', 'maquetteUe'])
                ->get()
                ->groupBy(fn ($occurrence) => $occurrence->maquetteUe->ue_id);

            $ecues = collect();
            foreach ($ueIds as $ueId) {
                if ($occurrences->has($ueId)) {
                    $ecues = $ecues->merge(
                        $occurrences->get($ueId)->map->matiere->filter(fn ($m) => $m->actif)
                    );
                } else {
                    $ecues = $ecues->merge(
                        Matiere::where('ue_id', $ueId)->where('actif', true)->get()
                    );
                }
            }
            $ecues = $ecues->unique('id')->sortBy('code')->values();

            if ($ecues->isEmpty()) {
                throw new DomainException('Maquette sans ECUE exploitable.');
            }

            $groupes = Group::where('programme_id', $programmeId)
                ->when($niveau !== null, fn ($q) => $q->where('niveau', $niveau))
                ->where('actif', true)->orderBy('code')->get();

            if ($groupes->isEmpty()) {
                throw new DomainException('Aucun groupe actif pour ce périmètre.');
            }

            $examen = Examen::firstOrCreate(
                ['session_id' => $session->id, 'programme_id' => $programmeId, 'niveau_id' => null],
                [
                    'annee_universitaire_id' => $session->annee_universitaire_id,
                    'libelle' => 'Session '.$session->nom,
                    'type' => $session->type === 'rattrapage' ? 'rattrapage' : 'examen',
                    'statut' => 'draft',
                ]
            );

            $creees = 0;
            $ignorees = [];
            $jour = Carbon::parse($dateDebut);

            foreach ($ecues as $ecue) {
                foreach ($groupes as $groupe) {
                    if (Epreuve::where('session_id', $session->id)
                        ->where('matiere_id', $ecue->id)
                        ->where('groupe_id', $groupe->id)
                        ->exists()) {
                        continue;
                    }

                    try {
                        $this->epreuves->creer($examen, [
                            'session_id' => $session->id,
                            'matiere_id' => $ecue->id,
                            'ue_id' => $ecue->ue_id,
                            'groupe_id' => $groupe->id,
                            'date' => $jour->format('Y-m-d'),
                            'heure_debut' => $heureDebut,
                            'heure_fin' => $heureFin,
                        ]);
                        $creees++;
                    } catch (DomainException $e) {
                        $ignorees[] = ['matiere' => $ecue->code, 'groupe' => $groupe->code, 'raison' => $e->getMessage()];
                    }
                }
                $jour->addDay();
            }

            EvaluationChanged::dispatch('session.preparee', $session->id);

            return ['examen_id' => $examen->id, 'creees' => $creees, 'ignorees' => $ignorees];
        });
    }

    /**
     * Salles compatibles : capacité suffisante, libres côté planning
     * (créneaux) ET côté examens, triées par capacité croissante.
     *
     * @return array<int, array{id: int, code: string, capacite: ?int}>
     */
    public function suggererSalles(Epreuve $epreuve): array
    {
        $epreuve->loadMissing(['groupe.etudiants', 'matiere']);
        $candidats = $epreuve->capacite_necessaire > 0
            ? (int) $epreuve->capacite_necessaire
            : $epreuve->groupe?->etudiants()->count() ?? 0;

        $jour = Carbon::parse($epreuve->date->format('Y-m-d'))->format('l');
        $debut = substr((string) $epreuve->heure_debut, 0, 5);
        $fin = substr((string) $epreuve->heure_fin, 0, 5);

        $salles = Room::where('actif', true)
            ->when($candidats > 0, fn ($q) => $q->where('capacite', '>=', $candidats))
            ->orderBy('capacite')->get();

        $libres = [];

        foreach ($salles as $salle) {
            if ($this->salleOccupeePlanning($salle->id, $jour, $debut, $fin)) {
                continue;
            }

            $conflits = $this->conflits->detecter([
                'date' => $epreuve->date->format('Y-m-d'),
                'heure_debut' => $debut,
                'heure_fin' => $fin,
                'salle_id' => $salle->id,
            ], $epreuve->id);

            if (array_any($conflits, fn (array $c) => $c['type'] === 'salle' && $c['critique'])) {
                continue;
            }

            $libres[] = ['id' => $salle->id, 'code' => $salle->code, 'capacite' => $salle->capacite];
        }

        return $libres;
    }

    /**
     * Affectation équitable : enseignants les moins sollicités sur la
     * session d'abord, conflits horaires exclus automatiquement.
     *
     * @return array{assignes: int, ignores: int}
     */
    public function affecterSurveillantsAuto(Epreuve $epreuve, int $nombre): array
    {
        $charges = Surveillance::whereHas(
            'epreuve',
            fn ($q) => $q->where('session_id', $epreuve->session_id)
        )->selectRaw('teacher_id, COUNT(*) as total')
            ->whereNotNull('teacher_id')->groupBy('teacher_id')->pluck('total', 'teacher_id');

        $candidats = Teacher::where('actif', true)->get()
            ->sortBy(fn (Teacher $t) => $charges[$t->id] ?? 0)
            ->values();

        $assignes = 0;
        $ignores = 0;

        foreach ($candidats as $enseignant) {
            if ($assignes >= $nombre) {
                break;
            }

            try {
                $this->surveillances->assigner($epreuve, ['teacher_id' => $enseignant->id]);
                $assignes++;
            } catch (DomainException) {
                $ignores++;
            }
        }

        return ['assignes' => $assignes, 'ignores' => $ignores];
    }

    private function salleOccupeePlanning(int $salleId, string $jour, string $debut, string $fin): bool
    {
        return ScheduleSlot::where('room_id', $salleId)
            ->where('day_of_week', $jour)
            ->where('status', '!=', 'cancelled')
            ->get()
            ->contains(fn ($slot) => $slot->chevauche($debut, $fin));
    }
}
