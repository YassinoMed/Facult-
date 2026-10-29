<?php

namespace App\Modules\AssistantIa\Services;

use App\Models\User;
use App\Modules\AssistantIa\Contracts\FournisseurIaInterface;
use App\Modules\AssistantIa\Drivers\FauxFournisseurIa;
use App\Modules\AssistantIa\Drivers\FournisseurColab;
use App\Modules\AssistantIa\Drivers\FournisseurGroqCloud;
use App\Modules\AssistantIa\Drivers\FournisseurLocal;
use App\Modules\AssistantIa\Drivers\FournisseurRestDistant;
use App\Modules\AssistantIa\DTO\DecisionRoutageIa;
use App\Modules\AssistantIa\DTO\DemandeIa;
use App\Modules\AssistantIa\DTO\MessageIa;
use App\Modules\AssistantIa\DTO\ReponseIa;
use App\Modules\AssistantIa\Exceptions\AssistantIaException;
use App\Modules\AssistantIa\Exceptions\ConsentementRequisException;
use App\Modules\AssistantIa\Exceptions\QuotasDepassesException;
use Illuminate\Support\Facades\Cache;

/**
 * Passerelle centrale de gestion et d'orchestration des fournisseurs d'IA.
 * Applique les plafonds de consommation, la politique hybride et la traçabilité sans secrets.
 */
class PasserelleFournisseurs
{
    /**
     * @var array<string, FournisseurIaInterface>
     */
    protected array $fournisseurs = [];

    protected ?string $fournisseurParDefaut = null;

    protected ?PolitiqueRoutageIa $politique = null;

    /**
     * @param  array<string, mixed>|null  $config
     */
    public function __construct(
        protected ?array $config = null,
        ?PolitiqueRoutageIa $politique = null
    ) {
        $this->config = $config ?? config('assistant-ia', []);
        $this->fournisseurParDefaut = $this->config['fournisseur'] ?? 'faux';
        $this->politique = $politique ?? app(PolitiqueRoutageIa::class);
    }

    /**
     * Enregistre un pilote de fournisseur.
     */
    public function enregistrerFournisseur(string $cle, FournisseurIaInterface $fournisseur): self
    {
        $this->fournisseurs[$cle] = $fournisseur;

        return $this;
    }

    /**
     * Remplace ou force le fournisseur actif (notamment pour les tests).
     */
    public function definirFournisseurActif(string $cle): self
    {
        $this->fournisseurParDefaut = $cle;

        return $this;
    }

    /**
     * Définit ou injecte le service de politique de routage.
     */
    public function definirPolitiqueRoutage(PolitiqueRoutageIa $politique): self
    {
        $this->politique = $politique;

        return $this;
    }

    public function politiqueRoutage(): PolitiqueRoutageIa
    {
        return $this->politique ??= app(PolitiqueRoutageIa::class);
    }

    /**
     * Obtient le fournisseur configuré ou demandé avec validation stricte anti-bascule arbitraire.
     *
     * @throws AssistantIaException
     */
    public function obtenirFournisseur(?string $cle = null): FournisseurIaInterface
    {
        $cle = $cle ?? $this->fournisseurParDefaut ?? 'faux';

        if (isset($this->fournisseurs[$cle])) {
            return $this->fournisseurs[$cle];
        }

        $fournisseursAutorises = ['faux', 'distant', 'local', 'groq', 'colab'];
        if (! in_array($cle, $fournisseursAutorises, true)) {
            JournalisationIa::alerteSecurite("Tentative d'utilisation d'un fournisseur IA inconnu ou non autorisé : '{$cle}'");
            throw new AssistantIaException("Fournisseur IA non reconnu ou non autorisé : '{$cle}'. Fournisseurs supportés : ".implode(', ', $fournisseursAutorises).'.', 400);
        }

        $configFournisseur = $this->config['fournisseurs'][$cle] ?? null;

        $instance = match ($cle) {
            'colab' => new FournisseurColab(
                baseUrl: (string) ($configFournisseur['base_url'] ?? ''),
                cleApi: (string) ($configFournisseur['cle_api'] ?? ''),
                modele: (string) ($configFournisseur['modele'] ?? 'qwen2.5-coder-7b-instruct'),
                timeoutSecondes: (int) ($configFournisseur['timeout_secondes'] ?? 30),
                connectTimeoutSecondes: (int) ($configFournisseur['connect_timeout_secondes'] ?? 5),
                forcerHttps: (bool) ($configFournisseur['forcer_https'] ?? true)
            ),
            'distant' => new FournisseurRestDistant(
                baseUrl: (string) ($configFournisseur['base_url'] ?? 'https://api.openai.com/v1'),
                cleApi: (string) ($configFournisseur['cle_api'] ?? ''),
                modele: (string) ($configFournisseur['modele'] ?? 'gpt-4o-mini'),
                timeoutSecondes: (int) ($configFournisseur['timeout_secondes'] ?? 30),
                forcerHttps: (bool) ($configFournisseur['forcer_https'] ?? true)
            ),
            'groq' => new FournisseurGroqCloud(
                baseUrl: (string) ($configFournisseur['base_url'] ?? config('services.groq.base_url', 'https://api.groq.com/openai/v1')),
                cleApi: (string) ($configFournisseur['cle_api'] ?? config('services.groq.key', '')),
                modele: (string) ($configFournisseur['modele'] ?? config('services.groq.model', 'llama-3.3-70b-versatile')),
                timeoutSecondes: (int) ($configFournisseur['timeout_secondes'] ?? config('services.groq.timeout', 30)),
                connectTimeoutSecondes: (int) ($configFournisseur['connect_timeout_secondes'] ?? config('services.groq.connect_timeout', 5)),
                forcerHttps: (bool) ($configFournisseur['forcer_https'] ?? true)
            ),
            'local' => new FournisseurLocal(
                baseUrl: (string) ($configFournisseur['base_url'] ?? 'http://127.0.0.1:11434/v1'),
                cleApi: (string) ($configFournisseur['cle_api'] ?? 'local-token'),
                modele: (string) ($configFournisseur['modele'] ?? 'nvidia/NVIDIA-Nemotron-3.5-Bolt-E4B-4GB-DFlash'),
                modeleEmbeddings: (string) ($configFournisseur['modele_embeddings'] ?? 'all-minilm'),
                timeoutSecondes: (int) ($configFournisseur['timeout_secondes'] ?? 60),
                maxCaracteresEntree: (int) ($configFournisseur['max_caracteres_entree'] ?? 100000)
            ),
            'faux' => new FauxFournisseurIa(
                modele: (string) ($configFournisseur['modele'] ?? 'fake-model-v1')
            ),
        };

        $this->fournisseurs[$cle] = $instance;

        return $instance;
    }

