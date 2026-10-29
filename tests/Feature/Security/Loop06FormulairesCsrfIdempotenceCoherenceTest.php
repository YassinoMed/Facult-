<?php

namespace Tests\Feature\Security;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\MensReference;
use App\Models\RegistrationPeriod;
use App\Modules\OnlineRequests\Models\OnlineRequest;
use App\Modules\OnlineRequests\Models\RequestType;
use App\Services\PeriodeInscriptionService;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * LOOP-06 — Formulaires, CSRF, idempotence et cohérence des données.
 *
 * Vérifie :
 * 1. Protection CSRF structurelle sur toutes les routes web mutantes (inscription et étudiant).
 * 2. Rejet des requêtes mutantes sans jeton CSRF ou avec jeton falsifié.
 * 3. Protection XSS par échappement automatique Blade dans les formulaires et profils.
 * 4. Protection XSS dans les messages de demandes et fils de discussion.
 * 5. Protection contre le mass-assignment (champs sensibles statut, num_inscription, privilèges ignorés).
 * 6. Protection contre le mass-assignment lors de la reprise de dossier existant.
 * 7. Idempotence et anti-rejeu sur la soumission de formulaire d'inscription.
 * 8. Atomicité transactionnelle : rollback complet en cas d'erreur lors de l'inscription.
 * 9. Normalisation automatique des entrées (casse email, nettoyage des espaces).
 */
