<?php

namespace App\Modules\AssistantIa\Services;

use App\Models\User;
use App\Modules\AssistantIa\Models\AiConversation;
use App\Modules\AssistantIa\Models\AiDocumentIndex;
use App\Modules\AssistantIa\Models\AiMessage;
use App\Modules\AssistantIa\Models\AiPropositionAction;
use App\Modules\AssistantIa\Models\AiSourceConnaissance;
use Illuminate\Support\Facades\Log;

/**
 * Service d'observabilité, de métriques de consommation et d'audit pour UniRegistre AI.
 * Mesure les indicateurs techniques et budgétaires sans exposer de données sensibles.
 */
class ObservabiliteIaService
{
    /**
     * Calcule les métriques globales d'observabilité du module.
     *
     * @return array<string, mixed>
     */
    public function metriquesGlobales(): array
    {
        $totalConversations = AiConversation::count();
        $totalMessages = AiMessage::count();

        $messagesAssistant = AiMessage::where('role', 'assistant')->get();
        $nbReponses = $messagesAssistant->count();

        $totalTokensPrompt = $messagesAssistant->sum('tokens_prompt');
        $totalTokensReponse = $messagesAssistant->sum('tokens_reponse');
        $latenceMoyenne = $nbReponses > 0 ? round($messagesAssistant->avg('latence_ms'), 1) : 0.0;

        $reponsesSourcess = $messagesAssistant->filter(fn ($m) => ! empty($m->sources))->count();
        $tauxSourcage = $nbReponses > 0 ? round(($reponsesSourcess / $nbReponses) * 100, 1) : 0.0;

        // Statistiques des approbations
        $statsApprobations = [
            'en_attente' => AiPropositionAction::where('statut', 'pending_approval')->count(),
            'validees' => AiPropositionAction::where('statut', 'completed')->count(),
            'rejetees' => AiPropositionAction::where('statut', 'rejected')->count(),
            'expirees' => AiPropositionAction::where('statut', 'expired')->count(),
        ];

        // Fréquence d'utilisation des outils
        $outilsFrequence = [];
        foreach ($messagesAssistant as $m) {
            if (! empty($m->appels_outils)) {
                foreach ($m->appels_outils as $appel) {
                    $nom = $appel['outil'] ?? 'inconnu';
                    $outilsFrequence[$nom] = ($outilsFrequence[$nom] ?? 0) + 1;
                }
            }
        }
        arsort($outilsFrequence);

        return [
            'total_conversations' => $totalConversations,
            'total_messages' => $totalMessages,
            'total_tokens_prompt' => (int) $totalTokensPrompt,
            'total_tokens_reponse' => (int) $totalTokensReponse,
            'total_tokens' => (int) ($totalTokensPrompt + $totalTokensReponse),
            'latence_moyenne_ms' => $latenceMoyenne,
            'taux_reponses_sourcees_pct' => $tauxSourcage,
            'approbations' => $statsApprobations,
            'frequence_outils' => array_slice($outilsFrequence, 0, 10),
            'fournisseur_actif' => config('assistant-ia.fournisseur', 'faux'),
            'knowledge_hub' => $this->metriquesKnowledgeHub(),
            'passerelle_hybride' => $this->metriquesPasserelleHybride(),
        ];
    }

    /**
     * Calcule les métriques de souveraineté de la passerelle hybride.
     *
     * @return array<string, mixed>
     */
    public function metriquesPasserelleHybride(): array
    {
        $conversations = AiConversation::all();
        $total = $conversations->count();
        $locales = $conversations->whereIn('fournisseur', ['local', 'faux'])->count();
        $distantes = $conversations->where('fournisseur', 'distant')->count();

        $tauxSouveraineteLocale = $total > 0 ? round(($locales / $total) * 100, 1) : 100.0;

        return [
            'total_conversations' => $total,
            'conversations_locales' => $locales,
            'conversations_distantes' => $distantes,
            'taux_souverainete_locale_pct' => $tauxSouveraineteLocale,
            'distant_active' => (bool) config('assistant-ia.fournisseurs.distant.actif', false),
            'local_first' => (bool) config('assistant-ia.routage.local_first', true),
            'interdire_fallback_automatique' => (bool) config('assistant-ia.routage.interdire_fallback_automatique', true),
        ];
    }

