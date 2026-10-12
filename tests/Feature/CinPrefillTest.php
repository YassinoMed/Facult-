<?php

namespace Tests\Feature;

use App\Http\Requests\VerifierCinRequest;
use App\Models\AnneeUniversitaire;
use App\Models\DossierAcademique;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\MensReference;
use App\Services\PeriodeInscriptionService;
use App\Services\StudentLookupService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Fonctionnalité « Pré-remplissage automatique par CIN » (§1-§13).
 *
 * Couvre : CIN inexistant/existant, second facteur obligatoire (date de
 * naissance exacte — §P0-4), non-exposition du mot de passe et de son hash,
 * CIN invalides, anti-doublon métier, rate limiting, CSRF, absence de SQL
 * injection et de mass assignment.
 */
class CinPrefillTest extends TestCase
{
    use RefreshDatabase;

    protected Faculte $faculte;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences',
            'code' => 'FS',
            'description' => 'Test',
            'active' => true,
        ]);

        // Page publique en régime strict : période ouverte globale.
        $annee = AnneeUniversitaire::create([
            'libelle' => '2025-2026', 'date_debut' => '2025-09-01', 'date_fin' => '2026-06-30', 'active' => true,
        ]);
        app(PeriodeInscriptionService::class)->creerNormale([
            'annee_universitaire_id' => $annee->id,
            'opened_at' => '2020-01-01 00:00', 'closed_at' => '2030-12-31 23:59',
        ], null);
    }

    /** Crée un étudiant complet (mot de passe haché via le cast du modèle). */
    private function creerEtudiant(array $attributs = []): Etudiant
    {
        // `statut` n'est plus mass-assignable (audit §P3-17).
        $statut = $attributs['statut'] ?? 'valide';
        unset($attributs['statut']);

        $etudiant = Etudiant::create(array_merge([
            'cin' => '12345678',
            'nom' => 'BEN ALI',
            'prenom' => 'Mohamed',
            'email' => 'mohamed.benali@example.com',
            'telephone' => '+212612345678',
            'date_naissance' => '2003-05-17',
            'sexe' => 'M',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L2',
            'annee_academique' => '2025-2026',
            'adresse' => '45 Avenue Hassan II',
            'ville' => 'Rabat',
            'code_postal' => '10000',
            'annee_bac' => '2022',
            'mention_bac' => 'Bien',
            'password' => 'MotdepasseSecret123',
        ], $attributs));
        $etudiant->definirStatut($statut);

        return $etudiant;
    }

    /* ---------------------------------------------------------------
     | CIN inexistant → exists = false, formulaire vierge
     * -------------------------------------------------------------- */
    public function test_cin_inexistant_retourne_exists_false(): void
    {
        $reponse = $this->postJson(route('inscription.verifierCin'), [
            'cin' => '99999999',
            'date_naissance' => '2000-01-01',
        ]);

        $reponse->assertOk()
            ->assertExactJson(['exists' => false]);
    }

    public function test_cin_avec_espaces_est_refusee_jamais_normalisee(): void
    {
        $this->creerEtudiant();

        // LOOP saisies §3 : les espaces sont REFUSÉS, jamais corrigés
        // silencieusement — « 1234567 8 » ne devient jamais « 12345678 ».
        $reponse = $this->postJson(route('inscription.verifierCin'), [
            'cin' => ' 12345678 ',
            'date_naissance' => '2003-05-17',
        ]);

        $reponse->assertStatus(422)->assertJsonValidationErrors(['cin']);
    }

    /* ---------------------------------------------------------------
     | CIN existant → exists = true + informations correctes
     * -------------------------------------------------------------- */
    public function test_cin_existant_retourne_les_informations_de_prefill(): void
    {
        $etudiant = $this->creerEtudiant();

        // Matricule via le dossier académique (module Archives, §11)
        DossierAcademique::create([
            'etudiant_id' => $etudiant->id,
            'matricule' => 'MAT-2023-0456',
            'date_archivage' => '2023-09-01',
            'statut' => 'actif',
        ]);

        $reponse = $this->postJson(route('inscription.verifierCin'), [
            'cin' => '12345678',
            'date_naissance' => '2003-05-17',
        ]);

        $reponse->assertOk()
            ->assertJsonPath('exists', true)
            ->assertJsonPath('etudiant.cin', '12345678')
            ->assertJsonPath('etudiant.nom', 'BEN ALI')
            ->assertJsonPath('etudiant.prenom', 'Mohamed')
            ->assertJsonPath('etudiant.email', 'mohamed.benali@example.com')
            ->assertJsonPath('etudiant.telephone', '+212612345678')
            ->assertJsonPath('etudiant.date_naissance', '2003-05-17')
            ->assertJsonPath('etudiant.sexe', 'M')
            ->assertJsonPath('etudiant.adresse', '45 Avenue Hassan II')
            ->assertJsonPath('etudiant.ville', 'Rabat')
            ->assertJsonPath('etudiant.code_postal', '10000')
            ->assertJsonPath('etudiant.faculte_id', (string) $this->faculte->id)
            ->assertJsonPath('etudiant.niveau', 'L2')
            ->assertJsonPath('etudiant.matricule', 'MAT-2023-0456');
    }

    public function test_cin_zero_initial_preserve_comme_chaine(): void
    {
        // LOOP saisies §3 : la CIN reste une chaîne — les zéros initiaux
        // sont préservés (jamais convertis en entier).
        $etudiant = $this->creerEtudiant(['cin' => '01234567']);

        $reponse = $this->postJson(route('inscription.verifierCin'), [
            'cin' => '01234567',
            'date_naissance' => '2003-05-17',
        ]);

        $reponse->assertOk()
            ->assertJsonPath('exists', true)
            ->assertJsonPath('etudiant.cin', '01234567');
    }

    public function test_cin_existant_sans_dossier_academique_retourne_matricule_null(): void
    {
        $this->creerEtudiant();

        $reponse = $this->postJson(route('inscription.verifierCin'), [
            'cin' => '12345678',
            'date_naissance' => '2003-05-17',
        ]);

        $reponse->assertOk()
            ->assertJsonPath('exists', true)
            ->assertJsonPath('etudiant.matricule', null);
    }

    /* ---------------------------------------------------------------
     | Second facteur (§P0-4) : la date de naissance est OBLIGATOIRE
     | et doit correspondre exactement — le CIN seul ne suffit jamais.
     * -------------------------------------------------------------- */
    public function test_date_naissance_manquante_est_refusee(): void
    {
        $this->creerEtudiant();

        $this->postJson(route('inscription.verifierCin'), ['cin' => '12345678'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['date_naissance']);
    }

    public function test_date_naissance_future_est_refusee(): void
    {
        $this->postJson(route('inscription.verifierCin'), [
            'cin' => '12345678',
            'date_naissance' => now()->addYear()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors(['date_naissance']);
    }

    public function test_cin_valide_mais_date_naissance_incorrecte_retourne_exists_false(): void
    {
        $this->creerEtudiant();

        // Date fausse : réponse STRICTEMENT identique à un CIN inconnu
        // — impossible de distinguer « CIN inexistant » de « date erronée ».
        $reponse = $this->postJson(route('inscription.verifierCin'), [
            'cin' => '12345678',
            'date_naissance' => '1990-01-01',
        ]);

        $reponse->assertOk()->assertExactJson(['exists' => false]);
    }

    public function test_etudiant_sans_date_naissance_connue_n_est_jamais_retourne(): void
    {
        // Fail-closed : sans date de naissance en base, le pré-remplissage
        // est impossible (aucune vérification possible → aucun retour).
        $etudiant = $this->creerEtudiant();
        $etudiant->update(['date_naissance' => null]);

        $this->postJson(route('inscription.verifierCin'), [
            'cin' => '12345678',
            'date_naissance' => '2003-05-17',
        ])->assertOk()->assertExactJson(['exists' => false]);
    }

    /* ---------------------------------------------------------------
     | Mot de passe : JAMAIS exposé (ni valeur, ni hash, ni clé)
     * -------------------------------------------------------------- */
    public function test_la_reponse_ne_contient_jamais_le_mot_de_passe_ni_son_hash(): void
    {
        $etudiant = $this->creerEtudiant();
        $hash = $etudiant->password;
        $this->assertStringStartsWith('$2y$', $hash);

        $reponse = $this->postJson(route('inscription.verifierCin'), [
            'cin' => '12345678',
            'date_naissance' => '2003-05-17',
        ]);

        $etudiantDto = $reponse->json('etudiant');
        $this->assertIsArray($etudiantDto);

        // Aucune clé sensible dans le DTO
        foreach (['password', 'password_confirmation', 'password_hash', 'remember_token', 'statut', 'id', 'num_inscription'] as $cleInterdite) {
            $this->assertArrayNotHasKey($cleInterdite, $etudiantDto, "La clé « {$cleInterdite} » ne doit jamais être exposée.");
        }

        // Whitelist exacte des champs retournés
        $this->assertEqualsCanonicalizing(
            ['cin', 'nom', 'prenom', 'email', 'telephone', 'date_naissance', 'sexe', 'adresse', 'ville', 'code_postal', 'faculte_id', 'niveau', 'matricule'],
            array_keys($etudiantDto)
        );

        // Le hash bcrypt lui-même n'apparaît nulle part dans le corps
        $corps = $reponse->getContent();
        $this->assertStringNotContainsString($hash, $corps);
        $this->assertStringNotContainsString('$2y$', $corps);
        $this->assertStringNotContainsString('MotdepasseSecret123', $corps);
    }

    public function test_le_service_ne_retourne_jamais_le_hash_meme_en_acces_direct(): void
    {
        $etudiant = $this->creerEtudiant();

        $dto = app(StudentLookupService::class)->donneesPrefill($etudiant);

        $this->assertArrayNotHasKey('password', $dto);
        $this->assertArrayNotHasKey('remember_token', $dto);
        $this->assertNotContains($etudiant->password, $dto);
    }

    /* ---------------------------------------------------------------
     | CIN invalides → 422 (validation stricte, §5/§10)
     * -------------------------------------------------------------- */
    public function test_cin_vide_est_refuse(): void
    {
        $this->postJson(route('inscription.verifierCin'), ['cin' => '', 'date_naissance' => '2003-05-17'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cin']);
    }

    public function test_cin_trop_court_est_refuse(): void
    {
        $this->postJson(route('inscription.verifierCin'), ['cin' => 'AB12', 'date_naissance' => '2003-05-17'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cin']);
    }

    public function test_cin_trop_long_est_refuse(): void
    {
        $this->postJson(route('inscription.verifierCin'), ['cin' => str_repeat('A', 31), 'date_naissance' => '2003-05-17'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cin']);
    }

    public function test_cin_avec_caracteres_invalides_est_refuse(): void
    {
        foreach (['BK 123456', 'BK@12345', 'BK_12345', "BK'12345", 'BK.12345'] as $cinInvalide) {
            $this->postJson(route('inscription.verifierCin'), ['cin' => $cinInvalide, 'date_naissance' => '2003-05-17'])
                ->assertStatus(422, "Le CIN « {$cinInvalide} » aurait dû être refusé.");
        }
    }

    public function test_cin_longueurs_limites_exactes(): void
    {
        // LOOP saisies §3 : exactement 8 chiffres — 7 et 9 REFUSÉS.
        $this->postJson(route('inscription.verifierCin'), ['cin' => '1234567', 'date_naissance' => '2000-01-01'])
            ->assertStatus(422)->assertJsonValidationErrors(['cin']);

        $this->postJson(route('inscription.verifierCin'), ['cin' => '123456789', 'date_naissance' => '2000-01-01'])
            ->assertStatus(422)->assertJsonValidationErrors(['cin']);

        // 8 chiffres : format valide (CIN inconnue → exists = false).
        $this->postJson(route('inscription.verifierCin'), ['cin' => '87654321', 'date_naissance' => '2000-01-01'])
            ->assertOk()->assertExactJson(['exists' => false]);

        // Zéro initial : 8 chiffres valides.
        $this->postJson(route('inscription.verifierCin'), ['cin' => '01234567', 'date_naissance' => '2000-01-01'])
            ->assertOk()->assertExactJson(['exists' => false]);
    }

    /* ---------------------------------------------------------------
     | SQL injection : la regex rejette toute charge utile SQL (§5)
     * -------------------------------------------------------------- */
    public function test_injection_sql_est_rejetee_par_la_validation(): void
    {
        $this->creerEtudiant();

        foreach (["' OR '1'='1", "BK123456'--", '1; DROP TABLE etudiants', 'BK123456 UNION SELECT password--'] as $charge) {
            $this->postJson(route('inscription.verifierCin'), ['cin' => $charge, 'date_naissance' => '2003-05-17'])
                ->assertStatus(422);
        }

        // La base est intacte et la réponse ne fuite aucune donnée
        $this->assertDatabaseHas('etudiants', ['cin' => '12345678']);
    }

    public function test_la_recherche_est_parametree_et_ne_contourne_pas_l_unicite(): void
    {
        $this->creerEtudiant();

        // Même avec un CIN ressemblant, aucun contournement possible
        $this->postJson(route('inscription.verifierCin'), ['cin' => '12345679', 'date_naissance' => '2003-05-17'])
            ->assertOk()->assertExactJson(['exists' => false]);
    }

    /* ---------------------------------------------------------------
     | §6 : la recherche ne crée ni ne modifie JAMAIS de compte
     * -------------------------------------------------------------- */
    public function test_la_verification_cin_ne_cree_ni_ne_modifie_aucun_dossier(): void
    {
        $etudiant = $this->creerEtudiant();
        $modifieAvant = $etudiant->updated_at;

        $this->postJson(route('inscription.verifierCin'), ['cin' => '12345678', 'date_naissance' => '2003-05-17'])->assertOk();
        $this->postJson(route('inscription.verifierCin'), ['cin' => '87654321', 'date_naissance' => '2003-05-17'])->assertOk();

        $this->assertSame(1, Etudiant::count());
        $this->assertEquals($modifieAvant, $etudiant->fresh()->updated_at);
    }

    /* ---------------------------------------------------------------
     | Doublon : un second étudiant avec le même CIN est refusé (§6)
     * -------------------------------------------------------------- */
    public function test_inscription_avec_cin_existant_est_refusee_sans_doublon(): void
    {
        $this->creerEtudiant();

        $reponse = $this->post(route('inscription.store'), [
            'cin' => 'bk123456', // normalisation insensible à la casse
            'nom' => 'Autre',
            'prenom' => 'Personne',
            'email' => 'autre.personne@example.com',
            'sexe' => 'F',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2025-2026',
            'adresse' => 'Rue du Test',
            'ville' => 'Rabat',
            'code_postal' => '10000',
            'annee_bac' => '2024',
            'password' => 'motdepasse1',
            'password_confirmation' => 'motdepasse1',
        ]);

        // Message métier explicite, aucun doublon créé, données d'origine intactes
        $reponse->assertSessionHasErrors(['cin']);
        $this->assertSame(1, Etudiant::count());
        $this->assertSame('BEN ALI', Etudiant::where('cin', '12345678')->sole()->nom);
    }

    /* ---------------------------------------------------------------
     | Mass assignment : les champs non autorisés sont ignorés (§5/§7)
     * -------------------------------------------------------------- */
    public function test_le_endpoint_de_recherche_ignore_tout_champ_supplementaire(): void
    {
        $this->creerEtudiant();

        $reponse = $this->postJson(route('inscription.verifierCin'), [
            'cin' => '12345678',
            'date_naissance' => '2003-05-17',
            'password' => 'pirate123',
            'statut' => 'en_attente',
            'role' => 'super_admin',
        ]);

        $reponse->assertOk()->assertJsonPath('exists', true);

        $corps = $reponse->getContent();
        $this->assertStringNotContainsString('pirate123', $corps);
        // Rien n'a été modifié en base
        $this->assertSame('valide', Etudiant::where('cin', '12345678')->sole()->statut);
        $this->assertTrue(Hash::check('MotdepasseSecret123', Etudiant::where('cin', '12345678')->sole()->password));
    }

    public function test_inscription_ignore_les_champs_non_fillable(): void
    {
        MensReference::create([
            'cin_normalise' => '08112233', 'cin_original' => '08112233',
            'date_naissance' => '2003-05-17', 'nom' => 'Mass', 'prenom' => 'Assignement',
            'annee' => '2025-2026', 'source_hash' => hash('sha256', '081122332003-05-17'),
        ]);

        $reponse = $this->post(route('inscription.store'), [
            'cin' => '08112233',
            'nom' => 'Mass',
            'prenom' => 'Assignement',
            'email' => 'mass.assign@example.com',
            'date_naissance' => '2003-05-17',
            'sexe' => 'M',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2025-2026',
            'adresse' => 'Rue Test',
            'ville' => 'Fes',
            'code_postal' => '30000',
            'annee_bac' => '2024',
            'password' => 'motdepasse1',
            'password_confirmation' => 'motdepasse1',
            // Tentatives d'injection hors $fillable / hors validation
            'statut' => 'valide',
            'id' => 9999,
        ]);

        $reponse->assertRedirect(route('inscription.success'));

        $etudiant = Etudiant::where('cin', '08112233')->sole();
        // La règle de validation n'autorise pas "statut" → valeur par défaut conservée
        $this->assertSame('en_attente', $etudiant->statut);
        $this->assertNotSame(9999, $etudiant->id);
    }

    /* ---------------------------------------------------------------
     | Rate limiting : throttle:10,1 — protection anti-énumération (§5)
     * -------------------------------------------------------------- */
    public function test_rate_limiting_bloque_apres_dix_requetes(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->postJson(route('inscription.verifierCin'), ['cin' => sprintf('%08d', 10000000 + $i), 'date_naissance' => '2000-01-01'])
                ->assertOk("La requête {$i}/10 aurait dû passer.");
        }

        $this->postJson(route('inscription.verifierCin'), ['cin' => '10000011', 'date_naissance' => '2000-01-01'])
            ->assertStatus(429);
    }

    /* ---------------------------------------------------------------
     | Sécurité du transport : POST + CSRF, GET refusé (§4/§5)
     * -------------------------------------------------------------- */
    public function test_la_methode_get_est_refusee_sur_l_endpoint(): void
    {
        $this->getJson('/inscription/verifier-cin')->assertStatus(405);
    }

    public function test_l_endpoint_est_dans_le_groupe_web_avec_verification_csrf(): void
    {
        $route = Route::getRoutes()->match(
            Request::create('/inscription/verifier-cin', 'POST')
        );

        // La route appartient au groupe « web »...
        $this->assertContains('web', $route->gatherMiddleware());

        // ...qui applique PreventRequestForgery (ex-VerifyCsrfToken) : tout
        // POST sans jeton valide est rejeté (419) hors environnement de test.
        $groupes = Route::getMiddlewareGroups();
        $this->assertContains(PreventRequestForgery::class, $groupes['web']);
    }

    public function test_la_validation_du_cin_s_appuie_sur_une_form_request_dediee(): void
    {
        $route = Route::getRoutes()->match(
            Request::create('/inscription/verifier-cin', 'POST')
        );

        $this->assertSame('inscription.verifierCin', $route->getName());

        // La Form Request impose sa règle regex stricte (8 chiffres ASCII)
        $regles = (new VerifierCinRequest)->rules();
        $this->assertContains('required', $regles['cin']);
        $this->assertContains('regex:/\A[0-9]{8}\z/', $regles['cin']);

        // Second facteur obligatoire (§P0-4) : la date de naissance
        $this->assertContains('required', $regles['date_naissance']);
        $this->assertContains('before:today', $regles['date_naissance']);
    }

    /* ---------------------------------------------------------------
     | Page d'inscription : le branchement JS de pré-remplissage est présent
     * -------------------------------------------------------------- */
    public function test_la_page_inscription_embarque_la_recherche_dynamique(): void
    {
        $reponse = $this->get(route('inscription.create'));

        $reponse->assertOk();
        $reponse->assertSee('rechercherCin()', false);
        $reponse->assertSee('cinRecherche', false);
        $reponse->assertSee('csrf-token', false);
        // Aucun champ mot de passe pré-rempli côté serveur
        $reponse->assertSee('name="password"', false);
    }
}
