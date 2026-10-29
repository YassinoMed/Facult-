<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Test historique (nom conservé) : la page d'accueil publique doit
 * répondre 200. Elle interroge l'état des périodes d'inscription : la
 * base de test doit donc être migrée, comme pour tous les tests HTTP
 * qui touchent un contrôleur réel.
 */
class ScrutinTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_accueil_publique_repond(): void
    {
        $response = $this->get('/');
        $response->assertOk();
    }
}
