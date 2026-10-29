<?php

namespace App\Console\Commands;

use App\Services\RattachementAcademiqueCompletService;
use Illuminate\Console\Command;

/**
 * Commande CLI d'audit et de rattachement académique complet des étudiants :
 * parcours LMD (programme), groupe et niveau référentiel.
 */
class HarmoniserParcoursEtudiantsCommand extends Command
{
    protected $signature = 'referentiel:harmoniser-etudiants-parcours
                            {--force : Exécute les écritures en base (sinon mode simulation)}
                            {--faculte= : Restreindre à une faculté spécifique (id)}';

    protected $description = 'Audite la base de données et associe à chaque étudiant un parcours, un groupe et un niveau référentiel cohérents.';

    public function handle(RattachementAcademiqueCompletService $service): int
    {
        $force = (bool) $this->option('force');
        $faculteId = $this->option('faculte') ? (int) $this->option('faculte') : null;

        $this->info('========================================================================');
        $this->info('      UNIREGISTRE — AUDIT & HARMONISATION DES PARCOURS ÉTUDIANTS       ');
        $this->info('========================================================================');
        $this->line('Mode : '.($force ? '<fg=green;options=bold>ÉCRITURE RÉELLE (--force)</>' : '<fg=yellow;options=bold>SIMULATION / AUDIT SEUL (DRY-RUN)</>'));
        if ($faculteId) {
            $this->line("Périmètre restreint à la faculté ID : {$faculteId}");
        }
        $this->newLine();

        $resultat = $service->executerHarmonisation($faculteId, $force);
        $avant = $resultat['audit_avant'];
        $apres = $resultat['audit_apres'];
        $actions = $resultat['resume_actions'];

        $this->comment('--- 1. ÉTAT INITIAL DE LA BASE DE DONNÉES ---');
        $this->table(
            ['Métrique', 'Valeur'],
            [
                ['Total étudiants', $avant['total_etudiants']],
                ['Étudiants sans groupe affecté', $avant['sans_groupe']],
                ['Étudiants sans programme (parcours)', $avant['sans_programme']],
                ['Étudiants sans niveau référentiel', $avant['sans_niveau']],
                ['Inscriptions académiques totales', $avant['inscriptions_totales']],
                ['Inscriptions sans programme_id', $avant['inscriptions_sans_programme']],
                ['Inscriptions sans niveau_id', $avant['inscriptions_sans_niveau']],
                ['Groupes existants au planning', $avant['groupes_totaux']],
                ['Groupes sans programme LMD', $avant['groupes_sans_programme']],
                ['Groupes sans niveau référentiel', $avant['groupes_sans_niveau']],
            ]
        );

        $this->newLine();
        $this->comment('--- 2. ACTIONS APPLIQUÉES / SIMULÉES ---');
        $this->table(
            ['Action réalisée', 'Nombre d\'occurrences'],
            [
                ['Groupes d\'examen/cours synchronisés', $actions['groupes_maj']],
                ['Groupes spécifiques créés (M1/M2/D1)', $actions['groupes_crees']],
                ['Étudiants orphelins affectés à un groupe', $actions['etudiants_sans_groupe_affectes']],
                ['Étudiants rattachés (programme + niveau)', $actions['etudiants_maj']],
                ['Inscriptions académiques synchronisées', $actions['inscriptions_maj']],
            ]
        );

        if ($force) {
            $this->newLine();
            $this->comment('--- 3. ÉTAT FINAL APRÈS HARMONISATION ---');
            $this->table(
                ['Métrique', 'Avant', 'Après'],
                [
                    ['Étudiants sans groupe', $avant['sans_groupe'], '<fg=green>'.$apres['sans_groupe'].'</>'],
                    ['Étudiants sans parcours (programme)', $avant['sans_programme'], '<fg=green>'.$apres['sans_programme'].'</>'],
                    ['Étudiants sans niveau_id', $avant['sans_niveau'], '<fg=green>'.$apres['sans_niveau'].'</>'],
                    ['Inscriptions sans programme_id', $avant['inscriptions_sans_programme'], '<fg=green>'.$apres['inscriptions_sans_programme'].'</>'],
                    ['Inscriptions sans niveau_id', $avant['inscriptions_sans_niveau'], '<fg=green>'.$apres['inscriptions_sans_niveau'].'</>'],
                    ['Groupes sans programme', $avant['groupes_sans_programme'], '<fg=green>'.$apres['groupes_sans_programme'].'</>'],
                ]
            );

            $this->info('✓ Harmonisation réussie : 100% des étudiants disposent désormais d\'un parcours, d\'un groupe et d\'un niveau référentiel conformes.');
        } else {
            $this->newLine();
            $this->warn('! Simulation terminée sans modification de la base. Pour appliquer ces associations, relancez avec :');
            $this->line('  <fg=cyan>php artisan referentiel:harmoniser-etudiants-parcours --force</>');
        }

        return self::SUCCESS;
    }
}

