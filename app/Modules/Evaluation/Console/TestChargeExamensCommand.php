<?php

namespace App\Modules\Evaluation\Console;

use App\Modules\Evaluation\Services\ExamChargeService;
use DomainException;
use Illuminate\Console\Command;

/**
 * §7.3 / §2.7 / §2.15 — `examens:charge`.
 *
 * Expose le harnais de charge mesuré. REFUSE de s'exécuter sans base MySQL
 * dédiée (nom contenant « _test ») : aucune donnée de développement ou de
 * production n'est lue, écrite ou supprimée. Le code de retour reflète le
 * verdict : SUCCESS seulement si p95, taux d'erreur et pic mémoire restent
 * sous les seuils.
 */
final class TestChargeExamensCommand extends Command
{
    protected $signature = 'examens:charge
        {--iterations=20 : nombre d\'itérations de charge}
        {--candidats=40 : nombre de candidats du jeu de données}
        {--json : sortie machine}';

    protected $description = 'Exécute la charge des examens (réconciliation, quatre yeux, gouvernance) sur une base MySQL dédiée et vérifie les seuils.';

    public function handle(ExamChargeService $charge): int
    {
        $iterations = max(1, (int) $this->option('iterations'));
        $candidats = max(2, (int) $this->option('candidats'));

        try {
            $rapport = $charge->executer($iterations, $candidats);
        } catch (DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($rapport, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->afficher($rapport);
        }

        return $rapport['respecte'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $rapport
     */
    private function afficher(array $rapport): void
    {
        $this->info('Charge des examens — réconciliation tri-sources et gouvernance');
        $this->line(sprintf(
            'Base : %s (%s) — %d itération(s), %d candidat(s)',
            $rapport['infrastructure']['database'] ?? '?',
            $rapport['infrastructure']['driver'] ?? '?',
            $rapport['parametres']['iterations'] ?? 0,
            $rapport['parametres']['candidats'] ?? 0
        ));
        $this->newLine();

        $lignes = [];
        foreach ($rapport['operations'] as $operation) {
            $lignes[] = [
                $operation['operation'],
                $operation['executions'],
                $operation['erreurs'],
                $operation['p50_ms'],
                $operation['p95_ms'],
                $operation['p99_ms'],
                $operation['max_ms'],
            ];
        }

        $this->table(
            ['Opération', 'Exéc.', 'Err.', 'p50 ms', 'p95 ms', 'p99 ms', 'max ms'],
            $lignes
        );

        $this->newLine();
        $this->line(sprintf(
            'Global : %d exécution(s), %d erreur(s) — p50 %s ms, p95 %s ms, p99 %s ms',
            $rapport['executions'],
            $rapport['erreurs'],
            $rapport['p50_ms'],
            $rapport['p95_ms'],
            $rapport['p99_ms']
        ));
        $this->line(sprintf(
            'Seuils : p95 ≤ %s ms, erreurs ≤ %s %%, mémoire ≤ %s Mo — pic mémoire mesuré %s Mo',
            $rapport['seuils']['p95_ms'],
            $rapport['seuils']['taux_erreur_pct'],
            $rapport['seuils']['memoire_mb'],
            $rapport['memoire_pic_mb']
        ));

        if ($rapport['respecte']) {
            $this->info('VERDICT : charge conforme aux seuils.');

            return;
        }

        $this->error('VERDICT : charge NON conforme.');
        foreach ($rapport['raisons'] as $raison) {
            $this->line('  - '.$raison);
        }

        foreach ($rapport['detail_erreurs'] as $detail) {
            $this->line('  ! '.$detail['operation'].' → '.$detail['erreur']);
        }
    }
}
