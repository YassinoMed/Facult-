<?php

namespace App\Modules\SimulationTemporelle\Support;

use App\Modules\SimulationTemporelle\Services\ServiceSimulationTemporelle;

/**
 * Catalogue ST-14 — contrat commun des scénarios avancés.
 *
 * SOURCE DE VÉRITÉ MÉTADONNÉES uniquement : ce catalogue ne calcule rien,
 * ne persiste rien et ne remplace pas ServiceSimulationTemporelle. Il
 * décrit les scénarios, leur schéma de paramètres, leurs hypothèses et
 * leur compatibilité avec le moteur existant.
 *
 * RÈGLES :
 * - un scénario n'est `disponible` qu'après validation de son LOOP ;
 * - un scénario `planifie` n'a ni schéma exécutable ni version de formules ;
 * - aucune valeur métier par défaut n'est injectée par le validateur ;
 * - le hash de définition est distinct du hash d'exécution du service.
 */
final class CatalogueScenarios
{
    public const VERSION_CONTRAT = '1.0';

    public const STATUT_PLANIFIE = 'planifie';

    public const STATUT_DISPONIBLE = 'disponible';

    public const STATUT_BLOQUE = 'bloque_business';

    public const COMPATIBILITES = [
        'moteur_existant' => 'Moteur existant, sans extension',
        'configuration' => 'Réalisable par configuration des paramètres existants',
        'extension_temporelle' => 'Nécessite des paramètres variables dans le temps',
        'extension_par_niveau' => 'Nécessite des taux différenciés par niveau',
        'extension_cohorte' => 'Nécessite une identité logique de cohorte',
        'extension_capacite' => 'Nécessite une capacité datée ou hypothétique',
        'extension_repartition' => 'Nécessite une projection de répartition par groupe',
        'extension_budgetaire' => 'Nécessite une enveloppe ou des coûts paramétrables',
        'analyse_nouvelle' => 'Nécessite une nouvelle capacité analytique',
        'decision_institutionnelle' => 'Hypothèse ou seuil à valider institutionnellement',
    ];

    public const CATEGORIES = [
        'fondation' => 'Fondation et contrat commun',
        'admissions_demographie' => 'Admissions et démographie',
        'reussite_flux' => 'Réussite, redoublement et abandon',
        'cohortes_lmd' => 'Cohortes et progression LMD',
        'capacites_infrastructures' => 'Capacités et infrastructures',
        'budget_ressources' => 'Ressources et projections budgétaires',
        'crises_resilience' => 'Crises et résilience',
        'incertitude_analyses' => 'Sensibilité et incertitude',
    ];

    /** Champs présents dans toute définition, disponible ou planifiée. */
    private const CHAMPS_OBLIGATOIRES = [
        'code', 'nom', 'categorie', 'lot', 'objectif', 'version', 'statut',
        'perimetre', 'compatibilite', 'dependances', 'schema', 'hypotheses',
        'version_formules', 'resultats_attendus', 'indicateurs',
    ];

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $cache = null;

    /**
     * Catalogue complet : socle + scénarios clôturés + scénarios planifiés.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function tout(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $definitions = ['SOCLE-EFFECTIFS' => self::socle(), 'SIM-01' => self::sim01(), 'SIM-02' => self::sim02(), 'SIM-03' => self::sim03(), 'SIM-04' => self::sim04(), 'SIM-05' => self::sim05(), 'SIM-06' => self::sim06(), 'SIM-07' => self::sim07(), 'SIM-08' => self::sim08(), 'SIM-09' => self::sim09(), 'SIM-10' => self::sim10(), 'SIM-11' => self::sim11(), 'SIM-12' => self::sim12(), 'SIM-13' => self::sim13()];
        foreach (self::planifies() as $code => $meta) {
            $definitions[$code] = self::normaliserPlanifie($code, $meta);
        }

        self::verifierIntegrite($definitions);

        return self::$cache = $definitions;
    }

    /** @return array<string, mixed> */
    public static function exiger(string $code): array
    {
        $definitions = self::tout();

        if (! isset($definitions[$code])) {
            throw new \DomainException("Scénario inconnu : {$code}.");
        }

        return $definitions[$code];
    }

    /** @return array<string, mixed> */
    public static function exigerDisponible(string $code): array
    {
        $definition = self::exiger($code);

        if ($definition['statut'] !== self::STATUT_DISPONIBLE) {
            throw new \DomainException("Scénario {$code} non disponible (statut : {$definition['statut']}).");
        }

        return $definition;
    }

    public static function estDisponible(string $code): bool
    {
        return (self::tout()[$code]['statut'] ?? null) === self::STATUT_DISPONIBLE;
    }

    /**
     * Valide les paramètres d'un scénario disponible selon son schéma.
     * Aucune valeur par défaut n'est ajoutée ; les clés inconnues sont
     * refusées. L'existence en base (niveau, groupes) reste contrôlée par
     * le service d'exécution, pas par ce catalogue.
     *
     * @param  array<string, mixed>  $parametres
     * @return array<string, mixed>
     *
     * @throws \DomainException
     */
    public static function validerParametres(string $code, array $parametres): array
    {
        $definition = self::exigerDisponible($code);

        if (! is_array($definition['schema']) || $definition['schema'] === []) {
            throw new \DomainException("Scénario {$code} : schéma de paramètres absent.");
        }

        self::validerTableau($parametres, $definition['schema'], 'parametres');

        if ($code === 'SOCLE-EFFECTIFS') {
            self::validerReglesCommunes($parametres);
        }

        if ($code === 'SIM-01') {
            self::validerReglesCommunes($parametres);
            self::validerReglesSim01($parametres);
        }

        if ($code === 'SIM-02') {
            self::validerReglesCommunes($parametres);
            self::validerReglesSim02($parametres);
        }

        if ($code === 'SIM-03') {
            self::validerReglesCommunes($parametres);
            self::validerReglesSim03($parametres);
        }

        if ($code === 'SIM-04') {
            self::validerReglesCommunes($parametres);
            self::validerReglesSim04($parametres);
        }

        if ($code === 'SIM-05') {
            self::validerReglesCommunes($parametres);
            self::validerReglesSim05($parametres);
        }

        if ($code === 'SIM-06') {
            self::validerReglesCommunes($parametres);
            self::validerReglesSim06($parametres);
        }

        if ($code === 'SIM-07') {
            self::validerReglesCommunes($parametres);
            self::validerReglesSim07($parametres);
        }

        if ($code === 'SIM-08') {
            self::validerReglesCommunes($parametres);
            self::validerReglesSim08($parametres);
        }

        if ($code === 'SIM-09') {
            self::validerReglesCommunes($parametres);
            self::validerReglesSim09($parametres);
        }

        if ($code === 'SIM-10') {
            self::validerReglesCommunes($parametres);
            self::validerReglesSim10($parametres);
        }

        if ($code === 'SIM-11') {
            self::validerReglesCommunes($parametres);
            self::validerReglesSim11($parametres);
        }

        if ($code === 'SIM-12') {
            self::validerReglesCommunes($parametres);
            self::validerReglesSim12($parametres);
        }

        if ($code === 'SIM-13') {
            self::validerReglesCommunes($parametres);
            self::validerReglesSim13($parametres);
        }

        return $parametres;
    }

