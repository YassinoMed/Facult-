<?php

namespace App\Modules\ParcoursAcademique\Services;

use App\Contracts\PlanificationParcoursInterface;
use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\ParcoursAcademique\Models\ParcoursDispense;
use App\Modules\ParcoursAcademique\Models\ParcoursEquivalence;
use App\Modules\ParcoursAcademique\Models\ParcoursPasserelle;
use App\Modules\ParcoursAcademique\Models\ParcoursPlan;
use App\Modules\ParcoursAcademique\Models\ParcoursReorientation;
use App\Services\AcademicProgressionService;
use App\Services\AuditService;

/**
 * Planification automatique du parcours (§1).
 * Analyse niveau, formation, spécialité, semestre, crédits,
 * validées/non-validées, dettes, décisions, conditions de
 * passage/réinscription. Ne devine jamais : progression via
 * AcademicProgressionService (décisions officielles + ordre
 * référentiel), jamais d'incrément aveugle.
 */
class PlanParcoursService implements PlanificationParcoursInterface
{
    public function __construct(
        private readonly AcademicProgressionService $progressions,
        private readonly CreditParcoursService $credits,
        private readonly DetteAcademiqueService $dettes,
        private readonly ParcoursRegleService $regles,
        private readonly AuditService $audit,
    ) {}

    public function analyser(Etudiant $etudiant, ?InscriptionAcademique $source = null): array
    {
        $etudiant->loadMissing(['programmeRef', 'niveauRef', 'faculte']);

        $source ??= InscriptionAcademique::where('etudiant_id', $etudiant->id)
            ->with(['programmeRef', 'niveauRef', 'anneeUniversitaire', 'semestres'])
            ->orderByDesc('annee_universitaire_id')
            ->first();

        $credits = $this->credits->creditsEtudiant($etudiant);
        $dettes = $this->dettes->lister($etudiant);

        $amenagements = [
            'equivalences_validees' => ParcoursEquivalence::where('etudiant_id', $etudiant->id)->where('statut', 'validee')->count(),
            'dispenses_validees' => ParcoursDispense::where('etudiant_id', $etudiant->id)->where('statut', 'validee')->count(),
            'reorientations' => ParcoursReorientation::where('etudiant_id', $etudiant->id)->whereIn('statut', ['proposee', 'validee'])->count(),
            'passerelles' => ParcoursPasserelle::where('etudiant_id', $etudiant->id)->whereIn('statut', ['proposee', 'eligible', 'admise'])->count(),
        ];

        $decisions = DecisionDeliberation::where('etudiant_id', $etudiant->id)
            ->where('publie', true)->orderByDesc('id')->limit(5)->get()
            ->map(fn ($d) => [
                'decision' => $d->decision,
                'inscription_id' => $d->inscription_academique_id,
                'credits' => $d->credits_obtenus ?? null,
            ])->all();

        $propositions = [];
        $reglesUtilisees = [];

        if ($source !== null) {
            $evaluation = $this->progressions->evaluer($etudiant, $source);
            $reglesUtilisees[] = 'AcademicProgressionService::evaluer (décision publiée + ordre référentiel)';

            $propositions[] = [
                'scenario' => $evaluation['action'],
                'niveau_cible' => $evaluation['niveau_cible']?->code,
                'programme_cible' => $evaluation['programme_cible']?->nom,
                'annee_cible' => $evaluation['annee_cible']?->libelle,
                'blocages' => $evaluation['blocages'],
                'base_officielle' => true,
            ];

            $codeNiveau = strtoupper((string) ($source->niveauRef?->code ?? $source->niveau ?? ''));
            if (str_starts_with($codeNiveau, 'L')) {
                $regle = $this->regles->resoudre(
                    $codeNiveau === 'L3' ? 'ADMISSION_MASTER' : 'PASSAGE_L1_L2',
                    $source->annee_universitaire_id, $source->programme_id
                );
                $reglesUtilisees[] = $regle['reference'];
                $propositions[] = $this->propositionNiveau($codeNiveau, $credits, $dettes, $regle);
            }
        }

        return [
            'etudiant_id' => $etudiant->id,
            'inscription_source_id' => $source?->id,
            'niveau_actuel' => $source?->niveauRef?->code ?? $source?->niveau ?? $etudiant->niveauRef?->code,
            'programme' => $source?->programmeRef?->nom ?? $etudiant->programmeRef?->nom,
            'specialite' => $etudiant->programmeRef?->specialite?->nom,
            'credits_obtenus' => $credits['obtenus'],
            'credits_requis' => $credits['requis'],
            'credits_manquants' => $credits['restants'],
            'validees' => $credits['par_ue'],
            'dettes' => $dettes,
            'decisions' => $decisions,
            'amenagements' => $amenagements,
            'propositions' => $propositions,
            'regles' => $reglesUtilisees,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $dettes
     * @param  array{code: string, criteres: array<string, mixed>, reference: string}  $regle
     * @return array<string, mixed>
     */
    private function propositionNiveau(string $codeNiveau, array $credits, array $dettes, array $regle): array
    {
        $criteres = $regle['criteres'];
        $conditionsNecessaires = [];
        $satisfaites = [];
        $nonSatisfaites = [];

        if ($codeNiveau === 'L3') {
            $conditionsNecessaires = [
                'Diplôme Licence (180 crédits)',
                'Moyenne ≥ '.($criteres['moyenne_min'] ?? 10.0),
                'Décision d\'admission Master (jury/commission)',
            ];
            $credits['obtenus'] >= 180 ? $satisfaites[] = '180 crédits atteints' : $nonSatisfaites[] = 'Crédits insuffisants ('.$credits['obtenus'].'/180)';
            $nonSatisfaites[] = 'Admission Master : jamais automatique, décision externe requise';
        } else {
            $conditionsNecessaires = [
                'Crédits ≥ '.($criteres['credits_requis'] ?? 60).' sur l\'année',
                'Décision jury admis* ou compensation applicable',
                'Dettes reprises l\'année suivante',
            ];
            $dettesActives = collect($dettes)->where('repassable', true)->count();
            $dettesActives <= ($criteres['dettes_max'] ?? 2)
                ? $satisfaites[] = "Dettes contenues ({$dettesActives})"
                : $nonSatisfaites[] = "Trop de dettes ({$dettesActives})";
        }

        return [
            'scenario' => $codeNiveau === 'L3' ? 'passerelle_master' : 'passage',
            'conditions_necessaires' => $conditionsNecessaires,
            'conditions_satisfaites' => $satisfaites,
            'conditions_non_satisfaites' => $nonSatisfaites,
            'regle' => $regle['reference'],
            'base_officielle' => false,
        ];
    }

    /**
     * Enregistre une proposition de plan (brouillon réversible).
     */
    public function proposerPlan(Etudiant $etudiant, ?InscriptionAcademique $source, string $objectif, array $contenu, ?int $auteurId): ParcoursPlan
    {
        $plan = ParcoursPlan::create([
            'etudiant_id' => $etudiant->id,
            'inscription_source_id' => $source?->id,
            'objectif' => $objectif,
            'contenu' => $contenu,
            'statut' => 'propose',
            'version' => 1,
            'auteur_id' => $auteurId,
        ]);

        $this->audit->tracer($etudiant, 'parcours.plan_propose', 'parcours_plan', $plan->id, null, [
            'objectif' => $objectif,
        ]);

        return $plan;
    }
}
