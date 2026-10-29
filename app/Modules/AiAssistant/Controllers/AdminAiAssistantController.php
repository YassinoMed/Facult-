<?php

namespace App\Modules\AiAssistant\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\AiAssistant\Models\AiConversation;
use App\Modules\AiAssistant\Models\AiMessage;
use App\Modules\AiAssistant\Services\AiChatService;
use App\Modules\AiAssistant\Support\EchecDoux;
use App\Modules\AiAssistant\Support\PermissionsAi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Throwable;

/**
 * Contrôleur back-office du chat IA (Lot 4).
 * Middlewares de groupe (provider) : web + module:ai-assistant (404 si
 * module désactivé) ; chaque action ajoute auth + can:<permission>.
 *
 * Sécurité : la propriété est vérifiée par la policy (404 en cas de non
 * propriété — pas 403, pour ne pas confirmer l'existence de la ressource),
 * puis re-vérifiée par le service (défense en profondeur).
 */
final class AdminAiAssistantController extends Controller
{
    public function __construct(private AiChatService $chat) {}

    public function index(Request $request): View
    {
        $this->authorizeOutil(PermissionsAi::ACCEDER);

        /** @var User|null $utilisateur */
        $utilisateur = $request->user();

        $conversations = $this->chat->conversationsUtilisateur($utilisateur);
        $convActive = null;
        /** @var Collection<int, AiMessage> $messages */
        $messages = collect();

        if (is_numeric($request->query('conversation'))) {
            $convActive = AiConversation::pourUtilisateur($utilisateur->id)
                ->find((int) $request->query('conversation'));

            if ($convActive !== null) {
                $messages = $this->chat->messagesRecents($convActive);
            }
        }

        return view('modules.ai-assistant.index', [
            'conversations' => $conversations,
            'convActive' => $convActive,
            'messages' => $messages,
            'utilisateur' => $utilisateur,
            'peutDeveloppeur' => PermissionsAi::permise(PermissionsAi::DEVELOPPEUR, (string) $utilisateur->role),
            'peutApprouver' => PermissionsAi::permise(PermissionsAi::ACTION_APPROUVER, (string) $utilisateur->role),
        ]);
    }

    /**
     * Crée une nouvelle conversation privée. JSON consommé par Alpine.
     */
    public function creerConversation(Request $request): JsonResponse
    {
        $this->authorizeOutil(PermissionsAi::ACCEDER);

        /** @var \Illuminate\Validation\Validator $validator */
        $validator = Validator::make($request->all(), [
            'mode' => ['required', 'in:developpeur,metier'],
            'titre' => ['nullable', 'string', 'max:160'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'erreur' => 'ai.demande_invalide',
                'message' => 'Paramètres de conversation invalides.',
            ], 422);
        }

        /** @var User|null $utilisateur */
        $utilisateur = $request->user();
        $mode = (string) $request->string('mode');

        if (! PermissionsAi::permise(
            $mode === 'developpeur' ? PermissionsAi::DEVELOPPEUR : PermissionsAi::METIER,
            (string) $utilisateur->role
        )) {
            return response()->json([
                'erreur' => 'ai.action.refusee',
                'message' => 'Mode de conversation non autorisé pour votre rôle.',
            ], 403);
        }

        $conversation = $this->chat->creerConversation(
            $utilisateur,
            $mode,
            $utilisateur->faculte_id,
            $request->string('titre')->toString() ?: null,
        );

        return response()->json([
            'conversation' => [
                'id' => $conversation->id,
                'uuid' => $conversation->uuid,
                'titre' => $conversation->titre,
                'mode' => $conversation->mode,
            ],
        ], 201);
    }

    /**
     * Liste JSON des conversations du propriétaire (pagination simple).
     */
    public function conversations(Request $request): JsonResponse
    {
        $this->authorizeOutil(PermissionsAi::ACCEDER);

        /** @var User|null $utilisateur */
        $utilisateur = $request->user();
        $page = max(1, (int) $request->query('page', '1'));
        $perPage = 20;

        $paginateur = $this->chat->conversationsUtilisateur($utilisateur, $perPage);
        $paginateur->withQueryString();

        $items = collect($paginateur->items())->map(fn (AiConversation $conv) => [
            'id' => $conv->id,
            'titre' => (string) $conv->titre,
            'mode' => (string) $conv->mode,
            'epingle' => (bool) $conv->epingle,
            'dernier_message_a' => optional($conv->dernier_message_a)->toIso8601String(),
        ]);

        return response()->json([
            'data' => $items,
            'page' => $page,
            'total' => $paginateur->total(),
        ]);
    }

    /**
     * Messages d'une conversation du propriétaire (Bornés).
     */
    public function messages(Request $request, AiConversation $conversation): JsonResponse
    {
        $this->authorizeOutil(PermissionsAi::ACCEDER);

        if (! Gate::forUser($request->user())->allows('view', $conversation)) {
            abort(404);
        }

        /** @var Collection<int, AiMessage> $messages */
        $messages = $this->chat->messagesRecents($conversation);

        return response()->json([
            'conversation' => [
                'id' => $conversation->id,
                'titre' => (string) $conversation->titre,
                'mode' => (string) $conversation->mode,
            ],
            'messages' => $messages->map(fn (AiMessage $message) => [
                'id' => $message->id,
                'role' => (string) $message->role,
                'contenu' => (string) $message->contenu,
                'sources' => $message->sources ?? [],
                'fournisseur' => optional($message->conversation)->fournisseur,
                'modele' => optional($message->conversation)->modele,
            ]),
        ]);
    }

    /**
     * Envoie un message utilisateur et retourne la réponse IA.
     */
    public function envoyerMessage(Request $request, AiConversation $conversation): JsonResponse
    {
        $this->authorizeOutil(PermissionsAi::ACCEDER);

        if (! Gate::forUser($request->user())->allows('envoyerMessage', $conversation)) {
            abort(404);
        }

        /** @var \Illuminate\Validation\Validator $validator */
        $validator = Validator::make($request->all(), [
            'contenu' => ['required', 'string', 'max:'.config('ai-assistant.limites.caracteres_message', 4000)],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'erreur' => 'ai.demande_invalide',
                'message' => 'Message vide ou trop long.',
            ], 422);
        }

        /** @var User|null $utilisateur */
        $utilisateur = $request->user();

        try {
            /** @var AiMessage $assistant */
            $assistant = $this->chat->envoyerMessage(
                $utilisateur,
                $conversation,
                $request->string('contenu')->toString()
            );

            return response()->json([
                'message' => [
                    'id' => $assistant->id,
                    'role' => 'assistant',
                    'contenu' => (string) $assistant->contenu,
                    'sources' => $assistant->sources ?? [],
                    'fournisseur' => $conversation->fournisseur,
                    'modele' => $conversation->modele,
                ],
            ]);
        } catch (Throwable $erreur) {
            [$code, $reponse] = EchecDoux::versReponse($erreur);

            return response()->json($reponse, $code);
        }
    }

    /**
     * Suppression d'une conversation du propriétaire.
     */
    public function detruire(Request $request, AiConversation $conversation): JsonResponse
    {
        $this->authorizeOutil(PermissionsAi::ACCEDER);

        if (! Gate::forUser($request->user())->allows('destroy', $conversation)) {
            abort(404);
        }

        /** @var User|null $utilisateur */
        $utilisateur = $request->user();

        $this->chat->supprimerConversation($utilisateur, $conversation);

        return response()->json(['supprime' => true]);
    }

    private function authorizeOutil(string $permission): void
    {
        Gate::authorize($permission);
    }
}
