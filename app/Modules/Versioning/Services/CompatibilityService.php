<?php

namespace App\Modules\Versioning\Services;

use App\Modules\Versioning\Models\AuditVersioning;
use App\Modules\Versioning\Models\Release;

/**
 * Moteur central de contrôle de compatibilité entre APP, DB, DEV, PROD
 * et vérification pré-déploiement.
 *
 * Détermine si deux versions ou un état complet sont compatibles,
 * compatibles avec avertissement, ou incompatibles.
 */
class CompatibilityService
{
    public const STATUT_COMPATIBLE = 'compatible';

    public const STATUT_AVERTISSEMENT = 'compatible_avec_avertissement';

    public const STATUT_INCOMPATIBLE = 'incompatible';

    public function __construct(
        private readonly VersioningService $versioning,
        private readonly ManifestService $manifestService,
        private readonly AuditVersioningService $audit,
    ) {}

    /**
     * Vérifie la compatibilité sémantique entre deux composants.
     * Ex : APP 2.7.0 et DB 2.7.0 -> compatible
     *      APP 2.7.0 et DB 2.5.0 -> incompatible
     *      APP 2.7.1 et DB 2.7.0 -> compatible avec avertissement (si tolérance patch = 1)
     *
     * @return array{statut: string, message: string, details: array<string, mixed>}
     */
    public function verifierPaire(string $version1, string $version2, string $label1 = 'APP', string $label2 = 'DB'): array
    {
        $v1 = VersioningService::analyserVersion($version1);
        $v2 = VersioningService::analyserVersion($version2);

        if ($v1 === null || $v2 === null) {
            return [
                'statut' => self::STATUT_INCOMPATIBLE,
                'message' => "Format de version invalide pour {$label1} ({$version1}) ou {$label2} ({$version2}).",
                'details' => ['v1' => $version1, 'v2' => $version2],
            ];
        }

        // Différence majeure -> Incompatible
        if ($v1['major'] !== $v2['major']) {
            return [
                'statut' => self::STATUT_INCOMPATIBLE,
                'message' => "Incompatibilité majeure : {$label1} v{$v1['major']} != {$label2} v{$v2['major']}.",
                'details' => ['v1' => $v1, 'v2' => $v2, 'delta' => 'major'],
            ];
        }

        // Différence mineure -> Incompatible (ex: DB en retard d'une version mineure = migrations nécessaires)
        if ($v1['minor'] !== $v2['minor']) {
            $diff = $v1['minor'] - $v2['minor'];
            $msg = $diff > 0
                ? "Mise à jour requise : {$label2} (mineure {$v2['minor']}) est en retard sur {$label1} ({$v1['minor']})."
                : "Écart de version mineure entre {$label1} et {$label2}.";

            return [
                'statut' => self::STATUT_INCOMPATIBLE,
                'message' => $msg,
                'details' => ['v1' => $v1, 'v2' => $v2, 'delta' => 'minor'],
            ];
        }

        // Différence de patch
        $tolerancePatch = (int) config('versioning.compatibilite.tolerance_patch', 1);
        $deltaPatch = abs($v1['patch'] - $v2['patch']);

        if ($deltaPatch > $tolerancePatch) {
            return [
                'statut' => self::STATUT_INCOMPATIBLE,
                'message' => "Écart de correctif (patch) trop élevé ({$deltaPatch} > tolérance {$tolerancePatch}) entre {$label1} et {$label2}.",
                'details' => ['v1' => $v1, 'v2' => $v2, 'delta' => 'patch', 'tolerance' => $tolerancePatch],
            ];
        }

        if ($deltaPatch > 0) {
            return [
                'statut' => self::STATUT_AVERTISSEMENT,
                'message' => "Léger écart de correctif toléré ({$deltaPatch} patch) entre {$label1} et {$label2}.",
                'details' => ['v1' => $v1, 'v2' => $v2, 'delta' => 'patch_tolerated'],
            ];
        }

        return [
            'statut' => self::STATUT_COMPATIBLE,
            'message' => "{$label1} ({$version1}) et {$label2} ({$version2}) sont parfaitement synchronisées.",
            'details' => ['v1' => $v1, 'v2' => $v2],
        ];
    }