    /**
     * Vérifie et incrémente le quota journalier d'un utilisateur.
     */
    public function verifierEtIncrementerQuota(User $utilisateur): void
    {
        $role = $utilisateur->role ?? 'defaut';
        $quotas = $this->config['limites']['quotas_journaliers_utilisateur'] ?? [];
        $plafond = (int) ($quotas[$role] ?? $quotas['defaut'] ?? 50);

        $cleCache = "assistant_ia_quota_{$utilisateur->id}_".now()->format('Ymd');
        $actuel = (int) Cache::get($cleCache, 0);

        if ($actuel >= $plafond) {
            JournalisationIa::avertissement("Plafond de requêtes IA journalier atteint pour l'utilisateur #{$utilisateur->id}", [
                'utilisateur_id' => $utilisateur->id,
                'role' => $role,
                'plafond' => $plafond,
            ]);

            throw new QuotasDepassesException("Votre quota journalier d'utilisation de l'assistant IA ({$plafond} requêtes) est atteint.");
        }

        Cache::put($cleCache, $actuel + 1, now()->endOfDay());
    }

    /**
     * Récupère la consommation journalière actuelle d'un utilisateur.
     *
     * @return array{consomme: int, plafond: int, restant: int}
     */
    public function consommationUtilisateur(User $utilisateur): array
    {
        $role = $utilisateur->role ?? 'defaut';
        $quotas = $this->config['limites']['quotas_journaliers_utilisateur'] ?? [];
        $plafond = (int) ($quotas[$role] ?? $quotas['defaut'] ?? 50);

        $cleCache = "assistant_ia_quota_{$utilisateur->id}_".now()->format('Ymd');
        $consomme = (int) Cache::get($cleCache, 0);

        return [
            'consomme' => $consomme,
            'plafond' => $plafond,
            'restant' => max(0, $plafond - $consomme),
        ];
    }

