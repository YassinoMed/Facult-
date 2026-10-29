<?php

namespace App\Modules\AiAssistant\Services;

use App\Models\User;
use App\Modules\AiAssistant\Contracts\AiFournisseur;
use App\Modules\AiAssistant\Contracts\RechercheDocumentaire;
use App\Modules\AiAssistant\Data\AiDemande;
use App\Modules\AiAssistant\Data\ChunkDocumentaire;
use App\Modules\AiAssistant\Exceptions\ActionRefuseeException;
use App\Modules\AiAssistant\Exceptions\AiAssistantException;
use App\Modules\AiAssistant\Exceptions\FournisseurIndisponibleException;
use App\Modules\AiAssistant\Exceptions\OutilRefuseException;
use App\Modules\AiAssistant\Exceptions\PlafondAtteintException;
use App\Modules\AiAssistant\Models\AiConversation;
use App\Modules\AiAssistant\Models\AiMessage;
use App\Modules\AiAssistant\Models\AiMetrique;
use App\Modules\AiAssistant\Support\BudgetPlafond;
use App\Modules\AiAssistant\Support\PermissionsAi;
use App\Modules\AiAssistant\Support\TraceurIa;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Orchestration du chat (Lot 4/5) :
 * - contrôle d'accès et propriété : double-check, jamais délégués au
 *   contenu du modèle ;
 * - plafonds horaires par utilisateur/mode ;
 * - contexte : historique borné + chunks RAG filtrés par permissions ;
 * - réponse fournisseur masculée ; outils pilotés UNIQUEMENT par
 *   l'orchestrateur (jamais par le contenu de la réponse) ;
 * - journal sans PII + métriques non sensibles.
 */
final class AiChatService
{
    public function __construct(private RechercheDocumentaire $recherche) {}

