<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * D4 (LOT 2) — les files NOMMÉES par les jobs (`onQueue('…')`) doivent
 * rester écoutées par la configuration worker de production : le guide
 * de déploiement est la source opérationnelle (supervisor). Si un job
 * lance une nouvelle file nommée sans y penser, ce test lève la
 * dérive plutôt que de découvrir en production que les jobs ne
 * tournent pas (queue database, pas de --queue = seul `default`).
 */
class QueueWorkerCoverageTest extends TestCase
{
    public function test_les_files_nommees_sont_couvertes_par_la_commande_worker(): void
    {
        $racines = [app_path('Jobs'), app_path('Modules')];
        $files = [];

        foreach ($racines as $racine) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($racine, \FilesystemIterator::SKIP_DOTS)) as $fichier) {
                if ($fichier->getExtension() !== 'php' || ! str_contains($fichier->getPathname(), 'Jobs')) {
                    continue;
                }

                $contenu = file_get_contents($fichier->getPathname());

                if (preg_match_all("/onQueue\(['\"]([a-z0-9_-]+)['\"]\)/i", $contenu, $m)) {
                    $files = array_merge($files, $m[1]);
                }
            }
        }

        $files = array_values(array_unique($files));

        // On attend AU MOINS les files productives connues (si l'ajout
        // disparaît, la prod ne s'arrête pas — test de dérive négatif
        // volontaire pour la couverture).
        $this->assertContains('documents', $files);
        $this->assertContains('verification', $files);

        $guide = file_get_contents(base_path('docs/GUIDE-DEPLOY.md'));

        foreach ($files as $file) {
            $this->assertStringContainsString($file, $guide,
                "La file « {$file} » (job `onQueue`) n'est pas écoutée par la commande worker du guide de déploiement (D4).");
        }
    }
}
