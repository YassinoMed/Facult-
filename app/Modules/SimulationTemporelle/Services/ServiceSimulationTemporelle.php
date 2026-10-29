<?php

namespace App\Modules\SimulationTemporelle\Services;

use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Schedule\Models\Group;
use App\Modules\SimulationTemporelle\Adaptateurs\AdaptateurEffectifsCohortes;
use App\Modules\SimulationTemporelle\Models\ExecutionTemporelle;
use App\Modules\SimulationTemporelle\Models\ScenarioTemporel;
use App\Modules\SimulationTemporelle\Models\SnapshotExecution;
use App\Modules\SimulationTemporelle\Support\AnalyseurSensibilite;
use App\Modules\SimulationTemporelle\Support\AuditeurQualiteDonnees;
use App\Modules\SimulationTemporelle\Support\ClasseurScenarios;
use App\Modules\SimulationTemporelle\Support\ComparateurProjections;
use App\Modules\SimulationTemporelle\Support\EstimateurCapacitesGroupes;
use App\Modules\SimulationTemporelle\Support\EvaluateurScenarios;
use App\Modules\SimulationTemporelle\Support\ExplicateurScenarios;
use App\Modules\SimulationTemporelle\Support\GenerateurVariantes;
use App\Modules\SimulationTemporelle\Support\GestionnaireAlertesSeuils;
use App\Modules\SimulationTemporelle\Support\MoteurEvenements;
use App\Modules\SimulationTemporelle\Support\OptimiseurPareto;
use App\Modules\SimulationTemporelle\Support\ProjecteurBudgetaire;
use App\Modules\SimulationTemporelle\Support\SimulateurMonteCarlo;
use DateTimeImmutable;

/**
 * Simulateur temporel multi-domaines (pilote : effectifs/cohortes LMD).
 *
 * RÈGLES DU MANDAT :
 * - AUCUN effet réel : lecture seule des sources, écriture UNIQUEMENT
 *   dans les 3 tables du module ; snapshots = agrégats sans PII ;
 * - le temps est SIMULÉ (moteur d'événements + années issues du
 *   référentiel) : jamais now(), jamais l'horloge système ;
 * - REPRODUCTIBILITÉ : hash_entrees (scénario+périmètre+graine+version
 *   moteur) et hash_resultat figés ; idempotence par UNIQUE ;
 * - les taux de progression et les poids sont SAISIS dans les critères —
 *   aucun taux n'est inventé ; taux absents = progression gelée avec
 *   AVERTISSEMENT explicite (jamais zéro silencieux) ;
 * - plafonds : variantes ≤ 20, horizon ≤ 6 ans, événements bornés.
 */
final class ServiceSimulationTemporelle
{
    public const VERSION_MOTEUR = '1.0';

    public const PLAFOND_VARIANTES = 20;

    public const PLAFOND_HORIZON = 6;

    public function __construct(
        private readonly AdaptateurEffectifsCohortes $adaptateur,
        private readonly EvaluateurScenarios $evaluateur,
        private readonly ClasseurScenarios $classeur,
        private ?AuditeurQualiteDonnees $auditeur = null,
        private ?ComparateurProjections $comparateur = null,
        private ?ExplicateurScenarios $explicateur = null,
        private ?EstimateurCapacitesGroupes $estimateurCapacites = null,
        private ?AnalyseurSensibilite $analyseurSensibilite = null,
        private ?OptimiseurPareto $optimiseurPareto = null,
        private ?SimulateurMonteCarlo $simulateurMonteCarlo = null,
        private ?ProjecteurBudgetaire $projecteurBudgetaire = null,
        private ?GestionnaireAlertesSeuils $gestionnaireAlertes = null,
    ) {
        $this->auditeur ??= new AuditeurQualiteDonnees;
        $this->comparateur ??= new ComparateurProjections;
        $this->explicateur ??= new ExplicateurScenarios;
        $this->estimateurCapacites ??= new EstimateurCapacitesGroupes;
        $this->analyseurSensibilite ??= new AnalyseurSensibilite;
        $this->optimiseurPareto ??= new OptimiseurPareto;
        $this->simulateurMonteCarlo ??= new SimulateurMonteCarlo;
        $this->projecteurBudgetaire ??= new ProjecteurBudgetaire;
        $this->gestionnaireAlertes ??= new GestionnaireAlertesSeuils;
    }

