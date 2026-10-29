<?php

namespace App\Modules\AssistantIa\Tools\Commun;

use App\Models\User;
use App\Modules\AssistantIa\Contracts\OutilIaInterface;
use App\Modules\AssistantIa\DTO\ResultatOutil;
use App\Modules\AssistantIa\Services\ConnecteurWebControle;

/**
 * Outil de recherche et consultation de la documentation technique officielle externe.
 * Restreint aux domaines whitelistés (Laravel, PHP, MySQL, Ollama, OWASP).
 */
class ConsulterDocumentationWebTool implements OutilIaInterface
{
    public function __construct(
        protected ?ConnecteurWebControle $connecteurWeb = null
    ) {
        $this->connecteurWeb = $connecteurWeb ?? app(ConnecteurWebControle::class);
    }

    public function nom(): string
    {
        return 'consulter_documentation_web';
    }

    public function description(): string
    {
        return 'Consulte une page de documentation technique officielle externe autorisée (Laravel, PHP, MySQL, Ollama, OWASP).';
    }

    public function mode(): string
    {
        return 'commun';
    }

    public function permissionRequise(): string
    {
        return 'assistant_ia.recherche_doc';
    }

    public function schemaParametres(): array
    {
        return [
            'type' => 'object',
            'required' => ['url'],
            'properties' => [
                'url' => [
                    'type' => 'string',
                    'description' => 'URL HTTPS complète vers la page officielle autorisée (ex: "https://laravel.com/docs/11.x/eloquent")',
                ],
            ],
        ];
    }

    public function estSensible(): bool
    {
        return false;
    }

    public function executer(array $parametres, User $utilisateur): ResultatOutil
    {
        $url = trim((string) ($parametres['url'] ?? ''));
        if (empty($url)) {
            return ResultatOutil::echec("L'argument 'url' est obligatoire.");
        }

        $resultat = $this->connecteurWeb->consulterPage($url);

        if (! $resultat['succes']) {
            return ResultatOutil::echec($resultat['message'] ?? 'Impossible d’accéder à la documentation externe.');
        }

        $sources = [
            [
                'source' => 'approved_web_source',
                'titre' => $resultat['titre'],
                'chemin' => $resultat['url'],
                'date' => $resultat['date_consultation'],
            ],
        ];

        return ResultatOutil::succes(
            message: "Documentation consultée avec succès depuis {$resultat['titre']} ({$resultat['url']}).",
            donnees: [
                'titre' => $resultat['titre'],
                'url' => $resultat['url'],
                'date_consultation' => $resultat['date_consultation'],
                'extrait' => mb_substr($resultat['contenu'], 0, 1500),
            ],
            sources: $sources
        );
    }
}
