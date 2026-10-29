<?php

namespace App\Modules\AssistantIa\Drivers;

use App\Modules\AssistantIa\Contracts\FournisseurIaInterface;
use App\Modules\AssistantIa\DTO\AppelOutil;
use App\Modules\AssistantIa\DTO\DemandeIa;
use App\Modules\AssistantIa\DTO\ReponseIa;
use App\Modules\AssistantIa\Exceptions\AssistantIaException;
use App\Modules\AssistantIa\Exceptions\QuotasDepassesException;
use App\Modules\AssistantIa\Services\JournalisationIa;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Adaptateur d'inférence pour Google Colab (Open Source sur GPU T4).
 *
 * Se connecte à un serveur vLLM ou FastAPI/llama-cpp-python hébergé sur Google Colab
 * via un tunnel HTTPS chiffré (Cloudflare Tunnel ou Ngrok), authentifié par jeton Bearer fort.
 *
 * Règle de souveraineté :
 * - Aucun outil d'écriture n'est transmis au notebook Colab (lecture seule stricte).
 * - En cas de déconnexion ou de mise en veille de Colab, le fail-soft est garanti (pas de fallback sauvage).
 */
class FournisseurColab implements FournisseurIaInterface
{
    public const MAX_OCTETS_REPONSE = 524288; // 512 Ko

    public function __construct(
        protected ?string $baseUrl = null,
        protected ?string $cleApi = null,
        protected string $modele = 'qwen2.5-coder-7b-instruct',
        protected int $timeoutSecondes = 30,
        protected int $connectTimeoutSecondes = 5,
        protected bool $forcerHttps = true
    ) {
        $this->baseUrl = rtrim($baseUrl ?? (string) config('assistant-ia.fournisseurs.colab.base_url', ''), '/');
        $this->cleApi = $cleApi ?? (string) config('assistant-ia.fournisseurs.colab.cle_api', '');
        $this->modele = $modele ?: (string) config('assistant-ia.fournisseurs.colab.modele', 'qwen2.5-coder-7b-instruct');

        // Validation stricte du protocole HTTPS pour tout endpoint distant
        if ($this->forcerHttps && $this->baseUrl !== '' && ! str_starts_with($this->baseUrl, 'https://')) {
            $estLocalTest = app()->environment('testing') && (str_contains($this->baseUrl, '127.0.0.1') || str_contains($this->baseUrl, 'localhost'));
            if (! $estLocalTest) {
                throw new AssistantIaException("Protocole non sécurisé rejeté : le fournisseur Colab requiert impérativement un point de terminaison chiffré HTTPS (reçu : '{$this->baseUrl}').", 400);
            }
        }
    }

    public function nomFournisseur(): string
    {
        return 'colab';
    }

    public function nomModele(): string
    {
        return $this->modele;
    }

