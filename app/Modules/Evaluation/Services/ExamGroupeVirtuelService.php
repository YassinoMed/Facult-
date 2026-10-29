<?php

namespace App\Modules\Evaluation\Services;

use App\Models\Etudiant;
use App\Modules\Evaluation\DTO\DecisionEligibiliteExamen;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use DomainException;
use Illuminate\Support\Collection;

/**
 * Service de détection, proposition et constitution des Groupes Virtuels d'Examen (LOT A - §2.1).
 *
 * Règles métier absolues :
 * 1. Correspondance académique stricte : niveau, programme/filière et spécialité.
 * 2. Seuls les étudiants inscrits au module concerné et déclarés éligibles sont retenus.
 * 3. ZÉRO ÉCRITURE sur les groupes académiques d'origine ni sur les inscriptions des étudiants.
 * 4. Un même sujet d'examen peut regrouper plusieurs groupes académiques sources.
 * 5. Le regroupement virtuel peut différer selon le module.
 * 6. L'éligibilité est recalculée individuellement et de façon explicable.
 */
class ExamGroupeVirtuelService
{
    public function __construct(
        private ServiceEligibiliteExamen $serviceEligibilite
    ) {}

    /**
     * Détecte automatiquement les groupes académiques compatibles avec l'épreuve cible.
     *
     * @return Collection<int, Group>
     */
    public function detecterGroupesCompatibles(Epreuve $epreuve): Collection
    {
        $epreuve->loadMissing(['matiere.ue.programme', 'examen.programme', 'examen.niveau']);

        $programmeId = $epreuve->examen?->programme_id ?? $epreuve->matiere?->ue?->programme_id;
        $niveauId = $epreuve->examen?->niveau_id;
        $matiereId = $epreuve->matiere_id;

        if ($matiereId === null) {
            return new Collection;
        }

        // Recherche des groupes attachés au même programme et/ou niveau
        return Group::query()
            ->when($programmeId !== null, fn ($q) => $q->where('programme_id', $programmeId))
            ->when($niveauId !== null, fn ($q) => $q->where('niveau_id', $niveauId))
            ->whereHas('etudiants.inscriptionsAcademiques.pedagogiques', function ($q) use ($matiereId) {
                $q->where('matiere_id', $matiereId)
                    ->where('statut', 'inscrit');
            })
            ->withCount('etudiants')
            ->orderBy('nom')
            ->get();
    }