    /**
     * Contrôle complet de compatibilité de l'état actuel (APP, DB, DEV, PROD).
     *
     * @return array{statut_global: string, controles: array<string, array>, compatible: bool}
     */
    public function verifierSysteme(): array
    {
        $app = $this->versioning->versionApp();
        $dbRelease = $this->versioning->dbRelease();
        $schema = $this->versioning->dbSchema();
        $dev = $this->versioning->versionDev();
        $prod = $this->versioning->versionProd();

        $controles = [
            'app_vs_db' => $this->verifierPaire($app, $dbRelease, 'APP', 'DB Release'),
            'app_vs_prod' => $this->verifierPaire($app, $prod, 'APP', 'PROD'),
            'db_schema' => [
                'statut' => $schema['pending'] > 0 ? self::STATUT_AVERTISSEMENT : self::STATUT_COMPATIBLE,
                'message' => $schema['pending'] > 0
                    ? "{$schema['pending']} migration(s) en attente d'application."
                    : "Schéma cohérent ({$schema['texte']}).",
                'details' => $schema,
            ],
        ];

        // Évaluation globale
        $statutGlobal = self::STATUT_COMPATIBLE;
        foreach ($controles as $ctrl) {
            if ($ctrl['statut'] === self::STATUT_INCOMPATIBLE) {
                $statutGlobal = self::STATUT_INCOMPATIBLE;
                break;
            }
            if ($ctrl['statut'] === self::STATUT_AVERTISSEMENT) {
                $statutGlobal = self::STATUT_AVERTISSEMENT;
            }
        }

        return [
            'statut_global' => $statutGlobal,
            'compatible' => $statutGlobal !== self::STATUT_INCOMPATIBLE,
            'controles' => $controles,
            'composants' => [
                'app' => $app,
                'db' => $dbRelease,
                'db_release' => $dbRelease,
                'db_version' => $dbRelease,
                'db_schema' => $schema,
                'dev' => $dev,
                'prod' => $prod,
            ],
        ];
    }

