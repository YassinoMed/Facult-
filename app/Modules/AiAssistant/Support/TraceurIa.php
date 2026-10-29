<?php

namespace App\Modules\AiAssistant\Support;

use Illuminate\Support\Facades\Log;

/**
 * Traceur du module IA (Lot 1/11) — journalisation SANS données
 * sensibles : seuls les champs explicitement autorisés par la config
 * (journalisation.champs_autorises) atteignent les logs. AUCUN contenu
 * de prompt, de réponse, ni de PII (CIN, documents) n'est journalisé.
 */
final class TraceurIa
{
    /**
     * @param  array<string, mixed>  $champs
     */
    public static function evenement(string $evenement, array $champs = []): void
    {
        $champsAutorises = array_flip(
            (array) config('ai-assistant.journalisation.champs_autorises', [])
        );

        // Filtrage strict : tout champ hors liste est abandonné.
        $retenu = array_intersect_key($champs, $champsAutorises);

        Log::info("[ai] {$evenement}", $retenu + ['ia_module' => true]);
    }
}
