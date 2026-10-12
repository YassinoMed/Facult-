<?php

namespace App\Modules\Versioning\Services;

use App\Modules\Versioning\Models\AuditVersioning;
use App\Modules\Versioning\Models\VersionComponent;
use Illuminate\Support\Facades\DB;

/**
 * Service central de versionnage — versions courantes, build number,
 * état global. Point d'entrée principal pour la consultation.
 */
class VersioningService
{
    /**
     * Version APP courante (source : config + composant DB).
     */
    public function versionApp(): string
    {
        return $this->versionComposant(VersionComponent::COMPOSANT_APP)
            ?? config('versioning.app_version', '1.0.0');
    }

    /**
     * Version logique DB (DB_RELEASE) — SemVer de compatibilité.
     * Indépendante du compteur de migrations physiques.
     */
    public function dbRelease(): string
    {
        return $this->versionComposant(VersionComponent::COMPOSANT_DB)
            ?? config('versioning.db_release', config('versioning.app_version', '1.0.0'));
    }

    /**
     * Version DB courante (alias rétrocompatible vers dbRelease).
     */
    public function versionDb(): string
    {
        return $this->dbRelease();
    }

    /**
     * Version DEV courante.
     */
    public function versionDev(): string
    {
        return $this->versionComposant(VersionComponent::COMPOSANT_DEV)
            ?? $this->versionApp().'-dev.1';
    }

    /**
     * Version PROD courante.
     */
    public function versionProd(): string
    {
        return $this->versionComposant(VersionComponent::COMPOSANT_PROD)
            ?? $this->versionApp();
    }

    /**
     * Build number courant.
     */
    public function buildCourant(): string
    {
        $prefixe = now()->format(config('versioning.build.format', 'Y.m.d'));

        // Compteur journalier basé sur les releases du jour
        try {
            $compteur = DB::table('releases')
                ->where('build_number', 'like', $prefixe.'%')
                ->count();
        } catch (\Throwable) {
            $compteur = 0;
        }

        return $prefixe.'.'.str_pad((string) ($compteur + 1), 3, '0', STR_PAD_LEFT);
    }

    /**
     * Environnement logique courant.
     */
    public function environnement(): string
    {
        return config('versioning.environnement', 'local');
    }

    /**
     * Résumé complet de l'état courant (pour le dashboard/health check).
     *
     * @return array<string, mixed>
     */
    public function etatCourant(): array
    {
        return [
            'app_version' => $this->versionApp(),
            'db_release' => $this->dbRelease(),
            'db_version' => $this->dbRelease(), // alias rétrocompatible
            'db_schema' => $this->dbSchema(),
            'dev_version' => $this->versionDev(),
            'prod_version' => $this->versionProd(),
            'build' => $this->buildCourant(),
            'environnement' => $this->environnement(),
            'framework' => [
                'laravel' => app()->version(),
                'php' => PHP_VERSION,
            ],
            'migrations' => $this->etatMigrations(),
        ];
    }

    /**
     * Met à jour un composant de version.
     */
    public function mettreAJourComposant(
        string $composant,
        string $version,
        ?int $releaseId = null,
        ?int $utilisateurId = null,
    ): VersionComponent {
        $env = $this->environnement();

        $ancien = VersionComponent::where('composant', $composant)
            ->where('environnement', $env)
            ->first();

        $ancienneVersion = $ancien?->version;

        $composantModel = VersionComponent::updateOrCreate(
            ['composant' => $composant, 'environnement' => $env],
            [
                'version' => $version,
                'release_id' => $releaseId,
                'updated_by' => $utilisateurId ?? auth()->id(),
            ],
        );

        // Audit de la mise à jour
        if ($ancienneVersion !== $version) {
            app(AuditVersioningService::class)->tracer(
                AuditVersioning::ACTION_VERSION_UPDATED,
                'composant',
                $composantModel->id,
                ['composant' => $composant, 'version' => $ancienneVersion],
                ['composant' => $composant, 'version' => $version],
            );
        }

        return $composantModel;
    }