    /**
     * Définir un scénario (descripteurs déclaratifs, version 1).
     *
     * @throws \DomainException si bornes/taux/poids invalides
     */
    public function definir(array $donnees, ?int $auteurId): ScenarioTemporel
    {
        $criteres = $this->validerCriteres($donnees['criteres'], $donnees['faculte_id'] ?? null);
        $this->validerDescripteurs($donnees['contraintes'], $donnees['objectifs']);

        return ScenarioTemporel::create([
            'code' => $donnees['code'],
            'libelle' => $donnees['libelle'],
            'domaine' => 'effectifs_cohortes',
            'faculte_id' => $donnees['faculte_id'] ?? null,
            'criteres' => $criteres,
            'contraintes' => $donnees['contraintes'],
            'objectifs' => $donnees['objectifs'],
            'version' => 1,
            'cree_par' => $auteurId,
        ]);
    }

    /**
     * Crée une nouvelle version incrémentée d'un scénario existant (Lot 4).
     */
    public function nouvelleVersion(ScenarioTemporel $source, array $modifications, ?int $auteurId): ScenarioTemporel
    {
        $nouvelleVersion = $source->version + 1;
        $donnees = array_merge($source->toArray(), $modifications);
        $donnees['code'] = $modifications['code'] ?? ($source->code.'_V'.$nouvelleVersion);
        $donnees['version'] = $nouvelleVersion;
        $donnees['cree_par'] = $auteurId;

        $criteres = $this->validerCriteres($donnees['criteres'], $donnees['faculte_id'] ?? null);
        $this->validerDescripteurs($donnees['contraintes'], $donnees['objectifs']);

        return ScenarioTemporel::create([
            'code' => $donnees['code'],
            'libelle' => $donnees['libelle'],
            'domaine' => $source->domaine,
            'faculte_id' => $donnees['faculte_id'] ?? null,
            'criteres' => $criteres,
            'contraintes' => $donnees['contraintes'],
            'objectifs' => $donnees['objectifs'],
            'version' => $nouvelleVersion,
            'cree_par' => $auteurId,
        ]);
    }

    /**
     * Duplique un scénario existant sous un nouveau code unique (Lot 4).
     */
    public function dupliquer(ScenarioTemporel $source, string $nouveauCode, ?int $auteurId): ScenarioTemporel
    {
        return ScenarioTemporel::create([
            'code' => $nouveauCode,
            'libelle' => $source->libelle.' (copie)',
            'domaine' => $source->domaine,
            'faculte_id' => $source->faculte_id,
            'criteres' => $source->criteres,
            'contraintes' => $source->contraintes,
            'objectifs' => $source->objectifs,
            'version' => 1,
            'cree_par' => $auteurId,
        ]);
    }

