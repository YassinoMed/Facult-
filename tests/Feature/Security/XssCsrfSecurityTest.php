<?php

namespace Tests\Feature\Security;

use App\Models\Etudiant;
use App\Models\Faculte;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\ActAsScolarite;
use Tests\TestCase;

/**
 * XSS stocké/réfléchi (§13, §52) + CSRF (§6) : Blade échappe par défaut,
 * les mutations exigent un token valide.
 */
class XssCsrfSecurityTest extends TestCase
{
    use ActAsScolarite, RefreshDatabase;

    private Etudiant $etudiant;

    protected function setUp(): void
    {
        parent::setUp();

        $faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->etudiant = Etudiant::create([
            'cin' => 'BK123456', 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => uniqid('s.', true).'@example.com',
            'sexe' => 'F', 'faculte_id' => $faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2025-2026',
            'adresse' => '1 rue', 'ville' => 'Rabat',
            'code_postal' => '10000', 'annee_bac' => '2024',
            'password' => 'motdepasse1',
        ]);
    }

    public function test_script_stocke_echappe_dans_liste(): void
    {
        $this->etudiant->forceFill(['nom' => '<script>alert(1)</script>'])->save();

        $this->scolarite('admin_scolarite');

        $reponse = $this->get(route('admin.etudiants.index'))->assertOk();

        $reponse->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $reponse->getContent());
    }

    public function test_img_onerror_stocke_echappe_dans_fiche(): void
    {
        $this->etudiant->forceFill(['prenom' => '<img src=x onerror=alert(1)>'])->save();

        $this->scolarite('admin_scolarite');

        $reponse = $this->get(route('admin.etudiants.show', $this->etudiant))->assertOk();

        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $reponse->getContent());
        $reponse->assertSee('&lt;img', false);
    }

    public function test_quote_breakout_echappe_en_recherche(): void
    {
        $this->scolarite('admin_scolarite');

        // Tentative de sortie d'attribut : les guillemets doivent être
        // échappés, l'attribut injecté ne doit jamais apparaître brut.
        $reponse = $this->get(route('admin.etudiants.index', ['search' => '" onmouseover="alert(1)']))
            ->assertOk();

        $this->assertStringNotContainsString('" onmouseover="alert(1)', $reponse->getContent());
        $reponse->assertSee('&quot; onmouseover=&quot;alert(1)', false);
    }

    public function test_badge_statut_sans_contenu_utilisateur(): void
    {
        // statut_badge est 100 % statique (match sur enum) : aucune entrée
        // utilisateur n'y est interpolée.
        $badge = $this->etudiant->statut_badge;

        $this->assertStringNotContainsString($this->etudiant->nom, $badge);
        $this->assertStringNotContainsString($this->etudiant->cin, $badge);
    }

    public function test_post_sans_csrf_rejete_419(): void
    {
        // Laravel bypass le CSRF quand runningUnitTests() (y compris en
        // instanciant le middleware) : on vérifie la comparaison des
        // jetons via réflexion + la présence du groupe 'web' (test suivant).
        $comparer = $this->comparateurCsrf();

        $requete = Request::create('/admin/archives', 'POST', ['etudiant_id' => 1]);
        $requete->setLaravelSession($session = app('session')->driver());
        $session->put('_token', 'jeton-session-valide');

        $this->assertFalse($comparer($requete));
    }

    public function test_post_csrf_invalide_rejete(): void
    {
        $comparer = $this->comparateurCsrf();

        $requete = Request::create('/admin/archives', 'POST', [
            'etudiant_id' => 1, '_token' => 'jeton-falsifie',
        ]);
        $requete->setLaravelSession($session = app('session')->driver());
        $session->put('_token', 'jeton-session-valide');

        $this->assertFalse($comparer($requete));

        // Jeton valide : accepté.
        $requeteOk = Request::create('/admin/archives', 'POST', [
            'etudiant_id' => 1, '_token' => 'jeton-session-valide',
        ]);
        $requeteOk->setLaravelSession($session);

        $this->assertTrue($comparer($requeteOk));
    }

    /**
     * Expose VerifyCsrfToken::tokensMatch (protected) pour vérification
     * unitaire : la comparaison constant-time rejette absent/falsifié.
     */
    private function comparateurCsrf(): \Closure
    {
        $middleware = app(VerifyCsrfToken::class);
        $methode = new \ReflectionMethod($middleware, 'tokensMatch');
        $methode->setAccessible(true);

        return fn (Request $requete) => $methode->invoke($middleware, $requete);
    }

    public function test_toutes_routes_web_mutantes_sous_csrf(): void
    {
        // Structurel : chaque route web POST/PUT/PATCH/DELETE doit porter
        // le groupe 'web' (qui inclut VerifyCsrfToken en production).
        $sansWeb = [];

        foreach (Route::getRoutes() as $route) {
            $methodes = array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']);
            $uri = $route->uri();

            if ($methodes === [] || str_starts_with($uri, 'api/')) {
                continue;
            }

            $middlewares = $route->gatherMiddleware();

            if (! in_array('web', $middlewares, true)) {
                $sansWeb[] = implode('|', $methodes).' '.$uri;
            }
        }

        $this->assertSame([], $sansWeb, 'Routes web mutantes hors groupe web (sans CSRF) : '.implode(', ', $sansWeb));
    }

    public function test_post_csrf_valide_accepte(): void
    {
        $this->scolarite('admin_scolarite');

        // Sans dossier existant : création légitime → 302 (pas 419).
        $this->post(route('admin.archives.store'), [
            'etudiant_id' => $this->etudiant->id,
        ])->assertRedirect();
    }
}
