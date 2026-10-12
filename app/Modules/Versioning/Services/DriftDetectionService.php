<?php

namespace App\Modules\Versioning\Services;

use App\Modules\Versioning\Models\AuditVersioning;
use App\Modules\Versioning\Models\Release;

/**
 * Service de détection de dérive (Drift Detection).
 * Compare l'état déclaré dans la release / le manifest actif
 * avec l'état réel actuel du système (code, base de données, config, lock).
 */
class DriftDetectionService
{
    public function __construct(
        private readonly VersioningService $versioning,
        private readonly ManifestService $manifestService,
        private readonly AuditVersioningService $audit,
    ) {}

    /**
     * Analyse l'état complet du système par rapport à la dernière release déployée
     * ou au manifest spécifié.
     *
     * @return array{
     *     drift_detecte: bool,
     *     score_integrite: int,
     *     ecarts: array<string, array{declare: mixed, reel: mixed, message: string, critique: bool}>,
     *     release_reference: ?string,
     *     verifie_le: string
     * }
     */
    public function detecter(?Release $release = null): array
    {
        // 1. Trouver la release de référence (soit passée en paramètre, soit la dernière déployée)
        if ($release === null) {
            $release = Release::where('statut', Release::STATUT_DEPLOYEE)
                ->latest('deployed_at')
                ->first();
        }

        $ecarts = [];

        if ($release === null) {
            // Aucune release déployée : dérive par défaut ou état initial
            return [
                'drift_detecte' => false,
                'score_integrite' => 100,
                'ecarts' => [],
                'release_reference' => null,
                'message' => 'Aucune release déployée enregistrée pour comparaison.',
                'verifie_le' => now()->toIso8601String(),
            ];
        }

        // 2. Charger le manifest de la release
        $manifest = $this->manifestService->charger($release->version);

        // 3. Vérification APP drift
        $appReel = $this->versioning->versionApp();
        if ($release->app_version !== $appReel) {
            $ecarts['app_version'] = [
                'declare' => $release->app_version,
                'reel' => $appReel,
                'message' => "Version APP déclarée ({$release->app_version}) diffère de la version installée ({$appReel}).",
                'critique' => true,
            ];
        }

        // 4. Vérification DB_RELEASE drift (SemVer logique)
        $dbReleaseReel = $this->versioning->dbRelease();
        $dbReleaseDeclare = $release->db_release ?? $release->db_version;
        if ($dbReleaseDeclare !== $dbReleaseReel) {
            $ecarts['db_release'] = [
                'declare' => $dbReleaseDeclare,
                'reel' => $dbReleaseReel,
                'message' => "DB Release déclarée ({$dbReleaseDeclare}) diffère de la version logique active ({$dbReleaseReel}).",
                'critique' => true,
            ];
        }

        // 5. Vérification DB_SCHEMA drift (indépendamment du SemVer)
        $schemaReel = $this->versioning->dbSchema();
        $schemaDeclare = $manifest['db_schema'] ?? ($manifest['migrations'] ?? $release->db_schema);

        if ($schemaReel['pending'] > 0) {
            $ecarts['db_schema_pending'] = [
                'declare' => '0 en attente',
                'reel' => "{$schemaReel['pending']} en attente",
                'message' => "Il y a {$schemaReel['pending']} migration(s) non exécutée(s) en base.",
                'critique' => true,
            ];
        }

        if (is_array($schemaDeclare) && isset($schemaDeclare['applied'])) {
            $declareApplied = (int) $schemaDeclare['applied'];
            $reelApplied = (int) $schemaReel['applied'];

            if ($declareApplied !== $reelApplied) {
                $ecarts['db_schema'] = [
                    'declare' => "{$declareApplied} appliquées",
                    'reel' => "{$reelApplied} appliquées ({$schemaReel['pending']} en attente)",
                    'message' => "Nombre de migrations appliquées différent du manifest ({$declareApplied} vs {$reelApplied}).",
                    'critique' => false,
                ];
            }
        }

        // 6. Vérification Manifest drift (intégrité cryptographique)
        if ($manifest !== null) {
            $integrite = $this->manifestService->verifierIntegrite($manifest);
            if (! $integrite) {
                $ecarts['manifest'] = [
                    'declare' => $release->manifest_hash,
                    'reel' => 'Manifest altéré',
                    'message' => "L'intégrité du manifest de la release est compromise.",
                    'critique' => true,
                ];
            }
        }

        // 7. Vérification Configuration drift
        if ($manifest !== null && isset($manifest['config_hash'])) {
            $configHashReel = $this->hashConfigurationActuelle();
            if (! hash_equals($manifest['config_hash'], $configHashReel)) {
                $ecarts['configuration'] = [
                    'declare' => $manifest['config_hash'],
                    'reel' => $configHashReel,
                    'message' => "L'empreinte de configuration contrôlée a dérivé par rapport au manifest.",
                    'critique' => false,
                ];
            }
        }

        // 8. Vérification Dépendances drift (composer.lock)
        if ($manifest !== null && isset($manifest['dependencies']['lock_hash'])) {
            $lockFile = base_path('composer.lock');
            $lockHashReel = is_file($lockFile) ? hash_file('sha256', $lockFile) : null;

            if ($lockHashReel !== null && ! hash_equals((string) $manifest['dependencies']['lock_hash'], $lockHashReel)) {
                $ecarts['dependencies'] = [
                    'declare' => $manifest['dependencies']['lock_hash'],
                    'reel' => $lockHashReel,
                    'message' => 'Le fichier composer.lock a été modifié après le déploiement de la release.',
                    'critique' => false,
                ];
            }
        }

        // 8. Calcul du score et de l'indicateur
        $driftDetecte = count($ecarts) > 0;
        $nbCritiques = count(array_filter($ecarts, fn ($e) => $e['critique']));
        $nbMineurs = count($ecarts) - $nbCritiques;

        $score = max(0, 100 - ($nbCritiques * 35) - ($nbMineurs * 15));

        if ($driftDetecte) {
            $this->audit->tracer(
                AuditVersioning::ACTION_DRIFT_DETECTED,
                'release',
                $release->id,
                ['version' => $release->version],
                ['score' => $score, 'ecarts' => array_keys($ecarts)],
                $release->environnement,
            );
        }

        return [
            'drift_detecte' => $driftDetecte,
            'score_integrite' => $score,
            'ecarts' => $ecarts,
            'release_reference' => $release->version,
            'verifie_le' => now()->toIso8601String(),
        ];
    }

    /**
     * Recalcule le hash de configuration contrôlée pour comparaison.
     */
    private function hashConfigurationActuelle(): string
    {
        $clesVerifiees = [
            'app.env', 'app.debug', 'app.locale',
            'database.default',
            'cache.default',
            'queue.default',
            'session.driver',
        ];

        $donnees = [];
        foreach ($clesVerifiees as $cle) {
            $donnees[$cle] = config($cle);
        }

        return hash('sha256', json_encode($donnees, JSON_UNESCAPED_UNICODE));
    }
}
