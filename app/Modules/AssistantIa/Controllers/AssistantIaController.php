<?php

namespace App\Modules\AssistantIa\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\AssistantIa\Drivers\FournisseurLocal;
use App\Modules\AssistantIa\Exceptions\AssistantIaException;
use App\Modules\AssistantIa\Exceptions\ConsentementRequisException;
use App\Modules\AssistantIa\Exceptions\SecuriteIaException;
use App\Modules\AssistantIa\Models\AiConversation;
use App\Modules\AssistantIa\Models\AiDocumentIndex;
use App\Modules\AssistantIa\Models\AiPropositionAction;
use App\Modules\AssistantIa\Models\AiSourceConnaissance;
use App\Modules\AssistantIa\Services\GestionnaireConsentementIa;
use App\Modules\AssistantIa\Services\JournalisationIa;
use App\Modules\AssistantIa\Services\OrchestrateurAgentIa;
use App\Modules\AssistantIa\Services\PasserelleFournisseurs;
use App\Modules\AssistantIa\Services\RegistreSourcesConnaissances;
use App\Modules\AssistantIa\Services\WorkflowApprobationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class AssistantIaController extends Controller
{
    public function __construct(
        protected OrchestrateurAgentIa $orchestrateur,
        protected PasserelleFournisseurs $passerelle
    ) {}

    /**
     * Vérifie que le compte utilisateur est authentifié et actif.
     */
    protected function verifierUtilisateurActif(Request $request): User
    {
        $utilisateur = $request->user();
        abort_unless($utilisateur && $utilisateur->estActif(), 403, 'Compte inactif ou désactivé.');

        return $utilisateur;
    }

    /**
     * Page principale du cockpit de l'assistant IA.
     */
    public function index(Request $request): View|JsonResponse
    {
        $utilisateur = $this->verifierUtilisateurActif($request);

        $conversations = AiConversation::pourUtilisateur($utilisateur)
            ->orderByDesc('dernier_message_a')
            ->orderByDesc('id')
            ->paginate(15);

        $conversationActive = null;
        if ($request->filled('c')) {
            $conversationActive = AiConversation::pourUtilisateur($utilisateur)
                ->where('uuid', $request->query('c'))
                ->with('messages')
                ->first();
        }

        $peutModeDev = $utilisateur->hasPermission('assistant_ia.mode_developpeur') || $utilisateur->estSuperAdmin();
        $peutDistant = $utilisateur->hasPermission('assistant_ia.fournisseur_distant') || $utilisateur->estSuperAdmin();
        $consommation = $this->passerelle->consommationUtilisateur($utilisateur);
        $fournisseur = $this->passerelle->obtenirFournisseur();

        $localDisponible = $this->passerelle->obtenirFournisseur('local')->estDisponible();

        if ($request->wantsJson()) {
            return response()->json([
                'conversations' => $conversations,
                'conversationActive' => $conversationActive,
                'consommation' => $consommation,
                'fournisseur' => $fournisseur->nomFournisseur(),
                'modele' => $fournisseur->nomModele(),
                'peutDistant' => $peutDistant,
                'localDisponible' => $localDisponible,
            ]);
        }

        return view('modules.assistant-ia.index', [
            'conversations' => $conversations,
            'conversationActive' => $conversationActive,
            'peutModeDev' => $peutModeDev,
            'peutDistant' => $peutDistant,
            'consommation' => $consommation,
            'nomFournisseur' => $fournisseur->nomFournisseur(),
            'nomModele' => $fournisseur->nomModele(),
            'localDisponible' => $localDisponible,
        ]);
    }

    /**
     * Crée une nouvelle conversation isolée.
     */
    public function creerConversation(Request $request): RedirectResponse|JsonResponse
    {
        $utilisateur = $this->verifierUtilisateurActif($request);

        $request->validate([
            'mode' => 'nullable|string|in:metier,developpeur',
            'titre' => 'nullable|string|max:100',
            'fournisseur' => 'nullable|string|in:local,distant,faux,groq',
        ]);

        $mode = $request->input('mode', 'metier');

        $fournisseurParDefaut = config('assistant-ia.fournisseur', 'local');
        $localDisponible = $this->passerelle->obtenirFournisseur('local')->estDisponible();
        $groqActif = config('assistant-ia.fournisseurs.groq.actif', false) || config('assistant-ia.routage.distant_autorise', false);
        $peutDistant = $utilisateur->hasPermission('assistant_ia.fournisseur_distant') || $utilisateur->estSuperAdmin();

        if (! $localDisponible && $groqActif && $peutDistant) {
            $fournisseurParDefaut = 'groq';
        }

        $fournisseurDemande = $request->input('fournisseur', $fournisseurParDefaut);

        if ($mode === 'developpeur') {
            if (! $utilisateur->hasPermission('assistant_ia.mode_developpeur') && ! $utilisateur->estSuperAdmin()) {
                abort(403, "Vous n'avez pas l'autorisation d'accéder au mode développeur.");
            }
        }

        if (in_array($fournisseurDemande, ['distant', 'groq'], true)) {
            if (! $utilisateur->hasPermission('assistant_ia.fournisseur_distant') && ! $utilisateur->estSuperAdmin()) {
                abort(403, "Vous ne disposez pas de l'habilitation requise pour utiliser un fournisseur distant/cloud.");
            }
            if ($fournisseurDemande === 'distant' && ! config('assistant-ia.fournisseurs.distant.actif', false) && ! app()->environment('testing')) {
                abort(403, "Le fournisseur distant n'est pas activé dans la configuration de l'établissement.");
            }
            if ($fournisseurDemande === 'groq' && ! config('assistant-ia.fournisseurs.groq.actif', false) && ! app()->environment('testing')) {
                abort(403, "Le fournisseur GroqCloud n'est pas activé dans la configuration de l'établissement.");
            }
        }

        $fournisseur = $this->passerelle->obtenirFournisseur($fournisseurDemande);

        $conversation = AiConversation::create([
            'user_id' => $utilisateur->id,
            'faculte_id' => $utilisateur->faculteRestreinteId(),
            'titre' => $request->input('titre', 'Nouvelle conversation'),
            'mode' => $mode,
            'fournisseur' => $fournisseur->nomFournisseur(),
            'modele' => $fournisseur->nomModele(),
            'dernier_message_a' => now(),
        ]);

        if ($request->wantsJson()) {
            return response()->json($conversation, 201);
        }

        return redirect()->route('admin.assistant-ia.conversations.show', $conversation->uuid);
    }

    /**
     * Affiche une conversation spécifique avec vérification stricte anti-IDOR.
     */
    public function afficherConversation(string $uuid, Request $request): View|JsonResponse
    {
        $utilisateur = $this->verifierUtilisateurActif($request);

        $conversation = AiConversation::pourUtilisateur($utilisateur)
            ->where('uuid', $uuid)
            ->with('messages')
            ->firstOrFail();

        $conversations = AiConversation::pourUtilisateur($utilisateur)
            ->orderByDesc('dernier_message_a')
            ->orderByDesc('id')
            ->paginate(15);

        $peutModeDev = $utilisateur->hasPermission('assistant_ia.mode_developpeur') || $utilisateur->estSuperAdmin();
        $peutDistant = $utilisateur->hasPermission('assistant_ia.fournisseur_distant') || $utilisateur->estSuperAdmin();
        $consommation = $this->passerelle->consommationUtilisateur($utilisateur);
        $fournisseur = $this->passerelle->obtenirFournisseur($conversation->fournisseur);

        $localDisponible = $this->passerelle->obtenirFournisseur('local')->estDisponible();

        if ($request->wantsJson()) {
            return response()->json([
                'conversation' => $conversation,
                'messages' => $conversation->messages,
                'peutDistant' => $peutDistant,
                'localDisponible' => $localDisponible,
            ]);
        }

        return view('modules.assistant-ia.index', [
            'conversations' => $conversations,
            'conversationActive' => $conversation,
            'peutModeDev' => $peutModeDev,
            'peutDistant' => $peutDistant,
            'consommation' => $consommation,
            'nomFournisseur' => $fournisseur->nomFournisseur(),
            'nomModele' => $fournisseur->nomModele(),
            'localDisponible' => $localDisponible,
        ]);
    }

    /**
     * Envoie un message et exécute la boucle d'inférence agentique avec validation de consentement.
     */
    public function envoyerMessage(string $uuid, Request $request): JsonResponse|RedirectResponse
    {
        $utilisateur = $this->verifierUtilisateurActif($request);

        $conversation = AiConversation::pourUtilisateur($utilisateur)
            ->where('uuid', $uuid)
            ->firstOrFail();

        $request->validate([
            'contenu' => 'required|string|max:4000',
            'jeton_consentement' => 'nullable|string',
            'allow_remote' => 'nullable|boolean',
            'motif_transfert' => 'nullable|string|max:200',
        ]);

        try {
            $resultat = $this->orchestrateur->traiterMessage(
                conversation: $conversation,
                contenuUtilisateur: (string) $request->input('contenu'),
                utilisateur: $utilisateur,
                jetonConsentement: $request->input('jeton_consentement'),
                optionsClient: [
                    'allow_remote' => $request->boolean('allow_remote'),
                    'motif_transfert' => (string) $request->input('motif_transfert', 'Demande utilisateur'),
                ]
            );

            if ($request->wantsJson()) {
                return response()->json([
                    'succes' => true,
                    'message' => $resultat['messageModele'],
                    'reponse' => $resultat['reponse'],
                ]);
            }

            return redirect()->route('admin.assistant-ia.conversations.show', $conversation->uuid);
        } catch (ConsentementRequisException $e) {
            if ($request->wantsJson()) {
                return response()->json([
                    'succes' => false,
                    'statut' => 'consentement_requis',
                    'erreur' => $e->getMessage(),
                    'defi_consentement' => $e->obtenirDefiConsentement(),
                ], 428);
            }

            return redirect()->back()->with('consentement_requis', [
                'message' => $e->getMessage(),
                'defi' => $e->obtenirDefiConsentement(),
            ]);
        } catch (SecuriteIaException $e) {
            if ($request->wantsJson()) {
                return response()->json([
                    'succes' => false,
                    'erreur' => $e->getMessage(),
                    'type' => 'securite',
                ], 403);
            }

            return redirect()->back()->withErrors(['erreur_ia' => $e->getMessage()]);
        } catch (AssistantIaException $e) {
            if ($request->wantsJson()) {
                return response()->json([
                    'succes' => false,
                    'erreur' => $e->getMessage(),
                    'type' => 'erreur_ia',
                ], $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500);
            }

            return redirect()->back()->withErrors(['erreur_ia' => $e->getMessage()]);
        } catch (\Throwable $e) {
            // Jamais de divulgation : le détail technique (SQL, chemins, corps
            // fournisseur) reste côté serveur, masqué des secrets et PII.
            Log::error("Erreur inattendue lors de l'échange IA : ".JournalisationIa::masquerDonneesSensibles($e->getMessage()), [
                'exception_classe' => $e::class,
                'conversation_uuid' => $uuid,
                'user_id' => $utilisateur->id,
            ]);

            $messageGenerique = "Une erreur technique inattendue est survenue lors de l'échange. Veuillez réessayer ultérieurement.";

            if ($request->wantsJson()) {
                return response()->json([
                    'succes' => false,
                    'erreur' => $messageGenerique,
                    'type' => 'erreur_systeme',
                ], 500);
            }

            return redirect()->back()->withErrors(['erreur_ia' => $messageGenerique]);
        }
    }

    /**
     * Change le moteur d'inférence (fournisseur/modèle) d'une conversation existante.
     */
    public function changerFournisseur(string $uuid, Request $request): RedirectResponse|JsonResponse
    {
        $utilisateur = $this->verifierUtilisateurActif($request);

        $conversation = AiConversation::pourUtilisateur($utilisateur)
            ->where('uuid', $uuid)
            ->firstOrFail();

        $request->validate([
            'fournisseur' => 'required|string|in:local,distant,faux,groq',
        ]);

        $fournisseurDemande = (string) $request->input('fournisseur');

        if (in_array($fournisseurDemande, ['distant', 'groq'], true)) {
            if (! $utilisateur->hasPermission('assistant_ia.fournisseur_distant') && ! $utilisateur->estSuperAdmin()) {
                abort(403, "Vous ne disposez pas de l'habilitation requise pour utiliser un fournisseur distant/cloud.");
            }
        }

        $fournisseur = $this->passerelle->obtenirFournisseur($fournisseurDemande);

        $conversation->update([
            'fournisseur' => $fournisseur->nomFournisseur(),
            'modele' => $fournisseur->nomModele(),
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'succes' => true,
                'fournisseur' => $conversation->fournisseur,
                'modele' => $conversation->modele,
            ]);
        }

        return redirect()->route('admin.assistant-ia.conversations.show', $conversation->uuid)
            ->with('succes', "Moteur d'inférence basculé sur [{$conversation->fournisseur}] avec succès.");
    }

    /**
     * Génère un défi de consentement explicite signé pour une requête donnée.
     */
    public function genererConsentement(Request $request, GestionnaireConsentementIa $gestionnaire): JsonResponse
    {
        $utilisateur = $this->verifierUtilisateurActif($request);

        $request->validate([
            'contenu' => 'required|string|max:4000',
            'fournisseur' => 'nullable|string|in:distant,groq',
            'motif' => 'nullable|string|max:200',
        ]);

        if (! $utilisateur->hasPermission('assistant_ia.fournisseur_distant') && ! $utilisateur->estSuperAdmin()) {
            return response()->json([
                'succes' => false,
                'erreur' => "Vous n'avez pas l'habilitation requise ('assistant_ia.fournisseur_distant') pour solliciter un fournisseur distant ou GroqCloud.",
            ], 403);
        }

        $fournisseur = (string) $request->input('fournisseur', 'distant');
        $modele = $fournisseur === 'groq'
            ? (string) config('assistant-ia.fournisseurs.groq.modele', config('services.groq.model', 'llama-3.3-70b-versatile'))
            : (string) config('assistant-ia.fournisseurs.distant.modele', 'gpt-4o-mini');
        $prompt = (string) $request->input('contenu');
        $motif = (string) $request->input('motif', 'Assistance IA avancée');

        try {
            $defi = $gestionnaire->genererDefiConsentement(
                utilisateur: $utilisateur,
                fournisseur: $fournisseur,
                modele: $modele,
                prompt: $prompt,
                sources: [],
                motif: $motif
            );

            return response()->json([
                'succes' => true,
                'defi' => $defi,
            ]);
        } catch (SecuriteIaException $e) {
            return response()->json([
                'succes' => false,
                'erreur' => $e->getMessage(),
                'type' => 'securite',
            ], 403);
        }
    }

    /**
     * Retourne l'état de santé et de disponibilité des fournisseurs d'IA locaux et distants.
     */
    public function statutFournisseurs(Request $request): JsonResponse
    {
        $utilisateur = $this->verifierUtilisateurActif($request);
        $peutDistant = $utilisateur->hasPermission('assistant_ia.fournisseur_distant') || $utilisateur->estSuperAdmin();

        $local = $this->passerelle->obtenirFournisseur('local');
        $distant = $this->passerelle->obtenirFournisseur('distant');
        $groq = $this->passerelle->obtenirFournisseur('groq');

        return response()->json([
            'fournisseur_par_defaut' => config('assistant-ia.fournisseur', 'local'),
            'local' => [
                'nom' => $local->nomFournisseur(),
                'modele' => $local->nomModele(),
                'disponible' => $local->estDisponible(),
                'endpoint' => config('assistant-ia.fournisseurs.local.base_url'),
            ],
            'distant' => [
                'nom' => $distant->nomFournisseur(),
                'modele' => $distant->nomModele(),
                'actif' => (bool) config('assistant-ia.fournisseurs.distant.actif', false),
                'autorise_utilisateur' => $peutDistant,
                'disponible' => $distant->estDisponible(),
            ],
            'groq' => [
                'nom' => $groq->nomFournisseur(),
                'modele' => $groq->nomModele(),
                'actif' => (bool) config('assistant-ia.fournisseurs.groq.actif', false),
                'autorise_utilisateur' => $peutDistant,
                'disponible' => $groq->estDisponible(),
            ],
        ]);
    }

    /**
     * Supprime logiquement une conversation.
     */
    public function supprimerConversation(string $uuid, Request $request): RedirectResponse|JsonResponse
    {
        $utilisateur = $this->verifierUtilisateurActif($request);

        $conversation = AiConversation::pourUtilisateur($utilisateur)
            ->where('uuid', $uuid)
            ->firstOrFail();

        $conversation->delete();

        if ($request->wantsJson()) {
            return response()->json(['succes' => true]);
        }

        return redirect()->route('admin.assistant-ia.index');
    }

    /**
     * Approuve une action sensible en attente (règle des 4 yeux).
     */
    public function approuverAction(int $id, Request $request, WorkflowApprobationService $workflow): JsonResponse|RedirectResponse
    {
        $utilisateur = $this->verifierUtilisateurActif($request);
        $proposition = AiPropositionAction::findOrFail($id);

        try {
            $resultat = $workflow->approuverAction(
                proposition: $proposition,
                approbateur: $utilisateur,
                commentaire: $request->input('commentaire')
            );

            if ($request->wantsJson()) {
                return response()->json($resultat);
            }

            return redirect()->back()->with('succes', $resultat['message']);
        } catch (SecuriteIaException $e) {
            if ($request->wantsJson()) {
                return response()->json(['succes' => false, 'erreur' => $e->getMessage()], 403);
            }

            abort(403, $e->getMessage());
        }
    }

    /**
     * Rejette une proposition d'action.
     */
    public function rejeterAction(int $id, Request $request, WorkflowApprobationService $workflow): JsonResponse|RedirectResponse
    {
        $utilisateur = $this->verifierUtilisateurActif($request);
        $proposition = AiPropositionAction::findOrFail($id);
        $motif = (string) $request->input('motif', 'Rejeté par l\'administrateur.');

        try {
            $workflow->rejeterAction($proposition, $utilisateur, $motif);

            if ($request->wantsJson()) {
                return response()->json(['succes' => true, 'message' => "L'action a été rejetée."]);
            }

            return redirect()->back()->with('succes', "L'action a été rejetée.");
        } catch (SecuriteIaException $e) {
            if ($request->wantsJson()) {
                return response()->json(['succes' => false, 'erreur' => $e->getMessage()], 403);
            }

            abort(403, $e->getMessage());
        }
    }

    /**
     * Tableau de bord d'administration des sources de connaissance du Knowledge Hub.
     */
    public function sources(Request $request): View|JsonResponse
    {
        $utilisateur = $this->verifierUtilisateurActif($request);
        abort_unless($utilisateur->hasPermission('assistant_ia.admin') || $utilisateur->estSuperAdmin(), 403, "Accès refusé à l'administration du Knowledge Hub.");

        $sources = AiSourceConnaissance::withTrashed()->orderBy('nom')->get();
        $totalMorceaux = AiDocumentIndex::count();
        $docsObsoletes = AiDocumentIndex::where('statut_document', 'obsolete')->count();

        /** @var FournisseurLocal $fournisseurLocal */
        $fournisseurLocal = $this->passerelle->obtenirFournisseur('local');
        $diagnosticLocal = $fournisseurLocal->diagnostiquerEnvironnement();

        if ($request->wantsJson()) {
            return response()->json([
                'sources' => $sources,
                'totalMorceaux' => $totalMorceaux,
                'docsObsoletes' => $docsObsoletes,
                'diagnosticLocal' => $diagnosticLocal,
            ]);
        }

        return view('modules.assistant-ia.sources', [
            'sources' => $sources,
            'totalMorceaux' => $totalMorceaux,
            'docsObsoletes' => $docsObsoletes,
            'diagnosticLocal' => $diagnosticLocal,
        ]);
    }

    /**
     * Déclenche la synchronisation manuelle d'une source de connaissance.
     */
    public function synchroniserSource(int $id, Request $request, RegistreSourcesConnaissances $registre): JsonResponse|RedirectResponse
    {
        $utilisateur = $this->verifierUtilisateurActif($request);
        abort_unless($utilisateur->hasPermission('assistant_ia.admin') || $utilisateur->estSuperAdmin(), 403, 'Opération non autorisée.');

        $source = AiSourceConnaissance::findOrFail($id);
        $resultat = $registre->synchroniserSource($source, true);

        if ($request->wantsJson()) {
            return response()->json($resultat);
        }

        $flashType = $resultat['succes'] ? 'succes' : 'erreur';

        return redirect()->back()->with($flashType, $resultat['message']);
    }

    /**
     * Suspend ou réactive une source de connaissance.
     */
    public function basculerStatutSource(int $id, Request $request): JsonResponse|RedirectResponse
    {
        $utilisateur = $this->verifierUtilisateurActif($request);
        abort_unless($utilisateur->hasPermission('assistant_ia.admin') || $utilisateur->estSuperAdmin(), 403, 'Opération non autorisée.');

        $source = AiSourceConnaissance::findOrFail($id);
        if ($source->estActive()) {
            $source->suspendre();
            $msg = "La source '{$source->nom}' a été suspendue.";
        } else {
            $source->reactiver();
            $msg = "La source '{$source->nom}' a été réactivée.";
        }

        if ($request->wantsJson()) {
            return response()->json(['succes' => true, 'statut' => $source->statut, 'message' => $msg]);
        }

        return redirect()->back()->with('succes', $msg);
    }

    /**
     * Initialise et enregistre toutes les sources de connaissances Markdown du projet.
     */
    public function initialiserSources(Request $request, RegistreSourcesConnaissances $registre): JsonResponse|RedirectResponse
    {
        $utilisateur = $this->verifierUtilisateurActif($request);
        abort_unless($utilisateur->hasPermission('assistant_ia.admin') || $utilisateur->estSuperAdmin(), 403, 'Opération non autorisée.');

        $sources = $registre->initialiserToutesLesSources();
        $message = "Toutes les sources documentaires du projet ont été initialisées avec succès ({$sources->count()} sources enregistrées).";

        if ($request->wantsJson()) {
            return response()->json([
                'succes' => true,
                'total_sources' => $sources->count(),
                'message' => $message,
            ]);
        }

        return redirect()->back()->with('succes', $message);
    }

    /**
     * Déclenche la synchronisation complète de l'ensemble des sources du Knowledge Hub.
     */
    public function synchroniserToutesSources(Request $request, RegistreSourcesConnaissances $registre): JsonResponse|RedirectResponse
    {
        $utilisateur = $this->verifierUtilisateurActif($request);
        abort_unless($utilisateur->hasPermission('assistant_ia.admin') || $utilisateur->estSuperAdmin(), 403, 'Opération non autorisée.');

        $resultat = $registre->synchroniserToutesLesSources(forcer: true);
        $message = "Synchronisation globale achevée : {$resultat['succes']} sources traitées, {$resultat['documents']} documents indexés, {$resultat['morceaux']} fragments RAG.";

        if ($request->wantsJson()) {
            return response()->json(array_merge($resultat, ['message' => $message]));
        }

        return redirect()->back()->with('succes', $message);
    }
}