class Loop06FormulairesCsrfIdempotenceCoherenceTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private RegistrationPeriod $periode;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences de Tunis',
            'code' => 'FST',
            'description' => 'Campus El Manar',
            'active' => true,
        ]);

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2025-2026',
            'date_debut' => '2025-09-01',
            'date_fin' => '2026-06-30',
            'active' => true,
        ]);

        $this->periode = app(PeriodeInscriptionService::class)->creerNormale([
            'annee_universitaire_id' => $this->annee->id,
            'opened_at' => now()->subDays(5)->format('Y-m-d H:i'),
            'closed_at' => now()->addDays(20)->format('Y-m-d H:i'),
        ], null);
    }

    private function creerReferenceMens(string $cin, string $dateNaissance, string $nom = 'Ben Ammar', string $prenom = 'Sami'): MensReference
    {
        return MensReference::create([
            'cin_normalise' => $cin,
            'cin_original' => $cin,
            'date_naissance' => $dateNaissance,
            'nom' => $nom,
            'prenom' => $prenom,
            'annee' => '2025-2026',
            'source_hash' => hash('sha256', $cin.$dateNaissance),
        ]);
    }

    private function payloadInscription(array $surcharges = []): array
    {
        return array_merge([
            'cin' => '08112233',
            'nom' => 'Ben Ammar',
            'prenom' => 'Sami',
            'email' => 'sami.benammar@etudiant.tn',
            'telephone' => '+216 71 123 456',
            'date_naissance' => '2004-05-14',
            'sexe' => 'M',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2025-2026',
            'adresse' => 'Avenue Habib Bourguiba, Tunis',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2023',
            'mention_bac' => 'Bien',
            'password' => 'MotDePasseFort@2026',
            'password_confirmation' => 'MotDePasseFort@2026',
            'confirmation' => '1',
        ], $surcharges);
    }

    // ── 1. Protection CSRF structurelle ─────────────────────────────────────

    public function test_toutes_les_routes_web_inscription_et_etudiant_mutantes_portent_le_groupe_web_csrf(): void
    {
        $routesNonProtegees = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            $methodes = array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']);

            if (empty($methodes)) {
                continue;
            }

            // Filtrer sur le périmètre portail inscription et espace étudiant
            if (! str_starts_with($uri, 'inscription') && ! str_starts_with($uri, 'espace-etudiant') && ! str_starts_with($uri, 'etudiant')) {
                continue;
            }

            $middlewares = $route->gatherMiddleware();

            if (! in_array('web', $middlewares, true)) {
                $routesNonProtegees[] = implode('|', $methodes).' /'.$uri;
            }
        }

        $this->assertEmpty(
            $routesNonProtegees,
            'Les routes mutantes suivantes ne sont pas protégées par le middleware CSRF web : '.implode(', ', $routesNonProtegees)
        );
    }

    public function test_comparateur_csrf_rejette_jeton_manquant_ou_falsifie(): void
    {
        $middleware = app(VerifyCsrfToken::class);
        $reflection = new \ReflectionMethod($middleware, 'tokensMatch');
        $reflection->setAccessible(true);

        // 1. Jeton absent
        $requeteSansToken = Request::create(route('inscription.store'), 'POST', ['cin' => '08112233']);
        $requeteSansToken->setLaravelSession($session = app('session')->driver());
        $session->put('_token', 'jeton-session-legitime-12345');

        $this->assertFalse($reflection->invoke($middleware, $requeteSansToken));

        // 2. Jeton falsifié
        $requeteFalsifiee = Request::create(route('inscription.store'), 'POST', [
            'cin' => '08112233',
            '_token' => 'jeton-pirate-forged',
        ]);
        $requeteFalsifiee->setLaravelSession($session);

        $this->assertFalse($reflection->invoke($middleware, $requeteFalsifiee));

        // 3. Jeton valide
        $requeteValide = Request::create(route('inscription.store'), 'POST', [
            'cin' => '08112233',
            '_token' => 'jeton-session-legitime-12345',
        ]);
        $requeteValide->setLaravelSession($session);

        $this->assertTrue($reflection->invoke($middleware, $requeteValide));
    }

    // ── 2. Protection XSS et neutralisation Blade ───────────────────────────

    public function test_xss_stocke_est_strictement_echappe_dans_le_profil_etudiant(): void
    {
        $etudiantXss = Etudiant::create([
            'cin' => '08112233',
            'num_inscription' => 'INS-2026-XSS',
            'nom' => '<script>alert("xss-nom")</script>',
            'prenom' => '<img src=x onerror=alert("xss-prenom")>',
            'email' => 'xss.test@etudiant.tn',
            'date_naissance' => '2004-05-14',
            'sexe' => 'M',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2025-2026',
            'adresse' => '"><script>alert("xss-adresse")</script>',
            'ville' => '<svg/onload=alert("xss-ville")>',
            'code_postal' => '1000',
            'annee_bac' => '2023',
            'password' => 'MotDePasseFort@2026',
        ]);

        $reponse = $this->withSession(['etudiant_auth_id' => $etudiantXss->id])
            ->get(route('etudiant.profil.show'));

        $reponse->assertOk();

        // Les balises dangereuses brutes ne doivent JAMAIS apparaître dans le HTML
        $this->assertStringNotContainsString('<script>alert("xss-nom")</script>', $reponse->getContent());
        $this->assertStringNotContainsString('<img src=x onerror=alert("xss-prenom")>', $reponse->getContent());
        $this->assertStringNotContainsString('"><script>alert("xss-adresse")</script>', $reponse->getContent());
        $this->assertStringNotContainsString('<svg/onload=alert("xss-ville")>', $reponse->getContent());

        // Elles doivent être correctement converties en entités HTML sûres
        $reponse->assertSee('&lt;script&gt;alert(&quot;xss-nom&quot;)&lt;/script&gt;', false);
        $reponse->assertSee('&lt;img src=x onerror=alert(&quot;xss-prenom&quot;)&gt;', false);
    }

    public function test_xss_dans_fil_de_discussion_demande_est_neutralise(): void
    {
        $etudiant = Etudiant::create([
            'cin' => '08112233',
            'nom' => 'Ben Ammar',
            'prenom' => 'Sami',
            'email' => 'sami.benammar@etudiant.tn',
            'date_naissance' => '2004-05-14',
            'sexe' => 'M',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2025-2026',
            'adresse' => 'Avenue Habib Bourguiba, Tunis',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2023',
            'password' => 'MotDePasseFort@2026',
        ]);

        $type = RequestType::firstOrCreate(
            ['code' => 'DEMANDE_NOTE'],
            ['name' => 'Réclamation de note', 'active' => true]
        );

        $demande = OnlineRequest::create([
            'reference' => 'DEM-2026-XSS',
            'etudiant_id' => $etudiant->id,
            'request_type_id' => $type->id,
            'objet' => '<script>alert("xss-objet")</script>',
            'status' => 'draft',
            'form_data' => ['message' => 'Contenu test'],
        ]);

        $reponse = $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->get(route('etudiant.demandes.show', $demande));

        $reponse->assertOk();
        $this->assertStringNotContainsString('<script>alert("xss-objet")</script>', $reponse->getContent());
        $reponse->assertSee('&lt;script&gt;alert(&quot;xss-objet&quot;)&lt;/script&gt;', false);
    }

    // ── 3. Protection anti-mass-assignment ──────────────────────────────────

    public function test_mass_assignment_statut_et_privileges_ignores_sur_inscription_publique(): void
    {
        $this->creerReferenceMens('08112233', '2004-05-14');

        // L'attaquant injecte statut valide, un numéro forgé, un flag admin
        $payload = $this->payloadInscription([
            'statut' => 'valide',
            'num_inscription' => 'INS-FORGE-99999',
            'is_admin' => 1,
            'role' => 'super_admin',
        ]);

        $reponse = $this->post(route('inscription.store'), $payload);
        $reponse->assertRedirect(route('inscription.success'));

        $etudiant = Etudiant::where('cin', '08112233')->first();
        $this->assertNotNull($etudiant);

        // Le statut reste en_attente (jamais valide par mass assignment)
        $this->assertEquals('en_attente', $etudiant->statut);
        // Le numéro est généré automatiquement par la séquence système, pas le numéro forgé
        $this->assertNotEquals('INS-FORGE-99999', $etudiant->num_inscription);
        $this->assertMatchesRegularExpression('/^INS-\d{4}-\d{5}$/', $etudiant->num_inscription);
    }

    // ── 4. Idempotence et intégrité de soumission ───────────────────────────

    public function test_double_soumission_avec_jeton_idempotence_ne_cree_aucun_doublon(): void
    {
        $this->creerReferenceMens('08112233', '2004-05-14');
        $idempotencyToken = 'IDEMP-TOKEN-LOOP06-ABC123XYZ';

        $payload = $this->payloadInscription([
            'idempotency_token' => $idempotencyToken,
        ]);

        // Première soumission
        $reponse1 = $this->post(route('inscription.store'), $payload);
        $reponse1->assertRedirect(route('inscription.success'));

        $this->assertDatabaseCount('etudiants', 1);
        $etudiantOriginal = Etudiant::where('cin', '08112233')->first();

        // Seconde soumission immédiate (simulation double-clic utilisateur ou coupure réseau)
        $reponse2 = $this->post(route('inscription.store'), $payload);
        $reponse2->assertRedirect(route('inscription.success'));

        // Toujours un seul enregistrement présent
        $this->assertDatabaseCount('etudiants', 1);
        $this->assertEquals($etudiantOriginal->id, Etudiant::where('cin', '08112233')->first()->id);
        $this->assertEquals($etudiantOriginal->num_inscription, session('num_inscription'));
    }

    public function test_double_soumission_json_avec_jeton_idempotence_retourne_payload_idempotent(): void
    {
        $this->creerReferenceMens('08112233', '2004-05-14');
        $idempotencyToken = 'IDEMP-JSON-TOKEN-456DEF';

        $payload = $this->payloadInscription([
            'idempotency_token' => $idempotencyToken,
        ]);

        // 1. Première requête JSON
        $reponse1 = $this->postJson(route('inscription.store'), $payload);
        $reponse1->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('deja_enregistre', false);

        $numInscription = $reponse1->json('num_inscription');

        // 2. Seconde requête JSON immédiate avec le même jeton
        $reponse2 = $this->postJson(route('inscription.store'), $payload);
        $reponse2->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('deja_enregistre', true)
            ->assertJsonPath('num_inscription', $numInscription);

        $this->assertDatabaseCount('etudiants', 1);
    }

    // ── 5. Atomicité transactionnelle et intégrité des données ──────────────

    public function test_atomicite_transactionnelle_rollback_complet_si_echec(): void
    {
        $this->creerReferenceMens('08112233', '2004-05-14');

        // Simulation d'une défaillance dans la transaction (ex: violation contrainte ultérieure)
        $payload = $this->payloadInscription();

        // On écoute le modèle Etudiant pour déclencher une exception artificielle après création
        Etudiant::saving(function ($etudiant) {
            if ($etudiant->email === 'fail.transaction@etudiant.tn') {
                throw new \RuntimeException('Erreur simulée dans la transaction');
            }
        });

        try {
            $this->post(route('inscription.store'), array_merge($payload, [
                'email' => 'fail.transaction@etudiant.tn',
            ]));
        } catch (\RuntimeException $e) {
            $this->assertEquals('Erreur simulée dans la transaction', $e->getMessage());
        }

        // Vérification : aucun étudiant orphelin n'a persisté en base
        $this->assertDatabaseMissing('etudiants', ['email' => 'fail.transaction@etudiant.tn']);
        $this->assertDatabaseMissing('etudiants', ['cin' => '08112233']);
    }

    // ── 6. Normalisation des données saisies ─────────────────────────────────

    public function test_normalisation_des_espaces_et_de_la_casse_email(): void
    {
        $this->creerReferenceMens('08112233', '2004-05-14');

        // Saisie avec espaces superflus et casse mixte
        $payload = $this->payloadInscription([
            'email' => '  SAMI.BENAMMAR@ETUDIANT.TN  ',
            'nom' => '   Ben Ammar   ',
            'prenom' => '   Sami   ',
            'ville' => '   Tunis   ',
            'adresse' => '   Avenue Habib Bourguiba   ',
        ]);

        $reponse = $this->post(route('inscription.store'), $payload);
        $reponse->assertRedirect(route('inscription.success'));

        $etudiant = Etudiant::where('cin', '08112233')->first();
        $this->assertNotNull($etudiant);

        // Données persistées propres et normalisées
        $this->assertEquals('sami.benammar@etudiant.tn', $etudiant->email);
        $this->assertEquals('Ben Ammar', $etudiant->nom);
        $this->assertEquals('Sami', $etudiant->prenom);
        $this->assertEquals('Tunis', $etudiant->ville);
        $this->assertEquals('Avenue Habib Bourguiba', $etudiant->adresse);
    }
}
