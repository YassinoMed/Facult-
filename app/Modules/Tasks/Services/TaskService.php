<?php

namespace App\Modules\Tasks\Services;

use App\Models\InscriptionAcademique;
use App\Models\User;
use App\Modules\Tasks\Events\TaskChanged;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskEvenement;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
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
            $tache = Task::where('cle_unique', $cleUnique)->lockForUpdate()->first();

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

            try {
                $tache = Task::create(['cle_unique' => $cleUnique, 'statut' => 'pending'] + $attributs);
                $this->journaliser($tache, 'creee', $attributs['created_by'] ?? null);
                TaskChanged::dispatch('creee', $tache->id);

                return $tache;
            } catch (UniqueConstraintViolationException) {
                $tache = Task::where('cle_unique', $cleUnique)->firstOrFail();
                $tache->update(array_intersect_key($attributs, array_flip([
                    'titre', 'description', 'priorite', 'echeance_a', 'lien', 'data', 'faculte_id', 'assigne_a',
                ])));

                return $tache->fresh();
            }
        }, 5);
    }

    /**
     * Clôture système les tâches dont la source est résolue.
     * Si une faculté est spécifiée, seules les tâches de cette faculté sont réconciliées
     * (évite la clôture accidentelle des tâches des autres facultés en cas de filtre).
     *
     * @param  array<int, string>  $clesActives
     */
    public function cloturerResolues(string $source, array $clesActives, ?int $faculteId = null): int
    {
        $query = Task::where('source', $source)
            ->whereIn('statut', ['pending', 'in_progress', 'waiting', 'expired']);

        if ($faculteId !== null) {
            $query->where('faculte_id', $faculteId);
        }

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
            $verrouillee = Task::whereKey($tache->id)->lockForUpdate()->firstOrFail();

            if (! $verrouillee->peutTransiterVers($cible)) {
                throw new DomainException("Transition {$verrouillee->statut} → {$cible} interdite.");
            }

            $verrouillee->update([
                'statut' => $cible,
                'terminee_a' => in_array($cible, ['completed', 'cancelled'], true) ? now() : null,
            ]);
            $this->journaliser($verrouillee, "statut:{$cible}", $auteurId, $commentaire);
            TaskChanged::dispatch('statut', $verrouillee->id);

            return $verrouillee->fresh();
        });
    }

    public function assigner(Task $tache, ?int $userId, ?int $auteurId = null): Task
    {
        return DB::transaction(function () use ($tache, $userId, $auteurId) {
            $verrouillee = Task::whereKey($tache->id)->lockForUpdate()->firstOrFail();
            $verrouillee->update(['assigne_a' => $userId]);
            $this->journaliser($verrouillee, 'assignee', $auteurId);
            TaskChanged::dispatch('assignee', $verrouillee->id);

            return $verrouillee->fresh();
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

    /**
     * Recherche de l'agent le plus approprié pour l'affectation d'une tâche (Lot P3).
     *
     * Critères stricts :
     * - Compte actif ($user->estActif())
     * - Disposant de la permission 'tasks.view'
     * - Respect strict du périmètre facultaire ($tache->faculte_id)
     * - Exclusion explicite ($exclusUserIds, ex. proposant d'une correction pour éviter tout contournement 4-eyes)
     * - Équilibrage de charge : agent ayant le moins de tâches actives assignées.
     *
     * @param  array<int, int>  $exclusUserIds
     */
    public function trouverAgentPourAffectation(Task $tache, array $exclusUserIds = []): ?User
    {
        $faculteId = $tache->faculte_id;

        $candidats = User::query()
            ->where('actif', true)
            ->when(! empty($exclusUserIds), fn ($q) => $q->whereNotIn('id', $exclusUserIds))
            ->get()
            ->filter(function (User $user) use ($faculteId) {
                if (! $user->hasPermission('tasks.view')) {
                    return false;
                }
                if ($faculteId !== null && ! $user->peutAccederFaculte((int) $faculteId)) {
                    return false;
                }

                return true;
            });

        if ($candidats->isEmpty()) {
            return null;
        }

        $candidatIds = $candidats->pluck('id')->all();
        $charges = Task::query()
            ->selectRaw('assigne_a, count(*) as total')
            ->whereIn('assigne_a', $candidatIds)
            ->whereIn('statut', ['pending', 'in_progress', 'waiting'])
            ->groupBy('assigne_a')
            ->pluck('total', 'assigne_a')
            ->all();

        return $candidats->sort(function (User $a, User $b) use ($faculteId, $charges) {
            $prioA = $faculteId !== null && (int) $a->faculte_id === (int) $faculteId ? 0 : 1;
            $prioB = $faculteId !== null && (int) $b->faculte_id === (int) $faculteId ? 0 : 1;
            if ($prioA !== $prioB) {
                return $prioA <=> $prioB;
            }

            $chargeA = (int) ($charges[$a->id] ?? 0);
            $chargeB = (int) ($charges[$b->id] ?? 0);
            if ($chargeA !== $chargeB) {
                return $chargeA <=> $chargeB;
            }

            return $a->id <=> $b->id;
        })->first();
    }

    /**
     * Affectation automatique contrôlée d'une tâche selon la disponibilité et la charge (Lot P3).
     *
     * @param  array<int, int>  $exclusUserIds
     */
    public function assignerAutomatiquement(Task $tache, array $exclusUserIds = []): ?User
    {
        $agent = $this->trouverAgentPourAffectation($tache, $exclusUserIds);

        if ($agent !== null) {
            $this->assigner($tache, (int) $agent->id, null);
            $this->journaliser($tache, 'affectation_automatique', null, "Assignée automatiquement à {$agent->name} (charge optimisée).");
        } else {
            $this->journaliser($tache, 'non_assignee_alerte', null, 'Aucun agent disponible dans le périmètre autorisé.');
        }

        return $agent;
    }

    /**
     * Escalade automatique des tâches en retard ou en souffrance (Lot P3).
     *
     * @return array{escaladees: int, ignorees: int}
     */
    public function escaladerTachesEnSouffrance(?int $faculteId = null): array
    {
        $maintenant = now();
        $query = Task::query()
            ->whereIn('statut', ['pending', 'in_progress', 'waiting'])
            ->where(function ($q) use ($maintenant) {
                $q->where(function ($sub) use ($maintenant) {
                    $sub->whereNotNull('echeance_a')->where('echeance_a', '<', $maintenant);
                })->orWhere(function ($sub) use ($maintenant) {
                    $sub->where('created_at', '<=', $maintenant->copy()->subHours(48));
                });
            })
            ->where('priorite', '!=', 'critical');

        if ($faculteId !== null) {
            $query->where('faculte_id', $faculteId);
        }

        $escaladees = 0;
        $ignorees = 0;

        foreach ($query->cursor() as $tache) {
            try {
                $affectes = Task::whereKey($tache->id)
                    ->where('priorite', '!=', 'critical')
                    ->update(['priorite' => 'critical']);

                if ($affectes > 0) {
                    $this->journaliser($tache, 'escalade_sla', null, 'Priorité relevée à critique suite à dépassement de délai SLA.');
                    $escaladees++;
                } else {
                    $ignorees++;
                }
            } catch (\Throwable) {
                $ignorees++;
            }
        }

        return ['escaladees' => $escaladees, 'ignorees' => $ignorees];
    }

    /**
     * Réconciliation des tâches orphelines (Lot P3).
     * - Aligne l'état des tâches sur la réalité académique si l'inscription a été clôturée.
     * - Crée les tâches manquantes pour les inscriptions actives en_cours sans tâche active.
     *
     * @return array{reconciliees: int, inchangees: int, crees: int}
     */
    public function reconcilierTachesOrphelines(?int $faculteId = null): array
    {
        $query = Task::query()
            ->where('source', 'inscription')
            ->whereIn('statut', ['pending', 'in_progress', 'waiting']);

        if ($faculteId !== null) {
            $query->where('faculte_id', $faculteId);
        }

        $reconciliees = 0;
        $inchangees = 0;

        foreach ($query->cursor() as $tache) {
            $inscriptionId = $tache->data['inscription_id'] ?? null;
            if ($inscriptionId === null && preg_match('/^inscription:(\d+)$/', $tache->cle_unique, $matches)) {
                $inscriptionId = (int) $matches[1];
            }

            if ($inscriptionId === null) {
                continue;
            }

            $inscription = InscriptionAcademique::find($inscriptionId);

            if ($inscription === null) {
                $affectes = Task::whereKey($tache->id)
                    ->whereIn('statut', ['pending', 'in_progress', 'waiting'])
                    ->update(['statut' => 'cancelled', 'terminee_a' => now()]);

                if ($affectes > 0) {
                    $this->journaliser($tache, 'reconciliation_orpheline', null, 'Inscription inexistante.');
                    $reconciliees++;
                } else {
                    $inchangees++;
                }
            } elseif ($inscription->statut === 'valide') {
                $affectes = Task::whereKey($tache->id)
                    ->whereIn('statut', ['pending', 'in_progress', 'waiting'])
                    ->update(['statut' => 'completed', 'terminee_a' => now()]);

                if ($affectes > 0) {
                    $this->journaliser($tache, 'reconciliation_validee', null, 'Inscription validée.');
                    $reconciliees++;
                } else {
                    $inchangees++;
                }
            } elseif (in_array($inscription->statut, ['ajourne', 'rejete', 'abandon'], true)) {
                $affectes = Task::whereKey($tache->id)
                    ->whereIn('statut', ['pending', 'in_progress', 'waiting'])
                    ->update(['statut' => 'cancelled', 'terminee_a' => now()]);

                if ($affectes > 0) {
                    $this->journaliser($tache, 'reconciliation_ajournee', null, "Inscription au statut {$inscription->statut}.");
                    $reconciliees++;
                } else {
                    $inchangees++;
                }
            } else {
                $inchangees++;
            }
        }

        // Création des tâches pour les inscriptions actives sans tâche active
        $inscriptionsSansTache = InscriptionAcademique::query()
            ->where('statut', 'en_cours')
            ->when($faculteId !== null, fn ($q) => $q->whereHas('etudiant', fn ($sub) => $sub->where('faculte_id', $faculteId)))
            ->with(['etudiant', 'anneeUniversitaire'])
            ->get();

        $creees = 0;
        foreach ($inscriptionsSansTache as $inscription) {
            $cle = "inscription:{$inscription->id}";
            $existe = Task::where('cle_unique', $cle)->whereIn('statut', ['pending', 'in_progress', 'waiting'])->exists();
            if (! $existe) {
                $nomEtudiant = $inscription->etudiant?->nom_complet ?? "Étudiant #{$inscription->etudiant_id}";
                $tacheCreee = $this->synchroniser($cle, [
                    'titre' => "Contrôle dossier {$nomEtudiant}",
                    'description' => "Inscription {$inscription->filiere} ({$inscription->niveau}) en attente de vérification.",
                    'source' => 'inscription',
                    'priorite' => 'normal',
                    'lien' => route('admin.centre-travail.show', $inscription),
                    'faculte_id' => $inscription->etudiant?->faculte_id,
                    'data' => [
                        'inscription_id' => $inscription->id,
                        'etudiant_id' => $inscription->etudiant_id,
                    ],
                ]);
                $this->journaliser($tacheCreee, 'creation_reconciliation', null, 'Tâche recréée par réconciliation.');
                $creees++;
            }
        }

        return ['reconciliees' => $reconciliees, 'inchangees' => $inchangees, 'creees' => $creees];
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
