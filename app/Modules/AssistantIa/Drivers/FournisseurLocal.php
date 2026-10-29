<?php

namespace App\Modules\AssistantIa\Drivers;

use App\Modules\AssistantIa\Contracts\FournisseurIaInterface;
use App\Modules\AssistantIa\DTO\AppelOutil;
use App\Modules\AssistantIa\DTO\DemandeIa;
use App\Modules\AssistantIa\DTO\ReponseIa;
use App\Modules\AssistantIa\Exceptions\AssistantIaException;
use App\Modules\AssistantIa\Services\FiltreSecuriteIa;
use App\Modules\AssistantIa\Services\JournalisationIa;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Adaptateur pour fournisseur local souverain (Ollama / vLLM local).
 * Les données ne quittent JAMAIS le serveur local.
 */
class FournisseurLocal implements FournisseurIaInterface
{
    public const MAX_OCTETS_REPONSE = 524288; // 512 Ko

    protected ?bool $supporteOutils = null;

    public function __construct(
        protected string $baseUrl = 'http://127.0.0.1:11434/v1',
        protected string $cleApi = 'local-token',
        protected string $modele = 'nvidia/NVIDIA-Nemotron-3.5-Bolt-E4B-4GB-DFlash',
        protected string $modeleEmbeddings = 'all-minilm',
        protected int $timeoutSecondes = 60,
        protected int $maxCaracteresEntree = 100000
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->validerNomModele($this->modele);
        if ($this->modeleEmbeddings !== '') {
            $this->validerNomModele($this->modeleEmbeddings);
        }
    }

    /**
     * Détermine si le modèle local prend en charge les outils natifs (Function Calling).
     * Les modèles ultra-légers (≤ 200M / purement textuels comme smollm:135m)
     * ne supportent pas le schéma JSON tools et renvoient HTTP 400 si 'tools' est transmis.
     */
    public function modeleSupporteOutilsNativement(): bool
    {
        if ($this->supporteOutils !== null) {
            return $this->supporteOutils;
        }

        $nom = strtolower($this->modele);
        if (str_contains($nom, 'smollm') || str_contains($nom, 'tinyllama') || str_contains($nom, 'minilm') || str_contains($nom, 'bge-')) {
            return $this->supporteOutils = false;
        }

        return true;
    }

    /**
     * Valide la conformité du nom de modèle local contre les injections de commande ou traversées de chemin.
     *
     * @throws AssistantIaException
     */
    public function validerNomModele(string $nom): void
    {
        $nom = trim($nom);
        if ($nom === '' || mb_strlen($nom) > 100 || ! preg_match('/^[a-zA-Z0-9_\-\.:\/]+$/', $nom) || str_contains($nom, '..')) {
            throw new AssistantIaException("Identifiant de modèle local invalide ou non sécurisé : '{$nom}'", 400);
        }
    }

    public function nomFournisseur(): string
    {
        return 'local';
    }

    public function nomModele(): string
    {
        return $this->modele;
    }

    public function nomModeleEmbeddings(): string
    {
        return $this->modeleEmbeddings;
    }