    public function estDisponible(): bool
    {
        if (empty($this->baseUrl) || (empty($this->cleApi) && ! app()->environment('testing'))) {
            return false;
        }

        try {
            $requete = Http::timeout(3);
            if (! empty($this->cleApi)) {
                $requete = $requete->withToken($this->cleApi);
            }

            $reponse = $requete->get("{$this->baseUrl}/models");

            return $reponse->successful();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Filtre les outils pour exclure formellement toute action d'écriture ou sensible du payload Colab.
     *
     * @param  array<int, array<string, mixed>>  $outils
     * @return array<int, array<string, mixed>>
     */
    protected function filtrerOutilsPourColab(array $outils): array
    {
        $outilsLectureSeule = [];
        $motifsEcriture = ['preparer_action', 'modifier', 'supprimer', 'valider', 'rejeter', 'ecrire', 'mettre_a_jour'];

        foreach ($outils as $outil) {
            $nom = (string) ($outil['function']['name'] ?? '');
            $estOutilEcriture = false;

            foreach ($motifsEcriture as $motif) {
                if (str_contains(strtolower($nom), $motif)) {
                    $estOutilEcriture = true;
                    break;
                }
            }

            if (! $estOutilEcriture) {
                $outilsLectureSeule[] = $outil;
            }
        }

        return $outilsLectureSeule;
    }

    public function genererReponse(DemandeIa $demande): ReponseIa
    {
        if (empty($this->baseUrl)) {
            throw new AssistantIaException("L'URL du serveur d'inférence Colab n'est pas configurée dans l'environnement.", 503);
        }

        if (empty($this->cleApi) && ! app()->environment('testing')) {
            throw new AssistantIaException("Le jeton d'authentification Colab (Bearer) n'est pas configuré dans l'environnement.", 401);
        }

        $debut = hrtime(true);

        $payload = [
            'model' => $this->modele,
            'messages' => $demande->preparerMessagesPourFournisseur(),
            'temperature' => $demande->temperature,
            'max_tokens' => $demande->maxTokens,
            'stream' => false,
        ];

        // Seuls les outils en lecture seule sont exposés au modèle
        $outilsFiltres = $this->filtrerOutilsPourColab($demande->outilsDisponibles);
        if (! empty($outilsFiltres)) {
            $payload['tools'] = $outilsFiltres;
            $payload['tool_choice'] = 'auto';
        }

        try {
            $requete = Http::timeout($this->timeoutSecondes)
                ->connectTimeout($this->connectTimeoutSecondes);

            if (! empty($this->cleApi)) {
                $requete = $requete->withToken($this->cleApi);
            }

            $reponseHttp = $requete->post("{$this->baseUrl}/chat/completions", $payload);
        } catch (ConnectionException $e) {
            JournalisationIa::avertissement("Délai d'attente ou déconnexion du serveur Google Colab : ".$e->getMessage());
            throw new AssistantIaException("Le serveur d'inférence Google Colab est inaccessible (délai dépassé, tunnel fermé ou session arrêtée). Veuillez vérifier l'état du notebook Colab.", 504, $e);
        } catch (\Throwable $e) {
            JournalisationIa::avertissement('Erreur de communication avec le serveur Google Colab : '.$e->getMessage());
            throw new AssistantIaException("Erreur de communication avec le serveur d'inférence Google Colab.", 500, $e);
        }

        $fin = hrtime(true);
        $latenceMs = round(($fin - $debut) / 1e6, 2);

        $statut = $reponseHttp->status();

        if ($statut === 429) {
            JournalisationIa::avertissement('Saturation du serveur Colab ou quota atteint (HTTP 429).');
            throw new QuotasDepassesException('Le serveur Colab est temporairement surchargé (HTTP 429).');
        }

        if ($statut === 401) {
            JournalisationIa::avertissement('Authentification Colab échouée (HTTP 401). Jeton Bearer non reconnu.');
            throw new AssistantIaException("Le jeton d'authentification du serveur Colab est invalide ou l'accès est refusé (HTTP 401).", 401);
        }

        if ($statut === 403) {
            JournalisationIa::avertissement('Accès refusé par le serveur Colab (HTTP 403).');
            throw new AssistantIaException('Accès refusé par le serveur d\'inférence Colab (HTTP 403).', 403);
        }

        if ($statut === 404) {
            JournalisationIa::avertissement('Endpoint ou modèle Colab introuvable (HTTP 404).');
            throw new AssistantIaException("L'endpoint d'inférence ou le modèle spécifié est introuvable sur le serveur Colab (HTTP 404).", 404);
        }

        if (! $reponseHttp->successful()) {
            JournalisationIa::avertissement("Erreur HTTP {$statut} renvoyée par le serveur Colab.");
            throw new AssistantIaException("Le serveur Colab a renvoyé une erreur (Code HTTP {$statut}).", $statut);
        }

        // Contrôle de taille maximale de la réponse (anti-DDoS / mémoire)
        $tailleCorps = strlen((string) $reponseHttp->body());
        if ($tailleCorps > self::MAX_OCTETS_REPONSE) {
            JournalisationIa::alerteSecurite("Réponse Colab volumineuse tronquée ({$tailleCorps} octets).");
            throw new AssistantIaException('La réponse du modèle dépasse la taille maximale autorisée (512 Ko).', 502);
        }

        $donnees = $reponseHttp->json();
        $choix = $donnees['choices'][0] ?? null;

        if (! $choix) {
            JournalisationIa::avertissement('Réponse Colab invalide : aucun choix retourné.');
            throw new AssistantIaException('Format de réponse invalide reçu du serveur Colab.', 502);
        }

        $message = $choix['message'] ?? [];
        $texteReponse = (string) ($message['content'] ?? '');
        $appelsOutilsRaw = (array) ($message['tool_calls'] ?? []);

        $appelsOutils = [];
        foreach ($appelsOutilsRaw as $outilBrut) {
            $nomFonction = (string) ($outilBrut['function']['name'] ?? '');
            $argumentsStr = (string) ($outilBrut['function']['arguments'] ?? '{}');

            $arguments = json_decode($argumentsStr, true);
            if (! is_array($arguments)) {
                $arguments = [];
            }

            $appelsOutils[] = new AppelOutil(
                id: (string) ($outilBrut['id'] ?? uniqid('call_colab_')),
                nomOutil: $nomFonction,
                arguments: $arguments
            );
        }

        $usage = $donnees['usage'] ?? [];
        $tokensPrompt = (int) ($usage['prompt_tokens'] ?? 0);
        $tokensReponse = (int) ($usage['completion_tokens'] ?? 0);

        return new ReponseIa(
            texte: $texteReponse,
            appelsOutils: $appelsOutils,
            tokensPrompt: $tokensPrompt,
            tokensReponse: $tokensReponse,
            latenceMs: $latenceMs,
            fournisseur: 'colab',
            modele: (string) ($donnees['model'] ?? $this->modele)
        );
    }
}
