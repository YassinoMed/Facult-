<?php

namespace App\Modules\AssistantIa\Tools\Metier;

use App\Models\User;
use App\Modules\AssistantIa\Contracts\OutilIaInterface;
use App\Modules\AssistantIa\DTO\ResultatOutil;
use App\Modules\Tasks\Models\Task;

/**
 * Outil métier : Consultation des tâches administratives avec isolation facultaire.
 */
class ConsulterStatutTacheTool implements OutilIaInterface
{
    public function nom(): string
    {
        return 'consulter_statut_tache';
    }

    public function description(): string
    {
        return 'Consulte le statut d\'une ou plusieurs tâches administratives (dossiers en attente, validations, relances scolarité).';
    }

    public function mode(): string
    {
        return 'metier';
    }

    public function permissionRequise(): string
    {
        return 'assistant_ia.mode_metier';
    }

    public function schemaParametres(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'tache_id' => [
                    'type' => 'integer',
                    'description' => 'Identifiant unique de la tâche administrative (optionnel)',
                ],
                'statut' => [
                    'type' => 'string',
                    'description' => 'Filtre de statut (ex: "pending", "in_progress", "waiting", "completed")',
                ],
            ],
        ];
    }

    public function estSensible(): bool
    {
        return false;
    }

    public function executer(array $parametres, User $utilisateur): ResultatOutil
    {
        $tacheId = (int) ($parametres['tache_id'] ?? 0);
        $statut = trim((string) ($parametres['statut'] ?? ''));

        $query = Task::query();

        // Isolation facultaire stricte
        if ($utilisateur->faculteRestreinteId() !== null) {
            $facId = $utilisateur->faculteRestreinteId();
            $query->where(fn ($q) => $q->whereNull('faculte_id')->orWhere('faculte_id', $facId));
        }

        if ($tacheId > 0) {
            $tache = $query->find($tacheId);
            if (! $tache) {
                return ResultatOutil::echec('Tâche introuvable ou non autorisée dans votre périmètre facultaire.');
            }

            return ResultatOutil::succes([
                'id' => $tache->id,
                'titre' => $tache->titre,
                'statut' => $tache->statut,
                'priorite' => $tache->priorite,
                'echeance' => $tache->echeance_a?->toIso8601String(),
                'active' => $tache->estActive(),
            ], "Tâche #{$tache->id} consultée : Statut '{$tache->statut}'.");
        }

        if ($statut !== '') {
            $query->where('statut', $statut);
        } else {
            $query->whereIn('statut', ['pending', 'in_progress', 'waiting']);
        }

        $taches = $query->latest('id')->take(10)->get()->map(fn ($t) => [
            'id' => $t->id,
            'titre' => $t->titre,
            'statut' => $t->statut,
            'priorite' => $t->priorite,
            'echeance' => $t->echeance_a?->format('Y-m-d H:i'),
        ])->all();

        return ResultatOutil::succes([
            'total' => count($taches),
            'taches' => $taches,
        ], count($taches).' tâche(s) administrative(s) trouvée(s).');
    }
}