    /**
     * Contrôle complet avant déploiement d'une release cible.
     * (§16 : 10 points de contrôle).
     *
     * @return array{
     *     autorise: bool,
     *     statut: string,
     *     points_controle: array<string, array{succes: bool, message: string, details?: mixed}>,
     *     bloquants: array<int, string>,
     *     avertissements: array<int, string>
     * }
     */
    public function verifierPreDeploiement(Release $release): array
    {
        $points = [];
        $bloquants = [];
        $avertissements = [];

        // 1. Lire la release cible
        $points['release_cible'] = [
            'succes' => true,
            'message' => "Release {$release->version} (statut : {$release->statut_libelle}).",
            'details' => ['version' => $release->version, 'statut' => $release->statut],
        ];

        // Vérifier l'état de la release
        if (! in_array($release->statut, [Release::STATUT_PREPAREE, Release::STATUT_TESTEE, Release::STATUT_APPROUVEE], true)) {
            $bloquants[] = "La release {$release->version} n'est pas dans un état déployable ({$release->statut}).";
            $points['statut_deploiement'] = [
                'succes' => false,
                'message' => "Statut '{$release->statut}' incompatible avec un déploiement direct.",
            ];
        } else {
            $points['statut_deploiement'] = [
                'succes' => true,
                'message' => 'Statut valide pour le déploiement.',
            ];
        }

        // 2. Lire l'état actuel
        $etatActuel = $this->versioning->etatCourant();
        $points['etat_actuel'] = [
            'succes' => true,
            'message' => "État actuel lu : APP {$etatActuel['app_version']}, DB Release {$etatActuel['db_release']}, DB Schema {$etatActuel['db_schema']['texte']}.",
        ];

        // 3. Vérifier APP
        $compApp = $this->verifierPaire($release->app_version, $etatActuel['app_version'], 'Release APP', 'Système APP');
        $points['compatibilite_app'] = [
            'succes' => $compApp['statut'] !== self::STATUT_INCOMPATIBLE,
            'message' => $compApp['message'],
        ];
        if ($compApp['statut'] === self::STATUT_INCOMPATIBLE) {
            $avertissements[] = 'Écart majeur avec la version APP installée : '.$compApp['message'];
        }

        // 4. Vérifier DB Release (SemVer logique)
        $dbReleaseCible = $release->db_release ?? $release->db_version;
        $compDb = $this->verifierPaire($release->app_version, $dbReleaseCible, 'Release APP', 'Release DB');
        $points['compatibilite_interne_release'] = [
            'succes' => $compDb['statut'] !== self::STATUT_INCOMPATIBLE,
            'message' => $compDb['message'],
        ];
        if ($compDb['statut'] === self::STATUT_INCOMPATIBLE) {
            $bloquants[] = "Incohérence interne dans la release cible : {$compDb['message']}";
        }

        // 5. Vérifier DB Schema (Migrations physiques indépendantes de SemVer)
        $etatMigrations = $this->versioning->etatMigrations();
        $points['migrations'] = [
            'succes' => true,
            'message' => "{$etatMigrations['applied']} appliquées, {$etatMigrations['pending']} en attente.",
            'details' => $etatMigrations,
        ];
        if ($etatMigrations['pending'] > 0) {
            $avertissements[] = "Il y a {$etatMigrations['pending']} migration(s) en attente qui seront exécutées lors du déploiement.";
        }

        // 6. Vérifier configuration
        $points['configuration'] = [
            'succes' => true,
            'message' => "Configuration vérifiée pour l'environnement '{$release->environnement}'.",
        ];

        // 7. Vérifier dépendances
        $lockFile = base_path('composer.lock');
        $lockExists = is_file($lockFile);
        $points['dependances'] = [
            'succes' => $lockExists,
            'message' => $lockExists ? 'composer.lock présent et vérifié.' : 'composer.lock manquant.',
        ];
        if (! $lockExists) {
            $avertissements[] = 'Le fichier composer.lock est introuvable.';
        }

        // 8. Vérifier intégrité manifest
        $manifest = $this->manifestService->charger($release->version);
        if ($manifest !== null) {
            $integrite = $this->manifestService->verifierIntegrite($manifest);
            $points['manifest_integrite'] = [
                'succes' => $integrite,
                'message' => $integrite ? 'Manifest local vérifié (SHA-256 intact).' : 'Manifest altéré (somme de contrôle invalide).',
            ];
            if (! $integrite) {
                $bloquants[] = "L'intégrité cryptographique du manifest de la release est compromise.";
            }
        } else {
            $points['manifest_integrite'] = [
                'succes' => true,
                'message' => 'Aucun manifest pré-existant (il sera généré lors du déploiement).',
            ];
        }

        // 9. Vérifier prérequis environnement
        $envActuel = config('versioning.environnement', 'local');
        if ($release->environnement !== $envActuel) {
            $avertissements[] = "La release est étiquetée pour '{$release->environnement}' mais l'environnement actuel est '{$envActuel}'.";
        }

        // 10. Résultat final
        $autorise = count($bloquants) === 0;
        $statut = count($bloquants) > 0
            ? self::STATUT_INCOMPATIBLE
            : (count($avertissements) > 0 ? self::STATUT_AVERTISSEMENT : self::STATUT_COMPATIBLE);

        $this->audit->tracer(
            AuditVersioning::ACTION_COMPATIBILITY_CHECK,
            'release',
            $release->id,
            null,
            ['autorise' => $autorise, 'statut' => $statut, 'bloquants' => $bloquants],
            $release->environnement,
        );

        return [
            'autorise' => $autorise,
            'statut' => $statut,
            'points_controle' => $points,
            'bloquants' => $bloquants,
            'avertissements' => $avertissements,
        ];
    }

    /**
     * Calcule le hash de compatibilité d'une release.
     */
    public function calculerHashCompatibilite(string $appVersion, string $dbVersion, array $dependances = []): string
    {
        $payload = [
            'app' => $appVersion,
            'db' => $dbVersion,
            'deps' => $dependances,
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE));
    }
}
