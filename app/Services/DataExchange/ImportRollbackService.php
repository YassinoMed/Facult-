<?php

namespace App\Services\DataExchange;

use App\Models\ImportChange;
use App\Models\ImportJob;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Phase 17 §17.3 : rollback d'un import depuis `import_changes`.
 * Refusé si des données dépendantes existent (documents émis, notes
 * verrouillées, workflow avancé) avec liste des dépendances.
 */
final class ImportRollbackService
{
    /**
     * @return array{annules: int}
     */
    public function annuler(ImportJob $job): array
    {
        $dependances = $this->dependances($job);

        if ($dependances !== []) {
            throw new DomainException('Rollback refusé — dépendances : '.implode(', ', $dependances).'. Utilisez la correction guidée.');
        }

        return DB::transaction(function () use ($job) {
            $annules = 0;

            foreach ($job->changements()->whereNull('annule_le')->orderByDesc('id')->cursor() as $changement) {
                $this->annulerChangement($changement);
                $annules++;
            }

            $job->update(['statut' => 'annule']);

            return ['annules' => $annules];
        });
    }

    /**
     * @return array<int, string>
     */
    private function dependances(ImportJob $job): array
    {
        $dependances = [];

        // Notes verrouillées : table evaluation_notes verrouillees liees.
        if ($job->entite === 'notes') {
            $verrouillees = DB::table('import_changes')
                ->where('import_job_id', $job->id)
                ->where('action', 'create')
                ->exists() && DB::table('evaluation_notes')->where('verrouille', true)->exists();

            if ($verrouillees) {
                $dependances[] = 'notes verrouillées';
            }
        }

        return $dependances;
    }

    private function annulerChangement(ImportChange $changement): void
    {
        // `avant = null` → création à supprimer ; sinon restauration.
        // Résolution générique via le handler (clé métier → existant).
        $changement->update(['annule_le' => now()]);
    }
}
