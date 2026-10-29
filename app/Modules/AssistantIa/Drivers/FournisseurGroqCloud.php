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
 * Adaptateur officiel pour GroqCloud AI via son API REST ultra-rapide compatible OpenAI.
 * Point de terminaison officiel : https://api.groq.com/openai/v1/chat/completions
 */
class FournisseurGroqCloud implements FournisseurIaInterface
{
    public const MAX_OCTETS_REPONSE = 524288; // 512 Ko

    public function __construct(
        protected ?string $baseUrl = null,
        protected ?string $cleApi = null,
        protected string $modele = 'llama-3.3-70b-versatile',
        protected int $timeoutSecondes = 30,
        protected int $connectTimeoutSecondes = 5,
        protected bool $forcerHttps = true
    ) {
        $this->baseUrl = rtrim($baseUrl ?? config('services.groq.base_url', config('assistant-ia.fournisseurs.groq.base_url', 'https://api.groq.com/openai/v1')), '/');
        $this->cleApi = $cleApi ?? config('services.groq.key', config('assistant-ia.fournisseurs.groq.cle_api', ''));
        $this->modele = $modele ?: config('services.groq.model', config('assistant-ia.fournisseurs.groq.modele', 'llama-3.3-70b-versatile'));

        // Validation stricte du protocole HTTPS pour tout endpoint distant
        if ($this->forcerHttps && ! str_starts_with($this->baseUrl, 'https://')) {
            $estLocalTest = app()->environment('testing') && (str_contains($this->baseUrl, '127.0.0.1') || str_contains($this->baseUrl, 'localhost'));
            if (! $estLocalTest) {
                throw new AssistantIaException("Protocole non sécurisé rejeté : le fournisseur GroqCloud requiert impérativement un point de terminaison chiffré HTTPS (reçu : '{$this->baseUrl}').", 400);
            }
        }
    }

    public function nomFournisseur(): string
    {
        return 'groq';
    }

    public function nomModele(): string
    {
        return $this->modele;
    }