    public function creerConversation(User $utilisateur, string $mode, ?int $faculteId = null, ?string $titre = null): AiConversation
    {
        $this->verifierPermissionsMode($utilisateur, $mode);

        return AiConversation::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $utilisateur->id,
            'faculte_id' => $faculteId,
            'titre' => mb_substr(trim((string) $titre) !== '' ? trim((string) $titre) : 'Nouvelle conversation', 0, 160),
            'mode' => $mode,
        ]);
    }

    /**
     * Envoie un message d'utilisateur et produit la réponse IA.
     */
    public function envoyerMessage(User $utilisateur, AiConversation $conversation, string $contenu): AiMessage
    {
        // Double-check serveur (policy contrôlée au-dessus aussi).
        if ((int) $conversation->user_id !== (int) $utilisateur->id) {
            throw new AiAssistantException('conversation_inconnue');
        }

        $this->verifierPermissionsMode($utilisateur, $conversation->mode);

        $longueurMax = (int) config('ai-assistant.limites.caracteres_message', 4000);
        if (mb_strlen($contenu) > $longueurMax) {
            throw new AiAssistantException('message_trop_long');
        }

        $contenu = trim($contenu);
        if ($contenu === '') {
            throw new AiAssistantException('message_vide');
        }

        try {
            BudgetPlafond::verifierEtIncrementer($utilisateur, $conversation->mode);

            $debut = hrtime(true);
            $messageUtilisateur = AiMessage::create([
                'conversation_id' => $conversation->id,
                'role' => 'user',
                'contenu' => $contenu,
                'tokens_prompt' => 0,
                'tokens_reponse' => 0,
                'latence_ms' => 0,
            ]);

            $chunks = $this->recherche->rechercher(
                $contenu,
                (int) config('ai-assistant.limites.chunks_rag_max', 5),
                PermissionsAi::permise(PermissionsAi::DOCUMENTS_RESTREINTS, (string) $utilisateur->role)
            );

            $reponse = app(AiFournisseur::class)->generer(
                $this->demande($conversation, $contenu, $chunks)
            );

            $messageAssistant = AiMessage::create([
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'contenu' => $reponse->texte,
                'sources' => $reponse->sources,
                'tokens_prompt' => $reponse->jetonsEntree ?? 0,
                'tokens_reponse' => $reponse->jetonsSortie ?? 0,
                'latence_ms' => $reponse->latenceMs,
            ]);

            $conversation->fournisseur = $reponse->fournisseur;
            $conversation->modele = $reponse->modele;
            $conversation->dernier_message_a = now();
            $conversation->save();

            AiMetrique::create([
                'conversation_id' => $conversation->id,
                'utilisateur_id' => $utilisateur->id,
                'fournisseur' => $reponse->fournisseur,
                'modele' => $reponse->modele,
                'outil' => null,
                'etat' => AiMetrique::ETAT_OK,
                'code_erreur' => null,
                'latence_ms' => $reponse->latenceMs,
                'jetons_entree' => $reponse->jetonsEntree,
                'jetons_sortie' => $reponse->jetonsSortie,
            ]);

            TraceurIa::evenement('generer.reponse', [
                'fournisseur' => $reponse->fournisseur,
                'modele' => $reponse->modele,
                'latence_ms' => $reponse->latenceMs,
                'jetons' => ($reponse->jetonsEntree ?? 0) + ($reponse->jetonsSortie ?? 0),
                'chunks' => count($chunks), // Niveau d'ancrage documentaire (aucun contenu).
                'outil' => null,
                'etat' => 'ok',
            ]);

            return $messageAssistant;
        } catch (PlafondAtteintException $erreur) {
            $this->retracerRefus($utilisateur, $conversation, null, AiMetrique::ETAT_PLAFOND, $erreur);

            throw $erreur;
        } catch (ActionRefuseeException|OutilRefuseException $erreur) {
            $this->retracerRefus($utilisateur, $conversation, null, AiMetrique::ETAT_REFUSE, $erreur);

            throw $erreur;
        } catch (FournisseurIndisponibleException $erreur) {
            $this->retracerEchec($utilisateur, $conversation, $erreur);

            throw $erreur;
        }
    }

    /**
     * @return Collection<int, AiMessage>
     */
    public function messagesRecents(AiConversation $conversation, int $limite = 50): Collection
    {
        return AiMessage::query()
            ->where('conversation_id', $conversation->id)
            ->orderByDesc('id')
            ->limit($limite)
            ->get()
            ->reverse()
            ->values();
    }

    public function conversationsUtilisateur(User $utilisateur, int $perPage = 20)
    {
        /** @var LengthAwarePaginator $paginator */
        $paginator = AiConversation::pourUtilisateur($utilisateur->id)
            ->parDernierMessage()
            ->paginate($perPage);

        return $paginator;
    }

    public function supprimerConversation(User $utilisateur, AiConversation $conversation): void
    {
        if ((int) $conversation->user_id !== (int) $utilisateur->id) {
            throw new AiAssistantException('conversation_inconnue');
        }

        // La FK canonique (conversation_id → ai_conversations ON DELETE
        // CASCADE) suppresse les messages UNIQUEMENT à la suppression
        // physique ; soft-delete conserve la trace privée de l'owner.
        $conversation->delete();
    }

    private function demande(AiConversation $conversation, string $contenu, array $chunks): AiDemande
    {
        $historique = AiMessage::query()
            ->where('conversation_id', $conversation->id)
            ->orderByDesc('id')
            ->skip(1)
            ->take((int) config('ai-assistant.contexte.messages_derniers', 12))
            ->get()
            ->reverse()
            ->map(fn (AiMessage $message) => [
                'role' => $message->role === 'assistant' ? 'assistant' : 'user',
                'contenu' => mb_substr($message->contenu, 0, 2000),
            ])
            ->all();

        return new AiDemande(
            question: $contenu,
            mode: $conversation->mode,
            utilisateurId: (int) $conversation->user_id,
            faculteId: $conversation->faculte_id,
            contexte: array_map(
                fn (ChunkDocumentaire $chunk) => $chunk->pourContexte(),
                $chunks
            ),
            historique: $historique,
            outilsAutorises: [], // Lot 7/8 : pilotage déterministe côté orchestrateur.
            jetonsMax: (int) config('ai-assistant.limites.jetons_max', 2000),
        );
    }

    private function verifierPermissionsMode(User $utilisateur, string $mode): void
    {
        $permission = $mode === 'developpeur' ? PermissionsAi::DEVELOPPEUR : PermissionsAi::METIER;

        if (! PermissionsAi::permise($permission, (string) $utilisateur->role)) {
            throw new ActionRefuseeException("mode_ia_{$mode}", 'permission absente');
        }
    }

    private function retracerRefus(
        User $utilisateur,
        AiConversation $conversation,
        ?string $outil,
        string $etat,
        AiAssistantException $erreur,
    ): void {
        AiMetrique::create([
            'conversation_id' => $conversation->id,
            'utilisateur_id' => $utilisateur->id,
            'fournisseur' => 'inconnu',
            'modele' => 'inconnu',
            'outil' => $outil,
            'etat' => $etat,
            'code_erreur' => $erreur->codePublic(),
            'latence_ms' => null,
            'jetons_entree' => null,
            'jetons_sortie' => null,
        ]);

        TraceurIa::evenement('generer.refus', [
            'outil' => $outil ?? 'aucun',
            'etat' => $etat,
        ]);
    }

    private function retracerEchec(
        User $utilisateur,
        AiConversation $conversation,
        FournisseurIndisponibleException $erreur,
    ): void {
        AiMetrique::create([
            'conversation_id' => $conversation->id,
            'utilisateur_id' => $utilisateur->id,
            'fournisseur' => 'inconnu',
            'modele' => 'inconnu',
            'outil' => null,
            'etat' => AiMetrique::ETAT_ECHEC,
            'code_erreur' => $erreur->codePublic(),
            'latence_ms' => null,
            'jetons_entree' => null,
            'jetons_sortie' => null,
        ]);

        TraceurIa::evenement('generer.echec', ['outil' => 'aucun', 'etat' => 'echec']);
    }
}
