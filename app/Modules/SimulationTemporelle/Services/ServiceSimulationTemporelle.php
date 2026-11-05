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
    /**
     * ST-14 / SIM-01 : 1.1 expose la trajectoire d'admissions par période
     * dans les mesures de chaque variante (auparavant uniquement dans les
     * snapshots de la variante 0). Aucune formule modifiée.
     * ST-14 / SIM-03 : 1.2 supporte l'interruption temporaire (extension_temporelle,
     * gel des admissions paramétré par duree_gel_annees).
     * ST-14 / SIM-04 : 1.3 supporte le choc ponctuel d'admissions
     * (extension_temporelle, pas_exceptionnel + admissions_exceptionnelles).
     * ST-14 / SIM-05 : 1.4 supporte la comparaison à une trajectoire de
     * croissance cible (analyse_nouvelle, taux_cible_croissance saisi).
     * ST-14 / SIM-06 : 1.5 supporte la récupération démographique
     * (extension_temporelle, creux progressif puis retour à la référence).
     * ST-14 / SIM-07 : 1.6 supporte la comparaison de deux jeux de taux
     * (analyse_nouvelle, taux_ameliore déclaré, sortants restitués).
     * ST-14 / SIM-08 : 1.7 supporte la dégradation de la réussite
     * (miroir : taux_degrade déclaré, baisse_passage restituée).
     * ST-14 / SIM-09→SIM-12 : 1.8 supporte le descripteur d'axe générique
     * (taux_modifie + axe_variation + sens_variation ; alias préservés).
     */
    public const VERSION_MOTEUR = '1.8';

    /** Bornes du taux de croissance CIBLE (référence : bornes du delta). */
    public const BORNE_TAUX_CIBLE_MIN = 0.0;

    public const PLAFOND_VARIANTES = 20;

    public const PLAFOND_HORIZON = 6;

    /** Bornes du delta annuel d'admissions (référence unique : catalogue ST-14). */
    public const BORNE_DELTA_MIN = -0.5;

    public const BORNE_DELTA_MAX = 2.0;

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
        $dureeGel = isset($criteres['duree_gel_annees']) ? (int) $criteres['duree_gel_annees'] : null;
        // ST-14 / SIM-04 — choc ponctuel : deux paramètres indissociables.
        $chocAdmissions = (isset($criteres['pas_exceptionnel'], $criteres['admissions_exceptionnelles']))
            ? [
                'pas' => (int) $criteres['pas_exceptionnel'],
                'admissions' => (int) $criteres['admissions_exceptionnelles'],
            ]
            : null;
        // ST-14 / SIM-05 — cible de croissance : trajectoire saisie, comparée
        // par pas aux admissions réellement produites. Absente = aucune clé
        // de cible ajoutée (rétrocompatibilité stricte).
        $tauxCibleCroissance = array_key_exists('taux_cible_croissance', $criteres)
            ? (float) $criteres['taux_cible_croissance']
            : null;
        $baseL1 = (float) (reset($etatInitial['cohortes']) ?: 0);

        // ST-14 / SIM-06 — récupération : trio indissociable. Garde
        // d'exécution minimale (bornes de forme) en plus de la validation
        // de définition : une ligne insérée hors service ne doit jamais
        // provoquer de division par zéro ni un creux non interprétable.
        $clesRecuperation = ['profondeur_baisse', 'duree_baisse_annees', 'duree_recuperation_annees'];
        $presentesRecuperation = array_values(array_filter(
            $clesRecuperation,
            static fn (string $cle): bool => array_key_exists($cle, $criteres)
        ));
        if ($presentesRecuperation !== [] && count($presentesRecuperation) !== count($clesRecuperation)) {
            throw new \DomainException('SIM-06 (récupération) : profondeur_baisse, duree_baisse_annees et duree_recuperation_annees sont indissociables.');
        }
        $recuperation = null;
        if ($presentesRecuperation !== []) {
            $dureeBaisse = (int) $criteres['duree_baisse_annees'];
            $dureeRecuperation = (int) $criteres['duree_recuperation_annees'];
            $profondeurBaisse = (float) $criteres['profondeur_baisse'];
            $horizonAnnees = (int) $criteres['horizon_annees'];
            if ($dureeBaisse < 1 || $dureeRecuperation < 1 || $profondeurBaisse <= 0.0 || $profondeurBaisse > 1.0) {
                throw new \DomainException('SIM-06 (récupération) : paramètres du creux invalides (profondeur ∈ ]0 ; 1], durées ≥ 1).');
            }
            if ($dureeBaisse + $dureeRecuperation > $horizonAnnees) {
                throw new \DomainException("SIM-06 (récupération) : la baisse ({$dureeBaisse}) + la récupération ({$dureeRecuperation}) dépassent l'horizon ({$horizonAnnees}) — le retour à la référence doit être observable dans l'horizon.");
            }
            $recuperation = [
                'duree_baisse' => $dureeBaisse,
                'duree_recuperation' => $dureeRecuperation,
                'profondeur' => $profondeurBaisse,
            ];
        }

        // ST-14 / SIM-07 — amélioration de la réussite : deux jeux de taux
        // COMPLETS déclarés (référence + amélioré). Garde de forme : aucune
        // compensation inventée, aucune combinaison avec les autres profils.
        $tauxAmeliore = null;
        if (array_key_exists('taux_ameliore', $criteres)) {
            $ameliore = $criteres['taux_ameliore'];
            if (! is_array($ameliore)
                || ! array_key_exists('passage', $ameliore)
                || ! array_key_exists('redoublement', $ameliore)
                || ! array_key_exists('abandon', $ameliore)) {
                throw new \DomainException('SIM-07 (réussite) : taux_ameliore doit fournir passage, redoublement et abandon.');
            }
            $tauxReference = $criteres['taux'] ?? null;
            if (! is_array($tauxReference)
                || ! array_key_exists('passage', $tauxReference)
                || ! array_key_exists('redoublement', $tauxReference)
                || ! array_key_exists('abandon', $tauxReference)) {
                throw new \DomainException('SIM-07 (réussite) : taux de référence requis pour comparer la réussite.');
            }
            if ($dureeGel !== null || $chocAdmissions !== null || $tauxCibleCroissance !== null || $recuperation !== null
                || array_key_exists('taux_degrade', $criteres) || self::aVariationGenerique($criteres)) {
                throw new \DomainException('SIM-07 (réussite) : incompatible avec gel, choc, cible, creux ou une autre variation — un seul profil à la fois.');
            }
            $tauxAmeliore = [
                'passage' => (float) $ameliore['passage'],
                'redoublement' => (float) $ameliore['redoublement'],
                'abandon' => (float) $ameliore['abandon'],
            ];
        }

        // ST-14 / SIM-08 — dégradation de la réussite : miroir de SIM-07
        // (jeu dégradé complet déclaré, passage strictement inférieur).
        // L'exclusion mutuelle avec taux_ameliore est garantie par la garde
        // SIM-07 ci-dessus, exécutée en premier.
        $tauxDegrade = null;
        if (array_key_exists('taux_degrade', $criteres)) {
            $degrade = $criteres['taux_degrade'];
            if (! is_array($degrade)
                || ! array_key_exists('passage', $degrade)
                || ! array_key_exists('redoublement', $degrade)
                || ! array_key_exists('abandon', $degrade)) {
                throw new \DomainException('SIM-08 (réussite) : taux_degrade doit fournir passage, redoublement et abandon.');
            }
            $tauxReference = $criteres['taux'] ?? null;
            if (! is_array($tauxReference)
                || ! array_key_exists('passage', $tauxReference)
                || ! array_key_exists('redoublement', $tauxReference)
                || ! array_key_exists('abandon', $tauxReference)) {
                throw new \DomainException('SIM-08 (réussite) : taux de référence requis pour comparer la réussite.');
            }
            if ($dureeGel !== null || $chocAdmissions !== null || $tauxCibleCroissance !== null || $recuperation !== null
                || self::aVariationGenerique($criteres)) {
                throw new \DomainException('SIM-08 (réussite) : incompatible avec gel, choc, cible, creux ou une autre variation — un seul profil à la fois.');
            }
            $tauxDegrade = [
                'passage' => (float) $degrade['passage'],
                'redoublement' => (float) $degrade['redoublement'],
                'abandon' => (float) $degrade['abandon'],
            ];
        }

        // ST-14 / SIM-09→SIM-12 — descripteur d'axe générique : taux_modifie
        // + axe_variation + sens_variation indissociables. L'exclusion
        // mutuelle avec les alias est garantie par les gardes précédentes,
        // exécutées en premier (SIM-07 → SIM-08 → générique).
        $reussiteGenerique = null;
        if (self::aVariationGenerique($criteres)) {
            if (! array_key_exists('taux_modifie', $criteres)
                || ! array_key_exists('axe_variation', $criteres)
                || ! array_key_exists('sens_variation', $criteres)) {
                throw new \DomainException('SIM-09→SIM-12 (réussite) : taux_modifie, axe_variation et sens_variation sont indissociables.');
            }
            $modifie = $criteres['taux_modifie'];
            if (! is_array($modifie)
                || ! array_key_exists('passage', $modifie)
                || ! array_key_exists('redoublement', $modifie)
                || ! array_key_exists('abandon', $modifie)) {
                throw new \DomainException('SIM-09→SIM-12 (réussite) : taux_modifie doit fournir passage, redoublement et abandon.');
            }
            $axe = (string) $criteres['axe_variation'];
            $sens = (string) $criteres['sens_variation'];
            if (! in_array($axe, ['passage', 'redoublement', 'abandon'], true)) {
                throw new \DomainException('SIM-09→SIM-12 (réussite) : axe_variation doit être passage, redoublement ou abandon.');
            }
            if (! in_array($sens, ['hausse', 'baisse'], true)) {
                throw new \DomainException('SIM-09→SIM-12 (réussite) : sens_variation doit être hausse ou baisse.');
            }
            $tauxReference = $criteres['taux'] ?? null;
            if (! is_array($tauxReference)
                || ! array_key_exists('passage', $tauxReference)
                || ! array_key_exists('redoublement', $tauxReference)
                || ! array_key_exists('abandon', $tauxReference)) {
                throw new \DomainException('SIM-09→SIM-12 (réussite) : taux de référence requis pour comparer la réussite.');
            }
            if ($dureeGel !== null || $chocAdmissions !== null || $tauxCibleCroissance !== null || $recuperation !== null) {
                throw new \DomainException('SIM-09→SIM-12 (réussite) : incompatible avec gel, choc, cible ou creux — un seul profil à la fois.');
            }
            $reussiteGenerique = [
                'taux' => [
                    'passage' => (float) $modifie['passage'],
                    'redoublement' => (float) $modifie['redoublement'],
                    'abandon' => (float) $modifie['abandon'],
                ],
                'axe' => $axe,
                'sens' => $sens,
            ];
        }

        // Descripteur commun de la comparaison de réussite (trois formes) :
        // jeu simulé, clé de variation (sens_axe) et magnitude POSITIVE.
        $reussiteTaux = null;
        $reussiteCle = null;
        $reussiteVariation = null;
        if ($tauxAmeliore !== null) {
            $reussiteTaux = $tauxAmeliore;
            $reussiteCle = 'hausse_passage';
            $reussiteVariation = $tauxAmeliore['passage'] - (float) $criteres['taux']['passage'];
        } elseif ($tauxDegrade !== null) {
            $reussiteTaux = $tauxDegrade;
            $reussiteCle = 'baisse_passage';
            $reussiteVariation = (float) $criteres['taux']['passage'] - $tauxDegrade['passage'];
        } elseif ($reussiteGenerique !== null) {
            $reussiteTaux = $reussiteGenerique['taux'];
            $reussiteCle = $reussiteGenerique['sens'].'_'.$reussiteGenerique['axe'];
            $reussiteVariation = $reussiteGenerique['sens'] === 'hausse'
                ? $reussiteTaux[$reussiteGenerique['axe']] - (float) $criteres['taux'][$reussiteGenerique['axe']]
                : (float) $criteres['taux'][$reussiteGenerique['axe']] - $reussiteTaux[$reussiteGenerique['axe']];
        }

        $variantesEvaluees = [];
        $snapshotsParVariante = [];

        foreach ($deltas as $index => $delta) {
            $tauxScenario = $reussiteTaux ?? ($criteres['taux'] ?? null);
            [$mesures, $snapshots, $sortantsSimules] = $this->simulerVariante(
                $etatInitial['cohortes'], $delta, (int) $criteres['horizon_annees'],
                $tauxScenario, $anneeBase, $dureeGel, $chocAdmissions, $recuperation
            );
            if ($tauxCibleCroissance !== null) {
                $mesures += $this->analyserCibleCroissance(
                    $mesures['admissions_par_periode'],
                    $baseL1,
                    $tauxCibleCroissance
                );
            }
            if ($reussiteTaux !== null) {
                // Trajectoire témoin : mêmes admissions, mêmes variantes,
                // seuls les taux de flux diffèrent (taux de référence).
                [$mesuresReferenceReussite, , $sortantsReference] = $this->simulerVariante(
                    $etatInitial['cohortes'], $delta, (int) $criteres['horizon_annees'],
                    $criteres['taux'], $anneeBase
                );
                $mesures += $this->analyserReussite(
                    $mesures['totaux'],
                    $mesuresReferenceReussite['totaux'],
                    $sortantsSimules,
                    $sortantsReference,
                    $reussiteCle,
                    $reussiteVariation
                );
            }
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
        // Les analyses portent sur la trajectoire PRINCIPALE : jeu simulé
        // si le scénario en déclare un (aucune substitution).
        $criteresAnalyses = $criteres;
        if ($reussiteTaux !== null) {
            $criteresAnalyses['taux'] = $reussiteTaux;
        }
        $simulateurFn = function ($cohortes, $delta, $horizon, $taux) use ($anneeBase, $dureeGel, $chocAdmissions, $recuperation) {
            [$m, $s] = $this->simulerVariante($cohortes, $delta, $horizon, $taux, $anneeBase, $dureeGel, $chocAdmissions, $recuperation);

            return $m['total_final'];
        };
        $sensibilite = $this->analyseurSensibilite->analyser($etatInitial['cohortes'], $criteresAnalyses, $simulateurFn);

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
            $criteresAnalyses,
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
            'classement' => array_map(fn ($v) => array_merge([
                'index' => $v['index'], 'delta' => $v['delta'],
                'score' => $v['evaluation']['score'], 'contributions' => $v['evaluation']['contributions'],
                'total_final' => $v['mesures']['total_final'],
                'totaux' => $v['mesures']['totaux'],
                'admissions_par_periode' => $v['mesures']['admissions_par_periode'],
            ], $this->champsSpecifiques($v['mesures'])), $classement['classement']),
            'non_conformes' => array_map(fn ($v) => array_merge([
                'index' => $v['index'], 'delta' => $v['delta'], 'violations' => $v['evaluation']['violations'],
                'total_final' => $v['mesures']['total_final'],
                'totaux' => $v['mesures']['totaux'],
                'admissions_par_periode' => $v['mesures']['admissions_par_periode'],
            ], $this->champsSpecifiques($v['mesures'])), $classement['non_conformes']),
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
     * @return array{0: array<string, mixed>, 1: array<int, array>, 2: array<int, int>}
     *                                                                                  [mesures, snapshots, sortants par pas] — les sortants sont les
     *                                                                                  partants du dernier niveau du périmètre (restitution SIM-07).
     */
    private function simulerVariante(array $cohortesDepart, float $delta, int $horizon, ?array $taux, int $anneeBase, ?int $dureeGel = null, ?array $chocAdmissions = null, ?array $recuperation = null): array
    {
        $moteur = new MoteurEvenements;
        $cohortes = $cohortesDepart;
        $baseL1 = (float) (reset($cohortesDepart) ?: 0);
        $ordres = array_keys($cohortesDepart); // niveaux triés par l'adaptateur
        $snapshots = [0 => ['cohortes' => $cohortes, 'total' => array_sum($cohortes)]];
        $sortants = [];

        $handler = function ($evenement) use (&$moteur, &$cohortes, &$snapshots, &$delta, &$taux, &$horizon, &$baseL1, &$ordres, &$sortants, $anneeBase, $dureeGel, $chocAdmissions, $recuperation): void {
            $pas = count($snapshots); // 1-based pour ce pas
            $reference = null;
            $facteur = null;
            $phase = null;

            // ST-14 / SIM-06 — récupération : creux multiplicatif sur la
            // trajectoire de référence, rampes linéaires, retour exact au
            // pas duree_baisse + duree_recuperation. Exclusif par validation.
            if ($recuperation !== null) {
                $reference = (int) round($baseL1 * pow(1 + $delta, $pas));
                [$facteur, $phase] = self::facteurRecuperation(
                    $pas,
                    $recuperation['duree_baisse'],
                    $recuperation['duree_recuperation'],
                    $recuperation['profondeur']
                );
                $admissions = (int) round($reference * $facteur);
            } elseif ($dureeGel !== null && $pas <= $dureeGel) {
                // ST-14 / SIM-03 : gel des admissions.
                $admissions = 0;
            } elseif ($dureeGel !== null) {
                $pasApresGel = $pas - $dureeGel;
                $admissions = (int) round($baseL1 * pow(1 + $delta, $pasApresGel));
            } else {
                $admissions = (int) round($baseL1 * pow(1 + $delta, $pas));
            }

            // ST-14 / SIM-04 — choc PONCTUEL : uniquement au pas déclaré.
            // La trajectoire de fond (delta) n'est ni modifiée ni composée :
            // les périodes suivantes retrouvent les admissions de référence.
            if ($chocAdmissions !== null && $pas === (int) $chocAdmissions['pas']) {
                $admissions += (int) $chocAdmissions['admissions'];
            }

            // Progression par niveaux, du dernier vers le premier — chaque
            // taux SAISI s'applique explicitement (somme ≤ 1 validée) ;
            // le reste éventuel = autres issues, jamais compté en passage.
            $nouveaux = [];
            for ($i = count($ordres) - 1; $i >= 0; $i--) {
                $code = $ordres[$i];
                $effectif = $cohortes[$code];
                $partent = (int) round($effectif * ($taux['passage'] ?? 0));
                $redoublants = (int) round($effectif * ($taux['redoublement'] ?? 0));

                // ST-11 P1-1 — conservation : aux frontières d'arrondi
                // (petits effectifs, taux ≈ 0.5+0.5), la somme des flux
                // arrondis peut dépasser l'effectif du niveau. Le
                // redoublement est plafonné pour que partent + redoublants
                // ≤ effectif : aucune population n'est créée.
                if ($partent + $redoublants > $effectif) {
                    $redoublants = max(0, $effectif - $partent);
                }

                if ($i === count($ordres) - 1) {
                    // ST-14 / SIM-07 — sortants du dernier niveau du périmètre
                    // (auparavant calculés puis jetés).
                    $sortants[$pas] = $partent;
                }

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
            if ($reference !== null) {
                // Trajectoire de référence et facteur stockés TELLS QUELS :
                // les restitutions sont construites sans recalcul parallèle.
                $snapshots[$pas]['admissions_reference'] = $reference;
                $snapshots[$pas]['facteur_recuperation'] = round((float) $facteur, 6);
                $snapshots[$pas]['phase_recuperation'] = $phase;
            }

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

        // ST-14 / SIM-01 — trajectoire d'admissions explicitement datée,
        // reprise TELLE QUELLE des snapshots du moteur (aucun recalcul).
        $admissionsParPeriode = [];
        foreach (array_slice($snapshots, 1, null, true) as $pas => $snapshot) {
            $admissionsParPeriode[] = [
                'pas' => (int) $pas,
                'admissions' => (int) ($snapshot['admissions'] ?? 0),
            ];
        }

        $mesures = [
            'total_final' => end($totaux),
            'totaux' => array_values($totaux),
            'variations_annuelles' => $variations,
            'admissions_par_periode' => $admissionsParPeriode,
        ];
        if ($recuperation !== null) {
            $mesures += $this->analyserRecuperation($snapshots);
        }

        return [$mesures, $snapshots, $sortants];
    }

    /**
     * ST-14 / SIM-06 — facteur de creux et phase pour un pas donné.
     * Rampe linéaire : 1 → (1 − profondeur) sur [1 ; d], puis
     * (1 − profondeur) → 1 sur ]d ; d + r], puis 1. Le retour est exact
     * au pas d + r par construction.
     *
     * @return array{0: float, 1: string} [facteur, phase]
     */
    private static function facteurRecuperation(int $pas, int $dureeBaisse, int $dureeRecuperation, float $profondeur): array
    {
        if ($pas <= $dureeBaisse) {
            return [1.0 - $profondeur * ($pas / $dureeBaisse), 'baisse'];
        }

        if ($pas <= $dureeBaisse + $dureeRecuperation) {
            return [(1.0 - $profondeur) + $profondeur * (($pas - $dureeBaisse) / $dureeRecuperation), 'recuperation'];
        }

        return [1.0, 'reference'];
    }

    /**
     * ST-14 / SIM-06 — restitutions du creux, construites depuis les
     * snapshots (référence et admissions déjà produites), sans recalcul
     * de la trajectoire. Le cumul et le creux sont exprimés en étudiants
     * (écarts entiers arrondis).
     *
     * @param  array<int, array<string, mixed>>  $snapshots
     * @return array<string, mixed>
     */
    private function analyserRecuperation(array $snapshots): array
    {
        $referenceParPeriode = [];
        $ecartsParPeriode = [];
        $ecartCumule = 0;
        $creux = 0;

        foreach (array_slice($snapshots, 1, null, true) as $pas => $snapshot) {
            $admissions = (int) ($snapshot['admissions'] ?? 0);
            $reference = (int) ($snapshot['admissions_reference'] ?? 0);
            $ecart = $admissions - $reference;
            $ecartCumule += $ecart;
            $creux = min($creux, $ecart);

            $referenceParPeriode[] = ['pas' => (int) $pas, 'admissions_reference' => $reference];
            $ecartsParPeriode[] = [
                'pas' => (int) $pas,
                'admissions' => $admissions,
                'admissions_reference' => $reference,
                'ecart' => $ecart,
                'phase' => $snapshot['phase_recuperation'] ?? 'reference',
                'facteur' => (float) ($snapshot['facteur_recuperation'] ?? 1.0),
            ];
        }

        return [
            'admissions_reference_par_periode' => $referenceParPeriode,
            'ecarts_recuperation_par_periode' => $ecartsParPeriode,
            'ecart_recuperation_cumule' => $ecartCumule,
            'creux_recuperation' => $creux,
        ];
    }

    /**
     * ST-14 / SIM-07 / SIM-08 — comparaison de la trajectoire simulée
     * (taux améliorés ou dégradés) à la trajectoire de référence (taux
     * déclarés) : admissions identiques, seule la progression diffère. Les
     * deux jeux de taux sont entièrement déclarés : aucune compensation
     * n'est inventée. La direction de la variation est portée par
     * $cleVariation ('hausse_passage' ou 'baisse_passage') avec une
     * magnitude positive ; les cinq champs de comparaison sont neutres.
     *
     * @param  array<int, int>  $totauxSimules  totaux pas 0 → H (trajectoire principale)
     * @param  array<int, int>  $totauxReference  totaux pas 0 → H (trajectoire témoin)
     * @param  array<int, int>  $sortantsSimules  partants du dernier niveau par pas
     * @param  array<int, int>  $sortantsReference  idem pour la trajectoire témoin
     * @param  string  $cleVariation  'hausse_passage' (SIM-07) ou 'baisse_passage' (SIM-08)
     * @return array<string, mixed>
     */
    private function analyserReussite(
        array $totauxSimules,
        array $totauxReference,
        array $sortantsSimules,
        array $sortantsReference,
        string $cleVariation,
        float $variationPassage
    ): array {
        $ecartsParPeriode = [];
        for ($pas = 1; $pas < count($totauxSimules); $pas++) {
            $totalSimule = (int) ($totauxSimules[$pas] ?? 0);
            $totalReference = (int) ($totauxReference[$pas] ?? 0);
            $ecartsParPeriode[] = [
                'pas' => $pas,
                'total_simule' => $totalSimule,
                'total_reference' => $totalReference,
                'ecart' => $totalSimule - $totalReference,
            ];
        }

        $sortantsParPeriode = [];
        $effetSortants = 0;
        foreach ($sortantsSimules as $pas => $sortants) {
            $sortantsRef = (int) ($sortantsReference[$pas] ?? 0);
            $effetSortants += (int) $sortants - $sortantsRef;
            $sortantsParPeriode[] = [
                'pas' => (int) $pas,
                'sortants' => (int) $sortants,
                'sortants_reference' => $sortantsRef,
            ];
        }

        return [
            $cleVariation => round($variationPassage, 4),
            'totaux_reference_reussite' => array_values($totauxReference),
            'ecarts_reussite_par_periode' => $ecartsParPeriode,
            'ecart_reussite_final' => ((int) end($totauxSimules)) - ((int) end($totauxReference)),
            'sortants_par_periode' => $sortantsParPeriode,
            'effet_sortants_cumules' => $effetSortants,
        ];
    }

    /**
     * ST-14 / SIM-05 — comparaison des admissions produites à une
     * trajectoire de croissance cible SAISIE. La cible est composée avec la
     * même formule que la trajectoire réelle, appliquée à $tauxCible.
     * Aucune écriture, aucun effet sur les cohortes : les écarts portent
     * sur les admissions entières réellement produites (arrondis assumés).
     *
     * @param  array<int, array{pas: int, admissions: int}>  $admissionsParPeriode
     * @return array<string, mixed>
     */
    private function analyserCibleCroissance(array $admissionsParPeriode, float $baseL1, float $tauxCible): array
    {
        $cibleParPeriode = [];
        $ecartsParPeriode = [];
        $ecartCumule = 0;
        $depassement = false;

        foreach ($admissionsParPeriode as $ligne) {
            $pas = (int) $ligne['pas'];
            $admissions = (int) $ligne['admissions'];
            $admissionsCible = (int) round($baseL1 * pow(1 + $tauxCible, $pas));
            $ecart = $admissions - $admissionsCible;
            $ecartCumule += $ecart;
            $depassement = $depassement || $ecart > 0;

            $cibleParPeriode[] = ['pas' => $pas, 'admissions_cible' => $admissionsCible];
            $ecartsParPeriode[] = [
                'pas' => $pas,
                'admissions' => $admissions,
                'admissions_cible' => $admissionsCible,
                'ecart' => $ecart,
                'depassement' => $ecart > 0,
            ];
        }

        return [
            'admissions_cible_par_periode' => $cibleParPeriode,
            'ecarts_cible_par_periode' => $ecartsParPeriode,
            'ecart_cible_cumule' => $ecartCumule,
            'depassement_cible' => $depassement,
        ];
    }

    /**
     * Champs spécifiques à exposer dans le classement : présents
     * UNIQUEMENT si la variante porte l'analyse correspondante — cible de
     * croissance (SIM-05), récupération (SIM-06) ou réussite (SIM-07).
     * Aucune clé null ajoutée.
     *
     * @param  array<string, mixed>  $mesures
     * @return array<string, mixed>
     */
    private function champsSpecifiques(array $mesures): array
    {
        $champs = [];
        foreach ([
            'admissions_cible_par_periode', 'ecarts_cible_par_periode', 'ecart_cible_cumule', 'depassement_cible',
            'admissions_reference_par_periode', 'ecarts_recuperation_par_periode', 'ecart_recuperation_cumule', 'creux_recuperation',
            'hausse_passage', 'baisse_passage', 'hausse_redoublement', 'baisse_redoublement',
            'hausse_abandon', 'baisse_abandon',
            'totaux_reference_reussite', 'ecarts_reussite_par_periode',
            'ecart_reussite_final', 'sortants_par_periode', 'effet_sortants_cumules',
        ] as $cle) {
            if (array_key_exists($cle, $mesures)) {
                $champs[$cle] = $mesures[$cle];
            }
        }

        return $champs;
    }

    /**
     * Une forme générique de variation de réussite est déclarée dès que
     * l'une des trois clés indissociables est présente ; la validation
     * ultérieure exige les trois.
     *
     * @param  array<string, mixed>  $criteres
     */
    private static function aVariationGenerique(array $criteres): bool
    {
        foreach (['taux_modifie', 'axe_variation', 'sens_variation'] as $cle) {
            if (array_key_exists($cle, $criteres)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lecture d'un paramètre numérique SAISI (int, float ou chaîne
     * numérique) — refuse tout le reste, y compris les valeurs non finies.
     *
     * @throws \DomainException
     */
    private static function nombreSaisi(mixed $valeur, string $nom): float
    {
        if (! is_int($valeur) && ! is_float($valeur) && ! (is_string($valeur) && is_numeric($valeur))) {
            throw new \DomainException("Paramètre {$nom} : un nombre est attendu.");
        }

        $nombre = (float) $valeur;
        if (! is_finite($nombre)) {
            throw new \DomainException("Paramètre {$nom} : valeur non finie refusée.");
        }

        return $nombre;
    }

    /**
     * Lecture d'un paramètre entier SAISI (int, float entier ou chaîne
     * entière) — refuse les fractions et les types non numériques.
     *
     * @throws \DomainException
     */
    private static function entierSaisi(mixed $valeur, string $nom): int
    {
        if (is_int($valeur)) {
            return $valeur;
        }
        if (is_float($valeur) && floor($valeur) === $valeur) {
            return (int) $valeur;
        }
        if (is_string($valeur) && preg_match('/^-?\d+$/', $valeur) === 1) {
            return (int) $valeur;
        }

        throw new \DomainException("Paramètre {$nom} : un entier est attendu.");
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

        if ($min > $max || $delta < $min || $delta > $max || $min < self::BORNE_DELTA_MIN || $max > self::BORNE_DELTA_MAX) {
            throw new \DomainException('Delta/bornes d\'admissions invalides (bornes ∈ ['.self::BORNE_DELTA_MIN.', '.self::BORNE_DELTA_MAX.'], delta dans les bornes).');
        }

        $horizon = (int) $criteres['horizon_annees'];
        if ($horizon < 1 || $horizon > self::PLAFOND_HORIZON) {
            throw new \DomainException('Horizon hors bornes [1, '.self::PLAFOND_HORIZON.'] ans.');
        }

        if (isset($criteres['duree_gel_annees'])) {
            $dureeGel = (int) $criteres['duree_gel_annees'];
            if ($dureeGel < 1 || $dureeGel > $horizon) {
                throw new \DomainException("La durée de gel ({$dureeGel} an(s)) doit être comprise entre 1 et l'horizon ({$horizon} an(s)).");
            }
        }

        // ST-14 / SIM-04 — choc ponctuel : les deux paramètres vont ensemble,
        // le pas doit exister dans l'horizon et le choc doit être strictement
        // positif (un choc nul relève de la trajectoire de fond).
        $pasExceptionnel = isset($criteres['pas_exceptionnel']) ? (int) $criteres['pas_exceptionnel'] : null;
        $admissionsExceptionnelles = isset($criteres['admissions_exceptionnelles']) ? (int) $criteres['admissions_exceptionnelles'] : null;

        if (($pasExceptionnel === null) !== ($admissionsExceptionnelles === null)) {
            throw new \DomainException('Choc exceptionnel incomplet : pas_exceptionnel et admissions_exceptionnelles sont indissociables.');
        }

        if ($pasExceptionnel !== null) {
            if ($pasExceptionnel < 1 || $pasExceptionnel > $horizon) {
                throw new \DomainException("Le pas exceptionnel ({$pasExceptionnel}) doit être compris entre 1 et l'horizon ({$horizon} an(s)).");
            }
            if ($admissionsExceptionnelles < 1) {
                throw new \DomainException('Le choc exceptionnel doit être strictement positif (un choc nul relève de la trajectoire de fond).');
            }
        }

        // ST-14 / SIM-05 — cible de croissance : type, bornes, supériorité
        // stricte du delta et incompatibilité avec gel / choc. La cible est
        // un paramètre SAISI : aucune valeur par défaut n'est introduite.
        if (array_key_exists('taux_cible_croissance', $criteres)) {
            $valeurCible = $criteres['taux_cible_croissance'];
            if (! is_int($valeurCible) && ! is_float($valeurCible) && ! (is_string($valeurCible) && is_numeric($valeurCible))) {
                throw new \DomainException('SIM-05 (cible de croissance) : taux_cible_croissance doit être un nombre.');
            }
            $tauxCible = (float) $valeurCible;
            if (! is_finite($tauxCible)) {
                throw new \DomainException('SIM-05 (cible de croissance) : taux_cible_croissance non fini refusé.');
            }
            if ($tauxCible < self::BORNE_TAUX_CIBLE_MIN || $tauxCible > self::BORNE_DELTA_MAX) {
                throw new \DomainException('SIM-05 (cible de croissance) : taux_cible_croissance hors bornes ['.self::BORNE_TAUX_CIBLE_MIN.' ; '.self::BORNE_DELTA_MAX.'].');
            }
            if (isset($criteres['duree_gel_annees'])) {
                throw new \DomainException('SIM-05 (cible de croissance) : incompatible avec duree_gel_annees — un gel relève de SIM-03.');
            }
            if ($pasExceptionnel !== null) {
                throw new \DomainException('SIM-05 (cible de croissance) : incompatible avec un choc ponctuel — un choc relève de SIM-04.');
            }
            if ($delta < 0) {
                throw new \DomainException('SIM-05 (croissance) : delta_admissions négatif refusé — utiliser SIM-02.');
            }
            if ($delta <= $tauxCible) {
                throw new \DomainException("SIM-05 (croissance supérieure à la cible) : delta_admissions ({$delta}) doit être strictement supérieur au taux cible ({$tauxCible}).");
            }
        }

        // ST-14 / SIM-06 — récupération démographique : trio indissociable,
        // bornes de forme, observabilité du retour dans l'horizon et
        // incompatibilités explicites (gel SIM-03, choc SIM-04, cible SIM-05).
        $clesRecuperation = ['profondeur_baisse', 'duree_baisse_annees', 'duree_recuperation_annees'];
        $presentesRecuperation = array_values(array_filter(
            $clesRecuperation,
            static fn (string $cle): bool => array_key_exists($cle, $criteres)
        ));
        if ($presentesRecuperation !== [] && count($presentesRecuperation) !== count($clesRecuperation)) {
            throw new \DomainException('SIM-06 (récupération) : profondeur_baisse, duree_baisse_annees et duree_recuperation_annees sont indissociables.');
        }

        if ($presentesRecuperation !== []) {
            $profondeurBaisse = self::nombreSaisi($criteres['profondeur_baisse'], 'profondeur_baisse');
            if ($profondeurBaisse <= 0.0) {
                throw new \DomainException('SIM-06 (récupération) : profondeur_baisse doit être strictement positive — un creux nul relève de la trajectoire de fond.');
            }
            if ($profondeurBaisse > 1.0) {
                throw new \DomainException('SIM-06 (récupération) : profondeur_baisse doit être au plus égale à 1 (aucune admission négative possible).');
            }

            $dureeBaisse = self::entierSaisi($criteres['duree_baisse_annees'], 'duree_baisse_annees');
            $dureeRecuperation = self::entierSaisi($criteres['duree_recuperation_annees'], 'duree_recuperation_annees');
            if ($dureeBaisse < 1) {
                throw new \DomainException('SIM-06 (récupération) : duree_baisse_annees doit être au moins égale à 1.');
            }
            if ($dureeRecuperation < 1) {
                throw new \DomainException('SIM-06 (récupération) : duree_recuperation_annees doit être au moins égale à 1.');
            }
            if ($dureeBaisse + $dureeRecuperation > $horizon) {
                throw new \DomainException("SIM-06 (récupération) : la baisse ({$dureeBaisse}) + la récupération ({$dureeRecuperation}) dépassent l'horizon ({$horizon}) — le retour à la référence doit être observable dans l'horizon.");
            }

            if (isset($criteres['duree_gel_annees'])) {
                throw new \DomainException('SIM-06 (récupération) : incompatible avec duree_gel_annees — un gel relève de SIM-03.');
            }
            if ($pasExceptionnel !== null) {
                throw new \DomainException('SIM-06 (récupération) : incompatible avec un choc ponctuel — un choc relève de SIM-04.');
            }
            if (array_key_exists('taux_cible_croissance', $criteres)) {
                throw new \DomainException('SIM-06 (récupération) : incompatible avec taux_cible_croissance — une cible relève de SIM-05.');
            }
        }

        // ST-14 / SIM-07 — amélioration de la réussite : deux jeux de taux
        // COMPLETS déclarés. Le moteur ne compense rien : les trois taux
        // améliorés viennent du scénario, seule la hausse de passage est
        // contrainte (strictement positive).
        if (array_key_exists('taux_ameliore', $criteres)) {
            $ameliore = $criteres['taux_ameliore'];
            if (! is_array($ameliore)
                || ! array_key_exists('passage', $ameliore)
                || ! array_key_exists('redoublement', $ameliore)
                || ! array_key_exists('abandon', $ameliore)) {
                throw new \DomainException('SIM-07 (réussite) : taux_ameliore doit fournir passage, redoublement et abandon.');
            }

            $tauxAmelioreValide = [];
            foreach (['passage', 'redoublement', 'abandon'] as $cle) {
                $valeur = self::nombreSaisi($ameliore[$cle], 'taux_ameliore.'.$cle);
                if ($valeur < 0.0 || $valeur > 1.0) {
                    throw new \DomainException("SIM-07 (réussite) : taux_ameliore.{$cle} hors [0,1].");
                }
                $tauxAmelioreValide[$cle] = $valeur;
            }
            if (array_sum($tauxAmelioreValide) > 1.0001) {
                throw new \DomainException('SIM-07 (réussite) : passage + redoublement + abandon (amélioré) > 1 : incohérent.');
            }

            if (! isset($criteres['taux']) || ! is_array($criteres['taux'])) {
                throw new \DomainException('SIM-07 (réussite) : taux de référence requis pour comparer la réussite.');
            }
            if ($tauxAmelioreValide['passage'] <= (float) $criteres['taux']['passage']) {
                throw new \DomainException('SIM-07 (réussite) : taux_ameliore.passage doit être strictement supérieur au passage de référence.');
            }

            if (isset($criteres['duree_gel_annees'])) {
                throw new \DomainException('SIM-07 (réussite) : incompatible avec duree_gel_annees — un gel relève de SIM-03.');
            }
            if ($pasExceptionnel !== null) {
                throw new \DomainException('SIM-07 (réussite) : incompatible avec un choc ponctuel — un choc relève de SIM-04.');
            }
            if (array_key_exists('taux_cible_croissance', $criteres)) {
                throw new \DomainException('SIM-07 (réussite) : incompatible avec taux_cible_croissance — une cible relève de SIM-05.');
            }
            if ($presentesRecuperation !== []) {
                throw new \DomainException('SIM-07 (réussite) : incompatible avec le trio de récupération — un creux relève de SIM-06.');
            }
            if (array_key_exists('taux_degrade', $criteres)) {
                throw new \DomainException('SIM-07 (réussite) : incompatible avec taux_degrade — une dégradation relève de SIM-08.');
            }
            if (self::aVariationGenerique($criteres)) {
                throw new \DomainException('SIM-07 (réussite) : incompatible avec une variation mono-axe — utiliser SIM-09 à SIM-12.');
            }
        }

        // ST-14 / SIM-08 — dégradation de la réussite : miroir du contrat
        // SIM-07. Les deux jeux de taux sont COMPLETS et déclarés ; seule la
        // baisse de passage est contrainte (strictement négative en
        // variation). L'exclusion mutuelle avec taux_ameliore est garantie
        // par le bloc SIM-07 ci-dessus, exécuté en premier.
        if (array_key_exists('taux_degrade', $criteres)) {
            $degrade = $criteres['taux_degrade'];
            if (! is_array($degrade)
                || ! array_key_exists('passage', $degrade)
                || ! array_key_exists('redoublement', $degrade)
                || ! array_key_exists('abandon', $degrade)) {
                throw new \DomainException('SIM-08 (réussite) : taux_degrade doit fournir passage, redoublement et abandon.');
            }

            $tauxDegradeValide = [];
            foreach (['passage', 'redoublement', 'abandon'] as $cle) {
                $valeur = self::nombreSaisi($degrade[$cle], 'taux_degrade.'.$cle);
                if ($valeur < 0.0 || $valeur > 1.0) {
                    throw new \DomainException("SIM-08 (réussite) : taux_degrade.{$cle} hors [0,1].");
                }
                $tauxDegradeValide[$cle] = $valeur;
            }
            if (array_sum($tauxDegradeValide) > 1.0001) {
                throw new \DomainException('SIM-08 (réussite) : passage + redoublement + abandon (dégradé) > 1 : incohérent.');
            }

            if (! isset($criteres['taux']) || ! is_array($criteres['taux'])) {
                throw new \DomainException('SIM-08 (réussite) : taux de référence requis pour comparer la réussite.');
            }
            if ($tauxDegradeValide['passage'] >= (float) $criteres['taux']['passage']) {
                throw new \DomainException('SIM-08 (réussite) : taux_degrade.passage doit être strictement inférieur au passage de référence.');
            }

            if (isset($criteres['duree_gel_annees'])) {
                throw new \DomainException('SIM-08 (réussite) : incompatible avec duree_gel_annees — un gel relève de SIM-03.');
            }
            if ($pasExceptionnel !== null) {
                throw new \DomainException('SIM-08 (réussite) : incompatible avec un choc ponctuel — un choc relève de SIM-04.');
            }
            if (array_key_exists('taux_cible_croissance', $criteres)) {
                throw new \DomainException('SIM-08 (réussite) : incompatible avec taux_cible_croissance — une cible relève de SIM-05.');
            }
            if ($presentesRecuperation !== []) {
                throw new \DomainException('SIM-08 (réussite) : incompatible avec le trio de récupération — un creux relève de SIM-06.');
            }
            if (self::aVariationGenerique($criteres)) {
                throw new \DomainException('SIM-08 (réussite) : incompatible avec une variation mono-axe — utiliser SIM-09 à SIM-12.');
            }
        }

        // ST-14 / SIM-09→SIM-12 — variation de réussite mono-axe : le jeu
        // modifié complet et le descripteur (axe, sens) sont indissociables.
        // Seul l'axe déclaré est contraint ; les autres taux restent libres
        // (origine déclarée, aucune compensation inventée). L'exclusion
        // mutuelle avec taux_ameliore / taux_degrade est garantie par les
        // blocs SIM-07 et SIM-08 ci-dessus, exécutés en premier.
        if (self::aVariationGenerique($criteres)) {
            if (! array_key_exists('taux_modifie', $criteres)
                || ! array_key_exists('axe_variation', $criteres)
                || ! array_key_exists('sens_variation', $criteres)) {
                throw new \DomainException('SIM-09→SIM-12 (réussite) : taux_modifie, axe_variation et sens_variation sont indissociables.');
            }

            $modifie = $criteres['taux_modifie'];
            if (! is_array($modifie)
                || ! array_key_exists('passage', $modifie)
                || ! array_key_exists('redoublement', $modifie)
                || ! array_key_exists('abandon', $modifie)) {
                throw new \DomainException('SIM-09→SIM-12 (réussite) : taux_modifie doit fournir passage, redoublement et abandon.');
            }

            $tauxModifieValide = [];
            foreach (['passage', 'redoublement', 'abandon'] as $cle) {
                $valeur = self::nombreSaisi($modifie[$cle], 'taux_modifie.'.$cle);
                if ($valeur < 0.0 || $valeur > 1.0) {
                    throw new \DomainException("SIM-09→SIM-12 (réussite) : taux_modifie.{$cle} hors [0,1].");
                }
                $tauxModifieValide[$cle] = $valeur;
            }
            if (array_sum($tauxModifieValide) > 1.0001) {
                throw new \DomainException('SIM-09→SIM-12 (réussite) : passage + redoublement + abandon (modifié) > 1 : incohérent.');
            }

            $axe = $criteres['axe_variation'];
            if (! is_string($axe) || ! in_array($axe, ['passage', 'redoublement', 'abandon'], true)) {
                throw new \DomainException('SIM-09→SIM-12 (réussite) : axe_variation doit être passage, redoublement ou abandon.');
            }
            $sens = $criteres['sens_variation'];
            if (! is_string($sens) || ! in_array($sens, ['hausse', 'baisse'], true)) {
                throw new \DomainException('SIM-09→SIM-12 (réussite) : sens_variation doit être hausse ou baisse.');
            }

            if (! isset($criteres['taux']) || ! is_array($criteres['taux'])
                || ! array_key_exists('passage', $criteres['taux'])
                || ! array_key_exists('redoublement', $criteres['taux'])
                || ! array_key_exists('abandon', $criteres['taux'])) {
                throw new \DomainException('SIM-09→SIM-12 (réussite) : taux de référence requis pour comparer la réussite.');
            }

            $referenceAxe = (float) $criteres['taux'][$axe];
            $modifieAxe = $tauxModifieValide[$axe];
            if ($sens === 'hausse' && $modifieAxe <= $referenceAxe) {
                throw new \DomainException("SIM-09→SIM-12 (réussite) : taux_modifie.{$axe} doit être strictement supérieur au taux de référence (sens hausse).");
            }
            if ($sens === 'baisse' && $modifieAxe >= $referenceAxe) {
                throw new \DomainException("SIM-09→SIM-12 (réussite) : taux_modifie.{$axe} doit être strictement inférieur au taux de référence (sens baisse).");
            }

            if (isset($criteres['duree_gel_annees'])) {
                throw new \DomainException('SIM-09→SIM-12 (réussite) : incompatible avec duree_gel_annees — un gel relève de SIM-03.');
            }
            if ($pasExceptionnel !== null) {
                throw new \DomainException('SIM-09→SIM-12 (réussite) : incompatible avec un choc ponctuel — un choc relève de SIM-04.');
            }
            if (array_key_exists('taux_cible_croissance', $criteres)) {
                throw new \DomainException('SIM-09→SIM-12 (réussite) : incompatible avec taux_cible_croissance — une cible relève de SIM-05.');
            }
            if ($presentesRecuperation !== []) {
                throw new \DomainException('SIM-09→SIM-12 (réussite) : incompatible avec le trio de récupération — un creux relève de SIM-06.');
            }
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