    /**
     * Exécuter : idempotent — les mêmes entrées (scénario versionné,
     * périmètre, année, graine, nb variantes) retournent l'exécution
     * EXISTANTE, sans recalcul ni doublon.
     */
    public function executer(ScenarioTemporel $scenario, int $anneeSourceId, string $graine, int $nbVariantes, ?int $auteurId = null): ExecutionTemporelle
    {
        if ($nbVariantes < 1 || $nbVariantes > self::PLAFOND_VARIANTES) {
            throw new \DomainException('Nombre de variantes hors bornes [1, '.self::PLAFOND_VARIANTES.'].');
        }

        $hashEntrees = hash('sha256', json_encode([
            'scenario' => [$scenario->id, $scenario->version, $scenario->criteres, $scenario->contraintes, $scenario->objectifs],
            'annee_source' => $anneeSourceId,
            'faculte' => $scenario->faculte_id,
            'graine' => $graine,
            'nb_variantes' => $nbVariantes,
            'version_moteur' => self::VERSION_MOTEUR,
        ]));

        $existante = ExecutionTemporelle::query()
            ->where('scenario_temporel_id', $scenario->id)
            ->where('hash_entrees', $hashEntrees)
            ->where('graine', $graine)
            ->first();

        if ($existante !== null && $existante->statut === 'terminee') {
            return $existante; // idempotence : mêmes entrées, même résultat figé
        }

        $debut = microtime(true);
        $criteres = $scenario->criteres;

        // 1. Audit Qualité des Données (Lot 1)
        $auditQualite = $this->auditeur->auditer(
            $scenario->faculte_id,
            $anneeSourceId,
            $criteres['niveau_id'] ?? null,
            $criteres['groupes'] ?? null
        );

        // 2. Extraction de l'état initial des effectifs (sans PII)
        $etatInitial = $this->adaptateur->etatInitial(
            $scenario->faculte_id, $anneeSourceId,
            $scenario->criteres['niveau_id'] ?? null,
            $scenario->criteres['groupes'] ?? null,
        );

        $deltas = GenerateurVariantes::deltasAdmissions(
            (float) $criteres['delta_admissions'],
            ['min' => (float) $criteres['bornes']['min'], 'max' => (float) $criteres['bornes']['max']],
            $nbVariantes,
            $graine,
        );

        $anneeBase = (int) substr((string) $etatInitial['annee_source'], 0, 4);
        $variantesEvaluees = [];
        $snapshotsParVariante = [];

        foreach ($deltas as $index => $delta) {
            [$mesures, $snapshots] = $this->simulerVariante(
                $etatInitial['cohortes'], $delta, (int) $criteres['horizon_annees'],
                $criteres['taux'] ?? null, $anneeBase,
            );
            $snapshotsParVariante[$index] = $snapshots;
            $evaluation = $this->evaluateur->evaluer($mesures, $scenario->contraintes, $scenario->objectifs);
            $variantesEvaluees[] = ['index' => $index, 'delta' => $delta, 'mesures' => $mesures, 'evaluation' => $evaluation];
        }

        $classement = $this->classeur->classer($variantesEvaluees);

        // 3. Explication détaillée des scores et contraintes (Lot 3)
        $explicationsVariantes = [];
        foreach ($variantesEvaluees as $v) {
            $explicationsVariantes[$v['index']] = $this->explicateur->expliquer($v, $scenario->contraintes, $scenario->objectifs);
        }

        // 4. Comparaison structurée Référence vs Projection (Lot 2)
        $varianteTop = $classement['classement'][0] ?? $variantesEvaluees[0];
        $varianteRef = $variantesEvaluees[0];
        $cibleTotal = null;
        foreach ($scenario->objectifs as $obj) {
            if ($obj['type'] === 'cible_total') {
                $cibleTotal = (float) $obj['cible'];
                break;
            }
        }
        $comparaison = $this->comparateur->comparer(
            $etatInitial,
            [
                'index' => $varianteTop['index'],
                'delta' => $varianteTop['delta'],
                'totaux' => $varianteTop['mesures']['totaux'],
                'total_final' => $varianteTop['mesures']['total_final'],
            ],
            [
                'index' => $varianteRef['index'],
                'delta' => $varianteRef['delta'],
                'totaux' => $varianteRef['mesures']['totaux'],
                'total_final' => $varianteRef['mesures']['total_final'],
            ],
            $cibleTotal,
            $scenario->contraintes
        );

        // 5. Capacité d'accueil et groupes nécessaires (Lot 5)
        $dernierSnapshot = end($snapshotsParVariante[$varianteTop['index']]);
        $cohortesFinales = $dernierSnapshot['cohortes'] ?? $etatInitial['cohortes'];
        $capacites = $this->estimateurCapacites->estimer(
            $cohortesFinales,
            $criteres['niveau_id'] ?? null,
            $criteres['groupes'] ?? null
        );

        // 6. Analyse de sensibilité OAT (Lot 7)
        $simulateurFn = function ($cohortes, $delta, $horizon, $taux) use ($anneeBase) {
            [$m, $s] = $this->simulerVariante($cohortes, $delta, $horizon, $taux, $anneeBase);

            return $m['total_final'];
        };
        $sensibilite = $this->analyseurSensibilite->analyser($etatInitial['cohortes'], $criteres, $simulateurFn);

        // 7. Optimisation multiobjectif & Frontière de Pareto (Lot 9)
        $variantesPourPareto = array_map(fn ($v) => [
            'index' => $v['index'],
            'delta' => $v['delta'],
            'total_final' => $v['mesures']['total_final'],
            'score' => $v['evaluation']['score'],
            'contributions' => $v['evaluation']['contributions'],
        ], $classement['classement']);
        $pareto = $this->optimiseurPareto->calculerFrontiere($variantesPourPareto);

        // 8. Simulation Monte-Carlo déterministe (Lot 10)
        $plafondContrainte = null;
        foreach ($scenario->contraintes as $c) {
            if ($c['type'] === 'plafond_total') {
                $plafondContrainte = (float) $c['valeur'];
                break;
            }
        }
        $monteCarlo = $this->simulateurMonteCarlo->simuler(
            $etatInitial['cohortes'],
            $criteres,
            50, // 50 itérations rapides et déterministes
            $graine,
            $simulateurFn,
            $plafondContrainte
        );

        // 9. Projection budgétaire hypothétique (Lot 11)
        $budget = $this->projecteurBudgetaire->projeter(
            $varianteTop['mesures']['totaux'],
            $capacites['groupes_theoriques_requis_total'] ?? 1
        );

        // 10. Alertes de seuils configurables (Lot 13)
        $alertesSeuils = $this->gestionnaireAlertes->evaluer(
            $varianteTop['mesures'],
            $capacites,
            $budget
        );

        $resultat = [
            'etat_initial' => $etatInitial,
            'audit_qualite' => $auditQualite,
            'comparaison' => $comparaison,
            'explications_variantes' => $explicationsVariantes,
            'capacites' => $capacites,
            'sensibilite' => $sensibilite,
            'pareto' => $pareto,
            'monte_carlo' => $monteCarlo,
            'budget' => $budget,
            'alertes_seuils' => $alertesSeuils,
            'avertissements' => isset($criteres['taux']) ? [] : [
                'Aucun taux de progression saisi : les cohortes restent gelées après admission (donnée absente, jamais remplacée par zéro).',
            ],
            'classement' => array_map(fn ($v) => [
                'index' => $v['index'], 'delta' => $v['delta'],
                'score' => $v['evaluation']['score'], 'contributions' => $v['evaluation']['contributions'],
                'total_final' => $v['mesures']['total_final'],
                'totaux' => $v['mesures']['totaux'],
            ], $classement['classement']),
            'non_conformes' => array_map(fn ($v) => [
                'index' => $v['index'], 'delta' => $v['delta'], 'violations' => $v['evaluation']['violations'],
                'total_final' => $v['mesures']['total_final'],
                'totaux' => $v['mesures']['totaux'],
            ], $classement['non_conformes']),
            'rappel' => 'Appui à la décision : AUCUNE décision réelle — toute application passe par un workflow distinct et autorisé.',
        ];

        $hashResultat = hash('sha256', json_encode($resultat));

        $execution = ExecutionTemporelle::create([
            'scenario_temporel_id' => $scenario->id,
            'statut' => 'terminee',
            'hash_entrees' => $hashEntrees,
            'hash_resultat' => $hashResultat,
            'graine' => $graine,
            'version_moteur' => self::VERSION_MOTEUR,
            'nb_variantes' => $nbVariantes,
            'horizon_annees' => (int) $criteres['horizon_annees'],
            'resultat' => $resultat,
            'duree_ms' => (int) ((microtime(true) - $debut) * 1000),
            'cree_par' => $auteurId,
        ]);

        // Snapshots de la variante RÉFÉRENCE (index 0) — agrégats uniquement.
        foreach ($snapshotsParVariante[0] as $pas => $etat) {
            SnapshotExecution::create([
                'execution_temporelle_id' => $execution->id,
                'pas' => $pas,
                'temps_simule' => (string) ($anneeBase + $pas),
                'etat' => $etat,
            ]);
        }

        return $execution;
    }

