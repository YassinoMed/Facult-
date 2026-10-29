<?php

namespace App\Console\Commands;

use App\DataMesh\Outbox\OutboxService;
use Illuminate\Console\Command;

/**
 * Publication des messages Transactional Outbox en attente (Data Mesh §7).
 * Lecture + transitions d'état uniquement : aucune écriture métier.
 * À planifier (toutes les minutes) pour une publication post-commit régulière.
 */
class PublierOutbox extends Command
{
    protected $signature = 'datamesh:outbox-publier '
        .'{--limite=50 : Nombre maximal de messages pending à traiter} '
        .'{--retenter : Réessayer aussi les messages en échec sous le seuil} '
        .'{--purge-jours=0 : Purger les published plus vieux que N jours (0 = désactivé)}';

    protected $description = 'Publie les messages outbox en attente et rejoue les échecs.';

    public function handle(OutboxService $outbox): int
    {
        $publies = $outbox->publierEnAttente(max(1, (int) $this->option('limite')));
        $this->info("Messages publiés : {$publies}.");

        $reexpedies = 0;
        if ($this->option('retenter')) {
            $reexpedies = $outbox->retenterEchecs();
            $this->info("Échecs réexpédiés : {$reexpedies}.");
        }

        $purges = 0;
        $jours = (int) $this->option('purge-jours');
        if ($jours > 0) {
            $purges = $outbox->purgerPublies($jours);
            $this->info("Messages publiés purgés (> {$jours} j) : {$purges}.");
        }

        return self::SUCCESS;
    }
}
