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
 * Adaptateur pour fournisseur distant via API REST compatible OpenAI / vLLM / Groq.
 */
class FournisseurRestDistant implements FournisseurIaInterface
{
    public const MAX_OCTETS_REPONSE = 524288; // 512 Ko

    public function __construct(
        protected string $baseUrl,
        protected string $cleApi,
        protected string $modele = 'gpt-4o-mini',
        protected int $timeoutSecondes = 30,
        protected bool $forcerHttps = true
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');

        // Validation stricte du protocole HTTPS pour tout endpoint distant
        if ($this->forcerHttps && ! str_starts_with($this->baseUrl, 'https://')) {
            $estLocalTest = app()->environment('testing') && (str_contains($this->baseUrl, '127.0.0.1') || str_contains($this->baseUrl, 'localhost'));
            if (! $estLocalTest) {
                throw new AssistantIaException("Protocole non sécurisé rejeté : le fournisseur distant requiert impérativement un point de terminaison chiffré HTTPS (reçu : '{$this->baseUrl}').", 400);
            }
        }
    }

    public function nomFournisseur(): string
    {
        return 'distant';
    }

    public function nomModele(): string
    {
        return $this->modele;
    }

    public function estDisponible(): bool
    {
        if ($this->cleApi === '' && ! app()->environment('testing')) {
            return false;
        }

        try {
            $reponse = Http::timeout(3)
                ->withToken($this->cleApi)
                ->get("{$this->baseUrl}/models");

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
        if ($this->cleApi === '' && ! app()->environment('testing')) {
            throw new AssistantIaException("La clé d'authentification du fournisseur distant n'est pas configurée dans l'environnement.", 401);
        }

        $debut = hrtime(true);

        $payload = [
            'model' => $this->modele,
            'messages' => $demande->preparerMessagesPourFournisseur(),
            'temperature' => $demande->temperature,
            'max_tokens' => $demande->maxTokens,
        ];

        // Seuls les outils en lecture seule sont transmis au fournisseur distant
        $outilsFiltres = $this->filtrerOutilsPourDistant($demande->outilsDisponibles);
        if (! empty($outilsFiltres)) {
            $payload['tools'] = $outilsFiltres;
            $payload['tool_choice'] = 'auto';
        }

        try {
            $requete = Http::timeout($this->timeoutSecondes);
            if ($this->cleApi !== '') {
                $requete = $requete->withToken($this->cleApi);
            }

            $reponseHttp = $requete->post("{$this->baseUrl}/chat/completions", $payload);
        } catch (ConnectionException $e) {
            JournalisationIa::avertissement("Délai d'attente ou échec réseau fournisseur distant : ".$e->getMessage());
            throw new AssistantIaException('Le fournisseur IA distant est inaccessible (délai dépassé ou réseau indisponible).', 504, $e);
        } catch (\Throwable $e) {
            JournalisationIa::avertissement('Erreur de requête vers fournisseur distant : '.$e->getMessage());
            throw new AssistantIaException('Erreur de communication avec le fournisseur IA distant.', 500, $e);
        }

        $fin = hrtime(true);
        $latenceMs = round(($fin - $debut) / 1e6, 2);

        if ($reponseHttp->status() === 429) {
            throw new QuotasDepassesException("Le quota du fournisseur distant d'IA a été dépassé (HTTP 429).");
        }

        if (! $reponseHttp->successful()) {
            $statut = $reponseHttp->status();
            $erreur = $reponseHttp->json('error.message') ?? $reponseHttp->body();
            JournalisationIa::avertissement("Échec fournisseur distant HTTP {$statut} : {$erreur}");
            throw new AssistantIaException("Le fournisseur distant a répondu avec une erreur (HTTP {$statut}).", $statut);
        }

        // Vérification de la taille maximale de réponse autorisée
        if (strlen($reponseHttp->body()) > self::MAX_OCTETS_REPONSE) {
            throw new AssistantIaException('La réponse du fournisseur distant dépasse le volume maximal autorisé (512 Ko).', 502);
        }

        $donnees = $reponseHttp->json() ?? [];
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
            fournisseur: 'distant',
            modele: $this->modele,
            metadata: ['id_reponse' => $donnees['id'] ?? null]
        );
    }
}