    public function estDisponible(): bool
    {
        try {
            // Test d'atteignabilité locale sans fuite réseau externe
            $reponse = Http::timeout(2)->get("{$this->baseUrl}/models");

            return $reponse->successful();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Liste les modèles actuellement téléchargés sur l'instance Ollama locale.
     *
     * @return array<int, string>
     */
    public function listerModelesInstalles(): array
    {
        try {
            $reponse = Http::timeout(2)->get("{$this->baseUrl}/models");
            if (! $reponse->successful()) {
                return [];
            }

            $donnees = $reponse->json() ?? [];
            $modeles = [];
            foreach ($donnees['data'] ?? [] as $m) {
                if (! empty($m['id'])) {
                    $modeles[] = (string) $m['id'];
                }
            }

            return $modeles;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Diagnostic complet de l'environnement matériel Apple Silicon et du serveur Ollama local.
     *
     * @return array{service_joignable: bool, endpoint: string, modele_actif: string, ram_totale_go: float, disque_libre_go: float, modele_recommande: string, embeddings_recommandes: string, statut: string, instructions_demarrage: array<string>}
     */
    public function diagnostiquerEnvironnement(): array
    {
        $joignable = $this->estDisponible();

        // Détection de la RAM physique (compatible macOS sysctl)
        $ramOctets = 8589934592; // 8 Go par défaut
        if (PHP_OS_FAMILY === 'Darwin') {
            $mem = @shell_exec('sysctl -n hw.memsize 2>/dev/null');
            if ($mem && is_numeric(trim($mem))) {
                $ramOctets = (float) trim($mem);
            }
        }
        $ramGo = round($ramOctets / (1024 * 1024 * 1024), 1);

        // Détection espace disque libre sur le volume du projet
        $disqueLibreOctets = @disk_free_space(base_path()) ?: (15 * 1024 * 1024 * 1024);
        $disqueLibreGo = round($disqueLibreOctets / (1024 * 1024 * 1024), 1);

        // Recommandation stricte pour 8 Go de RAM unifiée Apple Silicon M2
        $modeleRecommande = 'nvidia/NVIDIA-Nemotron-3.5-Bolt-E4B-4GB-DFlash';
        $embeddingsRecommandes = $this->modeleEmbeddings ?: 'all-minilm';

        $instructions = [
            '1. Installer Ollama : brew install ollama (ou télécharger sur https://ollama.com/download)',
            '2. Démarrer le serveur local en arrière-plan : ollama serve',
            "3. Télécharger le modèle souverain NVIDIA Nemotron : ollama pull {$this->modele} (ou nemotron-3.5-bolt)",
            "4. Télécharger le modèle d'embeddings léger : ollama pull {$this->modeleEmbeddings}",
            "5. Tester l'inférence locale : curl http://127.0.0.1:11434/v1/models",
        ];

        return [
            'service_joignable' => $joignable,
            'endpoint' => $this->baseUrl,
            'modele_actif' => $this->modele,
            'ram_totale_go' => $ramGo,
            'disque_libre_go' => $disqueLibreGo,
            'modele_recommande' => $modeleRecommande,
            'embeddings_recommandes' => $embeddingsRecommandes,
            'modele_nemotron' => 'nvidia/NVIDIA-Nemotron-3.5-Bolt-E4B-4GB-DFlash',
            'embeddings_ultra_legers' => 'all-minilm',
            'profil_materiel' => [
                'architecture' => PHP_OS_FAMILY === 'Darwin' ? 'Apple Silicon M2 / Mac' : 'Hôte Local',
                'memoire_totale_go' => $ramGo,
                'disque_libre_go' => $disqueLibreGo,
                'modele_recommande_generatif' => $this->modele ?: 'nvidia/NVIDIA-Nemotron-3.5-Bolt-E4B-4GB-DFlash',
                'modele_recommande_embeddings' => $this->modeleEmbeddings ?: 'all-minilm',
                'profil_nom' => 'NVIDIA Nemotron 3.5 Bolt (E4B 4GB DFlash) + All-MiniLM',
                'empreinte_ram' => '~4 Go VRAM / RAM unifiée (DFlash accéléré)',
            ],
            'statut' => $joignable ? 'pret' : 'service_offline',
            'instructions_demarrage' => $instructions,
        ];
    }

    public function genererReponse(DemandeIa $demande): ReponseIa
    {
        // 1. Validation de la taille des données d'entrée
        $totalCaracteres = 0;
        foreach ($demande->messages as $msg) {
            $totalCaracteres += mb_strlen($msg->contenu);
        }
        if ($totalCaracteres > $this->maxCaracteresEntree) {
            throw new AssistantIaException("Le volume du message entrant ({$totalCaracteres} caractères) dépasse le plafond autorisé ({$this->maxCaracteresEntree} caractères).", 413);
        }

        $debut = hrtime(true);

        $payload = [
            'model' => $this->modele,
            'messages' => $demande->preparerMessagesPourFournisseur(),
            'temperature' => $demande->temperature,
            'max_tokens' => $demande->maxTokens,
            'stream' => false,
        ];

        if (! empty($demande->outilsDisponibles) && $this->modeleSupporteOutilsNativement()) {
            $payload['tools'] = $demande->outilsDisponibles;
            $payload['tool_choice'] = 'auto';
        }

        try {
            $requete = Http::timeout($this->timeoutSecondes);
            if ($this->cleApi !== '') {
                $requete = $requete->withToken($this->cleApi);
            }

            $reponseHttp = $requete->post("{$this->baseUrl}/chat/completions", $payload);
        } catch (ConnectionException $e) {
            JournalisationIa::avertissement("Délai d'inférence locale ou service Ollama/vLLM non démarré : ".$e->getMessage());
            throw new AssistantIaException("Le service d'inférence locale (Ollama / vLLM) est injoignable sur {$this->baseUrl}. Aucun repli distant n'est effectué.", 504, $e);
        } catch (\Throwable $e) {
            JournalisationIa::avertissement('Erreur communication fournisseur local : '.$e->getMessage());
            throw new AssistantIaException("Erreur de communication avec le fournisseur d'inférence local.", 500, $e);
        }

        // Repli automatique sans 'tools' si le moteur local renvoie HTTP 400 pour incompatibilité d'outils
        if (! $reponseHttp->successful() && $reponseHttp->status() === 400 && ! empty($payload['tools'])) {
            $erreurJson = $reponseHttp->json();
            $messageErreur = is_array($erreurJson) && isset($erreurJson['error']['message'])
                ? (string) $erreurJson['error']['message']
                : (string) $reponseHttp->body();

            if (str_contains(strtolower($messageErreur), 'does not support tools') || str_contains(strtolower($messageErreur), 'tools')) {
                JournalisationIa::info("Le modèle local '{$this->modele}' ne supporte pas les outils natifs ('tools'). Repli automatique sans outils.");
                $this->supporteOutils = false;
                unset($payload['tools'], $payload['tool_choice']);

                try {
                    $reponseHttp = $requete->post("{$this->baseUrl}/chat/completions", $payload);
                } catch (\Throwable $e) {
                    JournalisationIa::avertissement('Échec lors du repli sans outils du fournisseur local : '.$e->getMessage());
                    throw new AssistantIaException("Erreur de communication avec le fournisseur d'inférence local après repli sans outils.", 500, $e);
                }
            }
        }

        $fin = hrtime(true);
        $latenceMs = round(($fin - $debut) / 1e6, 2);

        if (! $reponseHttp->successful()) {
            $statut = $reponseHttp->status();

            if ($statut === 401) {
                JournalisationIa::avertissement("Authentification refusée par le fournisseur d'inférence local (HTTP 401).");
                throw new AssistantIaException("Authentification refusée par le fournisseur d'inférence local (HTTP 401).", 401);
            }

            if ($statut === 403) {
                JournalisationIa::avertissement("Accès interdit par le fournisseur d'inférence local (HTTP 403).");
                throw new AssistantIaException("Accès interdit par le fournisseur d'inférence local (HTTP 403).", 403);
            }

            $erreurJson = $reponseHttp->json();
            $messageErreur = is_array($erreurJson) && isset($erreurJson['error']['message'])
                ? (string) $erreurJson['error']['message']
                : (string) $reponseHttp->body();

            $messageErreurPurge = FiltreSecuriteIa::purgerSortie($messageErreur);
            if ($this->cleApi !== '') {
                $messageErreurPurge = str_replace($this->cleApi, '[REDACTED]', $messageErreurPurge);
            }

            JournalisationIa::avertissement("Échec fournisseur local HTTP {$statut} : {$messageErreurPurge}");
            $suffixe = $messageErreurPurge !== '' ? " : {$messageErreurPurge}" : '.';
            throw new AssistantIaException("Le moteur d'inférence local a renvoyé une erreur (HTTP {$statut}){$suffixe}", $statut);
        }

        // Vérification de la taille de la réponse
        if (strlen($reponseHttp->body()) > self::MAX_OCTETS_REPONSE) {
            throw new AssistantIaException('La réponse du modèle local dépasse le volume maximal autorisé (512 Ko).', 502);
        }

        $donnees = $reponseHttp->json();
        if (! is_array($donnees)) {
            throw new AssistantIaException('Réponse invalide reçue du modèle local : format JSON corrompu ou non décodable.', 502);
        }

        if (empty($donnees['choices']) || ! is_array($donnees['choices'])) {
            throw new AssistantIaException('Réponse invalide reçue du modèle local : absence de choix généré.', 502);
        }

        $choix = $donnees['choices'][0]['message'] ?? [];
        $texte = (string) ($choix['content'] ?? '');

        $appelsOutils = [];
        if (! empty($choix['tool_calls']) && is_array($choix['tool_calls'])) {
            foreach ($choix['tool_calls'] as $tc) {
                $nom = trim((string) ($tc['function']['name'] ?? ''));
                if ($nom === '') {
                    continue;
                }
                $argsJson = $tc['function']['arguments'] ?? '{}';
                $args = is_array($argsJson) ? $argsJson : (json_decode((string) $argsJson, true) ?? []);
                $id = (string) ($tc['id'] ?? uniqid('call_'));
                $appelsOutils[] = new AppelOutil($id, $nom, $args);
            }
        }

        $usage = $donnees['usage'] ?? [];
        $tokensPrompt = (int) ($usage['prompt_tokens'] ?? 0);
        $tokensReponse = (int) ($usage['completion_tokens'] ?? 0);

        return new ReponseIa(
            texte: $texte,
            appelsOutils: $appelsOutils,
            tokensPrompt: $tokensPrompt,
            tokensReponse: $tokensReponse,
            latenceMs: $latenceMs,
            fournisseur: 'local',
            modele: $this->modele
        );
    }
}
