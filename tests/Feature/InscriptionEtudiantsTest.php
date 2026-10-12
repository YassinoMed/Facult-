<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\MensReference;
use App\Models\User;
use App\Services\PeriodeInscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class InscriptionEtudiantsTest extends TestCase
{
    use RefreshDatabase;

    protected Faculte $faculteActive;

    protected Faculte $faculteInactive;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculteActive = Faculte::create([
            'nom' => 'Faculté des Sciences et Techniques',
            'code' => 'FST',
            'description' => 'Sciences et technologies',
            'active' => true,
        ]);

        $this->faculteInactive = Faculte::create([
            'nom' => 'Faculté Inactive Test',
            'code' => 'FIT',
            'description' => 'Test',
            'active' => false,
        ]);

        // Le back-office est désormais protégé : chaque test s'authentifie
        // en tant que super_admin (les assertions restent inchangées).
        $this->actingAs(User::creerCompteBackOffice([
            'name' => 'Super Admin Test',
            'email' => 'superadmin.test@universite.test',
            'password' => Hash::make('Motdepasse@123'),
        ], 'super_admin'));

        // Page publique en régime strict : période ouverte globale pour
        // les années utilisées par les payloads (2025-2026).
        $annee = AnneeUniversitaire::create([
            'libelle' => '2025-2026', 'date_debut' => '2025-09-01', 'date_fin' => '2026-06-30', 'active' => true,
        ]);
        app(PeriodeInscriptionService::class)->creerNormale([
            'annee_universitaire_id' => $annee->id,
            'opened_at' => '2020-01-01 00:00', 'closed_at' => '2030-12-31 23:59',
        ], null);
    }

    public function test_public_registration_page_is_accessible_and_shows_active_faculties(): void
    {
        $response = $this->get(route('inscription.create'));

        $response->assertStatus(200);
        $response->assertSee('Faculté des Sciences et Techniques');
        $response->assertDontSee('Faculté Inactive Test');
    }

    public function test_student_can_register_and_num_inscription_is_generated_automatically(): void
    {
        MensReference::create([
            'cin_normalise' => '12345678', 'cin_original' => '12345678',
            'date_naissance' => '2004-03-15', 'nom' => 'Alami', 'prenom' => 'Mehdi',
            'annee' => '2025-2026', 'source_hash' => hash('sha256', 'BK1234562004-03-15'),
        ]);

        $data = [
            'cin' => '12345678',
            'nom' => 'Alami',
            'prenom' => 'Mehdi',
            'email' => 'mehdi.test@example.com',
            'telephone' => '+212 6 12 34 56 78',
            'date_naissance' => '2004-03-15',
            'sexe' => 'M',
            'faculte_id' => $this->faculteActive->id,
            'niveau' => 'L1',
            'annee_academique' => '2025-2026',
            'adresse' => '12 Rue de la Liberté',
            'ville' => 'Casablanca',
            'code_postal' => '20000',
            'annee_bac' => '2024',
            'mention_bac' => 'Très Bien',
            'password' => 'etudiant123',
            'password_confirmation' => 'etudiant123',
        ];

        $response = $this->post(route('inscription.store'), $data);

        $response->assertRedirect(route('inscription.success'));

        $annee = date('Y');
        $this->assertDatabaseHas('etudiants', [
            'cin' => '12345678',
            'email' => 'mehdi.test@example.com',
            'ville' => 'Casablanca',
            'annee_bac' => '2024',
            'mention_bac' => 'Très Bien',
            'statut' => 'en_attente',
        ]);

        $etudiant = Etudiant::where('cin', '12345678')->first();
        $this->assertNotNull($etudiant);
        $this->assertStringStartsWith("INS-{$annee}-", $etudiant->num_inscription);

        // Le mot de passe doit être haché bcrypt et jamais stocké en clair
        $this->assertTrue(Hash::check('etudiant123', $etudiant->password));
        $this->assertNotEquals('etudiant123', $etudiant->password);
        $this->assertStringStartsWith('$2y$', $etudiant->password);
    }

    public function test_student_registration_validation_fails_with_missing_data(): void
    {
        $response = $this->post(route('inscription.store'), []);

        $response->assertSessionHasErrors([
            'cin', 'nom', 'prenom', 'email', 'sexe', 'faculte_id', 'niveau', 'annee_academique', 'adresse', 'ville', 'code_postal', 'annee_bac', 'password',
        ]);
    }

    public function test_student_registration_fails_with_weak_or_mismatched_password(): void
    {
        $base = [
            'cin' => '12345670',
            'nom' => 'Faible',
            'prenom' => 'MotDePasse',
            'email' => 'faible.mdp@example.com',
            'sexe' => 'M',
            'faculte_id' => $this->faculteActive->id,
            'niveau' => 'L1',
            'annee_academique' => '2025-2026',
            'adresse' => 'Rue Test',
            'ville' => 'Rabat',
            'code_postal' => '10000',
            'annee_bac' => '2024',
        ];

        // Trop court
        $response1 = $this->post(route('inscription.store'), $base + [
            'password' => 'ab1', 'password_confirmation' => 'ab1',
        ]);
        $response1->assertSessionHasErrors(['password']);

        // Sans chiffre
        $response2 = $this->post(route('inscription.store'), $base + [
            'password' => 'sanschiffre', 'password_confirmation' => 'sanschiffre',
        ]);
        $response2->assertSessionHasErrors(['password']);

        // Confirmation différente
        $response3 = $this->post(route('inscription.store'), $base + [
            'password' => 'motdepasse1', 'password_confirmation' => 'autremotdepasse1',
        ]);
        $response3->assertSessionHasErrors(['password']);

        // Moins de 10 caractères (audit §P3-21 : 9 lettres+chiffres refusés)
        $response4 = $this->post(route('inscription.store'), $base + [
            'password' => 'Pass12345', 'password_confirmation' => 'Pass12345',
        ]);
        $response4->assertSessionHasErrors(['password']);

        // Aucun dossier ne doit exister
        $this->assertDatabaseMissing('etudiants', ['cin' => '12345670']);
    }

    public function test_student_registration_fails_with_duplicate_cin_or_email(): void
    {
        $etudiantCree = Etudiant::create(['cin' => '23456781',
            'nom' => 'Test',
            'prenom' => 'Premier',
            'email' => 'premier@example.com',
            'sexe' => 'M',
            'faculte_id' => $this->faculteActive->id,
            'niveau' => 'L1',
            'annee_academique' => '2025-2026',
            'adresse' => 'Rue Test',
            'ville' => 'Rabat',
            'code_postal' => '10000',
            'annee_bac' => '2024', ]);
        $etudiantCree->forceFill(['num_inscription' => 'INS-2026-00001'])->save();
        $etudiantCree->definirStatut('en_attente');

        // Same CIN
        $response1 = $this->post(route('inscription.store'), [
            'cin' => '23456781', // Testing case insensitive normalization
            'nom' => 'Autre',
            'prenom' => 'Nom',
            'email' => 'autre@example.com',
            'sexe' => 'M',
            'faculte_id' => $this->faculteActive->id,
            'niveau' => 'L1',
            'annee_academique' => '2025-2026',
            'adresse' => 'Rue Test',
            'ville' => 'Rabat',
            'code_postal' => '10000',
            'annee_bac' => '2024',
            'password' => 'etudiant123',
            'password_confirmation' => 'etudiant123',
        ]);
        $response1->assertSessionHasErrors(['cin']);

        // Same Email
        $response2 = $this->post(route('inscription.store'), [
            'cin' => '23456789',
            'nom' => 'Autre',
            'prenom' => 'Nom',
            'email' => 'premier@example.com',
            'sexe' => 'M',
            'faculte_id' => $this->faculteActive->id,
            'niveau' => 'L1',
            'annee_academique' => '2025-2026',
            'adresse' => 'Rue Test',
            'ville' => 'Rabat',
            'code_postal' => '10000',
            'annee_bac' => '2024',
            'password' => 'etudiant123',
            'password_confirmation' => 'etudiant123',
        ]);
        $response2->assertSessionHasErrors(['email']);
    }

    public function test_student_cannot_register_into_inactive_faculty(): void
    {
        $response = $this->post(route('inscription.store'), [
            'cin' => '23456700',
            'nom' => 'Test',
            'prenom' => 'User',
            'email' => 'inactive.faculty@example.com',
            'sexe' => 'M',
            'faculte_id' => $this->faculteInactive->id,
            'niveau' => 'L1',
            'annee_academique' => '2025-2026',
            'adresse' => 'Test Adresse',
            'ville' => 'Test',
            'code_postal' => '10000',
            'annee_bac' => '2024',
            'password' => 'etudiant123',
            'password_confirmation' => 'etudiant123',
        ]);

        $response->assertSessionHasErrors(['faculte_id']);
    }

    public function test_security_headers_are_present(): void
    {
        $response = $this->get(route('inscription.create'));

        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');
        $response->assertHeader('Cross-Origin-Resource-Policy', 'same-origin');
        // Audit §P3-23 : X-XSS-Protection obsolète — ne doit plus être émis.
        $response->assertHeaderMissing('X-XSS-Protection');
    }

    public function test_admin_etudiants_list_and_search_by_cin_and_num_inscription(): void
    {
        $etudiant = Etudiant::create(['cin' => '34567890',
            'nom' => 'Chercheur',
            'prenom' => 'Amine',
            'email' => 'chercheur@example.com',
            'sexe' => 'M',
            'faculte_id' => $this->faculteActive->id,
            'niveau' => 'L2',
            'annee_academique' => '2025-2026',
            'adresse' => 'Avenue Royale',
            'ville' => 'Marrakech',
            'code_postal' => '40000',
            'annee_bac' => '2023',
            'mention_bac' => 'Bien', ]);
        $etudiant->forceFill(['num_inscription' => 'INS-2026-00099'])->save();
        $etudiant->definirStatut('en_attente');

        $response = $this->get(route('admin.etudiants.index'));
        $response->assertStatus(200);
        $response->assertSee('34567890');
        $response->assertSee('INS-2026-00099');

        // Search by CIN
        $searchCin = $this->get(route('admin.etudiants.index', ['search' => '34567890']));
        $searchCin->assertStatus(200);
        $searchCin->assertSee('chercheur@example.com');

        // Search by Num Inscription
        $searchNum = $this->get(route('admin.etudiants.index', ['search' => 'INS-2026-00099']));
        $searchNum->assertStatus(200);
        $searchNum->assertSee('chercheur@example.com');

        // Search non-existing
        $searchEmpty = $this->get(route('admin.etudiants.index', ['search' => 'NONEXISTENT999']));
        $searchEmpty->assertStatus(200);
        $searchEmpty->assertDontSee('chercheur@example.com');
    }

    public function test_admin_can_view_student_details_including_bac_and_cin(): void
    {
        $etudiant = Etudiant::create(['cin' => '34567891',
            'nom' => 'Berrada',
            'prenom' => 'Salma',
            'email' => 'salma.berrada@example.com',
            'sexe' => 'F',
            'faculte_id' => $this->faculteActive->id,
            'niveau' => 'L3',
            'annee_academique' => '2025-2026',
            'adresse' => 'Quartier Palmier',
            'ville' => 'Casablanca',
            'code_postal' => '20100',
            'annee_bac' => '2022',
            'mention_bac' => 'Très Bien', ]);
        $etudiant->forceFill(['num_inscription' => 'INS-2026-00077'])->save();
        $etudiant->definirStatut('en_attente');

        $response = $this->get(route('admin.etudiants.show', $etudiant));
        $response->assertStatus(200);
        $response->assertSee('Salma Berrada');
        $response->assertSee('34567891');
        $response->assertSee('INS-2026-00077');
        $response->assertSee('Session 2022');
        $response->assertSee('Très Bien');
    }

    public function test_admin_can_update_student_status(): void
    {
        $etudiant = Etudiant::create(['cin' => '34567892',
            'nom' => 'StatutTest',
            'prenom' => 'Karim',
            'email' => 'karim.status@example.com',
            'sexe' => 'M',
            'faculte_id' => $this->faculteActive->id,
            'niveau' => 'L1',
            'annee_academique' => '2025-2026',
            'adresse' => 'Rue Test',
            'ville' => 'Tanger',
            'code_postal' => '90000',
            'annee_bac' => '2024', ]);
        $etudiant->forceFill(['num_inscription' => 'INS-2026-00055'])->save();
        $etudiant->definirStatut('en_attente');

        $response = $this->patch(route('admin.etudiants.updateStatut', $etudiant), [
            'statut' => 'valide',
        ]);

        $response->assertSessionHas('success');
        $this->assertEquals('valide', $etudiant->fresh()->statut);
    }

    public function test_admin_can_delete_student(): void
    {
        $etudiant = Etudiant::create(['cin' => '34567893',
            'nom' => 'DeleteTest',
            'prenom' => 'Nour',
            'email' => 'nour.delete@example.com',
            'sexe' => 'F',
            'faculte_id' => $this->faculteActive->id,
            'niveau' => 'L1',
            'annee_academique' => '2025-2026',
            'adresse' => 'Rue Test',
            'ville' => 'Agadir',
            'code_postal' => '80000',
            'annee_bac' => '2024', ]);
        $etudiant->forceFill(['num_inscription' => 'INS-2026-00033'])->save();
        $etudiant->definirStatut('en_attente');

        $response = $this->delete(route('admin.etudiants.destroy', $etudiant));

        $response->assertRedirect(route('admin.etudiants.index'));
        $this->assertDatabaseMissing('etudiants', ['id' => $etudiant->id]);
    }

    public function test_admin_faculte_crud_and_deletion_protection(): void
    {
        // Create faculte
        $storeRes = $this->post(route('admin.facultes.store'), [
            'nom' => 'Nouvelle Faculté',
            'code' => 'nf',
            'description' => 'Test Fac',
            'active' => '1',
        ]);
        $storeRes->assertRedirect(route('admin.facultes.index'));
        $this->assertDatabaseHas('facultes', ['code' => 'NF']);

        $faculte = Faculte::where('code', 'NF')->first();

        // Update faculte
        $updateRes = $this->put(route('admin.facultes.update', $faculte), [
            'nom' => 'Faculté Renommée',
            'code' => 'NF',
            'description' => 'Mise à jour',
            'active' => '0',
        ]);
        $updateRes->assertRedirect(route('admin.facultes.index'));
        $this->assertDatabaseHas('facultes', ['nom' => 'Faculté Renommée', 'active' => 0]);

        // Add a student to this faculty and verify deletion is prevented
        $etudiantCree = Etudiant::create(['cin' => '51000001',
            'nom' => 'Etudiant',
            'prenom' => 'Faculte',
            'email' => 'facstudent@example.com',
            'sexe' => 'M',
            'faculte_id' => $faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2025-2026',
            'adresse' => 'Rue Test',
            'ville' => 'Fes',
            'code_postal' => '30000',
            'annee_bac' => '2024', ]);
        $etudiantCree->forceFill(['num_inscription' => 'INS-2026-00018'])->save();
        $etudiantCree->definirStatut('en_attente');

        $deleteProtected = $this->delete(route('admin.facultes.destroy', $faculte));
        $deleteProtected->assertRedirect(route('admin.facultes.index'));
        $deleteProtected->assertSessionHas('error');
        $this->assertDatabaseHas('facultes', ['id' => $faculte->id]);
    }
}