    /**
     * Hash complet d'une définition de scénario (contrat + version +
     * paramètres + périmètre + graine + version moteur). Ce hash est un
     * hash de DÉFINITION : le hash d'exécution reste calculé par le service.
     *
     * @param  array<string, mixed>  $parametres
     */
    public static function empreinteScenario(
        string $code,
        int $version,
        array $parametres,
        ?int $faculteId,
        string $graine,
        string $versionMoteur = ServiceSimulationTemporelle::VERSION_MOTEUR,
    ): string {
        $definition = self::exigerDisponible($code);
        self::validerVersion($version);
        self::validerParametres($code, $parametres);

        if (trim($graine) === '') {
            throw new \DomainException('Graine obligatoire pour le hash de définition.');
        }

        return hash('sha256', json_encode([
            'contrat' => self::VERSION_CONTRAT,
            'code' => $definition['code'],
            'version' => $version,
            'version_formules' => $definition['version_formules'],
            'parametres' => self::canoniser($parametres),
            'faculte_id' => $faculteId,
            'graine' => $graine,
            'version_moteur' => $versionMoteur,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** Empreinte déterministe du catalogue complet (ordre canonique). */
    public static function empreinteCatalogue(): string
    {
        return hash('sha256', json_encode([
            'contrat' => self::VERSION_CONTRAT,
            'scenarios' => self::canoniser(self::tout()),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * Contrôle d'intégrité du catalogue : codes, catégories, versions,
     * statuts, schémas et dépendances.
     *
     * @param  array<string, array<string, mixed>>|null  $definitions
     * @return array{scenarios: int, disponibles: int, planifies: int, codes: array<int, string>}
     */
    public static function verifierIntegrite(?array $definitions = null): array
    {
        $definitions ??= self::tout();
        $codes = [];
        $disponibles = 0;
        $planifies = 0;

        foreach ($definitions as $code => $definition) {
            foreach (self::CHAMPS_OBLIGATOIRES as $champ) {
                if (! array_key_exists($champ, $definition)) {
                    throw new \DomainException("Catalogue : champ {$champ} absent pour {$code}.");
                }
            }

            if ($definition['code'] !== $code) {
                throw new \DomainException("Catalogue : code incohérent pour {$code}.");
            }

            if (isset($codes[$code])) {
                throw new \DomainException("Catalogue : code dupliqué {$code}.");
            }
            $codes[$code] = true;

            if (! preg_match('/^(SOCLE-EFFECTIFS|(?:SIM|CR|UNC)-\d{2})$/', $code)) {
                throw new \DomainException("Catalogue : format de code invalide {$code}.");
            }

            if (trim((string) $definition['nom']) === '') {
                throw new \DomainException("Catalogue : nom absent pour {$code}.");
            }

            if (! isset(self::CATEGORIES[$definition['categorie']])) {
                throw new \DomainException("Catalogue : catégorie inconnue pour {$code}.");
            }

            if (! in_array($definition['statut'], [self::STATUT_PLANIFIE, self::STATUT_DISPONIBLE, self::STATUT_BLOQUE], true)) {
                throw new \DomainException("Catalogue : statut invalide pour {$code}.");
            }

            if (! isset(self::COMPATIBILITES[$definition['compatibilite']])) {
                throw new \DomainException("Catalogue : compatibilité inconnue pour {$code}.");
            }

            if (! in_array($definition['perimetre'], ['faculte_ou_global', 'global', 'faculte'], true)) {
                throw new \DomainException("Catalogue : périmètre invalide pour {$code}.");
            }

            if ($definition['statut'] === self::STATUT_DISPONIBLE) {
                self::validerVersion($definition['version']);
                if (! is_array($definition['schema']) || $definition['schema'] === []) {
                    throw new \DomainException("Catalogue : schéma absent pour le scénario disponible {$code}.");
                }
                if (! is_string($definition['version_formules']) || preg_match('/^\d+\.\d+$/', $definition['version_formules']) !== 1) {
                    throw new \DomainException("Catalogue : version de formules invalide pour {$code}.");
                }
                $disponibles++;
            }

            if ($definition['statut'] === self::STATUT_PLANIFIE) {
                if ($definition['version'] !== null || $definition['version_formules'] !== null || $definition['schema'] !== null) {
                    throw new \DomainException("Catalogue : un scénario planifié ne peut pas exposer de version ni de schéma ({$code}).");
                }
                $planifies++;
            }
        }

        foreach ($definitions as $code => $definition) {
            foreach ((array) $definition['dependances'] as $dependance) {
                if (! isset($definitions[$dependance])) {
                    throw new \DomainException("Catalogue : dépendance inconnue {$dependance} pour {$code}.");
                }
            }
        }

        return [
            'scenarios' => count($definitions),
            'disponibles' => $disponibles,
            'planifies' => $planifies,
            'codes' => array_keys($definitions),
        ];
    }

    public static function validerVersion(int $version): int
    {
        if ($version < 1) {
            throw new \DomainException('Version de scénario invalide : entier ≥ 1 attendu.');
        }

        return $version;
    }

    /** @return array<string, mixed> */
    public static function schemaSocle(): array
    {
        return [
            'delta_admissions' => [
                'type' => 'number', 'requis' => true,
                'min' => ServiceSimulationTemporelle::BORNE_DELTA_MIN,
                'max' => ServiceSimulationTemporelle::BORNE_DELTA_MAX,
                'unite' => 'ratio annuel composé',
                'description' => 'Taux de croissance annuel appliqué aux admissions de référence du premier niveau.',
            ],
            'bornes' => [
                'type' => 'array', 'requis' => true,
                'schema' => [
                    'min' => ['type' => 'number', 'requis' => true, 'min' => ServiceSimulationTemporelle::BORNE_DELTA_MIN, 'max' => ServiceSimulationTemporelle::BORNE_DELTA_MAX],
                    'max' => ['type' => 'number', 'requis' => true, 'min' => ServiceSimulationTemporelle::BORNE_DELTA_MIN, 'max' => ServiceSimulationTemporelle::BORNE_DELTA_MAX],
                ],
            ],
            'horizon_annees' => [
                'type' => 'integer', 'requis' => true,
                'min' => 1, 'max' => ServiceSimulationTemporelle::PLAFOND_HORIZON,
                'unite' => 'années',
            ],
            'taux' => [
                'type' => 'array', 'requis' => false,
                'schema' => [
                    'passage' => ['type' => 'number', 'requis' => true, 'min' => 0, 'max' => 1],
                    'redoublement' => ['type' => 'number', 'requis' => true, 'min' => 0, 'max' => 1],
                    'abandon' => ['type' => 'number', 'requis' => true, 'min' => 0, 'max' => 1],
                ],
                'description' => 'Taux globaux, identiques pour tous les niveaux ; absents = progression gelée avec avertissement.',
            ],
            'niveau_id' => ['type' => 'integer', 'requis' => false, 'min' => 1],
            'groupes' => [
                'type' => 'array', 'requis' => false,
                'elements' => ['type' => 'integer', 'min' => 1],
            ],
        ];
    }

    /**
     * SIM-01 — même contrat que le socle, avec les taux REQUIS.
     *
     * Justification : le scénario revendique des effectifs projetés et des
     * capacités ; sans taux explicites le moteur ne projette aucune
     * progression et l'indicateur serait trompeur. Aucune valeur par
     * défaut n'est introduite : l'appelant doit fournir les trois taux.
     *
     * @return array<string, mixed>
     */
    public static function schemaSim01(): array
    {
        return self::schemaAvecTauxRequis('SIM-01');
    }

    /**
     * SIM-02 — même contrat que SIM-01 : les taux sont REQUIS, aucune
     * valeur par défaut. Seule la règle de domaine change (baisse).
     *
     * @return array<string, mixed>
     */
    public static function schemaSim02(): array
    {
        return self::schemaAvecTauxRequis('SIM-02');
    }

    /**
     * SIM-03 — interruption temporaire des admissions (gel).
     * Exige duree_gel_annees ∈ [1, horizon_annees] et les taux de flux.
     *
     * @return array<string, mixed>
     */
    public static function schemaSim03(): array
    {
        $schema = self::schemaAvecTauxRequis('SIM-03');
        $schema['duree_gel_annees'] = [
            'type' => 'integer',
            'requis' => true,
            'min' => 1,
            'max' => ServiceSimulationTemporelle::PLAFOND_HORIZON,
            'unite' => 'années',
            'description' => "Nombre d'années consécutives d'interruption totale des nouvelles admissions (admissions = 0) à compter de la première période simulée.",
        ];

        return $schema;
    }

    /**
     * SIM-04 — choc ponctuel d'admissions (admissions exceptionnelles).
     * Exige un pas exceptionnel ∈ [1, horizon], un nombre d'admissions
     * exceptionnelles ≥ 1 et les taux de flux.
     *
     * @return array<string, mixed>
     */
    public static function schemaSim04(): array
    {
        $schema = self::schemaAvecTauxRequis('SIM-04');
        $schema['admissions_exceptionnelles'] = [
            'type' => 'integer',
            'requis' => true,
            'min' => 1,
            'unite' => 'étudiants',
            'description' => "Nombre d'admissions SUPPLÉMENTAIRES, ajoutées une seule fois au pas exceptionnel ; la trajectoire de fond (delta) reste inchangée les autres années.",
        ];
        $schema['pas_exceptionnel'] = [
            'type' => 'integer',
            'requis' => true,
            'min' => 1,
            'max' => ServiceSimulationTemporelle::PLAFOND_HORIZON,
            'unite' => 'années',
            'description' => 'Pas de simulation (1 = première année projetée) au cours duquel le choc ponctuel est appliqué.',
        ];

        return $schema;
    }

    /**
     * SIM-05 — croissance supérieure à une cible saisie.
     * Exige un taux de croissance cible ∈ [0 ; 2.0] (aucune valeur par
     * défaut) et les taux de flux, comme les autres scénarios d'admissions.
     * Le gel et le choc ne figurent pas au schéma : leur présence est
     * refusée structurellement, puis par règle de domaine explicite.
     *
     * @return array<string, mixed>
     */
    public static function schemaSim05(): array
    {
        $schema = self::schemaAvecTauxRequis('SIM-05');
        $schema['taux_cible_croissance'] = [
            'type' => 'number',
            'requis' => true,
            'min' => ServiceSimulationTemporelle::BORNE_TAUX_CIBLE_MIN,
            'max' => ServiceSimulationTemporelle::BORNE_DELTA_MAX,
            'unite' => 'ratio annuel composé',
            'description' => 'Taux de croissance annuel CIBLE, saisi — aucune valeur par défaut. La trajectoire cible est round(baseL1 × (1 + taux_cible)^pas).',
        ];

        return $schema;
    }

    /**
     * SIM-06 — récupération démographique : creux progressif puis retour
     * progressif au niveau de référence. Exige un trio indissociable
     * (profondeur ∈ ]0 ; 1], durées ≥ 1) et les taux de flux. Le gel, le
     * choc et la cible ne figurent pas au schéma : refusés structurellement
     * puis par règle de domaine explicite.
     *
     * @return array<string, mixed>
     */
    public static function schemaSim06(): array
    {
        $schema = self::schemaAvecTauxRequis('SIM-06');
        $schema['profondeur_baisse'] = [
            'type' => 'number',
            'requis' => true,
            'min' => 0,
            'max' => 1,
            'unite' => 'ratio relatif à la trajectoire de référence',
            'description' => 'Profondeur maximale du creux, relative à la trajectoire de référence (strictement positive, 1 = creux total). Aucune valeur par défaut.',
        ];
        $schema['duree_baisse_annees'] = [
            'type' => 'integer',
            'requis' => true,
            'min' => 1,
            'max' => ServiceSimulationTemporelle::PLAFOND_HORIZON,
            'unite' => 'années',
            'description' => 'Nombre de périodes de baisse progressive jusqu\'au creux.',
        ];
        $schema['duree_recuperation_annees'] = [
            'type' => 'integer',
            'requis' => true,
            'min' => 1,
            'max' => ServiceSimulationTemporelle::PLAFOND_HORIZON,
            'unite' => 'années',
            'description' => 'Nombre de périodes de retour progressif au niveau de référence ; baisse + récupération ≤ horizon.',
        ];

        return $schema;
    }

    /**
     * SIM-07 — amélioration de la réussite : deux jeux de taux COMPLETS
     * (référence + amélioré). Le moteur ne compense rien : chaque taux du
     * jeu amélioré est déclaré. Le gel, le choc, la cible et le creux ne
     * figurent pas au schéma (refus structurel puis explicite).
     *
     * @return array<string, mixed>
     */
    public static function schemaSim07(): array
    {
        $schema = self::schemaAvecTauxRequis('SIM-07');
        $schema['taux_ameliore'] = [
            'type' => 'array',
            'requis' => true,
            'schema' => [
                'passage' => ['type' => 'number', 'requis' => true, 'min' => 0, 'max' => 1],
                'redoublement' => ['type' => 'number', 'requis' => true, 'min' => 0, 'max' => 1],
                'abandon' => ['type' => 'number', 'requis' => true, 'min' => 0, 'max' => 1],
            ],
            'description' => 'Taux SIMULÉS complets (passage, redoublement, abandon), saisis — aucune valeur par défaut. passage strictement supérieur au passage de référence ; aucune compensation implicite.',
        ];

        return $schema;
    }

    /**
     * SIM-08 — dégradation de la réussite : miroir de SIM-07. Deux jeux de
     * taux COMPLETS (référence + dégradé) ; passage dégradé strictement
     * inférieur. Le gel, le choc, la cible et le creux ne figurent pas au
     * schéma (refus structurel puis explicite). `taux_ameliore` est exclu
     * simultanément (exclusion vérifiée par le bloc SIM-07 en premier).
     *
     * @return array<string, mixed>
     */
    public static function schemaSim08(): array
    {
        $schema = self::schemaAvecTauxRequis('SIM-08');
        $schema['taux_degrade'] = [
            'type' => 'array',
            'requis' => true,
            'schema' => [
                'passage' => ['type' => 'number', 'requis' => true, 'min' => 0, 'max' => 1],
                'redoublement' => ['type' => 'number', 'requis' => true, 'min' => 0, 'max' => 1],
                'abandon' => ['type' => 'number', 'requis' => true, 'min' => 0, 'max' => 1],
            ],
            'description' => 'Taux SIMULÉS complets (passage, redoublement, abandon), saisis — aucune valeur par défaut. passage strictement inférieur au passage de référence ; aucune compensation implicite.',
        ];

        return $schema;
    }

    /**
     * SIM-09 — hausse du redoublement : variation mono-axe générique
     * (`taux_modifie` + `axe_variation` + `sens_variation`). Le schéma
     * épingle l'axe `redoublement` et le sens `hausse` ; les autres taux
     * restent libres (origine déclarée, aucune compensation inventée).
     *
     * @return array<string, mixed>
     */
    public static function schemaSim09(): array
    {
        $schema = self::schemaAvecTauxRequis('SIM-09');
        $schema['taux_modifie'] = [
            'type' => 'array',
            'requis' => true,
            'schema' => [
                'passage' => ['type' => 'number', 'requis' => true, 'min' => 0, 'max' => 1],
                'redoublement' => ['type' => 'number', 'requis' => true, 'min' => 0, 'max' => 1],
                'abandon' => ['type' => 'number', 'requis' => true, 'min' => 0, 'max' => 1],
            ],
            'description' => 'Taux SIMULÉS complets (passage, redoublement, abandon), saisis — aucune valeur par défaut. redoublement strictement supérieur à la référence ; aucune compensation implicite.',
        ];
        $schema['axe_variation'] = [
            'type' => 'string',
            'requis' => true,
            'valeurs' => ['redoublement'],
            'description' => 'Axe focal du scénario : redoublement (contrat SIM-09).',
        ];
        $schema['sens_variation'] = [
            'type' => 'string',
            'requis' => true,
            'valeurs' => ['hausse'],
            'description' => 'Sens focal du scénario : hausse (contrat SIM-09).',
        ];

        return $schema;
    }

    /**
     * SIM-10 — réduction du redoublement : variation mono-axe générique
     * (`taux_modifie` + `axe_variation` + `sens_variation`). Le schéma
     * épingle l'axe `redoublement` et le sens `baisse` ; les autres taux
     * restent libres (origine déclarée, aucune compensation inventée).
     *
     * @return array<string, mixed>
     */
    public static function schemaSim10(): array
    {
        $schema = self::schemaAvecTauxRequis('SIM-10');
        $schema['taux_modifie'] = [
            'type' => 'array',
            'requis' => true,
            'schema' => [
                'passage' => ['type' => 'number', 'requis' => true, 'min' => 0, 'max' => 1],
                'redoublement' => ['type' => 'number', 'requis' => true, 'min' => 0, 'max' => 1],
                'abandon' => ['type' => 'number', 'requis' => true, 'min' => 0, 'max' => 1],
            ],
            'description' => 'Taux SIMULÉS complets (passage, redoublement, abandon), saisis — aucune valeur par défaut. redoublement strictement inférieur à la référence ; aucune compensation implicite.',
        ];
        $schema['axe_variation'] = [
            'type' => 'string',
            'requis' => true,
            'valeurs' => ['redoublement'],
            'description' => 'Axe focal du scénario : redoublement (contrat SIM-10).',
        ];
        $schema['sens_variation'] = [
            'type' => 'string',
            'requis' => true,
            'valeurs' => ['baisse'],
            'description' => 'Sens focal du scénario : baisse (contrat SIM-10).',
        ];

        return $schema;
    }

    /**
     * SIM-11 — hausse de l'abandon : variation mono-axe générique
     * (`taux_modifie` + `axe_variation` + `sens_variation`). Le schéma
     * épingle l'axe `abandon` et le sens `hausse` ; les autres taux
     * restent libres (origine déclarée, aucune compensation inventée).
     *
     * @return array<string, mixed>
     */
    public static function schemaSim11(): array
    {
        $schema = self::schemaAvecTauxRequis('SIM-11');
        $schema['taux_modifie'] = [
            'type' => 'array',
            'requis' => true,
            'schema' => [
                'passage' => ['type' => 'number', 'requis' => true, 'min' => 0, 'max' => 1],
                'redoublement' => ['type' => 'number', 'requis' => true, 'min' => 0, 'max' => 1],
                'abandon' => ['type' => 'number', 'requis' => true, 'min' => 0, 'max' => 1],
            ],
            'description' => 'Taux SIMULÉS complets (passage, redoublement, abandon), saisis — aucune valeur par défaut. abandon strictement supérieur à la référence ; aucune compensation implicite.',
        ];
        $schema['axe_variation'] = [
            'type' => 'string',
            'requis' => true,
            'valeurs' => ['abandon'],
            'description' => 'Axe focal du scénario : abandon (contrat SIM-11).',
        ];
        $schema['sens_variation'] = [
            'type' => 'string',
            'requis' => true,
            'valeurs' => ['hausse'],
            'description' => 'Sens focal du scénario : hausse (contrat SIM-11).',
        ];

        return $schema;
    }

    /**
     * SIM-12 — réduction de l'abandon : variation mono-axe générique
     * (`taux_modifie` + `axe_variation` + `sens_variation`). Le schéma
     * épingle l'axe `abandon` et le sens `baisse` ; les autres taux
     * restent libres (origine déclarée, aucune compensation inventée).
     *
     * @return array<string, mixed>
     */
    public static function schemaSim12(): array
    {
        $schema = self::schemaAvecTauxRequis('SIM-12');
        $schema['taux_modifie'] = [
            'type' => 'array',
            'requis' => true,
            'schema' => [
                'passage' => ['type' => 'number', 'requis' => true, 'min' => 0, 'max' => 1],
                'redoublement' => ['type' => 'number', 'requis' => true, 'min' => 0, 'max' => 1],
                'abandon' => ['type' => 'number', 'requis' => true, 'min' => 0, 'max' => 1],
            ],
            'description' => 'Taux SIMULÉS complets (passage, redoublement, abandon), saisis — aucune valeur par défaut. abandon strictement inférieur à la référence ; aucune compensation implicite.',
        ];
        $schema['axe_variation'] = [
            'type' => 'string',
            'requis' => true,
            'valeurs' => ['abandon'],
            'description' => 'Axe focal du scénario : abandon (contrat SIM-12).',
        ];
        $schema['sens_variation'] = [
            'type' => 'string',
            'requis' => true,
            'valeurs' => ['baisse'],
            'description' => 'Sens focal du scénario : baisse (contrat SIM-12).',
        ];

        return $schema;
    }

    /**
     * SIM-13 — crise académique combinée : variation complète multi-dégra-
     * dations sur le descripteur générique (`taux_modifie` + `axe_variation`
     * + `sens_variation`). Le schéma n'épingle PAS d'axe : toute paire
     * focale DÉGRADANTE est admise (passage/baisse, redoublement/hausse,
     * abandon/hausse) ; les règles de domaine ajoutent « aucune
     * amélioration » et « au moins deux dégradations strictes ».
     *
     * @return array<string, mixed>
     */
    public static function schemaSim13(): array
    {
        $schema = self::schemaAvecTauxRequis('SIM-13');
        $schema['taux_modifie'] = [
            'type' => 'array',
            'requis' => true,
            'schema' => [
                'passage' => ['type' => 'number', 'requis' => true, 'min' => 0, 'max' => 1],
                'redoublement' => ['type' => 'number', 'requis' => true, 'min' => 0, 'max' => 1],
                'abandon' => ['type' => 'number', 'requis' => true, 'min' => 0, 'max' => 1],
            ],
            'description' => 'Taux SIMULÉS complets (passage, redoublement, abandon), saisis — aucune valeur par défaut. Au moins deux axes strictement dégradés, aucune amélioration ; l\'axe focal déclaré porte l\'une des dégradations.',
        ];
        $schema['axe_variation'] = [
            'type' => 'string',
            'requis' => true,
            'valeurs' => ['passage', 'redoublement', 'abandon'],
            'description' => 'Axe focal déclaré — la paire (axe, sens) doit être une dégradation : passage/baisse, redoublement/hausse ou abandon/hausse.',
        ];
        $schema['sens_variation'] = [
            'type' => 'string',
            'requis' => true,
            'valeurs' => ['hausse', 'baisse'],
            'description' => 'Sens focal déclaré, apparié à l\'axe : passage→baisse ; redoublement/abandon→hausse. Toute autre paire est refusée par les règles de domaine.',
        ];

        return $schema;
    }

    /** @return array<string, mixed> */
    private static function schemaAvecTauxRequis(string $code): array
    {
        $schema = self::schemaSocle();
        $schema['taux']['requis'] = true;
        $schema['taux']['description'] = 'Taux globaux REQUIS pour '.$code.', identiques pour tous les niveaux ; aucune valeur par défaut.';

        return $schema;
    }

    /** @return array<string, mixed> */
    private static function socle(): array
    {
        return [
            'code' => 'SOCLE-EFFECTIFS',
            'nom' => 'Socle effectifs et cohortes',
            'categorie' => 'fondation',
            'lot' => 'ST-14.00',
            'objectif' => 'Décrire les capacités réellement disponibles du moteur commun sans revendiquer les extensions futures.',
            'version' => 1,
            'statut' => self::STATUT_DISPONIBLE,
            'perimetre' => 'faculte_ou_global',
            'compatibilite' => 'moteur_existant',
            'dependances' => [],
            'schema' => self::schemaSocle(),
            'hypotheses' => [
                "Delta d'admissions annuel composé à partir des admissions de référence du premier niveau.",
                'Taux de passage, redoublement et abandon globaux, identiques pour tous les niveaux.',
                'Aucun taux saisi = progression gelée avec avertissement explicite (jamais zéro silencieux).',
                'Premier niveau de la cohorte = base des admissions (hypothèse du moteur actuel).',
            ],
            'version_formules' => '1.0',
            'resultats_attendus' => [
                'Trajectoire des effectifs par niveau et total annuel.',
                'Classement des variantes, contraintes et objectifs.',
                'Capacités théoriques, sensibilité OAT, Pareto, Monte-Carlo et budget indicatif.',
            ],
            'indicateurs' => [
                'total_initial', 'total_final', 'variations_annuelles',
                'groupes_theoriques_requis_total', 'taux_saturation_global_pct',
                'score', 'violations',
            ],
        ];
    }

    /**
     * SIM-01 — croissance des admissions.
     *
     * CONFIGURATION du moteur existant : même schéma que le socle, avec
     * une règle de domaine propre (delta et borne minimale ≥ 0). Aucune
     * formule nouvelle, aucune table nouvelle.
     *
     * @return array<string, mixed>
     */
    private static function sim01(): array
    {
        return [
            'code' => 'SIM-01',
            'nom' => 'Croissance des admissions',
            'categorie' => 'admissions_demographie',
            'lot' => 'A',
            'objectif' => "Projeter une croissance annuelle des nouvelles admissions et mesurer ses effets sur les effectifs, les capacités et l'écart à la cible.",
            'version' => 1,
            'statut' => self::STATUT_DISPONIBLE,
            'perimetre' => 'faculte_ou_global',
            'compatibilite' => 'configuration',
            'dependances' => ['SOCLE-EFFECTIFS'],
            'schema' => self::schemaSim01(),
            'hypotheses' => [
                "Delta d'admissions annuel COMPOSÉ : admissions(pas n) = round(baseL1 × (1 + delta)^n).",
                'baseL1 = effectif réel du premier niveau de la cohorte (donnée observée, non inventée).',
                'Croissance uniquement : delta ≥ 0 et borne minimale ≥ 0 (la baisse relève de SIM-02, le gel de SIM-03).',
                'Taux de passage/redoublement/abandon REQUIS et globaux : les effectifs projetés en dépendent.',
                'Aucune valeur de taux, de capacité ou de cible n\'est fournie par défaut : chaque valeur vient du scénario ou d\'une donnée observée.',
            ],
            'version_formules' => '1.0',
            'resultats_attendus' => [
                'Admissions par période (pas 1..horizon), reprises des snapshots du moteur.',
                'Effectifs projetés par période et total final.',
                "Variation absolue et relative par rapport à l'état initial.",
                'Écart à la cible si un objectif cible_total est déclaré.',
                'Groupes théoriques requis, déficit de groupes et places manquantes.',
                'Alertes de variation et de saturation (seuils du moteur, non institutionnels).',
            ],
            'indicateurs' => [
                'admissions_par_periode', 'totaux', 'variations_annuelles',
                'total_final', 'ecart_absolu', 'ecart_pourcentage', 'ecart_cible',
                'groupes_theoriques_requis_total', 'deficit_groupes',
                'taux_saturation_global_pct', 'places_manquantes',
            ],
        ];
    }

    /**
     * SIM-02 — baisse des admissions.
     *
     * CONFIGURATION du moteur existant : même schéma que SIM-01, avec une
     * règle de domaine propre (delta strictement négatif, borne maximale
     * ≤ 0). Aucune formule nouvelle, aucune table nouvelle, aucune
     * modification du moteur.
     *
     * @return array<string, mixed>
     */
    private static function sim02(): array
    {
        return [
            'code' => 'SIM-02',
            'nom' => 'Baisse des admissions',
            'categorie' => 'admissions_demographie',
            'lot' => 'A',
            'objectif' => "Projeter une diminution annuelle des nouvelles admissions et mesurer ses effets sur les effectifs, les capacités et l'écart à la cible.",
            'version' => 1,
            'statut' => self::STATUT_DISPONIBLE,
            'perimetre' => 'faculte_ou_global',
            'compatibilite' => 'configuration',
            'dependances' => ['SOCLE-EFFECTIFS'],
            'schema' => self::schemaSim02(),
            'hypotheses' => [
                "Delta d'admissions annuel COMPOSÉ et strictement négatif : admissions(pas n) = round(baseL1 × (1 + delta)^n).",
                'baseL1 = effectif réel du premier niveau de la cohorte (donnée observée, non inventée).',
                'Baisse uniquement : delta < 0 ; delta = 0 relève de SIM-01, le gel explicite de SIM-03.',
                'Bornes : toutes les variantes restent des baisses ou la stabilité (borne maximale ≤ 0).',
                'Taux de passage/redoublement/abandon REQUIS et globaux : les effectifs projetés en dépendent.',
                "Aucune valeur de taux, de capacité ou de cible n'est fournie par défaut : chaque valeur vient du scénario ou d'une donnée observée.",
                "Une baisse extrême peut produire des admissions nulles par arrondi ; ce n'est pas un gel explicite (SIM-03).",
            ],
            'version_formules' => '1.0',
            'resultats_attendus' => [
                'Admissions par période (pas 1..horizon), reprises des snapshots du moteur.',
                'Effectifs projetés par période et total final.',
                "Variation absolue et relative par rapport à l'état initial.",
                'Écart à la cible si un objectif cible_total est déclaré.',
                'Groupes théoriques requis, déficit de groupes et places manquantes.',
                'Alertes de variation et de saturation (seuils du moteur, non institutionnels).',
            ],
            'indicateurs' => [
                'admissions_par_periode', 'totaux', 'variations_annuelles',
                'total_final', 'ecart_absolu', 'ecart_pourcentage', 'ecart_cible',
                'groupes_theoriques_requis_total', 'deficit_groupes',
                'taux_saturation_global_pct', 'places_manquantes',
            ],
        ];
    }

    /**
     * SIM-03 — gel des admissions.
     *
     * EXTENSION TEMPORELLE : interruption temporaire des admissions pendant
     * duree_gel_annees (admissions = 0), suivie d'une reprise selon
     * delta_admissions.
     *
     * @return array<string, mixed>
     */
    private static function sim03(): array
    {
        return [
            'code' => 'SIM-03',
            'nom' => 'Gel des admissions',
            'categorie' => 'admissions_demographie',
            'lot' => 'A',
            'objectif' => 'Simuler une interruption temporaire des nouvelles admissions.',
            'version' => 1,
            'statut' => self::STATUT_DISPONIBLE,
            'perimetre' => 'faculte_ou_global',
            'compatibilite' => 'extension_temporelle',
            'dependances' => ['SOCLE-EFFECTIFS'],
            'schema' => self::schemaSim03(),
            'hypotheses' => [
                'Interruption stricte : pendant la durée du gel (1..duree_gel_annees), les admissions sont rigoureusement nulles (0).',
                "La durée du gel doit être comprise entre 1 et l'horizon de simulation.",
                'Reprise post-gel : au-delà de la durée du gel, les admissions reprennent selon delta_admissions appliqué depuis la réouverture.',
                'Conservation stricte : les cohortes antérieures poursuivent leurs transitions (passages, redoublements, abandons, diplomations).',
            ],
            'version_formules' => '1.0',
            'resultats_attendus' => [
                'Admissions par période avec 0 pendant la durée du gel.',
                'Décroissance ou assèchement des cohortes entrantes.',
                'Résorption des besoins en groupes et de la saturation.',
                'Conservation exacte des effectifs à chaque pas.',
            ],
            'indicateurs' => [
                'admissions_par_periode', 'totaux', 'variations_annuelles',
                'total_final', 'groupes_theoriques_requis_total', 'deficit_groupes',
            ],
        ];
    }

    /**
     * SIM-04 — admissions exceptionnelles.
     *
     * EXTENSION TEMPORELLE : choc PONCTUEL d'admissions ajouté à un seul pas
     * (pas_exceptionnel), sans modifier la trajectoire de fond (delta). Le
     * moteur unique applique l'ajout après le calcul des admissions de
     * référence ; les cohortes entrées poursuivent ensuite leurs transitions
     * normales. Aucune formule nouvelle, aucune table nouvelle.
     *
     * @return array<string, mixed>
     */
    private static function sim04(): array
    {
        return [
            'code' => 'SIM-04',
            'nom' => 'Admissions exceptionnelles',
            'categorie' => 'admissions_demographie',
            'lot' => 'A',
            'objectif' => "Introduire un choc ponctuel d'admissions sans modifier la trajectoire de fond.",
            'version' => 1,
            'statut' => self::STATUT_DISPONIBLE,
            'perimetre' => 'faculte_ou_global',
            'compatibilite' => 'extension_temporelle',
            'dependances' => ['SOCLE-EFFECTIFS'],
            'schema' => self::schemaSim04(),
            'hypotheses' => [
                'Choc PONCTUEL : les admissions exceptionnelles sont ajoutées UNE SEULE FOIS au pas_exceptionnel.',
                'Trajectoire de fond inchangée : les admissions des autres périodes restent round(baseL1 × (1 + delta)^pas), sans composition du choc.',
                "Le pas exceptionnel doit exister dans l'horizon (1..horizon) et le choc être strictement positif.",
                'Les étudiants admis exceptionnellement suivent ensuite les taux de passage/redoublement/abandon déclarés, comme toute la cohorte.',
                "Aucune valeur de taux, de capacité, de cible ou de choc n'est fournie par défaut : chaque valeur vient du scénario ou d'une donnée observée.",
            ],
            'version_formules' => '1.0',
            'resultats_attendus' => [
                'Admissions par période : trajectoire de fond + choc au pas déclaré, puis retour à la trajectoire de fond.',
                'Effectifs projetés par période et total final, avec conservation stricte.',
                'Groupes théoriques requis, déficit de groupes et places manquantes estimés au pas final (le pic intermédiaire relève de la Famille 4).',
                'Écart à la cible si un objectif cible_total est déclaré.',
                'Alertes de variation et de saturation (seuils du moteur, non institutionnels).',
            ],
            'indicateurs' => [
                'admissions_par_periode', 'totaux', 'variations_annuelles',
                'total_final', 'ecart_absolu', 'ecart_pourcentage', 'ecart_cible',
                'groupes_theoriques_requis_total', 'deficit_groupes',
                'taux_saturation_global_pct', 'places_manquantes',
            ],
        ];
    }

    /**
     * SIM-05 — cible de croissance saisie, comparée par période.
     *
     * Nouvelle capacité analytique : la cible n'est pas un total constant
     * (objectif `cible_total`) mais une TRAJECTOIRE composée au taux cible
     * saisi. Le moteur restitue les écarts par période, leur cumul et
     * l'existence d'au moins un dépassement. Aucune valeur de cible n'est
     * fournie par défaut.
     *
     * @return array<string, mixed>
     */
    private static function sim05(): array
    {
        return [
            'code' => 'SIM-05',
            'nom' => 'Croissance supérieure à la cible',
            'categorie' => 'admissions_demographie',
            'lot' => 'A',
            'objectif' => 'Comparer la projection à une trajectoire de croissance cible saisie et identifier les dépassements période par période.',
            'version' => 1,
            'statut' => self::STATUT_DISPONIBLE,
            'perimetre' => 'faculte_ou_global',
            'compatibilite' => 'analyse_nouvelle',
            'dependances' => ['SOCLE-EFFECTIFS'],
            'schema' => self::schemaSim05(),
            'hypotheses' => [
                'Trajectoire cible composée : admissions_cible(pas) = round(baseL1 × (1 + taux_cible_croissance)^pas) — même formule de composition que la trajectoire réelle, appliquée au taux cible SAISI.',
                'baseL1 = effectif réel du premier niveau de la cohorte (donnée observée, non inventée).',
                'Croissance supérieure STRICTE : delta_admissions > taux_cible_croissance, delta ≥ 0 et borne minimale ≥ 0.',
                'Écart par période = admissions réelles − admissions cibles (entiers arrondis) ; cumul = somme des écarts ; dépassement = au moins un écart strictement positif.',
                'Incompatible avec le gel (SIM-03) et le choc ponctuel (SIM-04) : la lecture de croissance ne serait plus interprétable.',
                "Aucune valeur de taux, de cible, de capacité ou de choc n'est fournie par défaut : chaque valeur vient du scénario ou d'une donnée observée.",
            ],
            'version_formules' => '1.0',
            'resultats_attendus' => [
                'Trajectoire cible datée, écarts signés par période, cumul et booléen de dépassement.',
                'Admissions réelles par période, effectifs projetés et total final (moteur inchangé).',
                'Écart à une cible totale (objectif cible_total) toujours disponible et distinct de la cible de croissance.',
                'Groupes théoriques requis, déficit de groupes et places manquantes estimés au pas final.',
                'Alertes de variation et de saturation (seuils du moteur, non institutionnels).',
            ],
            'indicateurs' => [
                'admissions_par_periode', 'admissions_cible_par_periode',
                'ecarts_cible_par_periode', 'ecart_cible_cumule', 'depassement_cible',
                'totaux', 'variations_annuelles', 'total_final', 'ecart_absolu',
                'ecart_pourcentage', 'ecart_cible', 'groupes_theoriques_requis_total',
                'deficit_groupes', 'taux_saturation_global_pct', 'places_manquantes',
            ],
        ];
    }

    /**
     * SIM-06 — creux progressif puis retour progressif à la référence.
     *
     * Le creux est MULTIPLICATIF sur la trajectoire de fond (delta), avec
     * des rampes linéaires : le retour est exact au pas
     * duree_baisse + duree_recuperation, qui doit rester dans l'horizon.
     * Aucune profondeur, aucune durée et aucun taux ne sont fournis par
     * défaut. Le gel, le choc et la cible sont incompatibles.
     *
     * @return array<string, mixed>
     */
    private static function sim06(): array
    {
        return [
            'code' => 'SIM-06',
            'nom' => 'Récupération démographique',
            'categorie' => 'admissions_demographie',
            'lot' => 'A',
            'objectif' => "Simuler une baisse progressive des admissions jusqu'à un creux, puis un retour progressif au niveau de référence.",
            'version' => 1,
            'statut' => self::STATUT_DISPONIBLE,
            'perimetre' => 'faculte_ou_global',
            'compatibilite' => 'extension_temporelle',
            'dependances' => ['SOCLE-EFFECTIFS'],
            'schema' => self::schemaSim06(),
            'hypotheses' => [
                'Trajectoire de référence inchangée : A_ref(pas) = round(baseL1 × (1 + delta)^pas) ; le creux la DÉVIE, il ne la remplace pas.',
                'Facteur de creux linéaire : f(p) = 1 − profondeur × (p / duree_baisse) jusqu\'au creux, puis f(p) = (1 − profondeur) + profondeur × ((p − duree_baisse) / duree_recuperation) jusqu\'au retour, puis f(p) = 1.',
                'Admissions creusées : admissions(pas) = round(A_ref(pas) × f(pas)).',
                'Creux atteint exactement au pas duree_baisse ; retour exact à la référence au pas duree_baisse + duree_recuperation, qui doit être ≤ horizon.',
                'Profondeur ∈ ]0 ; 1] : creux strictement positif, jamais négatif en admissions ; aucune valeur par défaut.',
                'Incompatible avec le gel (SIM-03), le choc ponctuel (SIM-04) et la cible de croissance (SIM-05).',
                'Les étudiants admis pendant et après le creux suivent les taux de passage/redoublement/abandon déclarés, comme toute la cohorte.',
            ],
            'version_formules' => '1.0',
            'resultats_attendus' => [
                'Admissions par période montrant la baisse, le creux et le retour ; trajectoire de référence rappelée période par période.',
                'Écarts signés vs référence, déficit cumulé et creux maximal en étudiants.',
                'Phases explicites par période (baisse / recuperation / reference).',
                'Effectifs projetés, total final et capacités (moteur inchangé).',
                'Alertes de variation et de saturation (seuils du moteur, non institutionnels).',
            ],
            'indicateurs' => [
                'admissions_par_periode', 'admissions_reference_par_periode',
                'ecarts_recuperation_par_periode', 'ecart_recuperation_cumule',
                'creux_recuperation', 'totaux', 'variations_annuelles', 'total_final',
                'ecart_absolu', 'ecart_pourcentage', 'ecart_cible',
                'groupes_theoriques_requis_total', 'deficit_groupes',
                'taux_saturation_global_pct', 'places_manquantes',
            ],
        ];
    }

    /**
     * SIM-07 — amélioration de la réussite : comparaison dans une même
     * exécution de deux jeux de taux déclarés (référence / amélioré), à
     * admissions identiques. Aucune compensation inventée : l'origine de la
     * hausse appartient au scénario. Restitue les effectifs comparés, les
     * écarts et les sortants (dernier niveau du périmètre).
     *
     * @return array<string, mixed>
     */
    private static function sim07(): array
    {
        return [
            'code' => 'SIM-07',
            'nom' => 'Amélioration de la réussite',
            'categorie' => 'reussite_flux',
            'lot' => 'A',
            'objectif' => "Mesurer l'effet d'une hausse déclarée du taux de passage sur la progression des cohortes et les sortants.",
            'version' => 1,
            'statut' => self::STATUT_DISPONIBLE,
            'perimetre' => 'faculte_ou_global',
            'compatibilite' => 'analyse_nouvelle',
            'dependances' => ['SOCLE-EFFECTIFS'],
            'schema' => self::schemaSim07(),
            'hypotheses' => [
                'Deux jeux de taux COMPLETS et saisis : taux (référence, trajectoire témoin) et taux_ameliore (simulé, trajectoire principale).',
                'Aucune compensation implicite : le redoublement et l\'abandon améliorés sont déclarés (baisse, stabilité, ou consommation du reste 1 − somme).',
                'Hausse STRICTE du passage : taux_ameliore.passage > taux.passage.',
                'Admissions identiques dans les deux trajectoires (même delta) : l\'écart isole strictement l\'effet des taux de flux.',
                'Sortants = partants du dernier niveau du périmètre (diplômés si le périmètre couvre le niveau terminal).',
                'Incompatible avec le gel (SIM-03), le choc (SIM-04), la cible (SIM-05) et le creux (SIM-06).',
                'Aucune valeur par défaut : sans taux_ameliore, aucune comparaison n\'est produite.',
            ],
            'version_formules' => '1.0',
            'resultats_attendus' => [
                'Hausse de passage déclarée rappelée (4 décimales).',
                'Totaux simulés et de référence période par période, écarts signés et écart final.',
                'Sortants par période (simulés vs référence) et effet cumulé sur les sortants.',
                'Effectifs projetés, total final, capacités et alertes (moteur inchangé).',
                'Analyses de sensibilité et Monte-Carlo calculées autour des taux AMÉLIORÉS (trajectoire principale).',
            ],
            'indicateurs' => [
                'admissions_par_periode', 'hausse_passage', 'totaux', 'totaux_reference_reussite',
                'ecarts_reussite_par_periode', 'ecart_reussite_final', 'sortants_par_periode',
                'effet_sortants_cumules', 'variations_annuelles', 'total_final',
                'ecart_absolu', 'ecart_pourcentage', 'ecart_cible',
                'groupes_theoriques_requis_total', 'deficit_groupes',
                'taux_saturation_global_pct', 'places_manquantes',
            ],
        ];
    }

    /**
     * SIM-08 — dégradation de la réussite : miroir de SIM-07. La trajectoire
     * principale suit les taux dégradés déclarés ; la trajectoire témoin
     * suit les taux de référence, à admissions identiques. Aucune
     * compensation inventée : l'origine de la baisse appartient au
     * scénario. Restitue les effectifs comparés, les écarts et les sortants.
     *
     * @return array<string, mixed>
     */
    private static function sim08(): array
    {
        return [
            'code' => 'SIM-08',
            'nom' => 'Dégradation de la réussite',
            'categorie' => 'reussite_flux',
            'lot' => 'A',
            'objectif' => "Mesurer l'effet d'une baisse déclarée du taux de passage sur la progression des cohortes et les sortants.",
            'version' => 1,
            'statut' => self::STATUT_DISPONIBLE,
            'perimetre' => 'faculte_ou_global',
            'compatibilite' => 'analyse_nouvelle',
            'dependances' => ['SOCLE-EFFECTIFS'],
            'schema' => self::schemaSim08(),
            'hypotheses' => [
                'Deux jeux de taux COMPLETS et saisis : taux (référence, trajectoire témoin) et taux_degrade (simulé, trajectoire principale).',
                'Aucune compensation implicite : le redoublement et l\'abandon dégradés sont déclarés (hausse, stabilité, ou fuite vers le reste 1 − somme).',
                'Baisse STRICTE du passage : taux_degrade.passage < taux.passage.',
                'Admissions identiques dans les deux trajectoires (même delta) : l\'écart isole strictement l\'effet des taux de flux.',
                'Sortants = partants du dernier niveau du périmètre (diplômés si le périmètre couvre le niveau terminal).',
                'Incompatible avec le gel (SIM-03), le choc (SIM-04), la cible (SIM-05), le creux (SIM-06) et l\'amélioration simultanée (SIM-07).',
                'Aucune valeur par défaut : sans taux_degrade, aucune comparaison n\'est produite.',
            ],
            'version_formules' => '1.0',
            'resultats_attendus' => [
                'Baisse de passage déclarée rappelée (4 décimales, magnitude positive).',
                'Totaux simulés et de référence période par période, écarts signés et écart final (positifs = étudiants bloqués en plus).',
                'Sortants par période (simulés vs référence) et effet cumulé sur les sortants (négatif = diplômés perdus).',
                'Effectifs projetés, total final, capacités et alertes (moteur inchangé).',
                'Analyses de sensibilité et Monte-Carlo calculées autour des taux DÉGRADÉS (trajectoire principale).',
            ],
            'indicateurs' => [
                'admissions_par_periode', 'baisse_passage', 'totaux', 'totaux_reference_reussite',
                'ecarts_reussite_par_periode', 'ecart_reussite_final', 'sortants_par_periode',
                'effet_sortants_cumules', 'variations_annuelles', 'total_final',
                'ecart_absolu', 'ecart_pourcentage', 'ecart_cible',
                'groupes_theoriques_requis_total', 'deficit_groupes',
                'taux_saturation_global_pct', 'places_manquantes',
            ],
        ];
    }

    /**
     * SIM-09 — hausse du redoublement : variation mono-axe. La trajectoire
     * principale suit le jeu modifié déclaré ; la trajectoire témoin suit
     * les taux de référence, à admissions identiques. Restitue les effectifs
     * comparés, les écarts et les sortants (même machinerie que SIM-07/08).
     *
     * @return array<string, mixed>
     */
    private static function sim09(): array
    {
        return [
            'code' => 'SIM-09',
            'nom' => 'Hausse du redoublement',
            'categorie' => 'reussite_flux',
            'lot' => 'A',
            'objectif' => "Évaluer l'accumulation d'effectifs liée à une hausse déclarée du redoublement et son effet sur les sortants.",
            'version' => 1,
            'statut' => self::STATUT_DISPONIBLE,
            'perimetre' => 'faculte_ou_global',
            'compatibilite' => 'analyse_nouvelle',
            'dependances' => ['SOCLE-EFFECTIFS'],
            'schema' => self::schemaSim09(),
            'hypotheses' => [
                'Deux jeux de taux COMPLETS et saisis : taux (référence, trajectoire témoin) et taux_modifie (simulé, trajectoire principale).',
                'Aucune compensation implicite : la provenance de la hausse du redoublement est déclarée (passage, abandon ou reste).',
                'Hausse STRICTE du redoublement : taux_modifie.redoublement > taux.redoublement.',
                'Admissions identiques dans les deux trajectoires (même delta) : l\'écart isole strictement l\'effet des taux de flux.',
                'Sortants = partants du dernier niveau du périmètre ; l\'effet aval peut être nul à horizon court.',
                'Incompatible avec le gel (SIM-03), le choc (SIM-04), la cible (SIM-05), le creux (SIM-06) et les alias passage (SIM-07/08).',
                'Aucune valeur par défaut : sans taux_modifie, aucune comparaison n\'est produite.',
            ],
            'version_formules' => '1.0',
            'resultats_attendus' => [
                'Hausse de redoublement déclarée rappelée (4 décimales, magnitude positive).',
                'Totaux simulés et de référence période par période, écarts signés et écart final (positifs = accumulation).',
                'Sortants par période (simulés vs référence) et effet cumulé sur les sortants.',
                'Effectifs projetés, total final, capacités et alertes (moteur inchangé).',
                'Analyses de sensibilité et Monte-Carlo calculées autour des taux MODIFIÉS (trajectoire principale).',
            ],
            'indicateurs' => [
                'admissions_par_periode', 'hausse_redoublement', 'totaux', 'totaux_reference_reussite',
                'ecarts_reussite_par_periode', 'ecart_reussite_final', 'sortants_par_periode',
                'effet_sortants_cumules', 'variations_annuelles', 'total_final',
                'ecart_absolu', 'ecart_pourcentage', 'ecart_cible',
                'groupes_theoriques_requis_total', 'deficit_groupes',
                'taux_saturation_global_pct', 'places_manquantes',
            ],
        ];
    }

    /**
     * SIM-10 — réduction du redoublement : variation mono-axe. La trajectoire
     * principale suit le jeu modifié déclaré ; la trajectoire témoin suit
     * les taux de référence, à admissions identiques. L'effet sur les
     * effectifs et les sortants dépend de l'origine déclarée de la baisse
     * (aucun signe supposé).
     *
     * @return array<string, mixed>
     */
    private static function sim10(): array
    {
        return [
            'code' => 'SIM-10',
            'nom' => 'Réduction du redoublement',
            'categorie' => 'reussite_flux',
            'lot' => 'A',
            'objectif' => "Mesurer l'effet d'une baisse déclarée du redoublement sur la progression des cohortes et les sortants.",
            'version' => 1,
            'statut' => self::STATUT_DISPONIBLE,
            'perimetre' => 'faculte_ou_global',
            'compatibilite' => 'analyse_nouvelle',
            'dependances' => ['SOCLE-EFFECTIFS'],
            'schema' => self::schemaSim10(),
            'hypotheses' => [
                'Deux jeux de taux COMPLETS et saisis : taux (référence, trajectoire témoin) et taux_modifie (simulé, trajectoire principale).',
                'Aucune compensation implicite : la provenance de la baisse du redoublement est déclarée (passage, abandon ou reste).',
                'Baisse STRICTE du redoublement : taux_modifie.redoublement < taux.redoublement.',
                'Admissions identiques dans les deux trajectoires (même delta) : l\'écart isole strictement l\'effet des taux de flux.',
                'Aucun signe favorable supposé : l\'effet sur les sortants peut être positif, nul ou négatif selon l\'origine déclarée.',
                'Incompatible avec le gel (SIM-03), le choc (SIM-04), la cible (SIM-05), le creux (SIM-06) et les alias passage (SIM-07/08).',
                'Aucune valeur par défaut : sans taux_modifie, aucune comparaison n\'est produite.',
            ],
            'version_formules' => '1.0',
            'resultats_attendus' => [
                'Baisse de redoublement déclarée rappelée (4 décimales, magnitude positive).',
                'Totaux simulés et de référence période par période, écarts signés et écart final (négatifs = effectifs réduits).',
                'Sortants par période (simulés vs référence) et effet cumulé sur les sortants (nul ou négatif possible).',
                'Effectifs projetés, total final, capacités et alertes (moteur inchangé).',
                'Analyses de sensibilité et Monte-Carlo calculées autour des taux MODIFIÉS (trajectoire principale).',
            ],
            'indicateurs' => [
                'admissions_par_periode', 'baisse_redoublement', 'totaux', 'totaux_reference_reussite',
                'ecarts_reussite_par_periode', 'ecart_reussite_final', 'sortants_par_periode',
                'effet_sortants_cumules', 'variations_annuelles', 'total_final',
                'ecart_absolu', 'ecart_pourcentage', 'ecart_cible',
                'groupes_theoriques_requis_total', 'deficit_groupes',
                'taux_saturation_global_pct', 'places_manquantes',
            ],
        ];
    }

    /**
     * SIM-11 — hausse de l'abandon : variation mono-axe. La trajectoire
     * principale suit le jeu modifié déclaré ; la trajectoire témoin suit
     * les taux de référence, à admissions identiques. `abandon` n'étant pas
     * réinjecté, une hausse absorbée par le slack est exactement neutre ;
     * tout effet provient du mouvement joint déclaré (passage↓ ou
     * redoublement↓), jamais d'une causalité isolée inventée.
     *
     * @return array<string, mixed>
     */
    private static function sim11(): array
    {
        return [
            'code' => 'SIM-11',
            'nom' => "Hausse de l'abandon",
            'categorie' => 'reussite_flux',
            'lot' => 'A',
            'objectif' => "Mesurer l'effet d'une hausse déclarée de l'abandon sur la progression des cohortes et les sortants.",
            'version' => 1,
            'statut' => self::STATUT_DISPONIBLE,
            'perimetre' => 'faculte_ou_global',
            'compatibilite' => 'analyse_nouvelle',
            'dependances' => ['SOCLE-EFFECTIFS'],
            'schema' => self::schemaSim11(),
            'hypotheses' => [
                'Deux jeux de taux COMPLETS et saisis : taux (référence, trajectoire témoin) et taux_modifie (simulé, trajectoire principale).',
                'Hausse STRICTE de l\'abandon : taux_modifie.abandon > taux.abandon.',
                'Aucune compensation implicite : la provenance de la hausse de l\'abandon est déclarée (passage↓, redoublement↓ ou slack).',
                'Admissions identiques dans les deux trajectoires (même delta) : l\'écart isole strictement l\'effet des taux de flux.',
                'abandon n\'est PAS réinjecté dans la progression : une hausse absorbée par le slack est exactement neutre ; aucun effet n\'est inventé.',
                'Aucun signe favorable supposé : l\'effet sur les effectifs et les sortants dépend du mouvement joint déclaré.',
                'Incompatible avec le gel (SIM-03), le choc (SIM-04), la cible (SIM-05), le creux (SIM-06) et les alias passage (SIM-07/08).',
                'Aucune valeur par défaut : sans taux_modifie, aucune comparaison n\'est produite.',
            ],
            'version_formules' => '1.0',
            'resultats_attendus' => [
                'Hausse d\'abandon déclarée rappelée (4 décimales, magnitude positive).',
                'Totaux simulés et de référence période par période, écarts signés et écart final.',
                'Sortants par période (simulés vs référence) et effet cumulé sur les sortants (négatif, nul ou positif possible).',
                'Effectifs projetés, total final, capacités et alertes (moteur inchangé).',
                'Analyses de sensibilité et Monte-Carlo calculées autour des taux MODIFIÉS (trajectoire principale) ; aucune sensibilité à l\'abandon n\'est inventée.',
            ],
            'indicateurs' => [
                'admissions_par_periode', 'hausse_abandon', 'totaux', 'totaux_reference_reussite',
                'ecarts_reussite_par_periode', 'ecart_reussite_final', 'sortants_par_periode',
                'effet_sortants_cumules', 'variations_annuelles', 'total_final',
                'ecart_absolu', 'ecart_pourcentage', 'ecart_cible',
                'groupes_theoriques_requis_total', 'deficit_groupes',
                'taux_saturation_global_pct', 'places_manquantes',
            ],
        ];
    }

    /**
     * SIM-12 — réduction de l'abandon : variation mono-axe. La trajectoire
     * principale suit le jeu modifié déclaré ; la trajectoire témoin suit
     * les taux de référence, à admissions identiques. `abandon` n'étant pas
     * réinjecté, une baisse absorbée par le slack est exactement neutre ;
     * tout effet provient du mouvement joint déclaré (passage↑ ou
     * redoublement↑), jamais d'une causalité isolée inventée.
     *
     * @return array<string, mixed>
     */
    private static function sim12(): array
    {
        return [
            'code' => 'SIM-12',
            'nom' => "Réduction de l'abandon",
            'categorie' => 'reussite_flux',
            'lot' => 'A',
            'objectif' => "Mesurer l'effet d'une baisse déclarée de l'abandon sur la progression des cohortes et les sortants.",
            'version' => 1,
            'statut' => self::STATUT_DISPONIBLE,
            'perimetre' => 'faculte_ou_global',
            'compatibilite' => 'analyse_nouvelle',
            'dependances' => ['SOCLE-EFFECTIFS'],
            'schema' => self::schemaSim12(),
            'hypotheses' => [
                'Deux jeux de taux COMPLETS et saisis : taux (référence, trajectoire témoin) et taux_modifie (simulé, trajectoire principale).',
                'Baisse STRICTE de l\'abandon : taux_modifie.abandon < taux.abandon.',
                'Aucune compensation implicite : la provenance de la baisse de l\'abandon est déclarée (passage↑, redoublement↑ ou slack).',
                'Admissions identiques dans les deux trajectoires (même delta) : l\'écart isole strictement l\'effet des taux de flux.',
                'abandon n\'est PAS réinjecté dans la progression : une baisse absorbée par le slack est exactement neutre ; aucun effet n\'est inventé.',
                'Aucun signe favorable supposé : l\'effet sur les effectifs et les sortants dépend du mouvement joint déclaré.',
                'Incompatible avec le gel (SIM-03), le choc (SIM-04), la cible (SIM-05), le creux (SIM-06) et les alias passage (SIM-07/08).',
                'Aucune valeur par défaut : sans taux_modifie, aucune comparaison n\'est produite.',
            ],
            'version_formules' => '1.0',
            'resultats_attendus' => [
                'Baisse d\'abandon déclarée rappelée (4 décimales, magnitude positive).',
                'Totaux simulés et de référence période par période, écarts signés et écart final.',
                'Sortants par période (simulés vs référence) et effet cumulé sur les sortants (négatif, nul ou positif possible).',
                'Effectifs projetés, total final, capacités et alertes (moteur inchangé).',
                'Analyses de sensibilité et Monte-Carlo calculées autour des taux MODIFIÉS (trajectoire principale) ; aucune sensibilité à l\'abandon n\'est inventée.',
            ],
            'indicateurs' => [
                'admissions_par_periode', 'baisse_abandon', 'totaux', 'totaux_reference_reussite',
                'ecarts_reussite_par_periode', 'ecart_reussite_final', 'sortants_par_periode',
                'effet_sortants_cumules', 'variations_annuelles', 'total_final',
                'ecart_absolu', 'ecart_pourcentage', 'ecart_cible',
                'groupes_theoriques_requis_total', 'deficit_groupes',
                'taux_saturation_global_pct', 'places_manquantes',
            ],
        ];
    }

    /**
     * SIM-13 — crise académique combinée : plusieurs dégradations simul-
     * tanées (passage↓, redoublement↑, abandon↑) déclarées sur le des-
     * cripteur générique. Aucune amélioration n'est admise ; au moins deux
     * axes doivent se dégrader STRICTEMENT (l'axe focal en fait partie).
     * La trajectoire témoin suit les taux de référence à admissions
     * identiques ; l'écart isole strictement l'effet combiné des taux.
     *
     * @return array<string, mixed>
     */
    private static function sim13(): array
    {
        return [
            'code' => 'SIM-13',
            'nom' => 'Crise académique combinée',
            'categorie' => 'reussite_flux',
            'lot' => 'A',
            'objectif' => 'Combiner plusieurs dégradations dans les limites du modèle et mesurer leur effet joint sur la progression des cohortes et les sortants.',
            'version' => 1,
            'statut' => self::STATUT_DISPONIBLE,
            'perimetre' => 'faculte_ou_global',
            'compatibilite' => 'configuration',
            'dependances' => ['SOCLE-EFFECTIFS'],
            'schema' => self::schemaSim13(),
            'hypotheses' => [
                'Deux jeux de taux COMPLETS et saisis : taux (référence, trajectoire témoin) et taux_modifie (simulé, trajectoire principale).',
                'Dégradations combinées : passage↓, redoublement↑, abandon↑ ; au moins DEUX axes strictement dégradés, aucune amélioration.',
                'Paire focale déclarante : passage/baisse, redoublement/hausse ou abandon/hausse — toute autre paire est refusée.',
                'Aucune compensation implicite : chaque mouvement du jeu modifié est déclaré par le scénario.',
                'Admissions identiques dans les deux trajectoires (même delta) : l\'écart isole strictement l\'effet combiné des taux.',
                'abandon n\'est PAS réinjecté dans la progression : une variation d\'abandon absorbée par le slack est exactement neutre ; aucun effet n\'est inventé.',
                'Aucun signe favorable supposé : l\'effet joint sur les effectifs et les sortants dépend des mouvements déclarés.',
                'Incompatible avec le gel (SIM-03), le choc (SIM-04), la cible (SIM-05), le creux (SIM-06) et les alias passage (SIM-07/08).',
                'Aucune valeur par défaut : sans taux_modifie, aucune comparaison n\'est produite.',
            ],
            'version_formules' => '1.0',
            'resultats_attendus' => [
                'Dégradation focale déclarée rappelée (4 décimales, magnitude positive).',
                'Totaux simulés et de référence période par période, écarts signés et écart final.',
                'Sortants par période (simulés vs référence) et effet cumulé sur les sortants (négatif, nul ou positif possible).',
                'Effectifs projetés, total final, capacités et alertes (moteur inchangé).',
                'Analyses de sensibilité et Monte-Carlo calculées autour des taux MODIFIÉS (trajectoire principale) ; aucune sensibilité à l\'abandon n\'est inventée.',
            ],
            'indicateurs' => [
                'admissions_par_periode', 'baisse_passage', 'hausse_redoublement', 'hausse_abandon',
                'totaux', 'totaux_reference_reussite',
                'ecarts_reussite_par_periode', 'ecart_reussite_final', 'sortants_par_periode',
                'effet_sortants_cumules', 'variations_annuelles', 'total_final',
                'ecart_absolu', 'ecart_pourcentage', 'ecart_cible',
                'groupes_theoriques_requis_total', 'deficit_groupes',
                'taux_saturation_global_pct', 'places_manquantes',
            ],
        ];
    }

    /**
     * 31 scénarios planifiés — métadonnées minimales, sans schéma exécutable
     * tant que leur LOOP n'est pas clôturé. La classification de
     * compatibilité est préliminaire et doit être confirmée par l'audit du
     * scénario concerné.
     *
     * @return array<string, array{nom: string, categorie: string, lot: string, objectif: string, compatibilite: string, dependances: array<int, string>}>
     */
    private static function planifies(): array
    {
        return [
            // LOT A — Admissions et démographie.

            // LOT A — Réussite, redoublement et abandon.

            // LOT B — Cohortes et progression LMD.
            'SIM-14' => ['nom' => 'Blocage en L1', 'categorie' => 'cohortes_lmd', 'lot' => 'B', 'objectif' => "Identifier l'accumulation d'étudiants en première année.", 'compatibilite' => 'extension_par_niveau', 'dependances' => []],
            'SIM-15' => ['nom' => 'Blocage en L2', 'categorie' => 'cohortes_lmd', 'lot' => 'B', 'objectif' => "Identifier l'accumulation d'étudiants en deuxième année.", 'compatibilite' => 'extension_par_niveau', 'dependances' => ['SIM-14']],
            'SIM-16' => ['nom' => 'Flux déséquilibrés entre niveaux', 'categorie' => 'cohortes_lmd', 'lot' => 'B', 'objectif' => 'Permettre des taux différents par niveau et mesurer les déséquilibres.', 'compatibilite' => 'extension_par_niveau', 'dependances' => ['SIM-14', 'SIM-15']],
            'SIM-17' => ['nom' => 'Cohorte exceptionnelle', 'categorie' => 'cohortes_lmd', 'lot' => 'B', 'objectif' => "Mesurer l'impact d'une cohorte à effectif inhabituel.", 'compatibilite' => 'extension_cohorte', 'dependances' => []],
            'SIM-18' => ['nom' => 'Double génération', 'categorie' => 'cohortes_lmd', 'lot' => 'B', 'objectif' => 'Simuler la coexistence de deux cohortes importantes dans un niveau.', 'compatibilite' => 'extension_cohorte', 'dependances' => ['SIM-17']],
            'SIM-19' => ['nom' => 'Rééquilibrage des niveaux', 'categorie' => 'cohortes_lmd', 'lot' => 'B', 'objectif' => 'Évaluer la convergence vers une distribution plus équilibrée.', 'compatibilite' => 'extension_par_niveau', 'dependances' => ['SIM-14', 'SIM-15', 'SIM-16']],

            // LOT C — Capacités et infrastructures.
            'SIM-20' => ['nom' => 'Saturation des capacités', 'categorie' => 'capacites_infrastructures', 'lot' => 'C', 'objectif' => 'Identifier les périodes où la capacité disponible devient insuffisante.', 'compatibilite' => 'extension_capacite', 'dependances' => []],
            'SIM-21' => ['nom' => 'Extension des capacités', 'categorie' => 'capacites_infrastructures', 'lot' => 'C', 'objectif' => "Analyser différents niveaux d'augmentation des capacités.", 'compatibilite' => 'extension_capacite', 'dependances' => ['SIM-20']],
            'SIM-22' => ['nom' => 'Perte temporaire de capacité', 'categorie' => 'capacites_infrastructures', 'lot' => 'C', 'objectif' => "Étudier l'indisponibilité temporaire d'une partie des capacités.", 'compatibilite' => 'extension_capacite', 'dependances' => ['SIM-20']],
            'SIM-23' => ['nom' => 'Création théorique de groupes', 'categorie' => 'capacites_infrastructures', 'lot' => 'C', 'objectif' => 'Comparer différents nombres de groupes théoriques sans modifier le référentiel.', 'compatibilite' => 'extension_capacite', 'dependances' => ['SIM-20']],
            'SIM-24' => ['nom' => 'Réduction de capacité par groupe', 'categorie' => 'capacites_infrastructures', 'lot' => 'C', 'objectif' => "Mesurer l'effet d'une réduction des capacités unitaires.", 'compatibilite' => 'extension_capacite', 'dependances' => ['SIM-20']],
            'SIM-25' => ['nom' => 'Répartition déséquilibrée des groupes', 'categorie' => 'capacites_infrastructures', 'lot' => 'C', 'objectif' => "Identifier les conséquences d'une concentration des étudiants sur certains groupes.", 'compatibilite' => 'extension_repartition', 'dependances' => ['SIM-20']],

            // LOT D — Ressources et projections budgétaires.
            'SIM-26' => ['nom' => 'Budget constant', 'categorie' => 'budget_ressources', 'lot' => 'D', 'objectif' => "Comparer l'évolution des effectifs à une enveloppe budgétaire constante.", 'compatibilite' => 'extension_budgetaire', 'dependances' => []],
            'SIM-27' => ['nom' => 'Réduction budgétaire', 'categorie' => 'budget_ressources', 'lot' => 'D', 'objectif' => "Mesurer les conséquences d'une baisse d'une enveloppe hypothétique.", 'compatibilite' => 'extension_budgetaire', 'dependances' => ['SIM-26']],
            'SIM-28' => ['nom' => 'Coût supplémentaire par groupe', 'categorie' => 'budget_ressources', 'lot' => 'D', 'objectif' => 'Mesurer l\'effet du nombre de groupes sur le coût estimatif.', 'compatibilite' => 'extension_budgetaire', 'dependances' => ['SIM-26']],
            'SIM-29' => ['nom' => 'Investissement de capacité', 'categorie' => 'budget_ressources', 'lot' => 'D', 'objectif' => 'Comparer un investissement hypothétique et les bénéfices en capacité.', 'compatibilite' => 'extension_budgetaire', 'dependances' => ['SIM-21', 'SIM-28']],
            'SIM-30' => ['nom' => 'Croissance sous contrainte budgétaire', 'categorie' => 'budget_ressources', 'lot' => 'D', 'objectif' => 'Comparer plusieurs possibilités de croissance lorsque les ressources sont limitées.', 'compatibilite' => 'extension_budgetaire', 'dependances' => ['SIM-26', 'SIM-27', 'SIM-28', 'SIM-29']],

            // LOT E — Crises et résilience.
            'CR-01' => ['nom' => 'Choc négatif sur les admissions', 'categorie' => 'crises_resilience', 'lot' => 'E', 'objectif' => 'Simuler un choc négatif sur les admissions pendant une ou plusieurs périodes.', 'compatibilite' => 'extension_temporelle', 'dependances' => ['SIM-02', 'SIM-04']],
            'CR-02' => ['nom' => 'Crise de réussite et de rétention', 'categorie' => 'crises_resilience', 'lot' => 'E', 'objectif' => "Combiner hausse du redoublement, hausse de l'abandon et variation du passage.", 'compatibilite' => 'configuration', 'dependances' => ['SIM-09', 'SIM-11', 'SIM-13']],
            'CR-03' => ['nom' => 'Admissions élevées et réussite dégradée', 'categorie' => 'crises_resilience', 'lot' => 'E', 'objectif' => 'Combiner une augmentation des entrées et une diminution du passage.', 'compatibilite' => 'configuration', 'dependances' => ['SIM-01', 'SIM-08']],
            'CR-04' => ['nom' => 'Réduction importante des capacités', 'categorie' => 'crises_resilience', 'lot' => 'E', 'objectif' => 'Simuler une réduction temporaire ou prolongée des capacités.', 'compatibilite' => 'extension_capacite', 'dependances' => ['SIM-22', 'SIM-24']],
            'CR-05' => ['nom' => 'Retour progressif après une crise', 'categorie' => 'crises_resilience', 'lot' => 'E', 'objectif' => 'Simuler le retour progressif des paramètres vers une référence.', 'compatibilite' => 'extension_temporelle', 'dependances' => ['SIM-06', 'CR-01']],
            'CR-06' => ['nom' => 'Crises successives', 'categorie' => 'crises_resilience', 'lot' => 'E', 'objectif' => 'Simuler plusieurs événements défavorables ordonnés à des périodes différentes.', 'compatibilite' => 'extension_temporelle', 'dependances' => ['CR-01', 'CR-02', 'CR-03', 'CR-04', 'CR-05']],

            // LOT F — Sensibilité et incertitude.
            'UNC-01' => ['nom' => 'Sensibilité combinée', 'categorie' => 'incertitude_analyses', 'lot' => 'F', 'objectif' => 'Étudier les variations simultanées de plusieurs paramètres et leurs interactions.', 'compatibilite' => 'analyse_nouvelle', 'dependances' => ['SIM-07', 'SIM-08', 'SIM-09', 'SIM-11']],
            'UNC-02' => ['nom' => 'Stress testing', 'categorie' => 'incertitude_analyses', 'lot' => 'F', 'objectif' => 'Explorer des cas défavorables documentés dans les limites définies.', 'compatibilite' => 'analyse_nouvelle', 'dependances' => ['SIM-13', 'CR-01', 'CR-02', 'CR-03', 'CR-04']],
            'UNC-03' => ['nom' => 'Analyse de robustesse', 'categorie' => 'incertitude_analyses', 'lot' => 'F', 'objectif' => 'Mesurer si un scénario reste acceptable lorsque les hypothèses varient.', 'compatibilite' => 'analyse_nouvelle', 'dependances' => ['UNC-02']],
            'UNC-04' => ['nom' => 'Comparaison probabiliste', 'categorie' => 'incertitude_analyses', 'lot' => 'F', 'objectif' => 'Comparer les distributions de résultats de plusieurs scénarios.', 'compatibilite' => 'analyse_nouvelle', 'dependances' => ['UNC-02']],
            'UNC-05' => ['nom' => 'Détection des seuils de rupture', 'categorie' => 'incertitude_analyses', 'lot' => 'F', 'objectif' => "Identifier la valeur d'un paramètre à partir de laquelle une contrainte est dépassée.", 'compatibilite' => 'analyse_nouvelle', 'dependances' => ['SIM-20', 'CR-01']],
            'UNC-06' => ['nom' => 'Analyse de récupération', 'categorie' => 'incertitude_analyses', 'lot' => 'F', 'objectif' => 'Déterminer le nombre de périodes nécessaires pour retrouver une référence.', 'compatibilite' => 'analyse_nouvelle', 'dependances' => ['SIM-06', 'CR-05']],
            'UNC-07' => ['nom' => 'Dépendances et interactions', 'categorie' => 'incertitude_analyses', 'lot' => 'F', 'objectif' => 'Étudier les interactions possibles entre paramètres sans inventer de corrélations.', 'compatibilite' => 'analyse_nouvelle', 'dependances' => ['UNC-01', 'UNC-04']],
            'UNC-08' => ['nom' => 'Stabilité de la frontière de Pareto', 'categorie' => 'incertitude_analyses', 'lot' => 'F', 'objectif' => 'Vérifier si les solutions non dominées restent stables lorsque les hypothèses changent.', 'compatibilite' => 'analyse_nouvelle', 'dependances' => ['SIM-30', 'UNC-03']],
        ];
    }

    /** @param array<string, mixed> $meta @return array<string, mixed> */
    private static function normaliserPlanifie(string $code, array $meta): array
    {
        return [
            'code' => $code,
            'nom' => $meta['nom'],
            'categorie' => $meta['categorie'],
            'lot' => $meta['lot'],
            'objectif' => $meta['objectif'],
            'version' => null,
            'statut' => self::STATUT_PLANIFIE,
            'perimetre' => 'faculte_ou_global',
            'compatibilite' => $meta['compatibilite'],
            'dependances' => $meta['dependances'],
            'schema' => null,
            'hypotheses' => [],
            'version_formules' => null,
            'resultats_attendus' => [],
            'indicateurs' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $valeurs
     * @param  array<string, mixed>  $schema
     */
    private static function validerTableau(array $valeurs, array $schema, string $chemin): void
    {
        foreach ($valeurs as $cle => $valeur) {
            if (! array_key_exists($cle, $schema)) {
                throw new \DomainException("Paramètre inconnu : {$chemin}.{$cle}.");
            }
            self::validerValeur($valeur, $schema[$cle], $chemin.'.'.$cle);
        }

        foreach ($schema as $cle => $regle) {
            if (($regle['requis'] ?? false) && ! array_key_exists($cle, $valeurs)) {
                throw new \DomainException("Paramètre requis absent : {$chemin}.{$cle}.");
            }
        }
    }

    /** @param array<string, mixed> $regle */
    private static function validerValeur(mixed $valeur, array $regle, string $chemin): void
    {
        $type = $regle['type'] ?? 'string';

        if ($type === 'number') {
            if (! is_int($valeur) && ! is_float($valeur) && ! (is_string($valeur) && is_numeric($valeur))) {
                throw new \DomainException("Type invalide pour {$chemin} : nombre attendu.");
            }
            $nombre = (float) $valeur;
            if (! is_finite($nombre)) {
                throw new \DomainException("Valeur non finie refusée pour {$chemin}.");
            }
            if (isset($regle['min']) && $nombre < (float) $regle['min']) {
                throw new \DomainException("Valeur hors bornes pour {$chemin} (minimum {$regle['min']}).");
            }
            if (isset($regle['max']) && $nombre > (float) $regle['max']) {
                throw new \DomainException("Valeur hors bornes pour {$chemin} (maximum {$regle['max']}).");
            }
        } elseif ($type === 'integer') {
            if (is_int($valeur)) {
                $entier = $valeur;
            } elseif (is_float($valeur) && floor($valeur) === $valeur) {
                $entier = (int) $valeur;
            } elseif (is_string($valeur) && preg_match('/^-?\d+$/', $valeur) === 1) {
                $entier = (int) $valeur;
            } else {
                throw new \DomainException("Type invalide pour {$chemin} : entier attendu.");
            }
            if (isset($regle['min']) && $entier < (int) $regle['min']) {
                throw new \DomainException("Valeur hors bornes pour {$chemin} (minimum {$regle['min']}).");
            }
            if (isset($regle['max']) && $entier > (int) $regle['max']) {
                throw new \DomainException("Valeur hors bornes pour {$chemin} (maximum {$regle['max']}).");
            }
        } elseif ($type === 'string') {
            if (! is_string($valeur)) {
                throw new \DomainException("Type invalide pour {$chemin} : chaîne attendue.");
            }
            if (isset($regle['max']) && mb_strlen($valeur) > (int) $regle['max']) {
                throw new \DomainException("Chaîne trop longue pour {$chemin} (maximum {$regle['max']}).");
            }
            if (isset($regle['pattern']) && preg_match($regle['pattern'], $valeur) !== 1) {
                throw new \DomainException("Format invalide pour {$chemin}.");
            }
        } elseif ($type === 'boolean') {
            if (! is_bool($valeur)) {
                throw new \DomainException("Type invalide pour {$chemin} : booléen attendu.");
            }
        } elseif ($type === 'array') {
            if (! is_array($valeur)) {
                throw new \DomainException("Type invalide pour {$chemin} : tableau attendu.");
            }
            if (isset($regle['min_elements']) && count($valeur) < (int) $regle['min_elements']) {
                throw new \DomainException("Nombre d'éléments insuffisant pour {$chemin}.");
            }
            if (isset($regle['schema'])) {
                self::validerTableau($valeur, $regle['schema'], $chemin);
            }
            if (isset($regle['elements'])) {
                foreach ($valeur as $index => $element) {
                    self::validerValeur($element, $regle['elements'], $chemin.'['.$index.']');
                }
            }
        } else {
            throw new \DomainException("Type de schéma inconnu pour {$chemin}.");
        }

        if (isset($regle['valeurs']) && ! in_array($valeur, $regle['valeurs'], true)) {
            throw new \DomainException("Valeur non autorisée pour {$chemin}.");
        }
    }

    /** @param array<string, mixed> $parametres */
    private static function validerReglesCommunes(array $parametres): void
    {
        $delta = (float) $parametres['delta_admissions'];
        $min = (float) $parametres['bornes']['min'];
        $max = (float) $parametres['bornes']['max'];

        if ($min > $max) {
            throw new \DomainException('Bornes incohérentes : min supérieur à max.');
        }

        if ($delta < $min || $delta > $max) {
            throw new \DomainException("Delta d'admissions hors de ses propres bornes.");
        }

        if (isset($parametres['taux'])) {
            $somme = (float) $parametres['taux']['passage']
                + (float) $parametres['taux']['redoublement']
                + (float) $parametres['taux']['abandon'];

            if ($somme > 1.0001) {
                throw new \DomainException('passage + redoublement + abandon > 1 : incohérent.');
            }
        }

        if (isset($parametres['groupes']) && ! isset($parametres['niveau_id'])) {
            throw new \DomainException('Sélection de groupes sans niveau : incompatible avec la cascade.');
        }
    }

    /**
     * SIM-01 = croissance uniquement : le scénario ne doit pas pouvoir
     * être détourné en baisse (SIM-02) ni en gel (SIM-03). Cette règle
     * découle de l'objectif du scénario, pas d'un seuil métier inventé.
     *
     * @param  array<string, mixed>  $parametres
     */
    private static function validerReglesSim01(array $parametres): void
    {
        if ((float) $parametres['delta_admissions'] < 0) {
            throw new \DomainException('SIM-01 (croissance) : delta_admissions négatif refusé — utiliser SIM-02.');
        }

        if ((float) $parametres['bornes']['min'] < 0) {
            throw new \DomainException('SIM-01 (croissance) : borne minimale négative refusée — utiliser SIM-02.');
        }
    }

    /**
     * SIM-02 = baisse effective uniquement. Le zéro reste couvert par
     * SIM-01 (admissions constantes) et le gel explicite par SIM-03.
     * La borne maximale ne doit pas ouvrir une variante de croissance.
     *
     * @param  array<string, mixed>  $parametres
     */
    private static function validerReglesSim02(array $parametres): void
    {
        $delta = (float) $parametres['delta_admissions'];
        $max = (float) $parametres['bornes']['max'];

        if ($delta >= 0) {
            throw new \DomainException('SIM-02 (baisse) : delta_admissions nul ou positif refusé — utiliser SIM-01 pour la croissance/stabilité, SIM-03 pour le gel explicite.');
        }

        if ($max > 0) {
            throw new \DomainException('SIM-02 (baisse) : borne maximale positive refusée — une variante de croissance relève de SIM-01.');
        }
    }

    /**
     * SIM-03 = gel explicite des admissions pendant une durée donnée.
     * La durée du gel doit être comprise entre 1 et l'horizon.
     *
     * @param  array<string, mixed>  $parametres
     */
    private static function validerReglesSim03(array $parametres): void
    {
        $duree = (int) $parametres['duree_gel_annees'];
        $horizon = (int) $parametres['horizon_annees'];

        if ($duree < 1) {
            throw new \DomainException("SIM-03 (gel) : la durée du gel ({$duree}) doit être d'au moins 1 an — un gel nul relève de SIM-01 pour la stabilité.");
        }

        if ($duree > $horizon) {
            throw new \DomainException("SIM-03 (gel) : la durée du gel ({$duree}) ne peut pas dépasser l'horizon de projection ({$horizon}).");
        }
    }

    /**
     * SIM-04 = choc PONCTUEL strictement positif, sur un pas réellement
     * simulé (1..horizon). Un choc nul relève de la trajectoire de fond ;
     * un pas hors horizon ne serait jamais appliqué (résultat trompeur).
     *
     * @param  array<string, mixed>  $parametres
     */
    private static function validerReglesSim04(array $parametres): void
    {
        $pas = (int) $parametres['pas_exceptionnel'];
        $horizon = (int) $parametres['horizon_annees'];
        $admissions = (int) $parametres['admissions_exceptionnelles'];

        if ($pas < 1 || $pas > $horizon) {
            throw new \DomainException("SIM-04 (choc ponctuel) : le pas exceptionnel ({$pas}) doit être compris entre 1 et l'horizon ({$horizon}).");
        }

        if ($admissions < 1) {
            throw new \DomainException("SIM-04 (choc ponctuel) : les admissions exceptionnelles ({$admissions}) doivent être strictement positives — un choc nul relève de la trajectoire de fond.");
        }
    }

    /**
     * SIM-05 = croissance STRICTEMENT supérieure à la cible saisie.
     * Le delta doit dépasser le taux cible ; le scénario ne doit pas
     * pouvoir être détourné en baisse (SIM-02), gel (SIM-03) ou choc
     * (SIM-04). Le schéma n'expose ni duree_gel_annees ni pas_exceptionnel :
     * les clés inconnues sont refusées structurellement.
     *
     * @param  array<string, mixed>  $parametres
     */
    private static function validerReglesSim05(array $parametres): void
    {
        $delta = (float) $parametres['delta_admissions'];
        $cible = (float) $parametres['taux_cible_croissance'];

        if ($delta < 0) {
            throw new \DomainException('SIM-05 (croissance) : delta_admissions négatif refusé — utiliser SIM-02.');
        }

        if ((float) $parametres['bornes']['min'] < 0) {
            throw new \DomainException('SIM-05 (croissance) : borne minimale négative refusée — utiliser SIM-02.');
        }

        if ($delta <= $cible) {
            throw new \DomainException("SIM-05 (croissance supérieure à la cible) : delta_admissions ({$delta}) doit être strictement supérieur au taux cible ({$cible}).");
        }
    }

    /**
     * SIM-06 = creux strictement positif et retour observable dans
     * l'horizon. Le trio est requis par le schéma (indissociable), la
     * profondeur maximale (1) et les durées minimales (1) y sont bornées :
     * la règle de domaine vérifie ce que le schéma ne peut pas exprimer
     * (creux nul refusé, somme des phases dans l'horizon).
     *
     * @param  array<string, mixed>  $parametres
     */
    private static function validerReglesSim06(array $parametres): void
    {
        $profondeur = (float) $parametres['profondeur_baisse'];
        $dureeBaisse = (int) $parametres['duree_baisse_annees'];
        $dureeRecuperation = (int) $parametres['duree_recuperation_annees'];
        $horizon = (int) $parametres['horizon_annees'];

        if ($profondeur <= 0.0) {
            throw new \DomainException('SIM-06 (récupération) : profondeur_baisse doit être strictement positive — un creux nul relève de la trajectoire de fond.');
        }

        if ($dureeBaisse + $dureeRecuperation > $horizon) {
            throw new \DomainException("SIM-06 (récupération) : la baisse ({$dureeBaisse}) + la récupération ({$dureeRecuperation}) dépassent l'horizon ({$horizon}) — le retour à la référence doit être observable dans l'horizon.");
        }
    }

    /**
     * SIM-07 = hausse STRICTE du passage déclarée, à taux complets.
     * Le schéma exige le jeu amélioré complet et l'invariant de somme est
     * vérifié ici pour le jeu amélioré (le jeu de référence est couvert par
     * validerReglesCommunes). Aucune compensation n'est imposée par le
     * catalogue : elle appartient au scénario.
     *
     * @param  array<string, mixed>  $parametres
     */
    private static function validerReglesSim07(array $parametres): void
    {
        $ameliore = $parametres['taux_ameliore'];
        $somme = (float) $ameliore['passage']
            + (float) $ameliore['redoublement']
            + (float) $ameliore['abandon'];

        if ($somme > 1.0001) {
            throw new \DomainException('SIM-07 (réussite) : passage + redoublement + abandon (amélioré) > 1 : incohérent.');
        }

        if ((float) $ameliore['passage'] <= (float) $parametres['taux']['passage']) {
            throw new \DomainException('SIM-07 (réussite) : taux_ameliore.passage doit être strictement supérieur au passage de référence.');
        }
    }

    /**
     * SIM-08 = baisse STRICTE du passage déclarée, à taux complets.
     * Miroir de SIM-07 : la somme du jeu dégradé est vérifiée ici (le jeu
     * de référence est couvert par validerReglesCommunes) et aucune
     * compensation n'est imposée — elle appartient au scénario.
     *
     * @param  array<string, mixed>  $parametres
     */
    private static function validerReglesSim08(array $parametres): void
    {
        $degrade = $parametres['taux_degrade'];
        $somme = (float) $degrade['passage']
            + (float) $degrade['redoublement']
            + (float) $degrade['abandon'];

        if ($somme > 1.0001) {
            throw new \DomainException('SIM-08 (réussite) : passage + redoublement + abandon (dégradé) > 1 : incohérent.');
        }

        if ((float) $degrade['passage'] >= (float) $parametres['taux']['passage']) {
            throw new \DomainException('SIM-08 (réussite) : taux_degrade.passage doit être strictement inférieur au passage de référence.');
        }
    }

    /**
     * SIM-09 = hausse STRICTE du redoublement déclarée, à taux complets.
     * La somme du jeu modifié est vérifiée ici (le jeu de référence est
     * couvert par validerReglesCommunes) ; aucune compensation n'est
     * imposée — l'origine de la hausse appartient au scénario.
     *
     * @param  array<string, mixed>  $parametres
     */
    private static function validerReglesSim09(array $parametres): void
    {
        $modifie = $parametres['taux_modifie'];
        $somme = (float) $modifie['passage']
            + (float) $modifie['redoublement']
            + (float) $modifie['abandon'];

        if ($somme > 1.0001) {
            throw new \DomainException('SIM-09 (réussite) : passage + redoublement + abandon (modifié) > 1 : incohérent.');
        }

        if ((float) $modifie['redoublement'] <= (float) $parametres['taux']['redoublement']) {
            throw new \DomainException('SIM-09 (réussite) : taux_modifie.redoublement doit être strictement supérieur au redoublement de référence.');
        }
    }

    /**
     * SIM-10 = baisse STRICTE du redoublement déclarée, à taux complets.
     * La somme du jeu modifié est vérifiée ici (le jeu de référence est
     * couvert par validerReglesCommunes) ; aucune compensation n'est
     * imposée — l'origine de la baisse appartient au scénario.
     *
     * @param  array<string, mixed>  $parametres
     */
    private static function validerReglesSim10(array $parametres): void
    {
        $modifie = $parametres['taux_modifie'];
        $somme = (float) $modifie['passage']
            + (float) $modifie['redoublement']
            + (float) $modifie['abandon'];

        if ($somme > 1.0001) {
            throw new \DomainException('SIM-10 (réussite) : passage + redoublement + abandon (modifié) > 1 : incohérent.');
        }

        if ((float) $modifie['redoublement'] >= (float) $parametres['taux']['redoublement']) {
            throw new \DomainException('SIM-10 (réussite) : taux_modifie.redoublement doit être strictement inférieur au redoublement de référence.');
        }
    }

    /**
     * SIM-11 = hausse STRICTE de l'abandon déclarée, à taux complets.
     * La somme du jeu modifié est vérifiée ici (le jeu de référence est
     * couvert par validerReglesCommunes) ; aucune compensation n'est
     * imposée — l'origine de la hausse appartient au scénario.
     *
     * @param  array<string, mixed>  $parametres
     */
    private static function validerReglesSim11(array $parametres): void
    {
        $modifie = $parametres['taux_modifie'];
        $somme = (float) $modifie['passage']
            + (float) $modifie['redoublement']
            + (float) $modifie['abandon'];

        if ($somme > 1.0001) {
            throw new \DomainException('SIM-11 (réussite) : passage + redoublement + abandon (modifié) > 1 : incohérent.');
        }

        if ((float) $modifie['abandon'] <= (float) $parametres['taux']['abandon']) {
            throw new \DomainException('SIM-11 (réussite) : taux_modifie.abandon doit être strictement supérieur à l\'abandon de référence.');
        }
    }

    /**
     * SIM-12 = baisse STRICTE de l'abandon déclarée, à taux complets.
     * La somme du jeu modifié est vérifiée ici (le jeu de référence est
     * couvert par validerReglesCommunes) ; aucune compensation n'est
     * imposée — l'origine de la baisse appartient au scénario.
     *
     * @param  array<string, mixed>  $parametres
     */
    private static function validerReglesSim12(array $parametres): void
    {
        $modifie = $parametres['taux_modifie'];
        $somme = (float) $modifie['passage']
            + (float) $modifie['redoublement']
            + (float) $modifie['abandon'];

        if ($somme > 1.0001) {
            throw new \DomainException('SIM-12 (réussite) : passage + redoublement + abandon (modifié) > 1 : incohérent.');
        }

        if ((float) $modifie['abandon'] >= (float) $parametres['taux']['abandon']) {
            throw new \DomainException('SIM-12 (réussite) : taux_modifie.abandon doit être strictement inférieur à l\'abandon de référence.');
        }
    }

    /**
     * SIM-13 = crise académique combinée. Règles de domaine (catalogue) :
     *   1. la paire focale (axe, sens) doit être une DÉGRADATION :
     *      passage/baisse, redoublement/hausse ou abandon/hausse ;
     *   2. AUCUNE amélioration : passage_modifie ≤ passage_ref,
     *      redoublement_modifie ≥ redoublement_ref, abandon_modifie ≥
     *      abandon_ref ;
     *   3. au moins DEUX axes strictement dégradés — l'axe focal strict
     *      (contrat du descripteur) plus au moins un autre ;
     *   4. somme du jeu modifié ≤ 1.0001 (comme SIM-10/11/12).
     * Une dégradation isolée relève de SIM-08 (passage), SIM-09
     * (redoublement) ou SIM-11 (abandon).
     *
     * @param  array<string, mixed>  $parametres
     */
    private static function validerReglesSim13(array $parametres): void
    {
        $modifie = $parametres['taux_modifie'];
        $reference = $parametres['taux'];

        $somme = (float) $modifie['passage']
            + (float) $modifie['redoublement']
            + (float) $modifie['abandon'];

        if ($somme > 1.0001) {
            throw new \DomainException('SIM-13 (crise combinée) : passage + redoublement + abandon (modifié) > 1 : incohérent.');
        }

        $axe = (string) $parametres['axe_variation'];
        $sens = (string) $parametres['sens_variation'];
        $pairesDegradantes = ['passage' => 'baisse', 'redoublement' => 'hausse', 'abandon' => 'hausse'];

        if (($pairesDegradantes[$axe] ?? null) !== $sens) {
            throw new \DomainException("SIM-13 (crise combinée) : la paire focale {$axe}/{$sens} n'est pas une dégradation — attendu passage-baisse, redoublement-hausse ou abandon-hausse.");
        }

        $passageStrictementBas = (float) $modifie['passage'] < (float) $reference['passage'];
        $redoublementStrictementHaut = (float) $modifie['redoublement'] > (float) $reference['redoublement'];
        $abandonStrictementHaut = (float) $modifie['abandon'] > (float) $reference['abandon'];

        if ((float) $modifie['passage'] > (float) $reference['passage']) {
            throw new \DomainException('SIM-13 (crise combinée) : passage_modifie supérieur au passage de référence — aucune amélioration admise.');
        }
        if ((float) $modifie['redoublement'] < (float) $reference['redoublement']) {
            throw new \DomainException('SIM-13 (crise combinée) : redoublement_modifie inférieur au redoublement de référence — aucune amélioration admise.');
        }
        if ((float) $modifie['abandon'] < (float) $reference['abandon']) {
            throw new \DomainException('SIM-13 (crise combinée) : abandon_modifie inférieur à l\'abandon de référence — aucune amélioration admise.');
        }

        $focalStrict = match ($axe) {
            'passage' => $passageStrictementBas,
            'redoublement' => $redoublementStrictementHaut,
            'abandon' => $abandonStrictementHaut,
        };

        if (! $focalStrict) {
            throw new \DomainException("SIM-13 (crise combinée) : l'axe focal {$axe} doit se dégrader STRICTEMENT (contrat du descripteur).");
        }

        $strictes = (int) $passageStrictementBas + (int) $redoublementStrictementHaut + (int) $abandonStrictementHaut;

        if ($strictes < 2) {
            throw new \DomainException('SIM-13 (crise combinée) : au moins deux axes doivent se dégrader strictement — une dégradation isolée relève de SIM-08, SIM-09 ou SIM-11.');
        }
    }

    private static function canoniser(mixed $valeur): mixed
    {
        if (! is_array($valeur)) {
            return $valeur;
        }

        if (array_is_list($valeur)) {
            return array_map([self::class, 'canoniser'], $valeur);
        }

        ksort($valeur);
        foreach ($valeur as $cle => $element) {
            $valeur[$cle] = self::canoniser($element);
        }

        return $valeur;
    }
}
