<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // AC-00c (13/10) : en CI, le build Vite s'exécute APRÈS les tests
        // (`ci.yml`) — sans `public/build` ni `public/hot`, `@vite` lèverait
        // une ViteException sur toute page rendue. On sert des tags vides ;
        // en local le comportement est identique pour les tests (le build
        // n'est jamais une dépendance d'assertion).
        $this->withoutVite();
    }
}
