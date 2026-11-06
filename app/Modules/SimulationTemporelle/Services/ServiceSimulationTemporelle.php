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
     * ST-14 / SIM-14→SIM-19 : 1.9 supporte les surcharges par niveau
     * (taux_par_niveau, niveau focal optionnel).
     * ST-14 / SIM-17→SIM-18 : 2.0 supporte l'identité de cohorte
     * (cohortes_identifiees : entrées externes déclarées, suivies comme
     * une PARTITION des agrégats — aucune formule de flux modifiée).
     */
    public const VERSION_MOTEUR = '2.0';

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

        // ST-14 / SIM-14→SIM-19 — surcharge par niveau : les codes déclarés
        // doivent exister dans le périmètre réellement simulé. Un niveau
        // hors périmètre est refusé (jamais ignoré silencieusement).
        $tauxParNiveau = null;
        if (array_key_exists('taux_par_niveau', $criteres)) {
            $tauxParNiveau = [];
            foreach ($criteres['taux_par_niveau'] as $codeNiveau => $tauxNiveau) {
                $codeNiveau = (string) $codeNiveau;
                if (! array_key_exists($codeNiveau, $etatInitial['cohortes'])) {
                    throw new \DomainException("SIM-14→SIM-19 (par niveau) : niveau {$codeNiveau} absent du périmètre simulé.");
                }
                $tauxParNiveau[$codeNiveau] = [
                    'passage' => (float) $tauxNiveau['passage'],
                    'redoublement' => (float) $tauxNiveau['redoublement'],
                    'abandon' => (float) $tauxNiveau['abandon'],
                ];
            }

            // Le niveau focal, s'il est déclaré, doit exister dans le
            // périmètre : un code inconnu produirait un écart nul
            // indistinguable d'un effet réel.
            if (array_key_exists('niveau_focal', $criteres)) {
                $niveauFocal = (string) $criteres['niveau_focal'];
                if (trim($niveauFocal) === '') {
                    throw new \DomainException('SIM-14→SIM-19 (par niveau) : le niveau focal ne peut pas être vide.');
                }
                if (! array_key_exists($niveauFocal, $etatInitial['cohortes'])) {
                    throw new \DomainException("SIM-14→SIM-19 (par niveau) : niveau focal {$niveauFocal} absent du périmètre simulé.");
                }
            }
        }

        // ST-14 / SIM-17→SIM-18 — identité de cohorte : normalisation,
        // existence des niveaux d'entrée dans le périmètre réel et contrat
        // de taille fondé sur les données du périmètre (aucun seuil
        // institutionnel inventé).
        //   · SIM-17 (une cohorte) : l'effectif doit DIFFÉRER de la
        //     génération de référence du niveau d'entrée — une génération
        //     complète seule décrit SIM-18, pas une cohorte exceptionnelle.
        //   · SIM-18 (au moins deux cohortes, même niveau, pas distincts) :
        //     chaque génération doit être AU MOINS aussi importante que la
        //     génération de référence du niveau.
        $cohortesIdentifiees = null;
        if (array_key_exists('cohortes_identifiees', $criteres)) {
            $cohortesInitiales = $etatInitial['cohortes'];
            $nbCohortes = count($criteres['cohortes_identifiees']);
            $cohortesIdentifiees = [];

            foreach ($criteres['cohortes_identifiees'] as $cohorte) {
                $niveauEntree = (string) $cohorte['niveau_entree'];
                if (! array_key_exists($niveauEntree, $cohortesInitiales)) {
                    throw new \DomainException("SIM-17→SIM-18 (identité de cohorte) : niveau d'entrée {$niveauEntree} absent du périmètre simulé.");
                }

                $effectif = (int) $cohorte['effectif'];
                $reference = (int) $cohortesInitiales[$niveauEntree];

                if ($nbCohortes === 1) {
                    if ($effectif === $reference) {
                        throw new \DomainException("SIM-17 (cohorte exceptionnelle) : l'effectif déclaré ({$effectif}) doit différer de la cohorte de référence du niveau {$niveauEntree} ({$reference}) — une génération complète relève de SIM-18.");
                    }
                } elseif ($effectif < $reference) {
                    throw new \DomainException("SIM-18 (double génération) : l'effectif de {$cohorte['code']} ({$effectif}) doit être au moins égal à la génération de référence du niveau {$niveauEntree} ({$reference}).");
                }

                $cohortesIdentifiees[] = [
                    'code' => (string) $cohorte['code'],
                    'niveau_entree' => $niveauEntree,
                    'pas_entree' => (int) $cohorte['pas_entree'],
                    'effectif' => $effectif,
                ];
            }
        }

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

        // ST-14 / SIM-14→SIM-19 — comparaison par niveau : la trajectoire
        // principale suit le jeu global + les surcharges déclarées ; la
        // trajectoire témoin suit le jeu global SANS surcharge, à
        // admissions identiques. L'écart isole strictement l'effet des
        // taux par niveau. La magnitude restituée est l'écart absolu
        // maximal sur les trois axes, tous niveaux surchargés confondus.
        if ($tauxParNiveau !== null) {
            if ($reussiteTaux !== null) {
                throw new \DomainException('SIM-14→SIM-19 (par niveau) : incompatible avec un autre descripteur de réussite — un seul profil à la fois.');
            }
            $reussiteTaux = $criteres['taux'];
            $reussiteCle = 'variation_niveau';
            $reussiteVariation = 0.0;
            foreach ($tauxParNiveau as $tauxNiveau) {
                foreach (['passage', 'redoublement', 'abandon'] as $axe) {
                    $reussiteVariation = max(
                        $reussiteVariation,
                        abs($tauxNiveau[$axe] - (float) $criteres['taux'][$axe])
                    );
                }
            }
        }

        $variantesEvaluees = [];
        $snapshotsParVariante = [];

        foreach ($deltas as $index => $delta) {
            $tauxScenario = $reussiteTaux ?? ($criteres['taux'] ?? null);
            [$mesures, $snapshots, $sortantsSimules, $cohortesAnalyse] = $this->simulerVariante(
                $etatInitial['cohortes'], $delta, (int) $criteres['horizon_annees'],
                $tauxScenario, $anneeBase, $dureeGel, $chocAdmissions, $recuperation,
                $tauxParNiveau, $cohortesIdentifiees
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
                [$mesuresReferenceReussite, $snapshotsReference, $sortantsReference] = $this->simulerVariante(
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
                if ($tauxParNiveau !== null) {
                    $mesures += $this->analyserNiveaux(
                        $snapshots,
                        $snapshotsReference,
                        $criteres['niveau_focal'] ?? null,
                        $tauxParNiveau
                    );
                }
            }

            // ST-14 / SIM-17→SIM-18 — trajectoire témoin : mêmes admissions,
            // mêmes variantes, SANS les entrées externes identifiées. L'écart
            // restitue strictement l'effet des cohortes déclarées.
            if ($cohortesIdentifiees !== null && $cohortesAnalyse !== null) {
                [$mesuresTemoin, $snapshotsTemoin] = $this->simulerVariante(
                    $etatInitial['cohortes'], $delta, (int) $criteres['horizon_annees'],
                    $criteres['taux'], $anneeBase
                );
                $mesures += $this->analyserCohortes(
                    $cohortesAnalyse,
                    $snapshots,
                    $snapshotsTemoin,
                    $cohortesIdentifiees
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
        $simulateurFn = function ($cohortes, $delta, $horizon, $taux) use ($anneeBase, $dureeGel, $chocAdmissions, $recuperation, $tauxParNiveau, $cohortesIdentifiees) {
            // ST-14 / SIM-14→SIM-19 — les analyses OAT/MC conservent le
            // profil par niveau déclaré : le scénario n'est jamais amputé
            // de ses paramètres structurels par une analyse secondaire.
            // ST-14 / SIM-17→SIM-18 — les cohortes identifiées sont elles
            // aussi structurelles : elles restent présentes dans chaque
            // réplication, sinon l'analyse porterait sur un autre scénario.
            [$m, $s] = $this->simulerVariante($cohortes, $delta, $horizon, $taux, $anneeBase, $dureeGel, $chocAdmissions, $recuperation, $tauxParNiveau, $cohortesIdentifiees);

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
     * @return array{0: array<string, mixed>, 1: array<int, array>, 2: array<int, int>, 3: array<string, mixed>|null}
     *                                                                                                                [mesures, snapshots, sortants par pas, analyse des cohortes identifiées] —
     *                                                                                                                les sortants sont les partants du dernier niveau du périmètre
     *                                                                                                                (restitution SIM-07) ; l'analyse des cohortes est nulle
     *                                                                                                                lorsque le scénario ne déclare aucune identité (SIM-17/18).
     */
    private function simulerVariante(array $cohortesDepart, float $delta, int $horizon, ?array $taux, int $anneeBase, ?int $dureeGel = null, ?array $chocAdmissions = null, ?array $recuperation = null, ?array $tauxParNiveau = null, ?array $cohortesIdentifiees = null): array
    {
        $moteur = new MoteurEvenements;
        $cohortes = $cohortesDepart;
        $baseL1 = (float) (reset($cohortesDepart) ?: 0);
        $ordres = array_keys($cohortesDepart); // niveaux triés par l'adaptateur
        $snapshots = [0 => ['cohortes' => $cohortes, 'total' => array_sum($cohortes)]];
        $sortants = [];

        // ST-14 / SIM-17→SIM-18 — identité de cohorte : partition des
        // agrégats par cohorte déclarée. Les parts identifiées sont une
        // LECTURE du moteur agrégé, jamais une seconde simulation : les
        // formules de flux restent celles du moteur.
        $partsIdentifiees = []; // niveau => [cohorte => effectif]
        $sortantsIdentifies = []; // pas => [cohorte => effectif]
        $autresSortiesIdentifiees = []; // cohorte => cumul
        $suiviCohortes = [];

        $handler = function ($evenement) use (&$moteur, &$cohortes, &$snapshots, &$delta, &$taux, &$horizon, &$baseL1, &$ordres, &$sortants, $anneeBase, $dureeGel, $chocAdmissions, $recuperation, $tauxParNiveau, $cohortesIdentifiees, &$partsIdentifiees, &$sortantsIdentifies, &$autresSortiesIdentifiees, &$suiviCohortes): void {
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
            $nouveauxParts = [];
            for ($i = count($ordres) - 1; $i >= 0; $i--) {
                $code = $ordres[$i];
                $effectif = $cohortes[$code];
                // ST-14 / SIM-14→SIM-19 — surcharge par niveau si déclarée,
                // sinon jeu global. Aucune compensation entre niveaux.
                $tauxNiveau = $tauxParNiveau[$code] ?? $taux;
                $partent = (int) round($effectif * ($tauxNiveau['passage'] ?? 0));
                $redoublants = (int) round($effectif * ($tauxNiveau['redoublement'] ?? 0));

                // ST-11 P1-1 — conservation : aux frontières d'arrondi
                // (petits effectifs, taux ≈ 0.5+0.5), la somme des flux
                // arrondis peut dépasser l'effectif du niveau. Le
                // redoublement est plafonné pour que partent + redoublants
                // ≤ effectif : aucune population n'est créée.
                if ($partent + $redoublants > $effectif) {
                    $redoublants = max(0, $effectif - $partent);
                }

                // ST-14 / SIM-17→SIM-18 — part identifiée des flux : le
                // flux agrégé est réparti au plus fort reste entre les
                // cohortes présentes et l'effectif non identifié. La part
                // allouée à une cohorte ne dépasse jamais son effectif ni
                // le flux agrégé ; aucune formule de flux n'est dupliquée.
                if ($cohortesIdentifiees !== null && ($partsIdentifiees[$code] ?? []) !== []) {
                    $presents = $partsIdentifiees[$code];
                    $partantsAlloues = self::repartirIdentites($presents, $partent, $effectif);

                    $capacitesRedoublement = [];
                    foreach ($presents as $identite => $effectifIdentite) {
                        $capacitesRedoublement[$identite] = max(0, $effectifIdentite - ($partantsAlloues[$identite] ?? 0));
                    }
                    $redoublantsAlloues = self::repartirIdentites(
                        $capacitesRedoublement,
                        $redoublants,
                        max(0, $effectif - $partent)
                    );

                    foreach ($presents as $identite => $effectifIdentite) {
                        $partis = (int) ($partantsAlloues[$identite] ?? 0);
                        $restants = (int) ($redoublantsAlloues[$identite] ?? 0);
                        $autresSortiesIdentifiees[$identite] = ($autresSortiesIdentifiees[$identite] ?? 0)
                            + max(0, $effectifIdentite - $partis - $restants);

                        if ($restants > 0) {
                            $nouveauxParts[$code][$identite] = ($nouveauxParts[$code][$identite] ?? 0) + $restants;
                        }

                        if ($i === count($ordres) - 1) {
                            if ($partis > 0) {
                                $sortantsIdentifies[$pas][$identite] = ($sortantsIdentifies[$pas][$identite] ?? 0) + $partis;
                            }
                        } elseif ($i + 1 < count($ordres) && $partis > 0) {
                            $suivant = $ordres[$i + 1];
                            $nouveauxParts[$suivant][$identite] = ($nouveauxParts[$suivant][$identite] ?? 0) + $partis;
                        }
                    }
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

            // ST-14 / SIM-17→SIM-18 — entrées externes du pas : ajoutées aux
            // agrégats ET à la partition identifiée APRÈS la progression,
            // comme les admissions ordinaires (elles progressent au pas
            // suivant). Chaque cohorte entre une seule fois.
            if ($cohortesIdentifiees !== null) {
                $partsIdentifiees = $nouveauxParts;
                foreach ($cohortesIdentifiees as $cohorte) {
                    if ((int) $cohorte['pas_entree'] !== $pas) {
                        continue;
                    }
                    $niveauEntree = $cohorte['niveau_entree'];
                    $codeCohorte = $cohorte['code'];
                    $cohortes[$niveauEntree] = ($cohortes[$niveauEntree] ?? 0) + (int) $cohorte['effectif'];
                    $partsIdentifiees[$niveauEntree][$codeCohorte] = ($partsIdentifiees[$niveauEntree][$codeCohorte] ?? 0)
                        + (int) $cohorte['effectif'];
                }

                $niveauxSuivi = [];
                foreach ($ordres as $codeNiveau) {
                    $cohortesNiveau = [];
                    $sommeIdentifiee = 0;
                    foreach ($partsIdentifiees[$codeNiveau] ?? [] as $identite => $effectifIdentite) {
                        if ($effectifIdentite <= 0) {
                            continue;
                        }
                        $cohortesNiveau[] = ['cohorte' => $identite, 'effectif' => $effectifIdentite];
                        $sommeIdentifiee += $effectifIdentite;
                    }
                    $niveauxSuivi[] = [
                        'niveau' => $codeNiveau,
                        'total' => (int) ($cohortes[$codeNiveau] ?? 0),
                        'cohortes' => $cohortesNiveau,
                        'effectif_non_identifie' => max(0, (int) ($cohortes[$codeNiveau] ?? 0) - $sommeIdentifiee),
                    ];
                }
                $suiviCohortes[$pas] = ['pas' => $pas, 'niveaux' => $niveauxSuivi];
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

        // ST-14 / SIM-17→SIM-18 — bilan de conservation par cohorte : chaque
        // cohorte est une partition des agrégats, donc
        // effectif_entree = effectif_final + sortants + autres_sorties.
        // Aucune formule de flux n'est recalculée : tout provient de la
        // lecture identifiée du moteur agrégé.
        $cohortesAnalyse = null;
        if ($cohortesIdentifiees !== null) {
            $finalParCohorte = [];
            foreach ($partsIdentifiees as $niveau => $cohortesNiveau) {
                foreach ($cohortesNiveau as $identite => $effectifIdentite) {
                    if ((int) $effectifIdentite <= 0) {
                        continue;
                    }
                    $finalParCohorte[$identite][(string) $niveau] = (int) $effectifIdentite;
                }
            }

            $bilan = [];
            foreach ($cohortesIdentifiees as $cohorte) {
                $codeCohorte = (string) $cohorte['code'];
                $parNiveau = [];
                foreach ($ordres as $codeNiveau) {
                    if (isset($finalParCohorte[$codeCohorte][$codeNiveau])) {
                        $parNiveau[$codeNiveau] = (int) $finalParCohorte[$codeCohorte][$codeNiveau];
                    }
                }

                $effectifFinal = array_sum($parNiveau);
                $sortantsCohorte = 0;
                foreach ($sortantsIdentifies as $lignes) {
                    $sortantsCohorte += (int) ($lignes[$codeCohorte] ?? 0);
                }
                $autresSorties = (int) ($autresSortiesIdentifiees[$codeCohorte] ?? 0);
                $ecartConservation = (int) $cohorte['effectif'] - ($effectifFinal + $sortantsCohorte + $autresSorties);

                $bilan[] = [
                    'cohorte' => $codeCohorte,
                    'niveau_entree' => (string) $cohorte['niveau_entree'],
                    'pas_entree' => (int) $cohorte['pas_entree'],
                    'effectif_entree' => (int) $cohorte['effectif'],
                    'effectif_final' => $effectifFinal,
                    'effectif_final_par_niveau' => $parNiveau,
                    'sortants' => $sortantsCohorte,
                    'autres_sorties' => $autresSorties,
                    'ecart_conservation' => $ecartConservation,
                ];
            }

            $cohortesAnalyse = [
                'suivi' => array_values($suiviCohortes),
                'bilan' => $bilan,
                'sortants_identifies' => $sortantsIdentifies,
            ];
        }

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

        return [$mesures, $snapshots, $sortants, $cohortesAnalyse];
    }

    /**
     * ST-14 / SIM-17→SIM-18 — répartition au plus fort reste d'un flux
     * agrégé entre les cohortes identifiées présentes et l'effectif non
     * identifié. Le flux est celui du moteur agrégé : cette fonction ne
     * calcule AUCUN taux ni aucune population, elle répartit un entier déjà
     * produit. L'effectif non identifié participe au départage mais ne
     * reçoit jamais d'unité au nom d'une cohorte ; une cohorte ne reçoit
     * jamais plus que sa capacité.
     *
     * @param  array<string, int>  $capacites  effectif disponible par cohorte
     * @return array<string, int> allocation par cohorte (mêmes clés)
     */
    private static function repartirIdentites(array $capacites, int $flux, int $effectif): array
    {
        $allocation = [];
        foreach ($capacites as $identite => $capacite) {
            $allocation[$identite] = 0;
        }

        if ($flux <= 0 || $capacites === []) {
            return $allocation;
        }

        $capaciteIdentifiee = 0;
        foreach ($capacites as $capacite) {
            $capaciteIdentifiee += max(0, (int) $capacite);
        }

        $effectif = max($effectif, $capaciteIdentifiee);
        if ($effectif <= 0) {
            return $allocation;
        }
        $flux = min($flux, $effectif);

        $nonIdentifie = max(0, $effectif - $capaciteIdentifiee);
        $restes = [];
        $alloue = 0;
        $ordre = 0;

        foreach ($capacites as $identite => $capacite) {
            $capacite = max(0, (int) $capacite);
            $quota = $flux * ($capacite / $effectif);
            $base = (int) floor($quota);
            $base = min($base, $capacite);
            $allocation[$identite] = $base;
            $alloue += $base;
            $restes[] = [
                'cle' => $identite,
                'reste' => $quota - $base,
                'capacite' => $capacite,
                'ordre' => $ordre++,
            ];
        }

        $quotaNonIdentifie = $flux * ($nonIdentifie / $effectif);
        $alloue += (int) floor($quotaNonIdentifie);
        $restes[] = [
            'cle' => null,
            'reste' => $quotaNonIdentifie - floor($quotaNonIdentifie),
            'capacite' => $nonIdentifie,
            'ordre' => $ordre,
        ];

        // Départage déterministe : plus fort reste, puis ordre déclaré.
        usort($restes, static function (array $a, array $b): int {
            return $b['reste'] <=> $a['reste'] ?: $a['ordre'] <=> $b['ordre'];
        });

        $aDistribuer = $flux - $alloue;
        foreach ($restes as $candidat) {
            if ($aDistribuer <= 0) {
                break;
            }
            if ($candidat['cle'] === null) {
                // L'unité revient à l'effectif non identifié.
                $aDistribuer--;

                continue;
            }
            if ($allocation[$candidat['cle']] >= $candidat['capacite']) {
                continue;
            }
            $allocation[$candidat['cle']]++;
            $aDistribuer--;
        }

        return $allocation;
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
     * ST-14 / SIM-17→SIM-18 — restitution de l'identité de cohorte :
     * suivi par pas, bilan de conservation, entrées externes, sortants,
     * poids, écarts à la trajectoire témoin (mêmes admissions SANS
     * cohortes identifiées) et coexistence de générations. Les écarts sont
     * signés (simulé − témoin) ; l'effectif identifié est une lecture de la
     * partition, jamais une seconde simulation.
     *
     * @param  array{suivi: array<int, array<string, mixed>>, bilan: array<int, array<string, mixed>>, sortants_identifies: array<int, array<string, int>>}  $cohortesAnalyse
     * @param  array<int, array<string, mixed>>  $snapshots
     * @param  array<int, array<string, mixed>>  $snapshotsTemoin
     * @param  array<int, array<string, mixed>>  $cohortesIdentifiees
     * @return array<string, mixed>
     */
    private function analyserCohortes(
        array $cohortesAnalyse,
        array $snapshots,
        array $snapshotsTemoin,
        array $cohortesIdentifiees
    ): array {
        $suiviParPas = [];
        foreach ($cohortesAnalyse['suivi'] as $suivi) {
            $suiviParPas[(int) $suivi['pas']] = $suivi;
        }

        $entreesParPas = [];
        foreach ($cohortesIdentifiees as $cohorte) {
            $entreesParPas[(int) $cohorte['pas_entree']][] = [
                'cohorte' => (string) $cohorte['code'],
                'effectif' => (int) $cohorte['effectif'],
            ];
        }

        $sortantsParPeriode = [];
        $ecartsParPeriode = [];
        $poidsParPeriode = [];
        $coexistenceParPeriode = [];
        $entreesParPeriode = [];
        $dureeCoexistence = 0;
        $picCoexistence = 0;

        foreach (array_slice($snapshots, 1, null, true) as $pas => $snapshot) {
            $pas = (int) $pas;
            $suivi = $suiviParPas[$pas] ?? ['niveaux' => []];

            $identifies = 0;
            $lignesPoids = [];
            $niveauxCoexistence = [];
            $simultaneesMax = 0;

            foreach ($suivi['niveaux'] as $niveau) {
                $cohortesNiveau = $niveau['cohortes'] ?? [];
                $effectifNiveau = 0;
                foreach ($cohortesNiveau as $ligne) {
                    $effectif = (int) $ligne['effectif'];
                    $effectifNiveau += $effectif;
                    $lignesPoids[] = [
                        'cohorte' => (string) $ligne['cohorte'],
                        'niveau' => (string) $niveau['niveau'],
                        'effectif' => $effectif,
                    ];
                }
                $identifies += $effectifNiveau;

                $nbCohortes = count($cohortesNiveau);
                if ($nbCohortes >= 2) {
                    $niveauxCoexistence[] = [
                        'niveau' => (string) $niveau['niveau'],
                        'cohortes' => array_map(static fn (array $ligne): string => (string) $ligne['cohorte'], $cohortesNiveau),
                        'effectif' => $effectifNiveau,
                    ];
                    $simultaneesMax = max($simultaneesMax, $nbCohortes);
                }
            }

            if ($simultaneesMax >= 2) {
                $dureeCoexistence++;
                $picCoexistence = max($picCoexistence, $simultaneesMax);
            }

            $totalSimule = (int) ($snapshot['total'] ?? 0);
            $totalTemoin = (int) ($snapshotsTemoin[$pas]['total'] ?? 0);

            $ecartsParPeriode[] = [
                'pas' => $pas,
                'total_simule' => $totalSimule,
                'total_reference' => $totalTemoin,
                'ecart' => $totalSimule - $totalTemoin,
                'effectif_identifie' => $identifies,
            ];

            foreach ($lignesPoids as $index => $ligne) {
                $lignesPoids[$index]['part'] = $totalSimule > 0
                    ? round($ligne['effectif'] / $totalSimule, 4)
                    : 0.0;
            }
            $poidsParPeriode[] = [
                'pas' => $pas,
                'effectif_identifie' => $identifies,
                'total' => $totalSimule,
                'part_identifiee' => $totalSimule > 0 ? round($identifies / $totalSimule, 4) : 0.0,
                'cohortes' => array_values($lignesPoids),
            ];

            $sortantsLignes = [];
            $sortantsTotal = 0;
            foreach ($cohortesAnalyse['sortants_identifies'][$pas] ?? [] as $codeCohorte => $effectif) {
                if ((int) $effectif <= 0) {
                    continue;
                }
                $sortantsLignes[] = ['cohorte' => (string) $codeCohorte, 'sortants' => (int) $effectif];
                $sortantsTotal += (int) $effectif;
            }
            $sortantsParPeriode[] = [
                'pas' => $pas,
                'cohortes' => $sortantsLignes,
                'total' => $sortantsTotal,
            ];

            $entrees = $entreesParPas[$pas] ?? [];
            $entreesParPeriode[] = [
                'pas' => $pas,
                'entrees' => $entrees,
                'total' => array_sum(array_map(static fn (array $ligne): int => (int) $ligne['effectif'], $entrees)),
            ];

            $coexistenceParPeriode[] = [
                'pas' => $pas,
                'cohortes_simultanees' => $simultaneesMax,
                'niveaux' => $niveauxCoexistence,
            ];
        }

        $dernierSimule = end($snapshots);
        $dernierTemoin = end($snapshotsTemoin);
        $finalSimule = (int) ($dernierSimule['total'] ?? 0);
        $finalTemoin = (int) ($dernierTemoin['total'] ?? 0);

        return [
            'cohortes_identifiees_appliquees' => $cohortesIdentifiees,
            'suivi_cohortes' => $cohortesAnalyse['suivi'],
            'bilan_cohortes' => $cohortesAnalyse['bilan'],
            'entrees_cohortes_par_periode' => $entreesParPeriode,
            'sortants_cohortes_par_periode' => $sortantsParPeriode,
            'ecarts_cohortes_par_periode' => $ecartsParPeriode,
            'ecart_cohortes_final' => $finalSimule - $finalTemoin,
            'poids_cohortes_par_periode' => $poidsParPeriode,
            'coexistence_par_periode' => $coexistenceParPeriode,
            'duree_coexistence' => $dureeCoexistence,
            'pic_coexistence' => $picCoexistence,
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
     * ST-14 / SIM-14→SIM-19 — comparaison PAR NIVEAU de la trajectoire
     * principale (jeu global + surcharges) et de la trajectoire témoin
     * (jeu global seul), à admissions identiques. Les écarts sont signés
     * (simulé − référence) et restitués pour chaque pas et au pas final.
     *
     * @param  array<int, array<string, mixed>>  $snapshots
     * @param  array<int, array<string, mixed>>  $snapshotsReference
     * @param  array<string, array<string, float>>  $tauxParNiveau
     * @return array<string, mixed>
     */
    private function analyserNiveaux(
        array $snapshots,
        array $snapshotsReference,
        ?string $niveauFocal,
        array $tauxParNiveau
    ): array {
        $ecartsParPeriode = [];
        foreach (array_slice($snapshots, 1, null, true) as $pas => $snapshot) {
            $reference = $snapshotsReference[$pas]['cohortes'] ?? [];
            $lignes = [];
            foreach ($snapshot['cohortes'] as $code => $effectif) {
                $effectifReference = (int) ($reference[$code] ?? 0);
                $lignes[] = [
                    'niveau' => (string) $code,
                    'simule' => (int) $effectif,
                    'reference' => $effectifReference,
                    'ecart' => (int) $effectif - $effectifReference,
                ];
            }
            $ecartsParPeriode[] = ['pas' => (int) $pas, 'niveaux' => $lignes];
        }

        $finalSimule = end($snapshots)['cohortes'] ?? [];
        $finalReference = end($snapshotsReference)['cohortes'] ?? [];
        $ecartsFinaux = [];
        foreach ($finalSimule as $code => $effectif) {
            $effectifReference = (int) ($finalReference[$code] ?? 0);
            $ecartsFinaux[] = [
                'niveau' => (string) $code,
                'simule' => (int) $effectif,
                'reference' => $effectifReference,
                'ecart' => (int) $effectif - $effectifReference,
            ];
        }

        $resultat = [
            'taux_par_niveau_appliques' => $tauxParNiveau,
            'niveaux_surcharges' => array_keys($tauxParNiveau),
            'ecarts_par_niveau' => $ecartsParPeriode,
            'cohortes_reference_finales' => $finalReference,
            'ecarts_niveaux_finaux' => $ecartsFinaux,
        ];

        if ($niveauFocal !== null) {
            $ecartFocal = 0;
            foreach ($ecartsFinaux as $ligne) {
                if ($ligne['niveau'] === $niveauFocal) {
                    $ecartFocal = $ligne['ecart'];
                    break;
                }
            }
            $resultat['niveau_focal'] = $niveauFocal;
            $resultat['ecart_niveau_focal_final'] = $ecartFocal;
        }

        return $resultat;
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
            'hausse_abandon', 'baisse_abandon', 'variation_niveau',
            'totaux_reference_reussite', 'ecarts_reussite_par_periode',
            'ecart_reussite_final', 'sortants_par_periode', 'effet_sortants_cumules',
            'taux_par_niveau_appliques', 'niveaux_surcharges', 'ecarts_par_niveau',
            'cohortes_reference_finales', 'ecarts_niveaux_finaux',
            'niveau_focal', 'ecart_niveau_focal_final',
            'cohortes_identifiees_appliquees', 'suivi_cohortes', 'bilan_cohortes',
            'entrees_cohortes_par_periode', 'sortants_cohortes_par_periode',
            'ecarts_cohortes_par_periode', 'ecart_cohortes_final',
            'poids_cohortes_par_periode', 'coexistence_par_periode',
            'duree_coexistence', 'pic_coexistence',
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

        // ST-14 / SIM-14→SIM-19 — taux PAR NIVEAU : surcharge explicite du
        // jeu global pour un ou plusieurs niveaux du périmètre. Aucune
        // valeur par défaut : chaque niveau surchargé doit fournir ses
        // trois taux. Le descripteur est exclusif de tout autre profil.
        if (array_key_exists('taux_par_niveau', $criteres)) {
            $parNiveau = $criteres['taux_par_niveau'];
            if (! is_array($parNiveau) || $parNiveau === []) {
                throw new \DomainException('SIM-14→SIM-19 (par niveau) : taux_par_niveau doit être un objet non vide.');
            }
            if (! isset($criteres['taux'])) {
                throw new \DomainException('SIM-14→SIM-19 (par niveau) : taux de référence requis pour comparer les niveaux.');
            }
            foreach (['taux_ameliore', 'taux_degrade', 'taux_modifie', 'axe_variation', 'sens_variation'] as $cleExclusive) {
                if (array_key_exists($cleExclusive, $criteres)) {
                    throw new \DomainException('SIM-14→SIM-19 (par niveau) : incompatible avec un autre descripteur de réussite — un seul profil à la fois.');
                }
            }
            if (isset($criteres['duree_gel_annees'])) {
                throw new \DomainException('SIM-14→SIM-19 (par niveau) : incompatible avec duree_gel_annees — un gel relève de SIM-03.');
            }
            if ($pasExceptionnel !== null) {
                throw new \DomainException('SIM-14→SIM-19 (par niveau) : incompatible avec un choc ponctuel — un choc relève de SIM-04.');
            }
            if (array_key_exists('taux_cible_croissance', $criteres)) {
                throw new \DomainException('SIM-14→SIM-19 (par niveau) : incompatible avec taux_cible_croissance — une cible relève de SIM-05.');
            }
            if ($presentesRecuperation !== []) {
                throw new \DomainException('SIM-14→SIM-19 (par niveau) : incompatible avec le trio de récupération — un creux relève de SIM-06.');
            }

            foreach ($parNiveau as $codeNiveau => $tauxNiveau) {
                $codeNiveau = (string) $codeNiveau;
                if (trim($codeNiveau) === '') {
                    throw new \DomainException('SIM-14→SIM-19 (par niveau) : le code de niveau est requis.');
                }
                if (! is_array($tauxNiveau)
                    || ! array_key_exists('passage', $tauxNiveau)
                    || ! array_key_exists('redoublement', $tauxNiveau)
                    || ! array_key_exists('abandon', $tauxNiveau)) {
                    throw new \DomainException("SIM-14→SIM-19 (par niveau) : taux_par_niveau.{$codeNiveau} doit fournir passage, redoublement et abandon.");
                }
                $sommeNiveau = 0.0;
                foreach (['passage', 'redoublement', 'abandon'] as $axe) {
                    if (! is_numeric($tauxNiveau[$axe])) {
                        throw new \DomainException("SIM-14→SIM-19 (par niveau) : taux_par_niveau.{$codeNiveau}.{$axe} doit être un nombre.");
                    }
                    $valeur = (float) $tauxNiveau[$axe];
                    if ($valeur < 0.0 || $valeur > 1.0) {
                        throw new \DomainException("SIM-14→SIM-19 (par niveau) : taux_par_niveau.{$codeNiveau}.{$axe} hors [0,1].");
                    }
                    $sommeNiveau += $valeur;
                }
                if ($sommeNiveau > 1.0001) {
                    throw new \DomainException("SIM-14→SIM-19 (par niveau) : {$codeNiveau} : passage + redoublement + abandon > 1 : incohérent.");
                }
            }
        }

        // ST-14 / SIM-17→SIM-18 — identité de cohorte : liste de cohortes
        // EXTERNES déclarées (code, niveau d'entrée, pas d'entrée, effectif).
        // Chaque entrée est ADDITIVE aux admissions ordinaires et suivie à
        // travers les niveaux comme une PARTITION des agrégats. Aucune
        // cohorte, aucun effectif et aucune taille de référence ne sont
        // inventés : tout est saisi, l'existence du niveau est contrôlée à
        // l'exécution sur le périmètre réel.
        if (array_key_exists('cohortes_identifiees', $criteres)) {
            $cohortesIdentifiees = $criteres['cohortes_identifiees'];
            if (! is_array($cohortesIdentifiees) || $cohortesIdentifiees === [] || ! array_is_list($cohortesIdentifiees)) {
                throw new \DomainException('SIM-17→SIM-18 (identité de cohorte) : cohortes_identifiees doit être une liste non vide.');
            }
            if (! isset($criteres['taux'])) {
                throw new \DomainException('SIM-17→SIM-18 (identité de cohorte) : taux de référence requis pour projeter les cohortes.');
            }

            foreach (['taux_ameliore', 'taux_degrade', 'taux_modifie', 'axe_variation', 'sens_variation', 'taux_par_niveau', 'niveau_focal'] as $cleExclusive) {
                if (array_key_exists($cleExclusive, $criteres)) {
                    throw new \DomainException('SIM-17→SIM-18 (identité de cohorte) : incompatible avec un autre descripteur — un seul profil à la fois.');
                }
            }
            if (isset($criteres['duree_gel_annees'])) {
                throw new \DomainException('SIM-17→SIM-18 (identité de cohorte) : incompatible avec duree_gel_annees — un gel relève de SIM-03.');
            }
            if (isset($criteres['pas_exceptionnel'], $criteres['admissions_exceptionnelles'])) {
                throw new \DomainException('SIM-17→SIM-18 (identité de cohorte) : incompatible avec un choc ponctuel — un choc relève de SIM-04.');
            }
            if (array_key_exists('taux_cible_croissance', $criteres)) {
                throw new \DomainException('SIM-17→SIM-18 (identité de cohorte) : incompatible avec taux_cible_croissance — une cible relève de SIM-05.');
            }
            if ($presentesRecuperation !== []) {
                throw new \DomainException('SIM-17→SIM-18 (identité de cohorte) : incompatible avec le trio de récupération — un creux relève de SIM-06.');
            }

            $codesCohortes = [];
            $niveauxEntree = [];
            $pasEntree = [];
            foreach ($cohortesIdentifiees as $index => $cohorte) {
                if (! is_array($cohorte)) {
                    throw new \DomainException("SIM-17→SIM-18 (identité de cohorte) : cohortes_identifiees[{$index}] doit être un objet.");
                }
                foreach (['code', 'niveau_entree', 'pas_entree', 'effectif'] as $cle) {
                    if (! array_key_exists($cle, $cohorte)) {
                        throw new \DomainException("SIM-17→SIM-18 (identité de cohorte) : cohortes_identifiees[{$index}].{$cle} est requis.");
                    }
                }

                $code = $cohorte['code'];
                if (! is_string($code) || trim($code) === '') {
                    throw new \DomainException("SIM-17→SIM-18 (identité de cohorte) : cohortes_identifiees[{$index}].code doit être une chaîne non vide.");
                }
                if (isset($codesCohortes[$code])) {
                    throw new \DomainException("SIM-17→SIM-18 (identité de cohorte) : code de cohorte dupliqué {$code} — l'identité doit être unique.");
                }
                $codesCohortes[$code] = true;

                $niveauEntree = $cohorte['niveau_entree'];
                if (! is_string($niveauEntree) || trim($niveauEntree) === '') {
                    throw new \DomainException("SIM-17→SIM-18 (identité de cohorte) : cohortes_identifiees[{$index}].niveau_entree doit être un code non vide.");
                }
                $niveauxEntree[$niveauEntree] = true;

                $pas = self::entierSaisi($cohorte['pas_entree'], "cohortes_identifiees[{$index}].pas_entree");
                if ($pas < 1 || $pas > $horizon) {
                    throw new \DomainException("SIM-17→SIM-18 (identité de cohorte) : cohortes_identifiees[{$index}].pas_entree doit être compris dans [1, {$horizon}].");
                }
                $pasEntree[$pas] = true;

                $effectif = self::entierSaisi($cohorte['effectif'], "cohortes_identifiees[{$index}].effectif");
                if ($effectif < 1) {
                    throw new \DomainException("SIM-17→SIM-18 (identité de cohorte) : cohortes_identifiees[{$index}].effectif doit être au moins égal à 1.");
                }
            }

            if (count($niveauxEntree) > 1) {
                throw new \DomainException('SIM-17→SIM-18 (identité de cohorte) : toutes les cohortes doivent entrer dans le MÊME niveau — une cohorte isolée relève de SIM-17.');
            }
            if (count($cohortesIdentifiees) > 1 && count($pasEntree) !== count($cohortesIdentifiees)) {
                throw new \DomainException('SIM-17→SIM-18 (double génération) : deux cohortes du même niveau doivent entrer à des pas DISTINCTS — une entrée simultanée ne décrit aucune coexistence de générations.');
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
