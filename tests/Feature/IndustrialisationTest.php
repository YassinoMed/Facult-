<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\SessionsController;
use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\Schedule\Models\Room;
use App\Services\DataExchange\Definitions\RoomDefinition;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Phases 25-32 — Industrialisation : PWA, salles localisées,
 * reset mot de passe, sessions, backup, système, audit routes.
 */
class IndustrialisationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Super', 'email' => 'super.ind@universite.test', 'password' => 'Motdepasse@123',
        ], 'super_admin');
        AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
    }

    public function test_pwa_manifest_sw_offline(): void
    {
        // Assets statiques : servis par le frontal HTTP en prod, vérifiés
        // ici au niveau fichier (aucun serveur web dans les tests).
        $manifest = json_decode((string) file_get_contents(public_path('manifest.webmanifest')), true);
        $this->assertSame('UniRegistre', $manifest['short_name']);
        $this->assertFileExists(public_path('icons/icon-192.png'));
        $this->assertFileExists(public_path('icons/icon-512.png'));
        $this->assertFileExists(public_path('icons/apple-touch-icon.png'));
        $sw = (string) file_get_contents(public_path('sw.js'));
        $this->assertStringContainsString('uniregistre-v1', $sw);
        $this->assertStringContainsString('/api/', $sw);

        $this->get('/hors-ligne')->assertOk();
        $this->get('/')->assertSee('/manifest.webmanifest', false)
            ->assertSee('theme-color', false);
    }

    public function test_salle_localisation_et_export(): void
    {
        $this->actingAs($this->admin)->post(route('admin.salles.store'), [
            'code' => 'B204', 'nom' => 'B204', 'capacite' => 60,
            'campus' => 'Hay Riad', 'batiment' => 'B', 'etage' => '2',
        ])->assertSessionHasNoErrors();

        $salle = Room::where('code', 'B204')->firstOrFail();
        $this->assertSame('Hay Riad · B · 2', $salle->localisation());

        $definition = app(RoomDefinition::class);
        $this->assertContains('campus', $definition->colonnes());
        $this->assertSame('Hay Riad', $definition->ligne($salle)['campus']);
    }

    public function test_reset_mot_de_passe_broker(): void
    {
        Notification::fake();

        // Compte existant : lien envoyé (notification).
        $this->post(route('admin.mot-de-passe.envoyer'), ['email' => 'super.ind@universite.test'])
            ->assertSessionHasNoErrors();
        Notification::assertSentTo($this->admin, ResetPassword::class);

        // Compte inexistant : même message, aucune fuite.
        $this->post(route('admin.mot-de-passe.envoyer'), ['email' => 'inconnu@universite.test'])
            ->assertSessionHas('success');

        // Reset avec token valide.
        $token = Password::broker('users')->createToken($this->admin);
        $this->post(route('admin.mot-de-passe.enregistrer'), [
            'token' => $token,
            'email' => 'super.ind@universite.test',
            'password' => 'Nouveau@Motdepasse123',
            'password_confirmation' => 'Nouveau@Motdepasse123',
        ])->assertSessionHasNoErrors();

        // Token réutilisé : rejeté.
        $this->post(route('admin.mot-de-passe.enregistrer'), [
            'token' => $token,
            'email' => 'super.ind@universite.test',
            'password' => 'Autre@Motdepasse123',
            'password_confirmation' => 'Autre@Motdepasse123',
        ])->assertSessionHasErrors('email');
    }

    public function test_sessions_liste_et_revocation_cloisonnee(): void
    {
        DB::table('sessions')->insert([
            ['id' => 'mienne-1', 'user_id' => $this->admin->id, 'ip_address' => '1.2.3.4', 'user_agent' => 'Test', 'payload' => '', 'last_activity' => time()],
            ['id' => 'autre-1', 'user_id' => 999999, 'ip_address' => '9.9.9.9', 'user_agent' => 'Autre', 'payload' => '', 'last_activity' => time()],
        ]);

        $reponse = $this->actingAs($this->admin)->get(route('admin.sessions.index'));
        $reponse->assertOk()->assertSee('1.2.3.4', false)->assertDontSee('9.9.9.9');

        // Session d'autrui : non supprimée (scope user_id).
        $this->actingAs($this->admin)->delete(route('admin.sessions.destroy', 'autre-1'))
            ->assertSessionHas('success');
        $this->assertDatabaseHas('sessions', ['id' => 'autre-1']);

        // La sienne : révoquée.
        $this->actingAs($this->admin)->delete(route('admin.sessions.destroy', 'mienne-1'))
            ->assertSessionHas('success');
        $this->assertDatabaseMissing('sessions', ['id' => 'mienne-1']);

        // Session courante : 422 via appel direct (le harnais HTTP
        // régénère l'ID entre deux appels, la garde reste testée ici).
        $requete = Request::create('/admin/sessions/'.$courante = session()->getId(), 'DELETE');
        $requete->setUserResolver(fn () => $this->admin);
        $requete->setLaravelSession(app('session')->driver());
        DB::table('sessions')->insert([
            ['id' => $courante, 'user_id' => $this->admin->id, 'ip_address' => '1.1.1.1', 'user_agent' => 'T', 'payload' => '', 'last_activity' => time()],
        ]);

        try {
            app(SessionsController::class)->destroy($requete, $courante);
            $this->fail('La session courante aurait dû être protégée (422).');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertDatabaseHas('sessions', ['id' => $courante]);
    }

    public function test_ci_fichiers_presents(): void
    {
        $this->assertFileExists(base_path('.github/workflows/ci.yml'));
        $this->assertFileExists(base_path('pint.json'));
        $this->assertArrayHasKey('lint', json_decode((string) file_get_contents(base_path('composer.json')), true)['scripts']);
    }

    public function test_sauvegarde_sqlite_fichier_manifest_retention(): void
    {
        $dossier = sys_get_temp_dir().'/sauvegarde-test-'.uniqid();
        mkdir($dossier, 0750, true);
        $bd = $dossier.'/test.sqlite';
        touch($bd);

        config(['database.connections.sauvegarde_test' => [
            'driver' => 'sqlite', 'database' => $bd, 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->artisan('sauvegarde:creer', [
            '--connexion' => 'sauvegarde_test', '--dossier' => $dossier,
            '--sans-documents' => true, '--conserver' => 5,
        ])->assertSuccessful();

        $manifests = glob($dossier.'/manifest-*.json');
        $this->assertCount(1, $manifests);
        $donnees = json_decode((string) file_get_contents($manifests[0]), true);
        $this->assertArrayHasKey('sha256', $donnees['artefacts']['base']);

        $this->artisan('sauvegarde:creer', ['--dossier' => $dossier, '--verifier' => true])->assertSuccessful();

        // Corruption détectée.
        file_put_contents($dossier.'/'.$donnees['artefacts']['base']['fichier'], 'corrompu');
        $this->artisan('sauvegarde:creer', ['--dossier' => $dossier, '--verifier' => true])->assertFailed();

        // Mémoire : refus explicite, jamais de faux succès.
        $this->artisan('sauvegarde:creer', [
            '--connexion' => 'testing', '--dossier' => $dossier, '--sans-documents' => true,
        ])->assertFailed();
    }

    public function test_systeme_reserve_super_admin(): void
    {
        $this->actingAs($this->admin)->get(route('admin.systeme.index'))->assertOk();

        $agent = User::creerCompteBackOffice([
            'name' => 'Agent', 'email' => 'agent.ind@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->actingAs($agent)->get(route('admin.systeme.index'))->assertForbidden();
    }

    public function test_audit_routes_sans_critique(): void
    {
        $this->artisan('audit:routes-securite')->assertSuccessful();
    }

    public function test_headers_securite_presents(): void
    {
        $reponse = $this->actingAs($this->admin)->get(route('admin.dashboard'));
        $reponse->assertHeader('X-Content-Type-Options', 'nosniff');
        $reponse->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $reponse->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertNotEmpty($reponse->headers->get('Content-Security-Policy'));
        $this->assertNotEmpty($reponse->headers->get('X-Request-ID'));
    }
}
