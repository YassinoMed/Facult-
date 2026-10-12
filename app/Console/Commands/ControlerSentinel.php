<?php

namespace App\Console\Commands;

use App\Modules\Sentinel\Services\SentinelService;
use Illuminate\Console\Command;

/**
 * Platform Sentinel — ronde de contrôles (planifiée toutes les
 * 15 minutes). Chaque contrôle est isolé : une panne n'échoue pas la
 * commande (erreurs listées en sortie, code retour 0 sauf --strict).
 */
class ControlerSentinel extends Command
{
    protected $signature = 'sentinel:controler {--check= : Code d’un contrôle unique} {--strict : Échouer si un contrôle est en erreur}';

    protected $description = 'Exécute la ronde de contrôles Platform Sentinel (incidents dédupliqués en base).';

    public function handle(SentinelService $sentinel): int
    {
        $resultat = $sentinel->executer($this->option('check'));

        $this->info("Contrôles exécutés : {$resultat['executes']} — incidents (créés/rappelés) : {$resultat['incidents']} — {$resultat['duree_ms']} ms.");

        if ($resultat['erreurs'] !== []) {
            $this->warn('Contrôles en erreur : '.implode(', ', $resultat['erreurs']));

            return $this->option('strict') ? self::FAILURE : self::SUCCESS;
        }

        return self::SUCCESS;
    }
}
