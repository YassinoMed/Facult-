<?php

namespace App\Modules\Evaluation\Services;

use App\Modules\Evaluation\Events\EvaluationChanged;
use App\Modules\Evaluation\Models\GroupeVirtuelExamen;

/**
 * Point unique d'émission des événements de notification du module
 * Évaluation (§2.18).
 *
 * IMPORTANT — sémantique honnête : cette classe ne prouve pas une
 * livraison. Elle émet un événement post-commit qui matérialise
 * l'intention de notifier ; la génération, l'envoi et la remise réelle
 * restent la responsabilité des canaux configurés (portail, e-mail).
 * Aucun test ne doit en déduire qu'un message a été délivré.
 */
class EvaluationNotificationService
{
    public function regroupementVirtuelPropose(GroupeVirtuelExamen $groupe, ?int $auteurId = null): void
    {
        EvaluationChanged::dispatch('regroupement_virtuel.propose', (int) $groupe->id);
    }
}
