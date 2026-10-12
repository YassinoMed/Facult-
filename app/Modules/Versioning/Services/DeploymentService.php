<?php

namespace App\Modules\Versioning\Services;

use App\Models\User;
use App\Modules\Versioning\Models\AuditVersioning;
use App\Modules\Versioning\Models\Deployment;
use App\Modules\Versioning\Models\Release;
use App\Modules\Versioning\Models\VersionComponent;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Moteur d'orchestration des déploiements et rollbacks locaux.
 *
 * Responsabilités :
 * - Verrouillage optimiste anti-concurrence
 * - Contrôles de prérequis bloquants
 * - Sauvegarde de secours via `sauvegarde:creer`
 * - Exécution sécurisée des migrations
 * - Mise à jour atomique des versions et composants
 * - Génération et archivage de manifest
 * - Journalisation complète et notifications aux administrateurs
 */
class DeploymentService
{
    public function __construct(
        private readonly VersioningService $versioning,
        private readonly ReleaseService $releaseService,
        private readonly CompatibilityService $compatibility,
        private readonly ManifestService $manifestService,
        private readonly AuditVersioningService $audit,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Exécute le déploiement local d'une release préparée ou approuvée.
     *
     * @param  array{sans_backup?: bool, forcer?: bool, code_confirmation?: string}  $options
     *
     * @throws \Throwable
     */
    public function deployer(Release $release, ?int $utilisateurId = null, array $options = []): Deployment
    {
        $debut = microtime(true);

        // 1. Verrouillage de la release (anti-concurrence)
        $this->releaseService->verrouiller($release);

        // 2. Contrôle de prérequis
        $verif = $this->compatibility->verifierPreDeploiement($release);
        if (! $verif['autorise'] && empty($options['forcer'])) {
            $this->releaseService->deverrouiller($release);
            throw new \InvalidArgumentException(
                'Déploiement refusé par le contrôle de compatibilité : '.implode(', ', $verif['bloquants'])
            );
        }

        // 3. Transition de statut vers EN_DEPLOIEMENT
        $this->releaseService->transiter($release, Release::STATUT_EN_DEPLOIEMENT, $utilisateurId, [
            'mode' => 'local',
        ]);

        // 4. Création de l'enregistrement de déploiement
        $deploiement = Deployment::create([
            'release_id' => $release->id,
            'type' => Deployment::TYPE_DEPLOY,
            'environnement' => $release->environnement,
            'statut' => Deployment::STATUT_EN_COURS,
            'started_at' => now(),
            'deployed_by' => $utilisateurId,
            'meta' => [
                'options' => $options,
                'avertissements' => $verif['avertissements'],
            ],
            'created_at' => now(),
        ]);

        $this->audit->tracer(
            AuditVersioning::ACTION_DEPLOYMENT_STARTED,
            'deployment',
            $deploiement->id,
            null,
            ['release' => $release->version],
            $release->environnement,
        );

        $backupPath = null;

        try {
            // 5. Sauvegarde de sécurité préalable (si non désactivée)
            if (empty($options['sans_backup'])) {
                $backupPath = $this->creerSauvegardePrealable($release);
                $deploiement->update(['backup_path' => $backupPath]);
            }

            // 6. Exécution des migrations si des migrations sont en attente
            $migrationsAvant = $this->versioning->etatMigrations();
            if ($migrationsAvant['pending'] > 0) {
                Artisan::call('migrate', ['--force' => true]);
            }
            $migrationsApres = $this->versioning->etatMigrations();

            // 7. Génération et stockage du manifest final avec l'état réel du schéma post-migrations
            $schemaReel = $this->versioning->dbSchema();
            $manifest = $this->manifestService->generer([
                'version' => $release->version,
                'app_version' => $release->app_version,
                'db_release' => $release->db_release ?? $release->db_version,
                'db_version' => $release->db_release ?? $release->db_version,
                'db_schema' => $schemaReel,
                'dev_version' => $release->dev_version,
                'prod_version' => $release->prod_version,
                'build_number' => $release->build_number,
                'notes' => $release->notes,
            ]);
            $manifestChemin = $this->manifestService->stocker($manifest, $release->version);

            // 8. Mise à jour atomique des composants et de la release
            DB::transaction(function () use ($release, $utilisateurId, $manifest, $schemaReel) {
                // Démarquer l'ancienne release déployée
                Release::where('statut', Release::STATUT_DEPLOYEE)
                    ->where('id', '!=', $release->id)
                    ->update(['statut' => Release::STATUT_ARCHIVEE]);

                // Activer la nouvelle release
                $release->statut = Release::STATUT_DEPLOYEE;
                $release->deployed_by = $utilisateurId;
                $release->deployed_at = now();
                $release->db_schema = $schemaReel;
                $release->manifest_hash = $manifest['manifest_hash'];
                $release->compatibility_hash = $this->compatibility->calculerHashCompatibilite(
                    $release->app_version,
                    $release->db_release ?? $release->db_version
                );
                $release->locked_at = null;
                $release->lock_key = null;
                $release->save();

                // Synchroniser les composants de version
                $dbReleaseVal = $release->db_release ?? $release->db_version;
                $this->versioning->mettreAJourComposant(VersionComponent::COMPOSANT_APP, $release->app_version, $release->id, $utilisateurId);
                $this->versioning->mettreAJourComposant(VersionComponent::COMPOSANT_DB, $dbReleaseVal, $release->id, $utilisateurId);
                if ($release->dev_version) {
                    $this->versioning->mettreAJourComposant(VersionComponent::COMPOSANT_DEV, $release->dev_version, $release->id, $utilisateurId);
                }
                if ($release->prod_version) {
                    $this->versioning->mettreAJourComposant(VersionComponent::COMPOSANT_PROD, $release->prod_version, $release->id, $utilisateurId);
                }
            });

            // 9. Clôture avec succès
            $duree = (int) round(microtime(true) - $debut);
            $deploiement->update([
                'statut' => Deployment::STATUT_REUSSI,
                'finished_at' => now(),
                'duree_secondes' => $duree,
                'meta' => array_merge($deploiement->meta ?? [], [
                    'manifest' => $manifestChemin,
                    'migrations_jouees' => $migrationsApres['applied'] - $migrationsAvant['applied'],
                ]),
            ]);

            $this->audit->tracer(
                AuditVersioning::ACTION_DEPLOYMENT_SUCCEEDED,
                'deployment',
                $deploiement->id,
                null,
                ['duree' => $duree, 'version' => $release->version],
                $release->environnement,
            );

            // Notifier les super-admins
            $this->notifierAdmins(
                'deploiement_reussi',
                "Release {$release->version} déployée avec succès",
                "Le déploiement de la release {$release->version} s'est terminé avec succès en {$duree}s sur l'environnement {$release->environnement}."
            );

            return $deploiement;
        } catch (\Throwable $e) {
            // Gestion de l'échec
            $duree = (int) round(microtime(true) - $debut);
            $deploiement->update([
                'statut' => Deployment::STATUT_ECHOUE,
                'finished_at' => now(),
                'duree_secondes' => $duree,
                'erreur' => $e->getMessage(),
            ]);

            $release->statut = Release::STATUT_ECHOUEE;
            $release->locked_at = null;
            $release->lock_key = null;
            $release->save();

            $this->audit->tracer(
                AuditVersioning::ACTION_DEPLOYMENT_FAILED,
                'deployment',
                $deploiement->id,
                null,
                ['erreur' => $e->getMessage(), 'version' => $release->version],
                $release->environnement,
            );

            $this->notifierAdmins(
                'deploiement_echoue',
                "Échec du déploiement de la release {$release->version}",
                "Le déploiement a échoué avec l'erreur : ".mb_substr($e->getMessage(), 0, 200)
            );

            throw $e;
        }
    }

    /**
     * Planifie et analyse la possibilité d'un rollback vers une release antérieure.
     * (§22 & §23 : plan de rollback, vérification des migrations destructives).
     *
     * @return array{
     *     possible: bool,
     *     release_actuelle: Release,
     *     release_cible: Release,
     *     migrations_a_annuler: array<int, string>,
     *     migrations_destructives: array<int, string>,
     *     avertissements: array<int, string>,
     *     bloquants: array<int, string>,
     *     plan: array<int, string>
     * }
     */
    public function planifierRollback(Release $releaseCible): array
    {
        $actuelle = Release::where('statut', Release::STATUT_DEPLOYEE)
            ->latest('deployed_at')
            ->first();

        if ($actuelle === null) {
            throw new \InvalidArgumentException('Aucune release actuellement déployée pour initier un rollback.');
        }

        if ($actuelle->id === $releaseCible->id) {
            throw new \InvalidArgumentException('La release cible est identique à la release actuellement déployée.');
        }

        $bloquants = [];
        $avertissements = [];
        $plan = [];

        // 1. Compatibilité sémantique
        $plan[] = "Régression applicative : {$actuelle->app_version} -> {$releaseCible->app_version}";
        $plan[] = "Régression schéma BD : {$actuelle->db_version} -> {$releaseCible->db_version}";

        // 2. Analyse des migrations
        $analyseMigrations = $this->analyserMigrationsRollback($actuelle, $releaseCible);
        $migrationsAAnnuler = $analyseMigrations['a_annuler'];
        $migrationsDestructives = $analyseMigrations['destructives'];

        if (count($migrationsDestructives) > 0) {
            $avertissements[] = 'Attention : '.count($migrationsDestructives).' migration(s) contiennent des opérations de suppression (dropTable/dropColumn).';
        }

        if (count($analyseMigrations['sans_down']) > 0) {
            $bloquants[] = 'Rollback DB bloqué : les migrations suivantes ne possèdent pas de méthode down() réversible : '.implode(', ', $analyseMigrations['sans_down']);
        }

        // 3. Déroulé des étapes
        $plan[] = "Création d'un backup local de sécurité pré-rollback";
        if (count($migrationsAAnnuler) > 0) {
            $plan[] = 'Rollback de '.count($migrationsAAnnuler).' lot(s) de migration DB';
        }
        $plan[] = "Restauration des composants de version ({$releaseCible->version})";
        $plan[] = "Restauration du manifest et audit de l'opération";

        $possible = count($bloquants) === 0;

        return [
            'possible' => $possible,
            'release_actuelle' => $actuelle,
            'release_cible' => $releaseCible,
            'migrations_a_annuler' => $migrationsAAnnuler,
            'migrations_destructives' => $migrationsDestructives,
            'avertissements' => $avertissements,
            'bloquants' => $bloquants,
            'plan' => $plan,
        ];
    }

    /**
     * Exécute le rollback vers une release antérieure.
     *
     * @param  array{code_confirmation?: string, rollback_db?: bool}  $options
     *
     * @throws \Throwable
     */
    public function rollback(Release $releaseCible, ?int $utilisateurId = null, array $options = []): Deployment
    {
        $debut = microtime(true);

        $plan = $this->planifierRollback($releaseCible);
        if (! $plan['possible']) {
            throw new \InvalidArgumentException(
                'Rollback impossible : '.implode(', ', $plan['bloquants'])
            );
        }

        $actuelle = $plan['release_actuelle'];

        // 1. Verrouiller la release actuelle
        $this->releaseService->verrouiller($actuelle);

        // 2. Marquer EN_ROLLBACK
        $this->releaseService->transiter($actuelle, Release::STATUT_EN_ROLLBACK, $utilisateurId, [
            'cible_id' => $releaseCible->id,
            'cible_version' => $releaseCible->version,
        ]);

        $deploiement = Deployment::create([
            'release_id' => $releaseCible->id,
            'type' => Deployment::TYPE_ROLLBACK,
            'environnement' => $releaseCible->environnement,
            'statut' => Deployment::STATUT_EN_COURS,
            'started_at' => now(),
            'deployed_by' => $utilisateurId,
            'meta' => [
                'release_origine' => $actuelle->version,
                'release_cible' => $releaseCible->version,
                'options' => $options,
            ],
            'created_at' => now(),
        ]);

        $this->audit->tracer(
            AuditVersioning::ACTION_ROLLBACK_STARTED,
            'deployment',
            $deploiement->id,
            ['actuelle' => $actuelle->version],
            ['cible' => $releaseCible->version],
            $actuelle->environnement,
        );

        try {
            // 3. Backup de sécurité avant toute manipulation
            $backupPath = $this->creerSauvegardePrealable($actuelle);
            $deploiement->update(['backup_path' => $backupPath]);

            // 4. Rollback de la base si demandé et possible
            if (! empty($options['rollback_db']) && count($plan['migrations_a_annuler']) > 0) {
                $steps = count($plan['migrations_a_annuler']);
                Artisan::call('migrate:rollback', [
                    '--step' => $steps,
                    '--force' => true,
                ]);
            }

            // 5. Restauration atomique de l'état
            DB::transaction(function () use ($actuelle, $releaseCible, $utilisateurId) {
                // Ancienne marquée ROLLBACKEE
                $actuelle->statut = Release::STATUT_ROLLBACKEE;
                $actuelle->rolled_back_at = now();
                $actuelle->locked_at = null;
                $actuelle->lock_key = null;
                $actuelle->save();

                // Cible réactivée comme DEPLOYEE
                $releaseCible->statut = Release::STATUT_DEPLOYEE;
                $releaseCible->deployed_by = $utilisateurId;
                $releaseCible->deployed_at = now();
                $releaseCible->save();

                // Synchroniser les composants de version
                $dbReleaseCibleVal = $releaseCible->db_release ?? $releaseCible->db_version;
                $this->versioning->mettreAJourComposant(VersionComponent::COMPOSANT_APP, $releaseCible->app_version, $releaseCible->id, $utilisateurId);
                $this->versioning->mettreAJourComposant(VersionComponent::COMPOSANT_DB, $dbReleaseCibleVal, $releaseCible->id, $utilisateurId);
                if ($releaseCible->dev_version) {
                    $this->versioning->mettreAJourComposant(VersionComponent::COMPOSANT_DEV, $releaseCible->dev_version, $releaseCible->id, $utilisateurId);
                }
                if ($releaseCible->prod_version) {
                    $this->versioning->mettreAJourComposant(VersionComponent::COMPOSANT_PROD, $releaseCible->prod_version, $releaseCible->id, $utilisateurId);
                }
            });

            $duree = (int) round(microtime(true) - $debut);
            $deploiement->update([
                'statut' => Deployment::STATUT_REUSSI,
                'finished_at' => now(),
                'duree_secondes' => $duree,
            ]);

            $this->audit->tracer(
                AuditVersioning::ACTION_ROLLBACK_SUCCEEDED,
                'deployment',
                $deploiement->id,
                ['origine' => $actuelle->version],
                ['cible' => $releaseCible->version, 'duree' => $duree],
                $releaseCible->environnement,
            );

            $this->notifierAdmins(
                'rollback_reussi',
                "Rollback vers {$releaseCible->version} effectué",
                "Le système a été restauré avec succès à la release {$releaseCible->version} en {$duree}s."
            );

            return $deploiement;
        } catch (\Throwable $e) {
            $duree = (int) round(microtime(true) - $debut);
            $deploiement->update([
                'statut' => Deployment::STATUT_ECHOUE,
                'finished_at' => now(),
                'duree_secondes' => $duree,
                'erreur' => $e->getMessage(),
            ]);

            $actuelle->locked_at = null;
            $actuelle->lock_key = null;
            $actuelle->save();

            $this->audit->tracer(
                AuditVersioning::ACTION_ROLLBACK_FAILED,
                'deployment',
                $deploiement->id,
                null,
                ['erreur' => $e->getMessage()],
                $actuelle->environnement,
            );

            $this->notifierAdmins(
                'rollback_echoue',
                "Échec du rollback vers {$releaseCible->version}",
                "Le rollback a échoué avec l'erreur : ".mb_substr($e->getMessage(), 0, 200)
            );

            throw $e;
        }
    }

    /**
     * Analyse fine des fichiers de migration pour évaluer la sécurité d'un rollback.
     *
     * @return array{a_annuler: array<int, string>, destructives: array<int, string>, sans_down: array<int, string>}
     */
    private function analyserMigrationsRollback(Release $actuelle, Release $cible): array
    {
        $dossier = database_path('migrations');
        $fichiers = [];

        if (is_dir($dossier)) {
            foreach (scandir($dossier) as $f) {
                if (str_ends_with($f, '.php')) {
                    $fichiers[] = pathinfo($f, PATHINFO_FILENAME);
                }
            }
        }
        sort($fichiers);

        // Migrations appliquées en base
        try {
            $appliquees = DB::table('migrations')->orderBy('id', 'desc')->pluck('migration')->all();
        } catch (\Throwable) {
            $appliquees = [];
        }

        $destructives = [];
        $sansDown = [];

        foreach ($appliquees as $mig) {
            $cheminFichier = $dossier.'/'.$mig.'.php';
            if (File::exists($cheminFichier)) {
                $contenu = File::get($cheminFichier);

                // Détecter présence de la méthode down
                if (! preg_match('/function\s+down\s*\(/i', $contenu)) {
                    $sansDown[] = $mig;
                }

                // Détecter opérations destructives dans down()
                if (preg_match('/dropTable|dropColumn|dropIfExists/i', $contenu)) {
                    $destructives[] = $mig;
                }
            }
        }

        return [
            'a_annuler' => $appliquees,
            'destructives' => $destructives,
            'sans_down' => $sansDown,
        ];
    }

    /**
     * Invoque la commande existante `sauvegarde:creer` avant déploiement.
     */
    private function creerSauvegardePrealable(Release $release): ?string
    {
        $dossierSauvegarde = storage_path('app/'.config('versioning.stockage.racine').'/backups');
        if (! is_dir($dossierSauvegarde)) {
            File::makeDirectory($dossierSauvegarde, 0755, true, true);
        }

        try {
            Artisan::call('sauvegarde:creer', [
                '--dossier' => $dossierSauvegarde,
                '--conserver' => config('versioning.retention.backups', 14),
            ]);

            $this->audit->tracer(
                AuditVersioning::ACTION_BACKUP_CREATED,
                'release',
                $release->id,
                null,
                ['dossier' => $dossierSauvegarde],
                $release->environnement,
            );

            return $dossierSauvegarde;
        } catch (\Throwable $e) {
            // Si la commande sauvegarde échoue (ex: mysqldump absent en dev), on trace l'avertissement
            $this->audit->tracer(
                'backup_skipped_warning',
                'release',
                $release->id,
                null,
                ['raison' => $e->getMessage()],
                $release->environnement,
            );

            return null;
        }
    }

    /**
     * Notifie les administrateurs via le NotificationService existant.
     */
    private function notifierAdmins(string $type, string $titre, string $message): void
    {
        try {
            $admins = User::where('role', 'super_admin')->get();
            foreach ($admins as $admin) {
                $this->notifications->notifierUser(
                    $admin,
                    'system_'.$type,
                    $titre,
                    $message,
                    route('admin.versioning.index'),
                    [],
                    2
                );
            }
        } catch (\Throwable) {
            // Fail-soft : ne jamais bloquer une opération de versioning si une notification échoue
        }
    }
}
