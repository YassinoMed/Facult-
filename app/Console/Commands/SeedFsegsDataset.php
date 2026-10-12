<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Dataset FSEGS (docs/testing/fsegs-dataset.md) : génération réaliste
 * ~5 000 étudiants (normal) ou charge (--load=N). Local/testing only
 * (garde dans le seeder).
 */
class SeedFsegsDataset extends Command
{
    protected $signature = 'fsegs:seed-dataset {--load= : multiplicateur de charge (ex. 2 = ~10 000 étudiants)}';

    protected $description = 'Génère le dataset FSEGS (faculté, référentiel, groupes, étudiants, inscriptions, affectations).';

    public function handle(): int
    {
        config(['fsegs-dataset.charge' => max(1, (int) $this->option('load', 1) ?: 1)]);

        $this->call('db:seed', ['--class' => 'FsegsDatasetSeeder']);

        return self::SUCCESS;
    }
}
