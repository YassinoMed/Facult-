<?php

namespace App\Modules\AssistantIa\Services;

use Illuminate\Support\Facades\Log;

class JournalisationIa
{
    /**
     * Masque les données sensibles (CIN, clés API, tokens, emails, mots de passe) dans une chaîne de texte.
     */
    public static function masquerDonneesSensibles(string $texte): string
    {
        // Masquer les tokens / clés API (Bearer, sk-..., api_key, password)
        $texte = preg_replace('/(Bearer\s+)[A-Za-z0-9\-_\.~+\/]+=*/i', '$1[MASQUÉ]', $texte);
        $texte = preg_replace('/(sk-[a-zA-Z0-9]{20,})/', '[CLÉ_API_MASQUÉE]', $texte);
        $texte = preg_replace('/(password|mot_de_passe|secret|token|api_key)\s*[:=]\s*["\']?([^"\'\s]+)["\']?/i', '$1: [MASQUÉ]', $texte);

        // Masquer les CIN (8 chiffres consécutifs)
        $texte = preg_replace('/\b\d{8}\b/', 'CIN_***', $texte);

        // Masquer les emails
        $texte = preg_replace('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', '***@***.***', $texte);

        // Masquer les numéros de téléphone (+216 ou 8 chiffres commençant par 2, 4, 5, 7, 9)
        $texte = preg_replace('/(?:\+216\s*)?[24579]\d{7}/', 'TEL_***', $texte);

        return $texte;
    }

    /**
     * Nettoie récursivement un tableau de contexte pour supprimer ou masquer les secrets et PII.
     *
     * @param  array<string, mixed>  $donnees
     * @return array<string, mixed>
     */
    public static function nettoyerContexte(array $donnees): array
    {
        $clesSensibles = [
            'password', 'mot_de_passe', 'secret', 'token', 'api_key', 'cle_api',
            'cin', 'identite', 'carte_identite', 'authorization', 'bearer',
            'document_contenu', 'piece_jointe', 'donnees_personnelles',
        ];

        $nettoye = [];

        foreach ($donnees as $cle => $valeur) {
            $cleMin = strtolower((string) $cle);

            $estSensible = false;
            foreach ($clesSensibles as $motif) {
                if (str_contains($cleMin, $motif)) {
                    $estSensible = true;
                    break;
                }
            }

            if ($estSensible) {
                $nettoye[$cle] = '[MASQUÉ]';

                continue;
            }

            if (is_array($valeur)) {
                $nettoye[$cle] = self::nettoyerContexte($valeur);
            } elseif (is_string($valeur)) {
                $nettoye[$cle] = self::masquerDonneesSensibles($valeur);
            } else {
                $nettoye[$cle] = $valeur;
            }
        }

        return $nettoye;
    }

    /**
     * Journalise un événement IA avec sanitization obligatoire.
     *
     * @param  array<string, mixed>  $contexte
     */
    public static function info(string $message, array $contexte = []): void
    {
        Log::channel('daily')->info('[AssistantIa] '.self::masquerDonneesSensibles($message), self::nettoyerContexte($contexte));
    }

    /**
     * Journalise un avertissement IA avec sanitization obligatoire.
     *
     * @param  array<string, mixed>  $contexte
     */
    public static function avertissement(string $message, array $contexte = []): void
    {
        Log::channel('daily')->warning('[AssistantIa] '.self::masquerDonneesSensibles($message), self::nettoyerContexte($contexte));
    }

    /**
     * Journalise une alerte de sécurité IA.
     *
     * @param  array<string, mixed>  $contexte
     */
    public static function alerteSecurite(string $message, array $contexte = []): void
    {
        Log::channel('daily')->alert('[AssistantIa][SÉCURITÉ] '.self::masquerDonneesSensibles($message), self::nettoyerContexte($contexte));
    }

    /**
     * Journalise de manière structurée et confidentielle une décision de routage hybride.
     * N'enregistre JAMAIS les prompts bruts, les documents sources ou les secrets.
     *
     * @param  array<string, mixed>  $metadonnees
     */
    public static function journaliserRoutage(
        string $correlationId,
        string $decision,
        string $fournisseur,
        string $motif,
        int $tailleCaracteres,
        bool $consentementPresent,
        array $metadonnees = []
    ): void {
        $contexte = [
            'correlation_id' => $correlationId,
            'decision' => $decision,
            'fournisseur' => $fournisseur,
            'motif' => self::masquerDonneesSensibles($motif),
            'taille_caracteres' => $tailleCaracteres,
            'consentement_present' => $consentementPresent,
            'horodatage' => now()->toIso8601String(),
        ];

        foreach ($metadonnees as $cle => $val) {
            if (! in_array(strtolower($cle), ['prompt', 'message', 'messages', 'texte', 'secret', 'cle_api'], true)) {
                $contexte[$cle] = is_string($val) ? self::masquerDonneesSensibles($val) : $val;
            }
        }

        Log::channel('daily')->info("[AssistantIa][ROUTAGE][{$correlationId}] Décision: {$decision} | Cible: {$fournisseur}", self::nettoyerContexte($contexte));
    }
}
