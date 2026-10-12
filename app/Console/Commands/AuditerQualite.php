<?php

namespace App\Console\Commands;

use App\Models\AnomalieQualite;
use App\Modules\Tasks\Services\TaskService;
use App\Services\AgregateurQualite;
use Illuminate\Console\Command;

/**
 * Balayage qualité (§19.9) : détecte, persiste, et crée une tâche
 * par anomalie active (idempotente, --sync-tasks). Jamais de
 * modification automatique des données métier.
 */
class AuditerQualite extends Command
{
    protected $signature = 'qualite:auditer {--sync-tasks : Créer des tâches pour les anomalies actives}';

    protected $description = 'Balayage qualité des données universitaires.';

    public function handle(AgregateurQualite $agregateur, TaskService $taches): int
    {
        $resultat = $agregateur->synchroniser();
        $creees = 0;

        if ($this->option('sync-tasks')) {
            $priorites = ['critique' => 'critical', 'erreur' => 'high', 'avertissement' => 'normal', 'info' => 'low'];

            foreach (AnomalieQualite::whereIn('statut', ['ouverte', 'acquittee', 'en_cours'])->cursor() as $anomalie) {
                $taches->synchroniser('qualite:'.$anomalie->cle_unique, [
                    'titre' => mb_substr($anomalie->message, 0, 150),
                    'description' => $anomalie->action_recommandee,
                    'source' => 'qualite',
                    'priorite' => $priorites[$anomalie->gravite] ?? 'normal',
                    'lien' => $anomalie->lien,
                ]);
                $creees++;
            }
        }

        $this->info("Anomalies : {$resultat['creees']} créées, {$resultat['reactualisees']} réactualisées, {$resultat['resolues']} résolues, {$creees} tâches.");

        return self::SUCCESS;
    }
}
