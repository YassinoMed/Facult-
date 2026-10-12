<?php

namespace App\Modules\Tasks\Listeners;

use App\Modules\OnlineRequests\Events\OnlineRequestSubmitted;
use App\Modules\Tasks\Services\TaskService;

/**
 * Demande soumise ⇒ tâche immédiate pour le service compétent
 * (§9.5). La génération quotidienne consolide ensuite (SLA…).
 */
class CreerTacheDemande
{
    public function __construct(private TaskService $taches) {}

    public function handle(OnlineRequestSubmitted $event): void
    {
        $demande = $event->demande;

        $this->taches->synchroniser("demande:{$demande->id}", [
            'titre' => "Traiter {$demande->reference}",
            'description' => $demande->objet,
            'source' => 'demande',
            'priorite' => 'normal',
            'echeance_a' => $demande->echeance(),
            'lien' => route('admin.demandes.show', $demande),
            'faculte_id' => $demande->faculte_id,
        ]);
    }
}