    /**
     * Simulation d'une variante : moteur d'événements (rentree puis
     * progression par année simulée) — temps construit, jamais now().
     *
     * @return array{0: array<string, mixed>, 1: array<int, array>}
     */
    private function simulerVariante(array $cohortesDepart, float $delta, int $horizon, ?array $taux, int $anneeBase): array
    {
        $moteur = new MoteurEvenements;
        $cohortes = $cohortesDepart;
        $baseL1 = (float) (reset($cohortesDepart) ?: 0);
        $ordres = array_keys($cohortesDepart); // niveaux triés par l'adaptateur
        $snapshots = [0 => ['cohortes' => $cohortes, 'total' => array_sum($cohortes)]];

        $handler = function ($evenement) use (&$moteur, &$cohortes, &$snapshots, &$delta, &$taux, &$horizon, &$baseL1, &$ordres, $anneeBase): void {
            $pas = count($snapshots); // 1-based pour ce pas
            $admissions = (int) round($baseL1 * pow(1 + $delta, $pas));

            // Progression par niveaux, du dernier vers le premier — chaque
            // taux SAISI s'applique explicitement (somme ≤ 1 validée) ;
            // le reste éventuel = autres issues, jamais compté en passage.
            $nouveaux = [];
            for ($i = count($ordres) - 1; $i >= 0; $i--) {
                $code = $ordres[$i];
                $effectif = $cohortes[$code];
                $partent = (int) round($effectif * ($taux['passage'] ?? 0));
                $redoublants = (int) round($effectif * ($taux['redoublement'] ?? 0));
                if ($i + 1 < count($ordres)) {
                    $nouveaux[$ordres[$i + 1]] = ($nouveaux[$ordres[$i + 1]] ?? 0) + $partent;
                }
                $cohortes[$code] = $redoublants;
            }
            $cohortes[$ordres[0]] = ($cohortes[$ordres[0]] ?? 0) + $admissions;
            foreach ($nouveaux as $code => $effectif) {
                $cohortes[$code] = ($cohortes[$code] ?? 0) + $effectif;
            }

            $total = array_sum($cohortes);
            $snapshots[$pas] = ['cohortes' => $cohortes, 'total' => $total, 'admissions' => $admissions];

            if ($pas < $horizon) {
                $moteur->planifier(
                    new DateTimeImmutable(($anneeBase + $pas + 1).'-09-01'),
                    'annee',
                    ['pas' => $pas + 1],
                );
            }
        };

        $moteur->planifier(new DateTimeImmutable(($anneeBase + 1).'-09-01'), 'annee', ['pas' => 1]);
        $moteur->traiter($handler);

        $totaux = array_map(fn ($s) => $s['total'], $snapshots);
        $variations = [];
        for ($i = 1; $i < count($totaux); $i++) {
            $variations[] = $totaux[$i - 1] > 0 ? round(($totaux[$i] - $totaux[$i - 1]) / $totaux[$i - 1], 4) : 0;
        }

        return [
            ['total_final' => end($totaux), 'totaux' => array_values($totaux), 'variations_annuelles' => $variations],
            $snapshots,
        ];
    }