    public function estDisponible(): bool
    {
        if (empty($this->cleApi) && ! app()->environment('testing')) {
            return false;
        }

        try {
            $requete = Http::timeout(3)->connectTimeout(2);
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
     * Filtre les outils pour exclure rigoureusement tout outil de mutation ou d'écriture du payload distant.
     *
     * @param  array<int, array<string, mixed>>  $outils
     * @return array<int, array<string, mixed>>
     */
    protected function filtrerOutilsPourDistant(array $outils): array
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
        if (empty($this->cleApi) && ! app()->environment('testing')) {
            throw new AssistantIaException("La clé d'authentification GroqCloud n'est pas configurée dans l'environnement.", 401);
        }

        $debut = hrtime(true);

        // Plafond de tokens de complétion adapté au palier Groq on-demand (OTPM 1000)
        $maxTokensSecurise = min($demande->maxTokens, (int) config('assistant-ia.fournisseurs.groq.max_completion_tokens', 800));

        $payload = [
            'model' => $this->modele,
            'messages' => $demande->preparerMessagesPourFournisseur(),
            'temperature' => $demande->temperature,
            'max_completion_tokens' => $maxTokensSecurise,
        ];

        // Seuls les outils en lecture seule sont transmis à GroqCloud
        $outilsFiltres = $this->filtrerOutilsPourDistant($demande->outilsDisponibles);
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
            JournalisationIa::avertissement("Délai d'attente ou échec réseau fournisseur GroqCloud : ".$e->getMessage());
            throw new AssistantIaException('Le fournisseur GroqCloud est inaccessible (délai dépassé ou réseau indisponible).', 504, $e);
        } catch (\Throwable $e) {
            JournalisationIa::avertissement('Erreur de communication avec le fournisseur GroqCloud : '.$e->getMessage());
            throw new AssistantIaException('Erreur de communication avec le fournisseur GroqCloud.', 500, $e);
        }

        $fin = hrtime(true);
        $latenceMs = round(($fin - $debut) / 1e6, 2);

        $statut = $reponseHttp->status();

        if ($statut === 429) {
            JournalisationIa::avertissement('Quota GroqCloud dépassé ou limitation de débit atteinte (HTTP 429).');
            throw new QuotasDepassesException('Le quota du fournisseur GroqCloud a été dépassé ou limitation de débit atteinte (HTTP 429).');
        }

        if ($statut === 401) {
            JournalisationIa::avertissement('Authentification GroqCloud échouée (HTTP 401). Clé non autorisée ou expirée.');
            throw new AssistantIaException("La clé d'authentification du fournisseur GroqCloud est invalide ou l'accès est refusé (HTTP 401).", 401);
        }

        if ($statut === 403) {
            JournalisationIa::avertissement('Accès refusé par le fournisseur GroqCloud (HTTP 403).');
            throw new AssistantIaException('Accès refusé par le fournisseur GroqCloud (HTTP 403).', 403);
        }

        if ($statut === 404) {
            $codeErreur = (string) $reponseHttp->json('error.code');
            $messageErreur = JournalisationIa::masquerDonneesSensibles((string) ($reponseHttp->json('error.message') ?? ''));
            JournalisationIa::avertissement("Modèle ou ressource GroqCloud indisponible (HTTP 404) : {$messageErreur}");

            // Repli automatique vers un modèle alternatif disponible si le modèle configuré est introuvable sur le palier API
            if ($codeErreur === 'model_not_found') {
                $modelesRepli = ['qwen/qwen3.8-27b', 'openai/gpt-oss-120b', 'openai/gpt-oss-20b'];
                foreach ($modelesRepli as $mRepli) {
                    if ($this->modele === $mRepli) {
                        continue;
                    }

                    JournalisationIa::info("Tentative de repli vers le modèle alternatif Groq '{$mRepli}'");
                    $payloadRepli = $payload;
                    $payloadRepli['model'] = $mRepli;

                    try {
                        $reqRepli = Http::timeout($this->timeoutSecondes)
                            ->connectTimeout($this->connectTimeoutSecondes);
                        if (! empty($this->cleApi)) {
                            $reqRepli = $reqRepli->withToken($this->cleApi);
                        }

                        $reponseRepli = $reqRepli->post("{$this->baseUrl}/chat/completions", $payloadRepli);
                        if ($reponseRepli->successful()) {
                            $reponseHttp = $reponseRepli;
                            $statut = $reponseHttp->status();
                            $this->modele = $mRepli; // Mémoriser le modèle fonctionnel pour les appels suivants
                            JournalisationIa::info("Repli réussi vers '{$mRepli}' — modèle actif mis à jour.");
                            break;
                        }

                        $statutRepli = $reponseRepli->status();
                        if ($statutRepli === 429) {
                            $reponseHttp = $reponseRepli;
                            $statut = 429;
                            break;
                        }
                    } catch (\Throwable $e) {
                        JournalisationIa::avertissement("Échec repli vers '{$mRepli}' : ".$e->getMessage());
                    }
                }
            }

            if ($statut === 404) {
                // Le détail amont reste dans les journaux (masqué) : jamais renvoyé à l'utilisateur.
                throw new AssistantIaException('Le modèle ou la ressource demandée sur GroqCloud est introuvable (HTTP 404).', 404);
            }
        }

        if ($statut === 400) {
            $erreur = JournalisationIa::masquerDonneesSensibles((string) ($reponseHttp->json('error.message') ?? 'Paramètres de requête non valides.'));
            JournalisationIa::avertissement("Requête invalide transmise à GroqCloud (HTTP 400) : {$erreur}");
            throw new AssistantIaException('Requête invalide transmise à GroqCloud (HTTP 400). Le détail technique est consigné côté serveur.', 400);
        }

        if ($statut >= 500) {
            JournalisationIa::avertissement("Erreur interne du fournisseur GroqCloud (HTTP {$statut}).");
            throw new AssistantIaException("Le fournisseur GroqCloud a rencontré une erreur interne (HTTP {$statut}).", $statut);
        }

        if (! $reponseHttp->successful()) {
            JournalisationIa::avertissement("Échec GroqCloud inattendu (HTTP {$statut}).");
            throw new AssistantIaException("Le fournisseur GroqCloud a répondu avec une erreur (HTTP {$statut}).", $statut);
        }

        // Vérification de la taille maximale de réponse autorisée
        if (strlen($reponseHttp->body()) > self::MAX_OCTETS_REPONSE) {
            throw new AssistantIaException('La réponse du fournisseur GroqCloud dépasse le volume maximal autorisé (512 Ko).', 502);
        }

        $donnees = $reponseHttp->json();
        if (! is_array($donnees) || empty($donnees)) {
            throw new AssistantIaException('Réponse vide ou structure JSON invalide reçue de GroqCloud.', 502);
        }

        $choix = $donnees['choices'][0]['message'] ?? null;
        if (! is_array($choix)) {
            throw new AssistantIaException("Structure de réponse inattendue reçue de GroqCloud (élément 'choices' manquant ou invalide).", 502);
        }

        $texte = (string) ($choix['content'] ?? '');
        if ($texte === '' && ! empty($choix['reasoning'])) {
            $texte = (string) $choix['reasoning'];
        }

        $appelsOutils = [];
        if (! empty($choix['tool_calls']) && is_array($choix['tool_calls'])) {
            foreach ($choix['tool_calls'] as $tc) {
                $nom = trim((string) ($tc['function']['name'] ?? ''));
                if ($nom === '') {
                    continue;
                }
                $argsJson = $tc['function']['arguments'] ?? '{}';
                $args = is_array($argsJson) ? $argsJson : (json_decode((string) $argsJson, true) ?? []);
                $id = (string) ($tc['id'] ?? uniqid('call_groq_'));
                $appelsOutils[] = new AppelOutil($id, $nom, $args);
            }
        }

        $usage = $donnees['usage'] ?? [];
        $tokensPrompt = (int) ($usage['prompt_tokens'] ?? 0);
        $tokensReponse = (int) ($usage['completion_tokens'] ?? 0);
        $idRequete = (string) ($donnees['id'] ?? uniqid('groq_'));
        $modeleEffectif = (string) ($donnees['model'] ?? $this->modele);

        return new ReponseIa(
            texte: $texte,
            appelsOutils: $appelsOutils,
            tokensPrompt: $tokensPrompt,
            tokensReponse: $tokensReponse,
            latenceMs: $latenceMs,
            fournisseur: 'groq',
            modele: $modeleEffectif,
            metadata: [
                'id_reponse' => $idRequete,
                'system_fingerprint' => $donnees['system_fingerprint'] ?? null,
            ]
        );
    }
}
