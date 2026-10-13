<?php

namespace App\Console\Commands;

use App\Services\LoopAuto\EvaluateurPorte;
use Illuminate\Console\Command;

/**
 * Moteur d'auto-passage (LOOP K) : évalue une porte et persiste l'état.
 * Chemins injectables pour tests (`--etat`, `--journal`) ; journal
 * idempotent (transition identique non dupliquée).
 */
class AvancerLoop extends Command
{
    protected $signature = 'loop:avancer {loop : Identifiant du LOOP courant}'
        .' {--verdict=pass : pass|blocked}'
        .' {--preuves : preuves disponibles}'
        .' {--reserves-bloquantes : réserve bloquante ouverte}'
        .' {--poursuite-autorisee : poursuite avec réserves autorisée}'
        .' {--suivant= : LOOP suivant résolu dans le registre}'
        .' {--tentative=0 : tentative en cours}'
        .' {--loops=0 : LOOP consécutifs dans cette exécution}'
        .' {--etat= : chemin du JSON d\'état}'
        .' {--journal= : chemin du journal}';

    protected $description = 'Évalue la porte de validation et enregistre la transition.';

    public function handle(): int
    {
        $resultat = EvaluateurPorte::evaluer([
            'verdict' => (string) $this->option('verdict'),
            'reserves_bloquantes' => (bool) $this->option('reserves-bloquantes'),
            'preuves' => (bool) $this->option('preuves'),
            'poursuite_autorisee' => (bool) $this->option('poursuite-autorisee'),
            'suivant_connu' => ((string) $this->option('suivant')) !== '',
            'tentative' => (int) $this->option('tentative'),
            'loops_consecutifs' => (int) $this->option('loops'),
        ]);

        $ligne = implode(' | ', [
            now()->toIso8601String(),
            (string) $this->argument('loop'),
            $resultat['transition'],
            $resultat['motif'],
        ]);

        $cheminJournal = (string) ($this->option('journal') ?: base_path('docs/engineering/loop-auto-journal.md'));
        $dernieres = is_file($cheminJournal) ? array_slice(file($cheminJournal, FILE_IGNORE_NEW_LINES), -5) : [];

        $doublon = false;
        foreach ($dernieres as $existante) {
            if (str_contains($existante, '| '.(string) $this->argument('loop').' | '.$resultat['transition'].' | '.$resultat['motif'])) {
                $doublon = true;

                break;
            }
        }

        if (! $doublon) {
            file_put_contents($cheminJournal, $ligne."\n", FILE_APPEND);
        }

        $cheminEtat = (string) ($this->option('etat') ?: base_path('docs/engineering/loop-auto-etat.json'));
        file_put_contents($cheminEtat, json_encode([
            'loop' => (string) $this->argument('loop'),
            'transition' => $resultat['transition'],
            'motif' => $resultat['motif'],
            'suivant' => (string) $this->option('suivant'),
            'mis_a_jour_le' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");

        $this->info($resultat['transition'].' — '.$resultat['motif'].($doublon ? ' (déjà journalisée)' : ''));

        return self::SUCCESS;
    }
}
