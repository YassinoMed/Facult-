<?php

namespace App\Modules\AssistantIa\Services;

use App\Models\User;
use App\Modules\AssistantIa\Exceptions\SecuriteIaException;
use App\Modules\AssistantIa\Models\AiPropositionAction;
use Illuminate\Support\Facades\DB;

/**
 * Service de gestion du workflow d'approbation humaine pour les actions sensibles (2-person rule / 4-eyes).
 */
class WorkflowApprobationService
{
    /**
     * Crée une proposition d'action en attente de validation humaine.
     *
     * @param  array<string, mixed>  $parametres
     */
    public function proposerAction(
        User $demandeur,
        string $typeAction,
        string $ressourceType,
        int $ressourceId,
        string $description,
        array $parametres,
        string $resumeImpact,
        string $niveauRisque = 'moyen',
        ?int $faculteId = null
    ): AiPropositionAction {
        // Enregistrement de la proposition
        $proposition = AiPropositionAction::create([
            'user_id' => $demandeur->id,
            'faculte_id' => $faculteId ?? $demandeur->faculteRestreinteId(),
            'type_action' => $typeAction,
            'ressource_type' => $ressourceType,
            'ressource_id' => $ressourceId,
            'description' => $description,
            'parametres' => $parametres,
            'resume_impact' => $resumeImpact,
            'niveau_risque' => $niveauRisque,
            'statut' => 'pending_approval',
        ]);

        JournalisationIa::info("Nouvelle proposition d'action sensible #{$proposition->id} créée par l'utilisateur #{$demandeur->id}", [
            'type_action' => $typeAction,
            'ressource_type' => $ressourceType,
            'ressource_id' => $ressourceId,
            'faculte_id' => $proposition->faculte_id,
            'niveau_risque' => $niveauRisque,
        ]);

        return $proposition;
    }

    /**
     * Valide et approuve une action sensible sous contrôle strict des habilitations, concurrence et séparation des rôles.
     *
     * @return array{succes: bool, message: string}
     *
     * @throws SecuriteIaException
     */
    public function approuverAction(AiPropositionAction $proposition, User $approbateur, ?string $commentaire = null): array
    {
        return DB::transaction(function () use ($proposition, $approbateur, $commentaire) {
            // Verrouillage pessimiste de la ligne pour prévenir toute course concurrente
            /** @var AiPropositionAction $action */
            $action = AiPropositionAction::where('id', $proposition->id)->lockForUpdate()->firstOrFail();

            // 1. Vérification des permissions de l'approbateur
            if (! $approbateur->hasPermission('assistant_ia.action_approuver') && ! $approbateur->estSuperAdmin()) {
                throw new SecuriteIaException('Vous ne disposez pas de la permission requise pour approuver des actions.');
            }

            // 2. Isolation stricte par faculté (BOLA / Cross-Tenant)
            if ($action->faculte_id !== null) {
                FiltreSecuriteIa::verifierPerimetreFaculte($approbateur, $action->faculte_id);
            }

            // 3. Séparation des rôles (4 yeux / 2-person rule) : le demandeur ne peut pas s'auto-approuver
            $separerRoles = (bool) config('assistant-ia.approbations.separer_demandeur_approbateur', true);
            if ($separerRoles && $action->user_id === $approbateur->id && ! $approbateur->estSuperAdmin()) {
                JournalisationIa::alerteSecurite("Tentative d'auto-approbation refusée pour l'utilisateur #{$approbateur->id} sur l'action #{$action->id}");
                throw new SecuriteIaException("Règle des 4 yeux violée : l'approbateur doit être distinct du demandeur de l'action.");
            }

            // 4. Vérification d'expiration
            if ($action->estExpiree()) {
                $action->update(['statut' => 'expired']);
                throw new SecuriteIaException("Cette proposition d'action a expiré le {$action->expire_a->format('Y-m-d H:i')}. Une nouvelle demande est requise.");
            }

            // 5. Vérification de l'état (anti-rejeu et concurrence)
            if ($action->statut !== 'pending_approval') {
                throw new SecuriteIaException("Cette action a déjà été traitée (Statut actuel : {$action->statut}). Le rejeu est interdit.");
            }

            // 6. Vérification d'intégrité cryptographique anti-falsification (sha256)
            if (! $action->verifierIntegrite()) {
                JournalisationIa::alerteSecurite("Altération cryptographique détectée sur l'action #{$action->id}");
                throw new SecuriteIaException("Échec de vérification d'intégrité : le payload de l'action a été altéré.");
            }

            // 7. Application de la décision — l'assistant ne s'auto-exécute
            // JAMAIS : l'approbation valide le mandat ; l'exécution
            // effective relève du service métier concerné (LOT 9).
            $action->update([
                'approbateur_id' => $approbateur->id,
                'statut' => 'completed',
                'execute_a' => now(),
                'decision_commentaire' => $commentaire ?? 'Action approuvée et validée. Exécution métier à réaliser hors assistant.',
            ]);

            JournalisationIa::info("Action #{$action->id} ({$action->type_action}) approuvée avec succès par #{$approbateur->id}", [
                'ressource' => "{$action->ressource_type}#{$action->ressource_id}",
                'faculte_id' => $action->faculte_id,
            ]);

            return [
                'succes' => true,
                'message' => "L'action '{$action->type_action}' a été approuvée. Son exécution effective relève du service métier concerné — l'assistant ne s'auto-exécute jamais.",
            ];
        });
    }

    /**
     * Rejette une proposition d'action sous transaction atomique et contrôle de faculté.
     */
    public function rejeterAction(AiPropositionAction $proposition, User $approbateur, string $motif): void
    {
        DB::transaction(function () use ($proposition, $approbateur, $motif) {
            /** @var AiPropositionAction $action */
            $action = AiPropositionAction::where('id', $proposition->id)->lockForUpdate()->firstOrFail();

            if (! $approbateur->hasPermission('assistant_ia.action_approuver') && ! $approbateur->estSuperAdmin()) {
                throw new SecuriteIaException("Vous n'avez pas l'autorisation de rejeter des actions.");
            }

            // Isolation stricte par faculté
            if ($action->faculte_id !== null) {
                FiltreSecuriteIa::verifierPerimetreFaculte($approbateur, $action->faculte_id);
            }

            if ($action->statut !== 'pending_approval') {
                throw new SecuriteIaException("Cette action n'est plus en attente d'approbation.");
            }

            $action->update([
                'approbateur_id' => $approbateur->id,
                'statut' => 'rejected',
                'decision_commentaire' => $motif,
            ]);

            JournalisationIa::info("Action #{$action->id} rejetée par #{$approbateur->id} : {$motif}");
        });
    }
}
