<?php

namespace App\Modules\AssistantIa\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AssistantIa\Exceptions\AssistantIaException;
use App\Modules\AssistantIa\Exceptions\ConsentementRequisException;
use App\Modules\AssistantIa\Exceptions\SecuriteIaException;
use App\Modules\AssistantIa\Models\AiConversation;
use App\Modules\AssistantIa\Services\ActionPopupService;
use App\Modules\AssistantIa\Services\ContextePageService;
use App\Modules\AssistantIa\Services\GestionnaireActeurPortail;
use App\Modules\AssistantIa\Services\OrchestrateurAgentIa;
use App\Modules\AssistantIa\Services\PasserelleFournisseurs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Contrôleur mince dédié au popup conversationnel UniRegistre AI.
 * Gère l'accès sécurisé multiportail (Admin, Enseignant, Étudiant), l'injection contextuelle
 * de la page courante, l'inférence RAG et le cycle de préparation/confirmation d'actions.
 */
class AssistantIaPopupController extends Controller
{
    public function __construct(
        protected GestionnaireActeurPortail $gestionnaireActeur,
        protected ContextePageService $contexteService,
        protected ActionPopupService $actionService,
        protected OrchestrateurAgentIa $orchestrateur,
        protected PasserelleFournisseurs $passerelle
    ) {}

    /**
     * Initialise l'état du popup pour l'acteur connecté et analyse le contexte de l'écran courant.
     */
    public function initialiser(Request $request): JsonResponse
    {
        try {
            $acteur = $this->gestionnaireActeur->resoudreActeurAutorise($request);
        } catch (SecuriteIaException $e) {
            return response()->json([
                'succes' => false,
                'authentifie' => false,
                'erreur' => $e->getMessage(),
            ], 401);
        }

        $contexte = $this->contexteService->reconstruireContexte(
            acteur: $acteur,
            routeName: $request->input('route_name'),
            routeParams: (array) $request->input('route_params', []),
            pathname: $request->input('pathname')
        );

        $conversations = AiConversation::pourActeur($acteur)
            ->orderByDesc('dernier_message_a')
            ->orderByDesc('id')
            ->take(5)
            ->get(['uuid', 'titre', 'mode', 'fournisseur', 'dernier_message_a', 'portail']);

        $fournisseurLocal = $this->passerelle->obtenirFournisseur('local');
        $fournisseurGroq = $this->passerelle->obtenirFournisseur('groq');

        return response()->json([
            'succes' => true,
            'authentifie' => true,
            'acteur' => [
                'portail' => $acteur->portail,
                'nom' => $acteur->nom,
                'role' => $acteur->role,
                'peut_mode_dev' => $acteur->peutModeDeveloppeur(),
                'peut_distant' => $acteur->peutFournisseurDistant(),
            ],
            'contexte' => $contexte,
            'conversations' => $conversations,
            'fournisseurs' => [
                'local_disponible' => $fournisseurLocal->estDisponible(),
                'groq_actif' => (bool) config('assistant-ia.fournisseurs.groq.actif', false),
                'fournisseur_actif' => config('assistant-ia.fournisseur', 'local'),
            ],
        ]);
    }

    /**
     * Crée une nouvelle session de conversation scoped à l'acteur connecté.
     */
    public function creerConversation(Request $request): JsonResponse
    {
        $acteur = $this->gestionnaireActeur->resoudreActeurAutorise($request);

        $request->validate([
            'titre' => 'nullable|string|max:100',
            'mode' => 'nullable|string|in:metier,developpeur',
        ]);

        $mode = $request->input('mode', 'metier');
        if ($mode === 'developpeur' && ! $acteur->peutModeDeveloppeur()) {
            return response()->json([
                'succes' => false,
                'erreur' => 'Le mode développeur est strictement réservé aux administrateurs habilités.',
            ], 403);
        }

        $fournisseurParDefaut = config('assistant-ia.fournisseur', 'local');
        $localDisponible = $this->passerelle->obtenirFournisseur('local')->estDisponible();
        $groqActif = config('assistant-ia.fournisseurs.groq.actif', false) || config('assistant-ia.routage.distant_autorise', false);

        if (! $localDisponible && $groqActif && $acteur->peutFournisseurDistant()) {
            $fournisseurParDefaut = 'groq';
        }

        $fournisseur = $this->passerelle->obtenirFournisseur($fournisseurParDefaut);

        $conversation = AiConversation::create([
            'user_id' => $acteur->estAdmin() ? $acteur->id : null,
            'teacher_id' => $acteur->estEnseignant() ? $acteur->id : null,
            'etudiant_id' => $acteur->estEtudiant() ? $acteur->id : null,
            'portail' => $acteur->portail,
            'faculte_id' => $acteur->faculteId,
            'titre' => $request->input('titre', 'Nouvelle discussion'),
            'mode' => $mode,
            'fournisseur' => $fournisseur->nomFournisseur(),
            'modele' => $fournisseur->nomModele(),
            'dernier_message_a' => now(),
        ]);

        return response()->json([
            'succes' => true,
            'conversation' => $conversation,
        ], 201);
    }

    /**
     * Charge l'historique d'une conversation avec vérification stricte anti-IDOR.
     */
    public function afficherConversation(string $uuid, Request $request): JsonResponse
    {
        $acteur = $this->gestionnaireActeur->resoudreActeurAutorise($request);

        $conversation = AiConversation::pourActeur($acteur)
            ->where('uuid', $uuid)
            ->with(['messages'])
            ->first();

        if (! $conversation) {
            return response()->json([
                'succes' => false,
                'erreur' => 'Conversation introuvable ou non autorisée.',
            ], 404);
        }

        return response()->json([
            'succes' => true,
            'conversation' => $conversation,
            'messages' => $conversation->messages,
        ]);
    }

