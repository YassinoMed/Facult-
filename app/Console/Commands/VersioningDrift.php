<?php

namespace App\Console\Commands;

use App\Modules\Versioning\Models\Release;
use App\Modules\Versioning\Services\DriftDetectionService;
use Illuminate\Console\Command;

class VersioningDrift extends Command
{
    protected $signature = 'versioning:drift
        {--release= : Version de la release à comparer (défaut : release déployée)}
        {--strict : Retourne un code d\'erreur 1 si un drift est détecté}
        {--json : Renvoyer le rapport au format JSON}';

    protected $description = 'Analyse et détecte la dérive (drift) entre les versions déclarées et réelles.';

    public function handle(DriftDetectionService $driftService): int
    {
        $version = $this->option('release');
        $release = $version ? Release::where('version', $version)->first() : null;

        $rapport = $driftService->detecter($release);

        if ($this->option('json')) {
            $this->line(json_encode($rapport, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return ($this->option('strict') && $rapport['drift_detecte']) ? self::FAILURE : self::SUCCESS;
        }

        $this->info('══════════════════════════════════════════════════════');
        $this->info('            RAPPORT DE DÉTECTION DE DÉRIVE           ');
        $this->info('══════════════════════════════════════════════════════');
        $this->line("Release de référence : <fg=cyan>{$rapport['release_reference']}</>");
        $this->line("Score d'intégrité    : <fg=".($rapport['score_integrite'] >= 80 ? 'green' : 'red').";options=bold>{$rapport['score_integrite']}%</>");
        $this->line("Date d'analyse       : {$rapport['verifie_le']}");
        $this->newLine();

        if (! $rapport['drift_detecte']) {
            $this->info('✔ AUCUN DRIFT DÉTECTÉ. Le système est en parfait alignement avec sa release.');

            return self::SUCCESS;
        }

        $this->error('✖ DRIFT DÉTECTÉ ! '.count($rapport['ecarts']).' écart(s) constaté(s) :');

        $lignes = [];
        foreach ($rapport['ecarts'] as $cle => $info) {
            $lignes[] = [
                $cle,
                is_scalar($info['declare']) ? (string) $info['declare'] : json_encode($info['declare']),
                is_scalar($info['reel']) ? (string) $info['reel'] : json_encode($info['reel']),
                $info['critique'] ? '<fg=red>CRITIQUE</>' : '<fg=yellow>MINEUR</>',
                $info['message'],
            ];
        }

        $this->table(['Élément', 'Déclaré', 'Réel', 'Niveau', 'Explication'], $lignes);

        return $this->option('strict') ? self::FAILURE : self::SUCCESS;
    }
}
