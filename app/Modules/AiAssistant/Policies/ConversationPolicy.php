<?php

namespace App\Modules\AiAssistant\Policies;

use App\Models\User;
use App\Modules\AiAssistant\Models\AiConversation;

/**
 * POLICY des conversations IA (Lot 3/5) : PRIVÉES par défaut. AUCUN
 * accès horizontal : seul le propriétaire consulte, complète, liste
 * ses conversations ; l'approbation ne donne pas de lecture générale.
 */
final class ConversationPolicy
{
    public function view(User $utilisateur, AiConversation $conversation): bool
    {
        return (int) $conversation->user_id === (int) $utilisateur->id;
    }

    public function envoyerMessage(User $utilisateur, AiConversation $conversation): bool
    {
        return (int) $conversation->user_id === (int) $utilisateur->id;
    }

    public function destroy(User $utilisateur, AiConversation $conversation): bool
    {
        return (int) $conversation->user_id === (int) $utilisateur->id;
    }
}
