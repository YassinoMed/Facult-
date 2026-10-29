<?php

namespace App\Modules\AiAssistant\Providers;

use App\Modules\AiAssistant\Contracts\AiFournisseur;
use App\Modules\AiAssistant\Data\AiDemande;
use App\Modules\AiAssistant\Data\AiReponse;
use App\Modules\AiAssistant\Exceptions\FournisseurIndisponibleException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Fournisseur LOCAL Ollama (API native /api/chat) — reprend le CONTRAT
 * `AiFournisseur` de la passerelle existante (aucune 2e architecture,
 * aucune dépendance SDK : client HTTP Laravel natif).
 *
 * Garanties LOCAL-STRICT :
 * - la base_url est refusée à la construction si elle n'est PAS en boucle
 *   locale (127.0.0.1, [::1], localhost) : toute destination distante est
 *   bloquée par construction ;
 * - le modèle configuré doit figurer dans le catalogue LOCAL du serveur
 *   (/api/tags) AVANT toute génération. Aucun « pull » n'est déclenché :
 *   un modèle absent = erreur typée, jamais un téléchargement ;
 * - les modèles « -cloud » (exécutés hors boîte par le service amont)
 *   et les entrées sans poids réels (< 1 Mo) sont refusés, même si le
 *   serveur les liste ;
 * - AUCUN fallback : service éteint ou modèle absent => erreur typée
 *   qui remonte (fail-soft géré par la passerelle de chat) ;
 * - aucune clé : aucun en-tête d'autorisation n'est envoyé (Ollama
 *   local n'en exige pas). Les motifs d'erreur restent génériques et ne
 *   réutilisent jamais le corps d'une réponse du serveur.
 */
final class FournisseurOllama implements AiFournisseur
{
    private string $baseUrl;

    private bool $verifierModele;

    /** @var array<string, mixed> */
    private array $options;

    private const SYSTEME_PAR_MODE = [
        'developpeur' => "Réponds uniquement à partir des extraits fournis. Ne révèle jamais de secrets ni d'environnement. Toute proposition de patch est une PROPOSITION.",
        'metier' => "Réponds uniquement à partir des règles documentées fournies. Si la réponse n'est pas établissable, dis-le clairement.",
    ];

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private string $nomFournisseur,
        private array $config,
    ) {
        $baseUrl = rtrim((string) ($config['base_url'] ?? ''), '/');
        $modele = (string) ($config['modele'] ?? '');

        if ($baseUrl === '' || $modele === '') {
            // Sans chemin, sans clé, sans télémétrie sur la config manquante.
            throw new FournisseurIndisponibleException(
                $nomFournisseur,
                'non configuré (base_url/modele manquants)'
            );
        }

        if (! self::estBoucleLocale($baseUrl)) {
            throw new FournisseurIndisponibleException(
                $nomFournisseur,
                'base_url hors boucle locale refusée'
            );
        }

        $this->baseUrl = $baseUrl;
        $this->modele = $modele;
        $this->verifierModele = (bool) ($config['verifier_modele'] ?? true);
        $candidat = $config['options'] ?? null;
        $this->options = is_array($candidat) ? $candidat : [];
        $this->secondes = max(5, (int) ($config['secondes'] ?? 60));
    }

    public function nom(): string
    {
        return $this->nomFournisseur;
    }

    public function modele(): string
    {
        return $this->modele;
    }

    public function generer(AiDemande $demande): AiReponse
    {
        if ($this->verifierModele) {
            $this->exigerModeleLocal();
        }

        $debut = hrtime(true);

        try {
            $reponse = Http::asJson()
                ->baseUrl($this->baseUrl)
                ->timeout($this->secondes)
                ->connectTimeout(3)
                ->post('/api/chat', [
                    'model' => $this->modele,
                    'messages' => $this->serialiser($demande),
                    'stream' => false, // NON négociable : sinon Ollama renvoie du flux jsonl.
                    'options' => $this->optionsGeneration($demande),
                ]);
        } catch (ConnectionException) {
            throw new FournisseurIndisponibleException($this->nomFournisseur, 'service local inaccessible');
        }

        if ($reponse->failed()) {
            throw new FournisseurIndisponibleException(
                $this->nomFournisseur,
                'HTTP '.$reponse->status()
            );
        }

        $texte = $reponse->json('message.content');
        if (! is_string($texte) || $texte === '') {
            throw new FournisseurIndisponibleException($this->nomFournisseur, 'réponse invalide');
        }

        $sources = array_map(
            fn (array $chunk) => ['titre' => (string) $chunk['titre'], 'chemin' => (string) $chunk['chemin']],
            $demande->contexte
        );

        return new AiReponse(
            texte: $texte,
            fournisseur: $this->nomFournisseur,
            modele: $this->modele,
            latenceMs: $this->latence($debut),
            jetonsEntree: $this->nombreOuNull((string) $reponse->json('prompt_eval_count')),
            jetonsSortie: $this->nombreOuNull((string) $reponse->json('eval_count')),
            sources: $sources,
            metadonnees: ['mode' => $demande->mode],
        );
    }

    /**
     * Catalogue LOCAL obligatoire : le modèle DOIT y figurer avec des
     * poids réels. Serveur muet ou hors service => erreur typée ; jamais
     * de pull implicite, jamais de re-routage vers une destination
     * externe.
     */
    private function exigerModeleLocal(): void
    {
        try {
            $tags = Http::asJson()
                ->baseUrl($this->baseUrl)
                ->timeout(5)
                ->connectTimeout(3)
                ->get('/api/tags');
        } catch (ConnectionException) {
            throw new FournisseurIndisponibleException($this->nomFournisseur, 'service local inaccessible');
        }

        if ($tags->failed()) {
            throw new FournisseurIndisponibleException($this->nomFournisseur, 'catalogue local illisible');
        }

        foreach (is_array($tags->json('models')) ? $tags->json('models') : [] as $entree) {
            if (! is_array($entree) || (string) ($entree['name'] ?? '') !== $this->modele) {
                continue;
            }

            if (str_ends_with($this->modele, '-cloud')) {
                throw new FournisseurIndisponibleException($this->nomFournisseur, 'modèle cloud refusé (requiert un service amont hors boîte)');
            }

            if ((int) ($entree['size'] ?? 0) < 1_000_000) {
                throw new FournisseurIndisponibleException($this->nomFournisseur, 'entrée de catalogue sans poids réels refusée');
            }

            return; // Modèle présent localement avec des poids : génération autorisée.
        }

        throw new FournisseurIndisponibleException($this->nomFournisseur, 'modèle non installé localement');
    }

    /**
     * Budget de génération borné SERVEUR : température fixe, num_predict
     * imposé par jetonsMax ou par le plafond configurationnel. Aucun
     * outil ni choix de budget exposé au modèle.
     *
     * @return array<string, mixed>
     */
    private function optionsGeneration(AiDemande $demande): array
    {
        return $this->options + [
            'temperature' => 0.2,
            'num_predict' => $demande->jetonsMax ?? (int) config('ai-assistant.limites.jetons_max', 2000),
        ];
    }

    /**
     * Sérialisation bornée identique à l'adaptateur OpenAI-compatible :
     * système par mode + historique + extraits étiquetés + question.
     *
     * @return list<array{role: string, content: string}>
     */
    private function serialiser(AiDemande $demande): array
    {
        $messages = [
            ['role' => 'system', 'content' => self::SYSTEME_PAR_MODE[$demande->mode]],
        ];

        foreach ($demande->historique as $tour) {
            $role = $tour['role'] === 'assistant' ? 'assistant' : 'user';
            $messages[] = ['role' => $role, 'content' => (string) $tour['contenu']];
        }

        foreach ($demande->contexte as $chunk) {
            $messages[] = [
                'role' => 'system',
                'content' => 'SOURCE Fiable [chemin='.$chunk['chemin'].'] '.$chunk['extrait'],
            ];
        }

        $messages[] = ['role' => 'user', 'content' => $demande->question];

        return $messages;
    }

    /**
     * Restriction : base_url réservée à la machine hôte (boucle locale
     * uniquement) — garantit qu'AUCUN flux ne quitte l'hôte.
     */
    private static function estBoucleLocale(string $url): bool
    {
        $hote = strtolower((string) parse_url($url, PHP_URL_HOST));

        return in_array($hote, ['127.0.0.1', '::1', 'localhost'], true);
    }

    private function nombreOuNull(string $nombre): ?int
    {
        return is_numeric($nombre) ? (int) $nombre : null;
    }

    private function latence(int $debut): int
    {
        return (int) ((hrtime(true) - $debut) / 1_000_000);
    }
}
