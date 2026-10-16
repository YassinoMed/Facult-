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
        if (config('database.default') !== 'sqlite') {
            $this->fail(
                'BLOCAGE SÉCURITÉ : les tests exigent la base sqlite isolée '
                .'(phpunit.xml), connexion active : '.config('database.default')
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
