<?php

namespace App\Console\Commands;

use App\Services\Recherche\SearchIndexer;
use Illuminate\Console\Command;

/**
 * Phase 18 §18.1 : réindexation complète en streaming + mesure de
 * dérive (bascule sans interruption : updateOrCreate ligne à ligne).
 */
class ReindexerRecherche extends Command
{
    protected $signature = 'search:reindex {entite?} {--derive : mesure la dérive index vs base}';

    protected $description = 'Réindexe la recherche universelle (streaming, sans verrou).';

    public function handle(SearchIndexer $indexeur): int
    {
        if ($this->option('derive')) {
            $derive = $indexeur->mesurerDerive();
            $this->info("Orphelins : {$derive['orphelins']}, manquants : {$derive['manquants']}");

            return self::SUCCESS;
        }

        $total = $indexeur->reindexer($this->argument('entite'));
        $this->info("{$total} entrée(s) indexée(s).");

        return self::SUCCESS;
    }
}
