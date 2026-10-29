<?php

namespace Tests\Concerns;

use App\Models\User;

/**
 * Helpers des suites API : création de clients externes à scopes restreints
 * (un token = un système externe, jamais de token global).
 */
trait ActAsApiClient
{
    /** Dernier token généré par clientApi() — pratique pour enchaîner les requêtes. */
    protected string $dernierToken = '';

    /**
     * Crée un compte technique client + un token Sanctum avec les scopes fournis.
     *
     * @param  array<int, string>  $scopes
     * @return array{0: User, 1: string}
     */
    protected function clientApi(array $scopes): array
    {
        $client = User::creerCompteBackOffice([
            'name' => 'Client API Test',
            'email' => uniqid('api-client-', true).'@integration.systeme',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $token = $client->createToken('integration-test', $scopes);
        $this->dernierToken = $token->plainTextToken;

        return [$client, $token->plainTextToken];
    }

    /**
     * Requête GET authentifiée par Bearer token.
     */
    protected function getApi(string $uri, string $token, array $headers = [])
    {
        return $this->get($uri, array_merge($headers, ['Authorization' => 'Bearer '.$token]));
    }

    protected function postApi(string $uri, array $data, string $token, array $headers = [])
    {
        return $this->post($uri, $data, array_merge($headers, ['Authorization' => 'Bearer '.$token]));
    }

    /**
     * Purge les guards résolus : simule une requête HTTP fraîche (en
     * production chaque requête a sa propre instance d'application).
     * Indispensable pour tester révocation / changement de token dans
     * une même méthode de test.
     *
     * `forgetGuards()` seul ne suffit pas : entre deux requêtes du test,
     * `auth()` (audit, composeurs de vues) est appelé hors requête et
     * recréerait le guard `sanctum` avec la requête encore liée — il
     * mettrait alors l'utilisateur du token précédent en cache. On
     * restaure donc aussi le guard par défaut de l'application.
     */
    protected function resetAuthGuards(): void
    {
        $auth = $this->app['auth'];

        $auth->forgetGuards();
        $auth->setDefaultDriver((string) env('AUTH_GUARD', 'web'));
    }
}
