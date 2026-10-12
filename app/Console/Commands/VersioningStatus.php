<?php

namespace App\Console\Commands;

use App\Modules\Versioning\Models\Release;
use App\Modules\Versioning\Services\CompatibilityService;
use App\Modules\Versioning\Services\DriftDetectionService;
use App\Modules\Versioning\Services\VersioningService;
use Illuminate\Console\Command;

class VersioningStatus extends Command
{
    protected $signature = 'versioning:status
        {--json : Renvoyer le résultat au format JSON}';

    protected $description = 'Affiche l\'état courant des versions APP, DB, DEV, PROD et releases.';

    public function handle(
        VersioningService $versioning,
        CompatibilityService $compatibility,
        DriftDetectionService $driftService
    ): int {
        $etat = $versioning->etatCourant();
        $releaseDeployee = Release::where('statut', Release::STATUT_DEPLOYEE)
            ->latest('deployed_at')
            ->first();
        $compat = $compatibility->verifierSysteme();
        $drift = $driftService->detecter($releaseDeployee);

        if ($this->option('json')) {
            $this->line(json_encode([
                'etat' => $etat,
                'release_active' => $releaseDeployee ? [
                    'version' => $releaseDeployee->version,
                    'build' => $releaseDeployee->build_number,
                    'deployed_at' => $releaseDeployee->deployed_at?->toIso8601String(),
                ] : null,
                'compatibilite' => $compat,
                'drift' => $drift,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info('══════════════════════════════════════════════════════');
        $this->info('           ÉTAT DU SYSTÈME DE VERSIONNAGE            ');
        $this->info('══════════════════════════════════════════════════════');

        $dbSchemaTexte = $etat['db_schema']['texte'] ?? "{$etat['migrations']['applied']} migrations appliquées";

        $this->table(
            ['Composant', 'Version', 'Statut / Détail'],
            [
                ['Application (APP)', $etat['app_version'], 'Version applicative SemVer'],
                ['DB Release', $etat['db_release'], 'Version logique de compatibilité'],
                ['DB Schema', $dbSchemaTexte, 'État technique des migrations'],
                ['Développement (DEV)', $etat['dev_version'], 'Branche locale active'],
                ['Production (PROD)', $etat['prod_version'], 'Cible de production'],
                ['Build courant', $etat['build'], 'Horodaté Y.m.d.NNN'],
                ['Environnement', $etat['environnement'], config('app.env')],
            ]
        );

        $this->newLine();
        $this->line('<fg=cyan;options=bold>Release actuellement déployée :</>');
        if ($releaseDeployee) {
            $this->line(" • Version : <fg=green;options=bold>{$releaseDeployee->version}</>");
            $this->line(" • Build   : {$releaseDeployee->build_number}");
            $this->line(" • Déployée le : {$releaseDeployee->deployed_at?->format('d/m/Y H:i:s')}");
            $this->line(' • Hash manifest : '.substr((string) $releaseDeployee->manifest_hash, 0, 16).'...');
        } else {
            $this->warn(' Aucune release marquée comme déployée (brouillon initial).');
        }

        $this->newLine();
        $this->line('<fg=cyan;options=bold>Contrôle de compatibilité :</>');
        if ($compat['statut_global'] === CompatibilityService::STATUT_COMPATIBLE) {
            $this->line(' <fg=green>✔ Compatible</>');
        } elseif ($compat['statut_global'] === CompatibilityService::STATUT_AVERTISSEMENT) {
            $this->warn(' ⚠ Compatible avec avertissement(s).');
        } else {
            $this->error(' ✖ Incompatibilité détectée entre composants !');
        }

        $this->newLine();
        $this->line('<fg=cyan;options=bold>Détection de dérive (Drift) :</>');
        if (! $drift['drift_detecte']) {
            $this->line(' <fg=green>✔ Aucun drift détecté</>');
            $this->line(" Score : <fg=green;options=bold>{$drift['score_integrite']}%</>");
        } else {
            $this->error(" ✖ DRIFT DÉTECTÉ ! Score d'intégrité : {$drift['score_integrite']}%");
            foreach ($drift['ecarts'] as $champ => $detail) {
                $this->line("   - <fg=yellow>{$champ}</> : {$detail['message']}");
            }
        }

        $this->info('══════════════════════════════════════════════════════');

        return self::SUCCESS;
    }
}