    /**
     * Exécute une requête d'inférence en appliquant la politique hybride complète et le consentement explicite.
     *
     * @param  array<int, mixed>  $sourcesRag
     * @param  array<string, mixed>  $optionsClient
     *
     * @throws AssistantIaException
     * @throws ConsentementRequisException
     */
    public function genererReponseHybride(
        DemandeIa $demande,
        User $utilisateur,
        ?string $fournisseurDemande = null,
        ?string $jetonConsentement = null,
        array $sourcesRag = [],
        array $optionsClient = []
    ): ReponseIa {
        // Extraction du dernier prompt pour analyse
        $dernierMessage = '';
        foreach (array_reverse($demande->messages) as $m) {
            if ($m->role === 'user') {
                $dernierMessage = $m->contenu;
                break;
            }
        }
        if ($dernierMessage === '' && ! empty($demande->messages)) {
            $dernierMessage = $demande->messages[0]->contenu;
        }

        $fLocal = isset($this->fournisseurs['local']) ? $this->fournisseurs['local'] : null;
        $fDistant = isset($this->fournisseurs['distant']) ? $this->fournisseurs['distant'] : null;

        // Évaluation par la politique centrale
        $decision = $this->politiqueRoutage()->evaluer(
            utilisateur: $utilisateur,
            prompt: $dernierMessage,
            fournisseurDemande: $fournisseurDemande,
            jetonConsentement: $jetonConsentement,
            sourcesRag: $sourcesRag,
            fournisseurLocal: $fLocal,
            fournisseurDistant: $fDistant,
            optionsClient: $optionsClient
        );

        JournalisationIa::journaliserRoutage(
            correlationId: $decision->correlationId,
            decision: $decision->decision,
            fournisseur: $decision->fournisseur,
            motif: $decision->motif,
            tailleCaracteres: mb_strlen($dernierMessage),
            consentementPresent: ! empty($jetonConsentement),
            metadonnees: [
                'utilisateur_id' => $utilisateur->id,
                'categories' => $decision->categoriesSensibles,
            ]
        );

        if ($decision->decision === DecisionRoutageIa::REFUSE) {
            throw new AssistantIaException($decision->motif, 403);
        }

        if ($decision->decision === DecisionRoutageIa::INDISPONIBLE) {
            throw new AssistantIaException($decision->motif, 503);
        }

        if ($decision->decision === DecisionRoutageIa::CONSENTEMENT_REQUIS) {
            throw new ConsentementRequisException(
                message: $decision->motif,
                defiConsentement: $decision->defiConsentement ?? []
            );
        }

        // Si le transfert distant est autorisé, minimisation du contexte
        $demandeEffective = $demande;
        if ($decision->decision === DecisionRoutageIa::DISTANT_AUTORISE) {
            $messagesMinimises = [];
            foreach ($demande->messages as $msg) {
                $messagesMinimises[] = new MessageIa(
                    role: $msg->role,
                    contenu: FiltreSecuriteIa::minimiserTextePourDistant($msg->contenu),
                    nomOutil: $msg->nomOutil,
                    toolCallId: $msg->toolCallId,
                    appelsOutils: $msg->appelsOutils,
                    metadata: $msg->metadata
                );
            }
            $demandeEffective = new DemandeIa(
                messages: $messagesMinimises,
                promptSysteme: FiltreSecuriteIa::minimiserTextePourDistant($demande->promptSysteme),
                outilsDisponibles: $demande->outilsDisponibles,
                temperature: $demande->temperature,
                maxTokens: $demande->maxTokens
            );
        }

        return $this->genererReponseDirecte($demandeEffective, $utilisateur, $decision->fournisseur);
    }

    /**
     * Envoie la demande au modèle directement (sans réévaluation de politique hybride)
     * avec contrôle des quotas et traçabilité.
     */
    public function genererReponseDirecte(
        DemandeIa $demande,
        ?User $utilisateur = null,
        ?string $cleFournisseur = null
    ): ReponseIa {
        if ($utilisateur !== null) {
            $this->verifierEtIncrementerQuota($utilisateur);
        }

        $fournisseur = $this->obtenirFournisseur($cleFournisseur);

        JournalisationIa::info("Envoi d'une demande au fournisseur IA [{$fournisseur->nomFournisseur()}] ({$fournisseur->nomModele()})", [
            'utilisateur_id' => $utilisateur?->id,
            'nb_messages' => count($demande->messages),
            'nb_outils' => count($demande->outilsDisponibles),
        ]);

        $reponse = $fournisseur->genererReponse($demande);

        JournalisationIa::info("Réponse reçue du fournisseur IA [{$reponse->fournisseur}] en {$reponse->latenceMs}ms", [
            'utilisateur_id' => $utilisateur?->id,
            'modele' => $reponse->modele,
            'tokens_prompt' => $reponse->tokensPrompt,
            'tokens_reponse' => $reponse->tokensReponse,
            'total_tokens' => $reponse->totalTokens(),
            'nb_appels_outils' => count($reponse->appelsOutils),
        ]);

        return $reponse;
    }

    /**
     * Envoie la demande au modèle avec contrôle des quotas et traçabilité.
     */
    public function genererReponse(
        DemandeIa $demande,
        ?User $utilisateur = null,
        ?string $cleFournisseur = null,
        ?string $jetonConsentement = null,
        array $sources = [],
        array $optionsClient = []
    ): ReponseIa {
        // Sécurité absolue : si le fournisseur distant ou GroqCloud est sollicité avec un utilisateur, obliger la politique
        if (in_array($cleFournisseur, ['distant', 'groq'], true) && $utilisateur !== null && ! app()->environment('testing')) {
            return $this->genererReponseHybride($demande, $utilisateur, $cleFournisseur, $jetonConsentement, $sources, $optionsClient);
        }

        return $this->genererReponseDirecte($demande, $utilisateur, $cleFournisseur);
    }
}