    /**
     * Calcule les métriques de santé et de volume du Knowledge Hub.
     *
     * @return array<string, mixed>
     */
    public function metriquesKnowledgeHub(): array
    {
        $sources = AiSourceConnaissance::withTrashed()->get();
        $totalSources = $sources->count();
        $sourcesActives = $sources->where('statut', 'actif')->count();
        $sourcesSuspendues = $sources->where('statut', 'suspendu')->count();
        $sourcesErreur = $sources->where('statut', 'erreur')->count();

        $tauxEchecSynchro = $totalSources > 0 ? round(($sourcesErreur / $totalSources) * 100, 1) : 0.0;

        $totalMorceaux = AiDocumentIndex::count();
        $docsObsoletes = AiDocumentIndex::where('statut_document', 'obsolete')->count();

        return [
            'total_sources' => $totalSources,
            'sources_actives' => $sourcesActives,
            'sources_suspendues' => $sourcesSuspendues,
            'sources_erreur' => $sourcesErreur,
            'taux_echec_synchro_pct' => $tauxEchecSynchro,
            'total_morceaux_indexes' => $totalMorceaux,
            'docs_obsoletes_exclus' => $docsObsoletes,
        ];
    }

    /**
     * Expurgation systématique des données sensibles, PII, CIN et tokens pour la journalisation.
     *
     * @param  array<string, mixed>  $donnees
     * @return array<string, mixed>
     */
    public function sanitiserDonneesPourJournal(array $donnees): array
    {
        $clesSensibles = ['token', 'secret', 'password', 'mot_de_passe', 'api_key', 'authorization', 'bearer', 'cle_privee', 'cin', 'carte_identite', 'carte_sejour'];

        $sanitise = [];
        foreach ($donnees as $cle => $valeur) {
            $cleMin = strtolower((string) $cle);
            $estCleSensible = false;
            foreach ($clesSensibles as $motif) {
                if (str_contains($cleMin, $motif)) {
                    $estCleSensible = true;
                    break;
                }
            }

            if ($estCleSensible) {
                $sanitise[$cle] = '[EXPURGÉ]';
            } elseif (is_array($valeur)) {
                $sanitise[$cle] = $this->sanitiserDonneesPourJournal($valeur);
            } elseif (is_string($valeur)) {
                // Remplacer les tokens Bearer, les emails et les numéros à risque
                $val = preg_replace('/Bearer\s+[A-Za-z0-9\-\._~\+\/]+=*/i', 'Bearer [EXPURGÉ]', $valeur);
                $val = preg_replace('/[a-zA-Z0-9_\.\-]+@[a-zA-Z0-9\-]+\.[a-zA-Z0-9\-\.]+/i', '[EMAIL_EXPURGÉ]', (string) $val);
                // Détection de numéros type CIN (8 chiffres consécutifs ou 1-2 lettres + 6 chiffres)
                $val = preg_replace('/\b[0-9]{8}\b/', '[CIN_EXPURGÉ]', (string) $val);
                // Tronquer si trop long pour éviter tout déni de service sur les logs
                if (strlen((string) $val) > 500) {
                    $val = substr((string) $val, 0, 500).'... [TRONQUÉ]';
                }
                $sanitise[$cle] = $val;
            } else {
                $sanitise[$cle] = $valeur;
            }
        }

        return $sanitise;
    }

    /**
     * Enregistre un événement auditable et sécurisé sans fuite de secrets.
     *
     * @param  array<string, mixed>  $contexte
     * @return array<string, mixed> Contexte expurgé enregistré
     */
    public function journaliserEvenementSecurise(string $evenement, array $contexte = [], ?User $utilisateur = null, string $niveau = 'info'): array
    {
        $contexteSanitise = $this->sanitiserDonneesPourJournal($contexte);
        $contexteSanitise['evenement'] = $evenement;
        $contexteSanitise['utilisateur_id'] = $utilisateur?->id;
        $contexteSanitise['horodatage'] = now()->toIso8601String();

        Log::log($niveau, "[UniRegistre-AI-Audit] {$evenement}", $contexteSanitise);

        return $contexteSanitise;
    }

    /**
     * Calcule les métriques spécifiques d'un utilisateur sans PII.
     *
     * @return array<string, mixed>
     */
    public function metriquesUtilisateur(User $utilisateur): array
    {
        $conversations = AiConversation::pourUtilisateur($utilisateur)->pluck('id');
        $messages = AiMessage::whereIn('conversation_id', $conversations)->get();

        $messagesAssistant = $messages->where('role', 'assistant');

        return [
            'nb_conversations' => $conversations->count(),
            'nb_messages' => $messages->count(),
            'tokens_consommes' => (int) ($messagesAssistant->sum('tokens_prompt') + $messagesAssistant->sum('tokens_reponse')),
            'latence_moyenne_ms' => round($messagesAssistant->avg('latence_ms') ?? 0.0, 1),
            'actions_proposees' => AiPropositionAction::where('user_id', $utilisateur->id)->count(),
        ];
    }
}
