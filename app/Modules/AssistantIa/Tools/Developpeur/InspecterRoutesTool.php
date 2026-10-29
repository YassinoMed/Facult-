<?php

namespace App\Modules\AssistantIa\Tools\Developpeur;

use App\Models\User;
use App\Modules\AssistantIa\Contracts\OutilIaInterface;
use App\Modules\AssistantIa\DTO\ResultatOutil;
use Illuminate\Support\Facades\Route;

/**
 * Outil développeur : Consultation et filtrage des routes déclarées dans l'application.
 */
class InspecterRoutesTool implements OutilIaInterface
{
    public function nom(): string
    {
        return 'consulter_routes';
    }

    public function description(): string
    {
        return 'Consulte les routes enregistrées dans Laravel avec leurs méthodes HTTP, URI, contrôleurs et middlewares.';
    }

    public function mode(): string
    {
        return 'developpeur';
    }

    public function permissionRequise(): string
    {
        return 'assistant_ia.mode_developpeur';
    }

    public function schemaParametres(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'filtre' => [
                    'type' => 'string',
                    'description' => 'Filtre textuel sur l\'URI, le nom de route ou l\'action (ex: "assistant-ia", "inscriptions", "admin.centre-travail")',
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
        $filtre = mb_strtolower(trim((string) ($parametres['filtre'] ?? '')));
        $routes = Route::getRoutes();
        $resultats = [];

        foreach ($routes as $route) {
            $uri = $route->uri();
            $nom = $route->getName() ?? '';
            $action = $route->getActionName();
            $methodes = implode('|', $route->methods());

            if ($filtre !== '') {
                if (
                    ! str_contains(mb_strtolower($uri), $filtre) &&
                    ! str_contains(mb_strtolower($nom), $filtre) &&
                    ! str_contains(mb_strtolower($action), $filtre)
                ) {
                    continue;
                }
            }

            $resultats[] = [
                'methodes' => $methodes,
                'uri' => $uri,
                'nom' => $nom,
                'action' => $action,
                'middleware' => $route->middleware(),
            ];

            if (count($resultats) >= 30) {
                break;
            }
        }

        return ResultatOutil::succes([
            'total' => count($resultats),
            'routes' => $resultats,
        ], count($resultats).' route(s) correspondante(s) trouvée(s).');
    }
}
