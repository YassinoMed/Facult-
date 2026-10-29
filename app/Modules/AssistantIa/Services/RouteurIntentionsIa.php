<?php

namespace App\Modules\AssistantIa\Services;

use App\Models\User;

/**
 * Service d'analyse et de routage des intentions utilisateur pour UniRegistre AI.
 * Détermine le type de demande, les sources requises et les permissions obligatoires côté serveur.
 */
class RouteurIntentionsIa
{
    public const INTENTIONS = [
        'code_query',          // Question sur le code source ou l'architecture
        'code_patch',          // Demande de proposition de patch ou modification
        'tech_diagnostic',     // Diagnostic de bug, exception ou trace d'erreur
        'web_search',          // Recherche de documentation technique externe officielle
        'business_query',      // Question sur les règles métier, LMD, examens ou scolarité
        'business_data',       // Consultation de données métier (dossier, statut tâche)
        'action_prep',         // Préparation d'une action sensible de scolarité
        'explanation',         // Demande d'explication pédagogique générale
        'ambiguous',           // Demande ambiguë nécessitant clarification
    ];

    /**
     * Analyse le message et classe l'intention principale.
     *
     * @return array{intention: string, sources_recommandees: array<string>, permission_requise: ?string, necessite_web: bool, necessite_approbation: bool}
     */
    public function classifier(string $message, User $utilisateur, string $mode = 'metier'): array
    {
        $texte = mb_strtolower(trim($message));

        // 1. Détection des demandes de patch / modification de code
        if (preg_match('/\b(propose|cr[ée]e|g[ée]n[èe]re|applique|fais)\s+(un\s+)?(patch|correctif|diff|modification\s+de\s+code)\b/iu', $texte)) {
            return [
                'intention' => 'code_patch',
                'sources_recommandees' => ['project_code', 'technical_documentation'],
                'permission_requise' => 'assistant_ia.action_proposer',
                'necessite_web' => false,
                'necessite_approbation' => true,
            ];
        }

        // 2. Détection de diagnostic technique
        if (preg_match('/\b(erreur|exception|bug|stack\s*trace|crash|sqlstate|queryexception|500|404|timeout)\b/iu', $texte)) {
            return [
                'intention' => 'tech_diagnostic',
                'sources_recommandees' => ['project_code', 'loop_report'],
                'permission_requise' => 'assistant_ia.mode_developpeur',
                'necessite_web' => false,
                'necessite_approbation' => false,
            ];
        }

        // 3. Détection de questions sur le code ou l'architecture
        if ($mode === 'developpeur' || preg_match('/\b(contr[ôo]leur|service|mod[èe]le|migration|route|middleware|policy|m[ée]thode|classe|namespace|table|bdd|repository|code)\b/iu', $texte)) {
            return [
                'intention' => 'code_query',
                'sources_recommandees' => ['project_code', 'technical_documentation', 'loop_report'],
                'permission_requise' => 'assistant_ia.mode_developpeur',
                'necessite_web' => false,
                'necessite_approbation' => false,
            ];
        }

        // 4. Détection de recherche documentaire externe (Laravel, PHP, OWASP, MySQL, Ollama)
        if (preg_match('/\b(laravel\s*(10|11|12)?|php\s*(8\.[0-5])?|mysql\s*8|ollama|owasp|composer|rfc|mdn)\b/iu', $texte)
            || preg_match('/\b(documentation\s+officielle|doc\s+externe|sur\s+le\s+web|derni[èe]re\s+version)\b/iu', $texte)) {
            return [
                'intention' => 'web_search',
                'sources_recommandees' => ['approved_web_source', 'technical_documentation'],
                'permission_requise' => 'assistant_ia.recherche_doc',
                'necessite_web' => true,
                'necessite_approbation' => false,
            ];
        }

        // 5. Détection de préparation d'action administrative sensible
        if (preg_match('/\b(valider|rejeter|inscrire|d[ée]bloquer|modifier|supprimer)\s+(le\s+)?(dossier|statut|inscription|note)\b/iu', $texte)) {
            return [
                'intention' => 'action_prep',
                'sources_recommandees' => ['business_procedure'],
                'permission_requise' => 'assistant_ia.action_proposer',
                'necessite_web' => false,
                'necessite_approbation' => true,
            ];
        }

        // 6. Détection de consultation de données métier spécifiques
        if (preg_match('/\b(dossier\s+num[ée]ro|pi[èe]ces?\s+manquantes?|statut\s+(du\s+dossier|de\s+la\s+t[âa]che)|pourquoi\s+le\s+dossier\s+est\s+bloqu[ée])\b/iu', $texte)) {
            return [
                'intention' => 'business_data',
                'sources_recommandees' => ['business_procedure'],
                'permission_requise' => 'assistant_ia.mode_metier',
                'necessite_web' => false,
                'necessite_approbation' => false,
            ];
        }

        // 7. Question métier générale (LMD, Inscriptions, Examens, etc.)
        if (preg_match('/\b(inscriptions?|semestres?|cr[ée]dits?|ue|ecue|sessions?|rattrapages?|mentions?|parcours|notes?|moyennes?|dispenses?|dettes?|lmd|ects)\b/iu', $texte)) {
            return [
                'intention' => 'business_query',
                'sources_recommandees' => ['business_procedure'],
                'permission_requise' => 'assistant_ia.mode_metier',
                'necessite_web' => false,
                'necessite_approbation' => false,
            ];
        }

        // 8. Message trop court ou ambigu
        if (mb_strlen($texte) < 10) {
            return [
                'intention' => 'ambiguous',
                'sources_recommandees' => [],
                'permission_requise' => null,
                'necessite_web' => false,
                'necessite_approbation' => false,
            ];
        }

        return [
            'intention' => 'explanation',
            'sources_recommandees' => ['business_procedure'],
            'permission_requise' => 'assistant_ia.mode_metier',
            'necessite_web' => false,
            'necessite_approbation' => false,
        ];
    }
}
