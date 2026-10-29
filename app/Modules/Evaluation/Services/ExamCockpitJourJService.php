<?php

namespace App\Modules\Evaluation\Services;

use App\Models\User;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Surveillance;
use Illuminate\Support\Carbon;

/**
 * Cockpit opérationnel du Jour J et supervision en temps réel (LOT I - §2.10, §3.1).
 *
 * Consolide en lecture seule optimisée :
 * - Épreuves du jour et état d'avancement
 * - Salles mobilisées
 * - Surveillants attendus, présents, absents et remplacés
 * - Candidats attendus, présents, retards, absents provisoires et régularisations
 * - Incidents par niveau de gravité (mineure, majeure, critique)
 * - Éléments bloquant la clôture des épreuves
 * - Filtrage strict au niveau serveur par rôle et par périmètre de faculté
 */
class ExamCockpitJourJService
{
    public function __construct(
        private ExamPresenceRegularisationService $presenceService,
        private ExamUrgenceSuppleanceService $urgenceService
    ) {}

    /**
     * Obtenir les métriques et l'état opérationnel du Jour J pour un utilisateur.
     *
     * @return array{
     *     date_jour: string,
     *     perimetre_applique: string,
     *     kpis_epreuves: array{total: int, terminees: int, en_cours: int, cloturees: int},
     *     kpis_etudiants: array{attendus: int, presents: int, retards: int, absents_provisoires: int, regularisations: int},
     *     kpis_surveillance: array{affectes: int, presents: int, remplacements: int},
     *     kpis_incidents: array{total: int, critiques: int, majeurs: int, mineurs: int},
     *     epreuves: array<int, array{
     *         id: int,
     *         code: string,
     *         matiere: string,
     *         horaire: string,
     *         salle: string,
     *         statut: string,
     *         candidats_count: int,
     *         presents_count: int,
     *         incidents_count: int,
     *         bloquants_cloture: int
     *     }>
     * }
     */
    public function obtenirCockpitJourJ(User $user, ?string $date = null, ?int $faculteId = null): array
    {
        $dateJour = $date ?? Carbon::today()->format('Y-m-d');
        $faculteRestreinteId = $user->faculteRestreinteId() ?? $faculteId;

        // Requête de base sur les épreuves de la journée
        $query = Epreuve::query()
            ->whereDate('date', $dateJour)
            ->where('statut', '!=', 'cancelled')
            ->when($faculteRestreinteId !== null, fn ($q) => $q->pourFaculteId($faculteRestreinteId))
            ->with(['matiere', 'salle', 'surveillants', 'incidents'])
            ->withCount(['candidats', 'incidents']);

        // Filtrage spécifique si l'utilisateur est enseignant/surveillant
        if (($user->role ?? null) === 'enseignant' && ($user->role ?? null) !== 'admin_scolarite') {
            $teacherId = $user->teacher?->id;
            $query->where(function ($q) use ($teacherId, $user) {
                $q->whereHas('surveillants', fn ($s) => $s->where('teacher_id', $teacherId)->orWhere('user_id', $user->id))
                    ->orWhere('examen.responsable_id', $user->id);
            });
        }

        $epreuves = $query->orderBy('heure_debut')->get();

        $epreuvesDetails = [];
        $totalAttendus = 0;
        $totalPresents = 0;
        $totalRetards = 0;
        $totalAbsentsProvisoires = 0;
        $totalRegularisations = 0;

        $incidentsCritiques = 0;
        $incidentsMajeurs = 0;
        $incidentsMineurs = 0;

        foreach ($epreuves as $epreuve) {
            $auditPresences = $this->presenceService->verifierEcartsPresences($epreuve);
            $totalAttendus += $auditPresences['total_candidats'];
            $totalPresents += $auditPresences['presents'];
            $totalRetards += $auditPresences['retards'];
            $totalAbsentsProvisoires += $auditPresences['absents_provisoires'];
            $totalRegularisations += $auditPresences['regularisations_en_cours'];

            // Compte des incidents par gravité
            foreach ($epreuve->incidents as $inc) {
                $meta = $this->urgenceService->extraireMetadonneesIncident($inc);
                $gravite = $meta['gravite'] ?? 'mineure';
                if ($gravite === 'critique') {
                    $incidentsCritiques++;
                } elseif ($gravite === 'majeure') {
                    $incidentsMajeurs++;
                } else {
                    $incidentsMineurs++;
                }
            }

            $epreuvesDetails[] = [
                'id' => $epreuve->id,
                'code' => (string) $epreuve->code,
                'matiere' => (string) ($epreuve->matiere?->nom ?? '—'),
                'horaire' => substr((string) $epreuve->heure_debut, 0, 5).' - '.substr((string) $epreuve->heure_fin, 0, 5),
                'salle' => (string) ($epreuve->salle?->nom ?? 'Non assignée'),
                'statut' => (string) $epreuve->statut,
                'candidats_count' => (int) $epreuve->candidats_count,
                'presents_count' => $auditPresences['presents'],
                'incidents_count' => (int) $epreuve->incidents_count,
                'bloquants_cloture' => count($auditPresences['motifs_blocage']),
            ];
        }

        $surveillancesTotal = Surveillance::whereIn('epreuve_id', $epreuves->pluck('id'))->get();
        $surveillantsAffectes = $surveillancesTotal->whereNotIn('statut', ['annule', 'remplace'])->count();
        $surveillantsPresents = $surveillancesTotal->where('presence', 'present')->count();
        $surveillantsRemplaces = $surveillancesTotal->where('statut', 'remplace')->count();

        return [
            'date_jour' => $dateJour,
            'perimetre_applique' => $faculteRestreinteId !== null ? "Faculté ID #{$faculteRestreinteId}" : 'Transversal / Global',
            'kpis_epreuves' => [
                'total' => $epreuves->count(),
                'terminees' => $epreuves->where('statut', 'completed')->count(),
                'en_cours' => $epreuves->where('statut', 'ongoing')->count(),
                'cloturees' => $epreuves->where('statut', 'locked')->count(),
            ],
            'kpis_etudiants' => [
                'attendus' => $totalAttendus,
                'presents' => $totalPresents,
                'retards' => $totalRetards,
                'absents_provisoires' => $totalAbsentsProvisoires,
                'regularisations' => $totalRegularisations,
            ],
            'kpis_surveillance' => [
                'affectes' => $surveillantsAffectes,
                'presents' => $surveillantsPresents,
                'remplacements' => $surveillantsRemplaces,
            ],
            'kpis_incidents' => [
                'total' => $incidentsCritiques + $incidentsMajeurs + $incidentsMineurs,
                'critiques' => $incidentsCritiques,
                'majeurs' => $incidentsMajeurs,
                'mineurs' => $incidentsMineurs,
            ],
            'epreuves' => $epreuvesDetails,
        ];
    }
}