    /** @throws \DomainException */
    private function validerCriteres(array $criteres, ?int $criteresFaculte = null): array
    {
        foreach (['delta_admissions', 'bornes', 'horizon_annees'] as $champ) {
            if (! array_key_exists($champ, $criteres)) {
                throw new \DomainException("Critère manquant : {$champ}.");
            }
        }

        $delta = (float) $criteres['delta_admissions'];
        $min = (float) $criteres['bornes']['min'];
        $max = (float) $criteres['bornes']['max'];

        if ($min > $max || $delta < $min || $delta > $max || $min < -0.5 || $max > 2.0) {
            throw new \DomainException('Delta/bornes d\'admissions invalides (bornes ∈ [-0.5, 2.0], delta dans les bornes).');
        }

        $horizon = (int) $criteres['horizon_annees'];
        if ($horizon < 1 || $horizon > self::PLAFOND_HORIZON) {
            throw new \DomainException('Horizon hors bornes [1, '.self::PLAFOND_HORIZON.'] ans.');
        }

        if (isset($criteres['taux'])) {
            $somme = (float) $criteres['taux']['passage'] + (float) $criteres['taux']['redoublement'] + (float) $criteres['taux']['abandon'];
            foreach (['passage', 'redoublement', 'abandon'] as $taux) {
                if ((float) $criteres['taux'][$taux] < 0 || (float) $criteres['taux'][$taux] > 1) {
                    throw new \DomainException("Taux {$taux} hors [0,1].");
                }
            }
            if ($somme > 1.0001) {
                throw new \DomainException('passage + redoublement + abandon > 1 : incohérent.');
            }
        }

        // HG-01 : hiérarchie niveau → groupes (relation EXISTANTE groups.niveau_id).
        if (isset($criteres['niveau_id'])) {
            if (Niveau::find((int) $criteres['niveau_id']) === null) {
                throw new \DomainException('Niveau #'.$criteres['niveau_id'].' inexistant.');
            }
        }

        if (isset($criteres['groupes'])) {
            if (! isset($criteres['niveau_id'])) {
                throw new \DomainException('Sélection de groupes sans niveau sélectionné : incompatible avec la cascade.');
            }
            foreach ((array) $criteres['groupes'] as $groupeId) {
                $groupe = Group::find((int) $groupeId);
                if ($groupe === null) {
                    throw new \DomainException('Groupe #'.$groupeId.' inexistant.');
                }
                if (! $groupe->actif) {
                    throw new \DomainException('Groupe '.$groupe->code.' inactif : refusé.');
                }
                if ($groupe->niveau_id === null || (int) $groupe->niveau_id !== (int) $criteres['niveau_id']) {
                    throw new \DomainException('Groupe '.$groupe->code.' rattaché à un autre niveau (ou sans rattachement) : rejeté, jamais réaffecté.');
                }
                if ($criteresFaculte !== null && ((int) $groupe->faculte_id !== $criteresFaculte)) {
                    throw new \DomainException('Groupe '.$groupe->code.' hors du périmètre de la faculté du scénario.');
                }
            }
        }

        return $criteres;
    }

