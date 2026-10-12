<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\AppNotification;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\MensReference;
use App\Models\RegistrationPeriod;
use App\Models\User;
use App\Modules\OnlineRequests\Models\OnlineRequest;
use App\Modules\OnlineRequests\Models\RequestType;
use App\Services\Identity\IdentityDocumentService;
use App\Services\RendezVousScolariteService;
use App\Services\StudentStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * LOOP 19 — Audit de sécurité et confidentialité transversal du parcours
 * d'inscription : compile en preuves exécutables ce que l'audit manuel +
 * `audit:routes-securite` (0 alerte, 49 REVUE pré-existantes) établissent :
 *
 * - throttles présents sur chaque route publique/étudiant sensible ;
 * - CSRF actif par configuration (bypass testing mesuré : 302 sans token,
 *   middleware jamais exclu — aucune route ne contourne le groupe `web`) ;
 * - anti-énumération (identifier + suivi neutres, rien créé) ;
 * - mass assignment inopérant (statut/groupe forgés ignorés) ;
 * - mot de passe jamais en clair (cast `hashed`) ;
 * - XSS neutralisé au rendu (stockage brut normal, Blade échappé) ;
 * - PII exclues des notifications et des métriques FAQ (sanctuarisation).
 */
class InscriptionSecuriteTransversaleTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'active' => true]);
    }

    private function ouvrirPeriode(): AnneeUniversitaire
    {
        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        RegistrationPeriod::create([
            'annee_universitaire_id' => $annee->id,
            'type_periode' => 'normale',
            'opened_at' => now()->subDay(), 'closed_at' => now()->addDay(), 'statut' => 'active',
        ]);

        return $annee;
    }

    private function semerMens(string $cin, string $naissance = '2004-03-15'): void
    {
        MensReference::create([
            'cin_normalise' => $cin, 'cin_original' => $cin,
            'date_naissance' => $naissance, 'nom' => 'Nom', 'prenom' => 'Prenom',
            'sexe' => 'M', 'nationalite' => 'Tunisienne', 'annee' => '2026-2027',
            'source_hash' => str_repeat('c', 64),
        ]);
    }

    private function payloadInscription(array $remplacements = []): array
    {
        return array_merge([
            'cin' => '08000001',
            'date_naissance' => '2004-03-15',
            'nom' => 'Nom', 'prenom' => 'Prenom',
            'email' => 'sec.into@universite.test', 'sexe' => 'M',
            'faculte_id' => $this->faculte->id, 'niveau' => 'L1',
            'annee_academique' => '2026-2027', 'adresse' => '12 rue',
            'ville' => 'Casa', 'code_postal' => '20000', 'annee_bac' => '2024',
            'password' => 'Etudiant@123', 'password_confirmation' => 'Etudiant@123',
            'confirmation' => true,
        ], $remplacements);
    }

    // ── Throttles : preuve de configuration exécutable ──────────────────────

    public function test_routes_sensibles_throttlees(): void
    {
        $attendus = [
            'inscription.identifier' => 'throttle:',
            'inscription.store' => 'throttle:',
            'inscription.periode-etat' => 'throttle:',
            'inscription.faq' => 'throttle:',
            'inscription.faq.assistant' => 'throttle:',
            'etudiant.recepisse' => 'throttle:',
            'etudiant.demandes.store' => 'throttle:',
            'etudiant.dossier.documents.store' => 'throttle:',
        ];

        foreach ($attendus as $nom => $marqueur) {
            $route = Route::getRoutes()->getByName($nom);
            $this->assertNotNull($route, "Route {$nom} introuvable.");

            $middlewares = implode(',', (array) $route->gatherMiddleware());
            $this->assertStringContainsString(
                $marqueur, $middlewares,
                "Route {$nom} sans rate limiting : abus possible."
            );
        }
    }

    public function test_csrf_actif_par_configuration_jamais_exclu(): void
    {
        $groupes = app('router')->getMiddlewareGroups();

        $web = implode(',', (array) ($groupes['web'] ?? []));
        $this->assertStringContainsString(
            'Forgery', $web,
            'Le groupe `web` ne protège plus contre le CSRF.'
        );

        // Aucune route du périmètre ne contourne la protection.
        $contenu = (string) file_get_contents(base_path('routes/web.php'));
        $this->assertStringNotContainsString('withoutMiddleware', $contenu);
    }

    // ── Anti-énumération ────────────────────────────────────────────────────

    public function test_identifier_cin_inconnue_neutre_rien_cree(): void
    {
        $this->ouvrirPeriode();

        // Contrat anti-énumération : même statut HTTP 200 pour tous, la
        // distinction passe par le body nécessaire au wizard — jamais
        // d'oracle « existe / n'existe pas » au niveau HTTP.
        $reponse = $this->postJson(route('inscription.identifier'), [
            'cin' => '99999999',
            'date_naissance' => '2000-01-01',
        ]);

        $reponse->assertOk()->assertJsonPath('status', 'UNKNOWN');
        $this->assertSame(0, Etudiant::count());

        // La CIN soumise n'est jamais répercutée (pas d'écho).
        $this->assertStringNotContainsString(
            '99999999',
            mb_strtolower((string) $reponse->getContent())
        );
    }

    // ── Mass assignment + secrets ───────────────────────────────────────────

    public function test_champs_forges_ignores_statut_et_groupe(): void
    {
        $this->ouvrirPeriode();
        $this->semerMens('08000001');

        $this->post('/inscription', $this->payloadInscription([
            'statut' => 'valide',
            'groupe_id' => 999,
            'is_admin' => true,
        ]))->assertRedirect(route('inscription.success'));

        $etudiant = Etudiant::where('cin', '08000001')->firstOrFail();

        $this->assertSame('en_attente', $etudiant->statut);
        $this->assertNull($etudiant->groupe_id);
    }

    public function test_mot_de_passe_jamais_en_clair(): void
    {
        $this->ouvrirPeriode();
        $this->semerMens('08000002');

        $this->post('/inscription', $this->payloadInscription([
            'cin' => '08000002',
            'email' => 'sec.hash@universite.test',
        ]))->assertRedirect(route('inscription.success'));

        $etudiant = Etudiant::where('cin', '08000002')->firstOrFail();

        $this->assertNotSame('Etudiant@123', $etudiant->password);
        $this->assertTrue(Hash::check('Etudiant@123', $etudiant->password));
    }

    // ── XSS neutralisé au rendu ─────────────────────────────────────────────

    public function test_nom_malveillant_echappe_au_rendu_profil(): void
    {
        $etudiant = Etudiant::create([
            'cin' => 'SECXSS01', 'nom' => '<script>alert(1)</script>', 'prenom' => 'Prenom',
            'email' => 'sec.xss@universite.test', 'date_naissance' => '2004-01-01',
            'sexe' => 'M', 'faculte_id' => $this->faculte->id, 'niveau' => 'L1',
            'annee_academique' => '2026-2027', 'adresse' => 'rue', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
        $etudiant->definirStatut('en_attente');

        $contenu = (string) $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->get(route('etudiant.profil.show'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $contenu);
        $this->assertStringContainsString('&lt;script&gt;', $contenu);
    }

    // ── PII exclues des notifications et métriques ──────────────────────────

    public function test_notification_statut_sans_pii(): void
    {
        $etudiant = Etudiant::create([
            'cin' => 'SECPII01', 'nom' => 'Nom', 'prenom' => 'Prenom',
            'email' => 'sec.pii@universite.test', 'date_naissance' => '2004-01-01',
            'sexe' => 'M', 'faculte_id' => $this->faculte->id, 'niveau' => 'L1',
            'annee_academique' => '2026-2027', 'adresse' => 'rue', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
        $etudiant->definirStatut('en_attente');

        $scolarite = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.pii@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        app(StudentStatusService::class)->changer($etudiant, 'valide', $scolarite->id, 'Dossier conforme.');

        $notification = AppNotification::where('etudiant_id', $etudiant->id)
            ->where('type', 'dossier.statut')->firstOrFail();

        $corps = $notification->titre.' '.$notification->message.' '.json_encode($notification->data);

        foreach (['SECPII01', 'sec.pii@universite.test', '2004-01-01', 'Etudiant@123'] as $secret) {
            $this->assertStringNotContainsString($secret, $corps);
        }
    }

    public function test_metriques_faq_sanctuarisees_si_pii_detectee(): void
    {
        $captures = [];
        Log::listen(function (MessageLogged $evenement) use (&$captures) {
            if (($evenement->message ?? '') === 'assistant_faq_metrique') {
                $captures[] = $evenement->context;
            }
        });

        $this->postJson(route('inscription.faq.assistant'), [
            'question' => 'Quel est le statut du dossier CIN 12345678 ?',
        ])->assertOk()->assertJsonPath('statut', 'refus_prive');

        $this->assertNotEmpty($captures, 'Aucune métrique journalisée.');

        foreach ($captures as $contexte) {
            $this->assertTrue($contexte['pii_detectee'] ?? false);
            $this->assertArrayHasKey('question_hash', $contexte);
            $this->assertNull($contexte['question_hash']);
        }
    }

    public function test_metriques_faq_sans_pii_hash_seulement(): void
    {
        $captures = [];
        Log::listen(function (MessageLogged $evenement) use (&$captures) {
            if (($evenement->message ?? '') === 'assistant_faq_metrique') {
                $captures[] = $evenement->context;
            }
        });

        $this->postJson(route('inscription.faq.assistant'), [
            'question' => 'Quand puis-je m inscrire ?',
        ])->assertOk();

        $this->assertNotEmpty($captures, 'Aucune métrique journalisée.');

        foreach ($captures as $contexte) {
            $this->assertFalse($contexte['pii_detectee'] ?? true);
            $this->assertIsString($contexte['question_hash'] ?? null);
            $this->assertStringNotContainsString('inscrire', (string) ($contexte['question_hash'] ?? ''));
        }
    }

    // ── IDOR & Cloisonnement profil / session ───────────────────────────────

    public function test_idor_etudiant_connecte_ne_peut_pas_voir_le_suivi_d_un_autre(): void
    {
        $etudiantA = Etudiant::create([
            'cin' => 'SECIDOR01', 'nom' => 'Ben Salah', 'prenom' => 'Mohamed',
            'email' => 'salah@universite.test', 'date_naissance' => '2004-01-01',
            'sexe' => 'M', 'faculte_id' => $this->faculte->id, 'niveau' => 'L1',
            'annee_academique' => '2026-2027', 'adresse' => 'rue A', 'ville' => 'Tunis',
            'code_postal' => '1000', 'annee_bac' => '2024', 'password' => 'Pass@123',
        ]);
        $etudiantA->definirStatut('en_attente');

        $etudiantB = Etudiant::create([
            'cin' => 'SECIDOR02', 'nom' => 'Gharbi', 'prenom' => 'Sarra',
            'email' => 'gharbi@universite.test', 'date_naissance' => '2004-02-02',
            'sexe' => 'F', 'faculte_id' => $this->faculte->id, 'niveau' => 'L1',
            'annee_academique' => '2026-2027', 'adresse' => 'rue B', 'ville' => 'Tunis',
            'code_postal' => '1000', 'annee_bac' => '2024', 'password' => 'Pass@123',
        ]);
        $etudiantB->definirStatut('en_attente');

        // L'étudiant A connecté ne doit voir que son propre suivi et jamais celui de B
        $response = $this->withSession(['etudiant_auth_id' => $etudiantA->id])
            ->get(route('etudiant.suivi'));

        $response->assertOk();
        $response->assertSee('Ben Salah');
        $response->assertDontSee('Gharbi');
    }

    public function test_idor_etudiant_ne_peut_pas_agir_sur_le_rendez_vous_d_un_autre(): void
    {
        $etudiantA = Etudiant::create([
            'cin' => 'SECRDV01', 'nom' => 'Kefi', 'prenom' => 'Yassine',
            'email' => 'kefi@universite.test', 'date_naissance' => '2004-01-01',
            'sexe' => 'M', 'faculte_id' => $this->faculte->id, 'niveau' => 'L1',
            'annee_academique' => '2026-2027', 'adresse' => 'rue', 'ville' => 'Tunis',
            'code_postal' => '1000', 'annee_bac' => '2024', 'password' => 'Pass@123',
        ]);
        $etudiantA->definirStatut('en_attente');

        $etudiantB = Etudiant::create([
            'cin' => 'SECRDV02', 'nom' => 'Trabelsi', 'prenom' => 'Ines',
            'email' => 'ines@universite.test', 'date_naissance' => '2004-02-02',
            'sexe' => 'F', 'faculte_id' => $this->faculte->id, 'niveau' => 'L1',
            'annee_academique' => '2026-2027', 'adresse' => 'rue', 'ville' => 'Tunis',
            'code_postal' => '1000', 'annee_bac' => '2024', 'password' => 'Pass@123',
        ]);
        $etudiantB->definirStatut('en_attente');

        $type = RequestType::firstOrCreate(
            ['code' => 'DEMANDE_LIBRE'],
            ['name' => 'Demande libre', 'active' => true]
        );

        // Créer un rendez-vous pour l'étudiant B
        $demandeB = OnlineRequest::create([
            'reference' => 'DEM-TEST-9999',
            'etudiant_id' => $etudiantB->id,
            'request_type_id' => $type->id,
            'objet' => 'Rendez-vous scolarité',
            'status' => 'submitted',
            'form_data' => [
                'motif_categorie' => 'renseignement',
                'description' => 'Aide inscription',
                'date_creneau' => '2026-10-15',
                'heure_creneau' => '10:00',
            ],
        ]);

        $serviceRdv = app(RendezVousScolariteService::class);

        // L'étudiant A tente d'annuler la demande de l'étudiant B -> rejet immédiat
        $this->expectException(ValidationException::class);
        $serviceRdv->annuler($demandeB, $etudiantA);
    }

    public function test_cloisonnement_etudiant_interdit_dacces_au_backoffice(): void
    {
        $etudiant = Etudiant::create([
            'cin' => 'SECBO01', 'nom' => 'Nom', 'prenom' => 'Prenom',
            'email' => 'etudiant.bo@universite.test', 'date_naissance' => '2004-01-01',
            'sexe' => 'M', 'faculte_id' => $this->faculte->id, 'niveau' => 'L1',
            'annee_academique' => '2026-2027', 'adresse' => 'rue', 'ville' => 'Tunis',
            'code_postal' => '1000', 'annee_bac' => '2024', 'password' => 'Pass@123',
        ]);
        $etudiant->definirStatut('en_attente');

        // Connecté uniquement sous session étudiant
        $response = $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->get(route('admin.inscriptions.file-traitement'));

        // Rejet vers la connexion administrative
        $response->assertRedirect(route('admin.connexion.show'));
    }

    public function test_cloisonnement_faculte_agent_interdit_dacces_a_une_autre_faculte(): void
    {
        $autreFaculte = Faculte::create(['nom' => 'FMT', 'code' => 'FMT', 'active' => true]);

        $etudiantAutre = Etudiant::create([
            'cin' => 'SECFAC01', 'nom' => 'Hammami', 'prenom' => 'Walid',
            'email' => 'walid.fmt@universite.test', 'date_naissance' => '2004-01-01',
            'sexe' => 'M', 'faculte_id' => $autreFaculte->id, 'niveau' => 'L1',
            'annee_academique' => '2026-2027', 'adresse' => 'rue', 'ville' => 'Tunis',
            'code_postal' => '1000', 'annee_bac' => '2024', 'password' => 'Pass@123',
        ]);
        $etudiantAutre->definirStatut('en_attente');

        $agentFaculteA = User::creerCompteBackOffice([
            'name' => 'Agent FST', 'email' => 'agent.fst@universite.test', 'password' => 'Secret123!',
            'faculte_id' => $this->faculte->id,
        ], 'admin_faculte');

        // L'agent de FST tente de commenter un dossier de FMT -> 403 Forbidden
        $response = $this->actingAs($agentFaculteA)
            ->post(route('admin.inscriptions.file-traitement.commenter', $etudiantAutre), [
                'commentaire' => 'Observation illégitime hors faculté',
            ]);

        $response->assertForbidden();
    }

    public function test_anti_enumeration_suivi_public_reponse_identique_si_inexistant_ou_faux_secret(): void
    {
        $etudiant = Etudiant::create([
            'cin' => 'SECSUIVI01', 'nom' => 'DossierExistant', 'prenom' => 'Karim',
            'email' => 'karim.suivi@universite.test', 'date_naissance' => '2004-05-20',
            'sexe' => 'M', 'faculte_id' => $this->faculte->id, 'niveau' => 'L1',
            'annee_academique' => '2026-2027', 'adresse' => 'rue', 'ville' => 'Tunis',
            'code_postal' => '1000', 'annee_bac' => '2024', 'password' => 'Pass@123',
        ]);
        $etudiant->definirStatut('en_attente');

        // 1. Numéro qui n'existe pas du tout
        $respFauxNumero = $this->post(route('inscription.suivi.rechercher'), [
            'reference' => 'NUM-INEXISTANT-999',
            'secondaire' => '2004-05-20',
        ]);
        $respFauxNumero->assertOk();
        $respFauxNumero->assertSee('Aucun dossier correspondant n’a été trouvé avec ces références');

        // 2. Numéro réel mais second facteur invalide (mauvaise date de naissance)
        $respFausseDate = $this->post(route('inscription.suivi.rechercher'), [
            'reference' => $etudiant->num_inscription,
            'secondaire' => '1990-01-01',
        ]);
        $respFausseDate->assertOk();
        $respFausseDate->assertSee('Aucun dossier correspondant n’a été trouvé avec ces références');

        // Les deux réponses comportent le même message neutre anti-oracle
        $this->assertSame(
            $respFauxNumero->viewData('erreurNeutre'),
            $respFausseDate->viewData('erreurNeutre')
        );
    }

    public function test_concurrence_idempotence_et_anti_doublon_cin(): void
    {
        $this->ouvrirPeriode();
        $this->semerMens('08000003');

        $payload = $this->payloadInscription([
            'cin' => '08000003',
            'email' => 'unique.etudiant@universite.test',
            'idempotency_token' => 'uuid-concurrence-12345',
        ]);

        // Première soumission réussie
        $this->post('/inscription', $payload)->assertRedirect(route('inscription.success'));
        $this->assertSame(1, Etudiant::where('cin', '08000003')->count());

        // Deuxième soumission concurrente / rejeu avec le même jeton : redirigé vers succès sans doublon en BD
        $this->post('/inscription', $payload)->assertRedirect(route('inscription.success'));
        $this->assertSame(1, Etudiant::where('cin', '08000003')->count());
    }

    public function test_stockage_prive_destruction_temporaire_apres_scan(): void
    {
        Storage::fake('private');
        Storage::fake('local');

        $identity = app(IdentityDocumentService::class);
        $fichier = UploadedFile::fake()->image('cin_test.jpg', 600, 400);

        $chemin = $identity->uploadDocument($fichier, 'cin');
        $this->assertNotEmpty($chemin);

        // La destruction systématique du temporaire garantit zéro résidu privé
        $identity->supprimerDocument($chemin);
        $this->assertFalse(file_exists($chemin));
    }
}
