<?php

namespace Database\Seeders;

use App\Modules\Schedule\Models\Group;
use Illuminate\Database\Seeder;

/**
 * Groupes complémentaires démo (faculté historique) : les niveaux L3,
 * M1 et M2 n'avaient aucun groupe cible, rendant toute affectation
 * automatique impossible pour ces étudiants. Crée les 3 groupes
 * manquants avec les mêmes conventions que les groupes existants
 * (programme NULL, filière Informatique). D1 reste volontairement
 * sans groupe (cas de test « aucun compatible »).
 *
 * Idempotent (firstOrCreate sur code).
 */
class GroupesNiveauxManquantsSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            $this->command?->warn('GroupesNiveauxManquantsSeeder ignoré (environnement non local).');

            return;
        }

        $reference = Group::where('code', 'L1-INFO-A')->first();
        abort_unless($reference !== null, 500, 'GroupesNiveauxManquantsSeeder : groupe L1-INFO-A introuvable.');
        $faculteId = (int) $reference->faculte_id;

        $crees = 0;

        foreach ([
            ['code' => 'L3-INFO-A', 'faculte_id' => $faculteId, 'nom' => 'Groupe L3-INFO-A', 'niveau' => 'L3', 'filiere' => 'Informatique', 'capacite' => 30],
            ['code' => 'M1-INFO-A', 'faculte_id' => $faculteId, 'nom' => 'Groupe M1-INFO-A', 'niveau' => 'M1', 'filiere' => 'Informatique', 'capacite' => 25],
            ['code' => 'M2-INFO-A', 'faculte_id' => $faculteId, 'nom' => 'Groupe M2-INFO-A', 'niveau' => 'M2', 'filiere' => 'Informatique', 'capacite' => 25],
            ['code' => 'M1-FSIT-A', 'faculte_id' => 5, 'nom' => 'Groupe M1 Ingénierie', 'niveau' => 'M1', 'filiere' => 'Ingénierie', 'capacite' => 30],
            ['code' => 'M2-FMD-A', 'faculte_id' => 7, 'nom' => 'Groupe M2 Dentaire', 'niveau' => 'M2', 'filiere' => 'Médecine Dentaire', 'capacite' => 30],
            ['code' => 'M2-FSE-A', 'faculte_id' => 6, 'nom' => 'Groupe M2 Éducation', 'niveau' => 'M2', 'filiere' => 'Éducation', 'capacite' => 30],
        ] as $definition) {
            $groupe = Group::firstOrCreate(
                ['code' => $definition['code']],
                [
                    'nom' => $definition['nom'],
                    'faculte_id' => $definition['faculte_id'],
                    'niveau' => $definition['niveau'],
                    'filiere' => $definition['filiere'],
                    'capacite' => $definition['capacite'],
                    'actif' => true,
                ]
            );

            if ($groupe->wasRecentlyCreated) {
                $crees++;
            }
        }

        $this->command?->info("GroupesNiveauxManquantsSeeder : {$crees} groupe(s) créé(s).");
    }
}
