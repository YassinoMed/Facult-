<?php

namespace App\Modules\AssistantIa\Services;

use App\Modules\AssistantIa\Models\AiConversation;
use App\Modules\AssistantIa\Models\AiMessage;
use Carbon\Carbon;

/**
 * Gestionnaire de mémoire, de contexte glissant et de rétention pour UniRegistre AI.
 */
class GestionnaireMemoireIa
{
    /**
     * Limite stricte de contexte : extrait les N derniers messages pour l'inférence.
     *
     * @return array<int, array{role: string, content: string}>
     */
    public function extraireContexteGlissant(AiConversation $conversation, int $maxMessages = 20): array
    {
        $messages = AiMessage::where('conversation_id', $conversation->id)
            ->orderBy('id', 'desc')
            ->take($maxMessages)
            ->get()
            ->reverse();

        $contexte = [];
        foreach ($messages as $msg) {
            $contexte[] = [
                'role' => $msg->role,
                'content' => $msg->contenu,
            ];
        }

        return $contexte;
    }

    /**
     * Génère un résumé factuel d'une longue conversation pour compacter le contexte.
     */
    public function genererResumeFactuel(AiConversation $conversation): string
    {
        $messages = AiMessage::where('conversation_id', $conversation->id)->orderBy('id')->get();
        if ($messages->count() <= 6) {
            return '';
        }

        $premierMessage = $messages->firstWhere('role', 'user')?->contenu ?? 'Discussion';
        $nbMessages = $messages->count();
        $dateDebut = $conversation->created_at->format('Y-m-d H:i');

        return "Résumé de contexte: Conversation initiée le {$dateDebut} comprenant {$nbMessages} échanges autour de : '".mb_substr($premierMessage, 0, 100)."'.";
    }

    /**
     * Purge les conversations et messages obsolètes selon la politique de rétention légale.
     *
     * @return int Nombre de conversations purgées
     */
    public function purgerConversationsObsoletes(int $joursRetention = 90): int
    {
        $dateLimite = Carbon::now()->subDays($joursRetention);

        $conversationsAPurger = AiConversation::onlyTrashed()
            ->where('deleted_at', '<', $dateLimite)
            ->get();

        $count = 0;
        foreach ($conversationsAPurger as $conv) {
            AiMessage::where('conversation_id', $conv->id)->delete();
            $conv->forceDelete();
            $count++;
        }

        if ($count > 0) {
            JournalisationIa::info("Purge de rétention RGPD exécutée : {$count} conversation(s) expirée(s) définitivement supprimée(s).");
        }

        return $count;
    }
}