    /** @throws \DomainException */
    private function validerDescripteurs(array $contraintes, array $objectifs): void
    {
        $plafond = null;
        $plancher = null;

        foreach ($contraintes as $contrainte) {
            $type = $contrainte['type'] ?? '';
            if (! in_array($type, EvaluateurScenarios::CONTRAINTES, true)) {
                throw new \DomainException('Type de contrainte inconnu : '.($contrainte['type'] ?? 'absent').'.');
            }
            if (! isset($contrainte['valeur']) || ! is_numeric($contrainte['valeur']) || (float) $contrainte['valeur'] < 0) {
                throw new \DomainException('Valeur de contrainte invalide (doit être un nombre positif).');
            }
            if ($type === 'plafond_total') {
                $plafond = (float) $contrainte['valeur'];
            }
            if ($type === 'plancher_total') {
                $plancher = (float) $contrainte['valeur'];
            }
        }

        if ($plafond !== null && $plancher !== null && $plancher > $plafond) {
            throw new \DomainException('Contraintes incompatibles : le plancher ('.$plancher.') est supérieur au plafond ('.$plafond.').');
        }

        foreach ($objectifs as $objectif) {
            $type = $objectif['type'] ?? '';
            if (! in_array($type, EvaluateurScenarios::OBJECTIFS, true)) {
                throw new \DomainException('Type d\'objectif inconnu : '.($objectif['type'] ?? 'absent').'.');
            }
            if (($objectif['poids'] ?? 0) <= 0) {
                throw new \DomainException('Poids d\'objectif obligatoire (> 0, SAISI).');
            }
            if ($type === 'cible_total' && (! isset($objectif['cible']) || ! is_numeric($objectif['cible']) || (float) $objectif['cible'] <= 0)) {
                throw new \DomainException('Cible d\'effectif invalide (doit être un nombre positif > 0).');
            }
        }

        if ($objectifs === []) {
            throw new \DomainException('Au moins un objectif est requis (sinon aucun classement possible).');
        }
    }
}