    /**
     * Envoie un message dans le popup, injecte le contexte de page vérifié et orchestre l'inférence.
     */
    public function envoyerMessage(string $uuid, Request $request): JsonResponse
    {
        $acteur = $this->gestionnaireActeur->resoudreActeurAutorise($request);

        $conversation = AiConversation::pourActeur($acteur)
            ->where('uuid', $uuid)
            ->first();

        if (! $conversation) {
            return response()->json([
                'succes' => false,
                'erreur' => 'Conversation introuvable ou non autorisée.',
            ], 404);
        }

        $request->validate([
            'contenu' => 'required|string|max:4000',
            'jeton_consentement' => 'nullable|string',
            'allow_remote' => 'nullable|boolean',
            'route_name' => 'nullable|string',
            'route_params' => 'nullable|array',
        ]);

        // Reconstruction sécurisée du contexte de page autorisé
        $contextePage = $this->contexteService->reconstruireContexte(
            acteur: $acteur,
            routeName: $request->input('route_name'),
            routeParams: (array) $request->input('route_params', [])
        );

        // Utilisateur synthétique d'autorisation
        $utilisateurIa = $acteur->versUtilisateurIa();

        try {
            $optionsClient = [
                'allow_remote' => $acteur->peutFournisseurDistant() && $request->boolean('allow_remote'),
                'motif_transfert' => 'Assistance contextuelle popup ('.$acteur->portail.')',
                'contexte_page' => $contextePage['invite_systeme'] ?? null,
            ];

            $resultat = $this->orchestrateur->traiterMessage(
                conversation: $conversation,
                contenuUtilisateur: (string) $request->input('contenu'),
                utilisateur: $utilisateurIa,
                jetonConsentement: $request->input('jeton_consentement'),
                optionsClient: $optionsClient
            );

            return response()->json([
                'succes' => true,
                'message' => $resultat['messageModele'],
                'reponse' => $resultat['reponse'],
                'citations' => $resultat['reponse']->citations(),
                'fournisseur' => $resultat['reponse']->fournisseur,
                'latence_ms' => $resultat['reponse']->latenceMs,
            ]);
        } catch (ConsentementRequisException $e) {
            return response()->json([
                'succes' => false,
                'statut' => 'consentement_requis',
                'erreur' => $e->getMessage(),
                'defi_consentement' => $e->obtenirDefiConsentement(),
            ], 428);
        } catch (SecuriteIaException $e) {
            return response()->json([
                'succes' => false,
                'erreur' => $e->getMessage(),
                'type' => 'securite',
            ], 403);
        } catch (AssistantIaException $e) {
            return response()->json([
                'succes' => false,
                'erreur' => $e->getMessage(),
                'type' => 'erreur_ia',
            ], $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500);
        } catch (\Throwable $e) {
            Log::error('Erreur inattendue dans le popup IA : '.$e->getMessage(), [
                'exception' => $e,
                'conversation_uuid' => $uuid,
                'acteur_portail' => $acteur->portail,
                'acteur_id' => $acteur->id,
            ]);

            return response()->json([
                'succes' => false,
                'erreur' => "Une erreur technique inattendue est survenue lors de l'échange.",
                'type' => 'erreur_systeme',
            ], 500);
        }
    }

    /**
     * Prépare une action métier sous forme de contrat sans l'exécuter.
     */
    public function preparerAction(Request $request): JsonResponse
    {
        $acteur = $this->gestionnaireActeur->resoudreActeurAutorise($request);

        $request->validate([
            'type_action' => 'required|string|max:100',
            'ressource_type' => 'required|string|max:100',
            'ressource_id' => 'required|integer',
            'justification' => 'required|string|max:500',
            'parametres' => 'nullable|array',
        ]);

        try {
            $preparation = $this->actionService->preparerAction(
                acteur: $acteur,
                typeAction: (string) $request->input('type_action'),
                ressourceType: (string) $request->input('ressource_type'),
                ressourceId: (int) $request->input('ressource_id'),
                parametres: (array) $request->input('parametres', []),
                justification: (string) $request->input('justification')
            );

            return response()->json([
                'succes' => true,
                'preparation' => $preparation,
            ], 201);
        } catch (SecuriteIaException $e) {
            return response()->json([
                'succes' => false,
                'erreur' => $e->getMessage(),
            ], 403);
        }
    }

    /**
     * Confirme explicitement une action préparée avec réautorisation serveur.
     */
    public function confirmerAction(string $actionUuid, Request $request): JsonResponse
    {
        $acteur = $this->gestionnaireActeur->resoudreActeurAutorise($request);

        try {
            $resultat = $this->actionService->confirmerAction($acteur, $actionUuid);

            return response()->json($resultat);
        } catch (SecuriteIaException $e) {
            return response()->json([
                'succes' => false,
                'erreur' => $e->getMessage(),
            ], 403);
        }
    }

    /**
     * Supprime une conversation de l'acteur (soft delete).
     */
    public function supprimerConversation(string $uuid, Request $request): JsonResponse
    {
        $acteur = $this->gestionnaireActeur->resoudreActeurAutorise($request);

        $conversation = AiConversation::pourActeur($acteur)
            ->where('uuid', $uuid)
            ->first();

        if (! $conversation) {
            return response()->json([
                'succes' => false,
                'erreur' => 'Conversation introuvable ou non autorisée.',
            ], 404);
        }

        $conversation->delete();

        return response()->json(['succes' => true]);
    }
}
