<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Garde anti-catastrophe (16/10/2026 : la base de dev a été vidée
        // par une opération destructive) : aucun test ne doit jamais
        // toucher MySQL ni aucune base réelle. Seule la base sqlite
        // isolée de phpunit.xml est autorisée — échec immédiat sinon.
        //
        // Seule exception, volontairement étroite : les tests de
        // concurrence MySQL DÉDIÉS (LOOP concurrence), exécutables
        // uniquement avec un opt-in explicite (`CONCURRENCE_MYSQL=1` ou
        // `LOOP4_MYSQL=1` — un flag par loop concernée) ET une base au
        // nom réservé `inscription_*_test` (jamais la base de dev
        // `inscription_etudiants`, jamais prod). Chaque test concerné
        // re-vérifie lui-même le nom exact de sa base.
        $base = (string) (getenv('DB_DATABASE') ?: '');
        $optInDedie = getenv('CONCURRENCE_MYSQL') === '1' || getenv('LOOP4_MYSQL') === '1';
        $concurrenceDediee = $optInDedie
            && config('database.default') === 'mysql'
            && preg_match('/^inscription_[a-z0-9_]*_test$/', strtolower($base)) === 1;

        if (config('database.default') !== 'sqlite' && ! $concurrenceDediee) {
            $this->fail(
                'BLOCAGE SÉCURITÉ : les tests exigent la base sqlite isolée '
                .'(phpunit.xml), connexion active : '.config('database.default')
                .' (seule exception : CONCURRENCE_MYSQL=1 ou LOOP4_MYSQL=1 + base inscription_*_test)'
            );
        }

        // AC-00c (13/10) : en CI, le build Vite s'exécute APRÈS les tests
        // (`ci.yml`) — sans `public/build` ni `public/hot`, `@vite` lèverait
        // une ViteException sur toute page rendue. On sert des tags vides ;
        // en local le comportement est identique pour les tests (le build
        // n'est jamais une dépendance d'assertion).
        $this->withoutVite();
    }
}
