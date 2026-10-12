<?php

namespace App\Modules\Tasks\Services;

use App\Modules\Tasks\Events\TaskChanged;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskEvenement;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Centre de tâches (§9) : création IDEMPOTENTE par clé source
 * (une demande = une tâche active), transitions serveur, bulk.
 */
class TaskService
{
    /**
     * Crée ou réactive la tâche d'une source (jamais de doublon actif).
     *
     * @param  array<string, mixed>  $attributs
     */
    public function synchroniser(string $cleUnique, array $attributs): Task
    {
        return DB::transaction(function () use ($cleUnique, $attributs) {
            $tache = Task::where('cle_unique', $cleUnique)->first();

            if ($tache !== null) {
                if (! $tache->estActive()) {
                    $tache->update(['statut' => 'pending', 'terminee_a' => null]);
                    $this->journaliser($tache, 'reactivee', null);
                }

                $tache->update(array_intersect_key($attributs, array_flip([
                    'titre', 'description', 'priorite', 'echeance_a', 'lien', 'data', 'faculte_id', 'assigne_a',
                ])));

                return $tache->fresh();
            }

            $tache = Task::create(['cle_unique' => $cleUnique, 'statut' => 'pending'] + $attributs);
            $this->journaliser($tache, 'creee', $attributs['created_by'] ?? null);
            TaskChanged::dispatch('creee', $tache->id);

            return $tache;
        });
    }

    /**
     * Clôture système les tâches dont la source est résolue.
     *
     * @param  array<int, string>  $clesActives
     */
    public function cloturerResolues(string $source, array $clesActives): int
    {
        $query = Task::where('source', $source)
            ->whereIn('statut', ['pending', 'in_progress', 'waiting', 'expired']);

        if ($clesActives !== []) {
            $query->whereNotIn('cle_unique', $clesActives);
        }

        $cloturees = 0;

        foreach ($query->cursor() as $tache) {
            $tache->update(['statut' => 'completed', 'terminee_a' => now()]);
            $this->journaliser($tache, 'resolue_systeme', null);
            $cloturees++;
        }

        return $cloturees;
    }

    public function transiter(Task $tache, string $cible, ?int $auteurId = null, ?string $commentaire = null): Task
    {
        return DB::transaction(function () use ($tache, $cible, $auteurId, $commentaire) {
            $tache->refresh();

            if (! $tache->peutTransiterVers($cible)) {
                throw new DomainException("Transition {$tache->statut} → {$cible} interdite.");
            }

            $tache->update([
                'statut' => $cible,
                'terminee_a' => in_array($cible, ['completed', 'cancelled'], true) ? now() : null,
            ]);
            $this->journaliser($tache, "statut:{$cible}", $auteurId, $commentaire);
            TaskChanged::dispatch('statut', $tache->id);

            return $tache->fresh();
        });
    }

    public function assigner(Task $tache, ?int $userId, ?int $auteurId = null): Task
    {
        return DB::transaction(function () use ($tache, $userId, $auteurId) {
            $tache->update(['assigne_a' => $userId]);
            $this->journaliser($tache, 'assignee', $auteurId);
            TaskChanged::dispatch('assignee', $tache->id);

            return $tache->fresh();
        });
    }

    /**
     * @param  array<int>  $ids
     * @return array{traitees: int, ignorees: int}
     */
    public function actionGroupee(array $ids, string $action, ?int $auteurId = null, ?int $assigneA = null): array
    {
        $traitees = 0;
        $ignorees = 0;

        foreach (Task::whereIn('id', array_slice($ids, 0, 100))->cursor() as $tache) {
            try {
                match ($action) {
                    'terminer' => $this->transiter($tache, 'completed', $auteurId),
                    'demarrer' => $this->transiter($tache, 'in_progress', $auteurId),
                    'assigner' => $this->assigner($tache, $assigneA, $auteurId),
                    default => throw new DomainException('Action inconnue.'),
                };
                $traitees++;
            } catch (DomainException) {
                $ignorees++;
            }
        }

        return ['traitees' => $traitees, 'ignorees' => $ignorees];
    }

    private function journaliser(Task $tache, string $action, ?int $auteurId, ?string $commentaire = null): void
    {
        TaskEvenement::create([
            'task_id' => $tache->id,
            'action' => $action,
            'commentaire' => $commentaire,
            'auteur_id' => $auteurId,
            'created_at' => now(),
        ]);
    }
}