    /**
     * Lit la version d'un composant depuis la BD.
     */
    public function versionComposant(string $composant): ?string
    {
        try {
            return VersionComponent::where('composant', $composant)
                ->where('environnement', $this->environnement())
                ->value('version');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * État technique réel du schéma de base de données (indépendant de SemVer).
     *
     * @return array{applied: int, pending: int, last: ?string, hash: string, texte: string}
     */
    public function dbSchema(): array
    {
        $etatMigrations = $this->etatMigrations();
        $derniere = $this->derniereMigration();
        $hash = $this->hashSchema();

        return [
            'applied' => $etatMigrations['applied'],
            'pending' => $etatMigrations['pending'],
            'last' => $derniere,
            'hash' => $hash,
            'texte' => "{$etatMigrations['applied']} migrations appliquées",
        ];
    }

    /**
     * Nom du dernier fichier de migration appliqué.
     */
    public function derniereMigration(): ?string
    {
        try {
            return DB::table('migrations')
                ->orderByDesc('id')
                ->value('migration');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Empreinte SHA-256 déterministe de la liste des migrations appliquées.
     */
    public function hashSchema(): string
    {
        try {
            $appliquees = DB::table('migrations')->orderBy('id')->pluck('migration')->all();

            return hash('sha256', implode('|', $appliquees));
        } catch (\Throwable) {
            return hash('sha256', 'empty');
        }
    }

    /**
     * @return array{applied: int, pending: int}
     */
    public function etatMigrations(): array
    {
        try {
            $appliquees = DB::table('migrations')->count();
            $fichiers = count(glob(database_path('migrations/*.php')) ?: []);

            return [
                'applied' => $appliquees,
                'pending' => max(0, $fichiers - $appliquees),
            ];
        } catch (\Throwable) {
            return ['applied' => 0, 'pending' => 0];
        }
    }

    /**
     * Parse une version sémantique.
     *
     * @return array{major: int, minor: int, patch: int, pre: string|null}|null
     */
    public static function parseVersion(string $version): ?array
    {
        if (preg_match('/^(\d+)\.(\d+)\.(\d+)(?:-(.+))?$/', trim($version), $m)) {
            return [
                'major' => (int) $m[1],
                'minor' => (int) $m[2],
                'patch' => (int) $m[3],
                'pre' => $m[4] ?? null,
            ];
        }

        return null;
    }

    /**
     * Alias en français pour parseVersion.
     */
    public static function analyserVersion(string $version): ?array
    {
        return self::parseVersion($version);
    }

    /**
     * Compare deux versions sémantiques.
     *
     * @return int -1 (a < b), 0 (a = b), 1 (a > b)
     */
    public static function comparerVersions(string $a, string $b): int
    {
        $pa = self::parseVersion($a);
        $pb = self::parseVersion($b);

        if ($pa === null || $pb === null) {
            return strcmp($a, $b) <=> 0;
        }

        if ($pa['major'] !== $pb['major']) {
            return $pa['major'] <=> $pb['major'];
        }
        if ($pa['minor'] !== $pb['minor']) {
            return $pa['minor'] <=> $pb['minor'];
        }
        if ($pa['patch'] !== $pb['patch']) {
            return $pa['patch'] <=> $pb['patch'];
        }

        // pre-release < release
        if ($pa['pre'] === null && $pb['pre'] !== null) {
            return 1;
        }
        if ($pa['pre'] !== null && $pb['pre'] === null) {
            return -1;
        }

        return strcmp((string) $pa['pre'], (string) $pb['pre']) <=> 0;
    }

    /**
     * Incrémente une version sémantique.
     */
    public static function incrementerVersion(string $version, string $niveau = 'patch'): string
    {
        $parsed = self::parseVersion($version);

        if ($parsed === null) {
            return $version;
        }

        return match ($niveau) {
            'major' => ($parsed['major'] + 1).'.0.0',
            'minor' => $parsed['major'].'.'.($parsed['minor'] + 1).'.0',
            default => $parsed['major'].'.'.$parsed['minor'].'.'.($parsed['patch'] + 1),
        };
    }

    /**
     * Valide le format d'une version sémantique.
     */
    public static function versionValide(string $version): bool
    {
        return self::parseVersion($version) !== null;
    }

    /**
     * Incrémente une version sémantique (méthode d'instance).
     */
    public function incrementer(string $version, string $niveau = 'patch'): string
    {
        return self::incrementerVersion($version, $niveau);
    }
}
