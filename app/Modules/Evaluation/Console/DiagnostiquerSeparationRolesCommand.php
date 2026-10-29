<?php

namespace App\Modules\Evaluation\Console;

use App\Modules\Evaluation\Services\ExamSeparationRolesDiagnosticService;
use Illuminate\Console\Command;

/**
 * §2.7 — `examens:separation-roles`.
 *
 * Rend la décision institutionnelle mesurable : quels rôles portent
 * aujourd'hui chaque étape du circuit, une attribution distincte existe-t-elle,
 * et sinon quelles permissions déplacer ou quels rôles créer.
 */
final class DiagnostiquerSeparationRolesCommand extends Command
{
    protected $signature = 'examens:separation-roles {--json : sortie machine}';

    protected $description = 'Diagnostique la faisabilité de la séparation des pouvoirs PAR RÔLE sur le circuit de gouvernance des examens.';

    public function handle(ExamSeparationRolesDiagnosticService $diagnostic): int
    {
        $resultat = $diagnostic->diagnostic();

        if ($this->option('json')) {
            $this->line((string) json_encode($resultat, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->afficher($resultat);
        }

        // Porte de sortie : si la règle est active et non satisfaite,
        // l'exploitation doit le voir dans le code de retour.
        if ($resultat['mode_actif'] && ! $resultat['faisable']) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array{
     *     mode_actif: bool,
     *     roles_par_etape: array<string, array<int, string>>,
     *     etapes_sans_role: array<int, string>,
     *     faisable: bool,
     *     proposition: array<string, string>|null,
     *     actions_requises: array<int, string>,
     *     role_technique: string
     * }  $resultat
     */
    private function afficher(array $resultat): void
    {
        $this->info('Séparation des pouvoirs par rôle — circuit de gouvernance des examens');
        $this->line('Mode : '.($resultat['mode_actif'] ? 'ACTIF' : 'inactif (séparation par personne uniquement)'));
        $this->line('Compte technique exclu des propositions : '.$resultat['role_technique']);
        $this->newLine();

        $lignes = [];
        foreach ($resultat['roles_par_etape'] as $etape => $roles) {
            $lignes[] = [
                $etape,
                ExamSeparationRolesDiagnosticService::ETAPES[$etape],
                $roles === [] ? '— aucun rôle' : implode(', ', $roles),
            ];
        }
        $this->table(['Étape', 'Permission requise', 'Rôles éligibles'], $lignes);
        $this->newLine();

        if ($resultat['faisable']) {
            $this->info('Attribution distincte possible :');
            foreach ($resultat['proposition'] ?? [] as $etape => $role) {
                $this->line("  {$etape} → {$role}");
            }

            return;
        }

        $this->warn('Aucune attribution distincte n’existe avec la matrice actuelle.');
        $this->line('Actions requises avant activation :');
        foreach ($resultat['actions_requises'] as $action) {
            $this->line('  • '.$action);
        }
    }
}