    /**
     * Propose et audite la constitution d'un groupe virtuel d'examen pour une épreuve
     * à partir d'une sélection de groupes sources.
     *
     * @param  array<int, int>  $groupesSourcesIds
     * @return array{
     *     epreuve_id: int,
     *     matiere_id: int,
     *     matiere_nom: string,
     *     groupes_sources: array<int, array{id: int, code: string, nom: string, effectif_origine: int}>,
     *     effectif_total_origine: int,
     *     effectif_eligible: int,
     *     effectif_exclu: int,
     *     candidats_eligibles: array<int, array{etudiant_id: int, num_inscription: string, nom: string, prenom: string, groupe_source_id: int, decision: string}>,
     *     etudiants_exclus: array<int, array{etudiant_id: int, num_inscription: string, nom: string, prenom: string, groupe_source_id: int, motif: string}>,
     *     est_valide: bool,
     *     anomalies_coherence: array<int, string>
     * }
     */
    public function proposerGroupeVirtuel(Epreuve $epreuve, array $groupesSourcesIds): array
    {
        $epreuve->loadMissing(['matiere.ue.programme', 'examen.programme', 'session', 'anneeUniversitaire']);

        if ($epreuve->matiere === null) {
            throw new DomainException("L'épreuve n'a aucun module/matière associé.");
        }

        $groupes = Group::whereIn('id', $groupesSourcesIds)->with('programme', 'niveauRef')->get();

        if ($groupes->isEmpty()) {
            throw new DomainException('Au moins un groupe source valide doit être sélectionné.');
        }

        // Vérification de la cohérence académique entre les groupes sources
        $anomalies = [];
        $programmesDistincts = $groupes->pluck('programme_id')->filter()->unique();
        $niveauxDistincts = $groupes->pluck('niveau_id')->filter()->unique();
        $codesNiveauDistincts = $groupes->pluck('niveau')->filter(fn ($v) => $v !== null && trim((string) $v) !== '')->map(fn ($v) => mb_strtoupper(trim((string) $v)))->unique();
        $filieresDistinctes = $groupes->pluck('filiere')->filter(fn ($v) => $v !== null && trim((string) $v) !== '')->map(fn ($v) => mb_strtoupper(trim((string) $v)))->unique();

        if ($programmesDistincts->count() > 1) {
            $anomalies[] = 'Incompatibilité : les groupes sélectionnés appartiennent à des programmes académiques distincts.';
        }

        if ($niveauxDistincts->count() > 1 || $codesNiveauDistincts->count() > 1) {
            $anomalies[] = 'Incompatibilité : les groupes sélectionnés appartiennent à des niveaux académiques distincts.';
        }

        if ($filieresDistinctes->count() > 1) {
            $anomalies[] = 'Incompatibilité : les groupes sélectionnés appartiennent à des filières distinctes.';
        }

        $groupesInfos = [];
        $candidatsEligibles = [];
        $etudiantsExclus = [];
        $etudiantsTraites = [];

        foreach ($groupes as $groupe) {
            $groupesInfos[] = [
                'id' => $groupe->id,
                'code' => (string) $groupe->code,
                'nom' => (string) $groupe->nom,
                'effectif_origine' => (int) $groupe->etudiants()->count(),
            ];

            // Récupération des étudiants du groupe source
            $etudiants = Etudiant::where('groupe_id', $groupe->id)
                ->orderBy('nom')
                ->orderBy('prenom')
                ->get();

            foreach ($etudiants as $etudiant) {
                // Éviter les doublons si un étudiant apparaîtrait plusieurs fois
                if (isset($etudiantsTraites[$etudiant->id])) {
                    continue;
                }
                $etudiantsTraites[$etudiant->id] = true;

                // Évaluation stricte de l'éligibilité via le service central
                $decision = $this->serviceEligibilite->evaluer($epreuve, $etudiant);

                if ($decision->decision === DecisionEligibiliteExamen::ELIGIBLE) {
                    $candidatsEligibles[] = [
                        'etudiant_id' => $etudiant->id,
                        'num_inscription' => (string) $etudiant->num_inscription,
                        'nom' => (string) $etudiant->nom,
                        'prenom' => (string) $etudiant->prenom,
                        'groupe_source_id' => $groupe->id,
                        'decision' => $decision->decision,
                    ];
                } else {
                    $etudiantsExclus[] = [
                        'etudiant_id' => $etudiant->id,
                        'num_inscription' => (string) $etudiant->num_inscription,
                        'nom' => (string) $etudiant->nom,
                        'prenom' => (string) $etudiant->prenom,
                        'groupe_source_id' => $groupe->id,
                        'motif' => $decision->motif ?? 'Étudiant non éligible pour cette épreuve.',
                    ];
                }
            }
        }

        return [
            'epreuve_id' => $epreuve->id,
            'matiere_id' => $epreuve->matiere_id,
            'matiere_nom' => (string) $epreuve->matiere->nom,
            'groupes_sources' => $groupesInfos,
            'effectif_total_origine' => count($etudiantsTraites),
            'effectif_eligible' => count($candidatsEligibles),
            'effectif_exclu' => count($etudiantsExclus),
            'candidats_eligibles' => $candidatsEligibles,
            'etudiants_exclus' => $etudiantsExclus,
            'est_valide' => $anomalies === [] && count($candidatsEligibles) > 0,
            'anomalies_coherence' => $anomalies,
        ];
    }
}
