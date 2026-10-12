<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\DocumentAcademique;
use App\Models\DossierAcademique;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\Note;
use App\Models\Semestre;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Séparation des profils Étudiant / Administrateur de faculté /
 * Super Administrateur (§1-20) :
 * - l'étudiant n'accède qu'à son espace et son dossier ;
 * - l'admin_faculte est strictement borné à SA faculté (anti-IDOR/BOLA,
 *   même en manipulant les IDs d'URL ou les filtres) ;
 * - le super admin garde la vision globale et gère les administrateurs ;
 * - le rôle/statut vient TOUJOURS de la base, jamais du client.
 */
class SeparationProfilsTest extends TestCase
{
    use RefreshDatabase;

    protected Faculte $faculteA;

    protected Faculte $faculteB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculteA = Faculte::create([
            'nom' => 'Faculté A — Informatique',
            'code' => 'FA',
            'description' => 'Test',
            'active' => true,
        ]);

        $this->faculteB = Faculte::create([
            'nom' => 'Faculté B — Médecine',
            'code' => 'FB',
            'description' => 'Test',
            'active' => true,
        ]);
    }

    /* ── Helpers ─────────────────────────────────────────────── */

    private function creerEtudiant(Faculte $faculte, string $cin): Etudiant
    {
        $etudiantCree = Etudiant::create(['cin' => $cin,
            'nom' => 'Nom'.substr($cin, -2),
            'prenom' => 'Prenom'.substr($cin, -2),
            'email' => strtolower($cin).'@example.com',
            'sexe' => 'M',
            'faculte_id' => $faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2025-2026',
            'adresse' => 'Rue Test',
            'ville' => 'Rabat',
            'code_postal' => '10000',
            'annee_bac' => '2024',
            'password' => 'motdepasse1', ]);
        $etudiantCree->definirStatut('en_attente');

        return $etudiantCree;
    }

    private function creerAdminFaculte(Faculte $faculte, array $attributs = []): User
    {
        $user = User::creerCompteBackOffice(array_merge([
            'name' => 'Admin '.substr($faculte->code, -1),
            'email' => 'admin.'.strtolower($faculte->code).'@universite.test',
            'password' => Hash::make('Motdepasse@123'),
            'faculte_id' => $faculte->id,
        ], $attributs), $attributs['role'] ?? 'admin_faculte');

        $this->actingAs($user);

        return $user;
    }

    private function creerSuperAdmin(): User
    {
        $user = User::creerCompteBackOffice([
            'name' => 'Super Admin Test',
            'email' => 'superadmin.test@universite.test',
            'password' => Hash::make('Motdepasse@123'),
        ], 'super_admin');

        $this->actingAs($user);

        return $user;
    }

    private function creerNote(Etudiant $etudiant): Note
    {
        // Un étudiant avec notes/dossier académique (archives) :
        // unique par étudiant (contrainte BD).
        DossierAcademique::firstOrCreate(
            ['etudiant_id' => $etudiant->id],
            [
                'matricule' => 'MAT-'.str_pad((string) $etudiant->id, 5, '0', STR_PAD_LEFT),
                'date_archivage' => '2025-09-01',
                'statut' => 'actif',
            ],
        );

        $annee = AnneeUniversitaire::firstOrCreate(
            ['libelle' => '2025-2026'],
            [
                'date_debut' => '2025-09-01',
                'date_fin' => '2026-07-15',
                'active' => true,
            ],
        );

        $inscription = InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $annee->id,
            'niveau' => 'L1',
            'filiere' => 'Test',
            'groupe' => 'G1',
            'statut' => 'valide',
        ]);

        $semestre = Semestre::create([
            'inscription_academique_id' => $inscription->id,
            'numero' => 1,
        ]);

        $matiere = Matiere::create([
            'code' => 'MAT-'.random_int(10000, 99999),
            'nom' => 'Matière Test',
            'coefficient' => 2,
            'credits' => 4,
            'niveau' => 'L1',
            'semestre' => 1,
        ]);

        return Note::create([
            'etudiant_id' => $etudiant->id,
            'inscription_academique_id' => $inscription->id,
            'semestre_id' => $semestre->id,
            'matiere_id' => $matiere->id,
            'note' => 12.5,
            'coefficient' => 2,
            'credits' => 4,
            'session' => 'normale',
        ]);
    }

    /* ── Profil Étudiant (§3/§20) ─────────────────────────────── */

    public function test_etudiant_accede_a_son_espace_mais_pas_au_back_office(): void
    {
        $etudiant = $this->creerEtudiant($this->faculteA, 'BK111111');

        session([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_auth_prenom' => $etudiant->prenom,
            'etudiant_auth_nom' => $etudiant->nom,
        ]);

        // Son espace : OK
        $this->get(route('etudiant.profil.show'))->assertOk();
        $this->get(route('etudiant.inscriptions.index'))->assertOk();
        $this->get(route('etudiant.notes.index'))->assertOk();
        $this->get(route('etudiant.documents.index'))->assertOk();
        $this->get(route('etudiant.historique.index'))->assertOk();

        // Le back-office exige le guard `users` : l'étudiant (session
        // `etudiant_auth_id`) est un invité pour /admin → redirection.
        $this->get(route('admin.etudiants.index'))
            ->assertRedirect(route('admin.connexion.show'));
    }

    public function test_etudiant_ne_peut_pas_consulter_les_documents_d_un_autre_etudiant(): void
    {
        $etudiantA = $this->creerEtudiant($this->faculteA, 'BK222221');
        $etudiantB = $this->creerEtudiant($this->faculteA, 'BK222222');

        $document = DocumentAcademique::create([
            'etudiant_id' => $etudiantB->id,
            'type_document' => 'releve_notes',
            'nom_original' => 'releve.pdf',
            'chemin' => 'documents/releve.pdf',
            'mime_type' => 'application/pdf',
            'taille' => 1024,
            'hash_fichier' => hash('sha256', 'releve.pdf'),
        ]);

        session(['etudiant_auth_id' => $etudiantA->id]);

        // Anti-IDOR : le document de B est introuvable pour A (404)
        $this->get(route('etudiant.archives.documents.download', $document))->assertNotFound();
    }

    public function test_etudiant_ne_peut_pas_creer_ni_modifier_une_note(): void
    {
        $etudiant = $this->creerEtudiant($this->faculteA, 'BK333331');
        $note = $this->creerNote($etudiant);

        session(['etudiant_auth_id' => $etudiant->id]);

        // Les routes d'écriture du back-office sont hors de portée du
        // profil étudiant : redirection vers la connexion scolarité.
        $this->post(route('admin.archives.notes.store', $note->inscriptionAcademique), [])
            ->assertRedirect(route('admin.connexion.show'));

        $this->patch(route('admin.archives.notes.update', $note), ['note_normale' => 18])
            ->assertRedirect(route('admin.connexion.show'));
    }

    /* ── Profil Admin Faculté : périmètre strict (§6/§20) ─────── */

    public function test_admin_faculte_accede_au_back_office_et_son_dashboard(): void
    {
        $this->creerAdminFaculte($this->faculteA);

        $this->get(route('admin.etudiants.index'))->assertOk();
        $this->get(route('admin.ma-faculte'))->assertOk();
    }

    public function test_admin_faculte_ne_voit_que_les_etudiants_de_sa_faculte(): void
    {
        $admin = $this->creerAdminFaculte($this->faculteA);

        $etudiantA = $this->creerEtudiant($this->faculteA, 'BK444441');
        $etudiantB = $this->creerEtudiant($this->faculteB, 'BK444442');

        $reponse = $this->get(route('admin.etudiants.index'));

        $reponse->assertOk()
            ->assertSee($etudiantA->num_inscription, false)
            ->assertDontSee($etudiantB->num_inscription, false);

        // Tentative de contournement via filtre client : ignorée
        $contournement = $this->get(route('admin.etudiants.index', ['faculte_id' => $this->faculteB->id]));
        $contournement->assertOk()->assertDontSee($etudiantB->num_inscription, false);

        // Même le registre des archives est scopé
        DossierAcademique::create([
            'etudiant_id' => $etudiantB->id,
            'matricule' => 'MAT-B-001',
            'date_archivage' => '2025-09-01',
            'statut' => 'actif',
        ]);

        $archives = $this->get(route('admin.archives.index'));
        $archives->assertOk()->assertDontSee('MAT-B-001');

        unset($admin);
    }

    public function test_admin_faculte_subit_l_idor_sur_les_fiches_et_actions(): void
    {
        $this->creerAdminFaculte($this->faculteA);

        $etudiantB = $this->creerEtudiant($this->faculteB, 'BK555551');

        // ID manipulé dans l'URL : fiche, statut et suppression refusés
        $this->get(route('admin.etudiants.show', $etudiantB))->assertForbidden();
        $this->patch(route('admin.etudiants.updateStatut', $etudiantB), ['statut' => 'valide'])->assertForbidden();
        $this->delete(route('admin.etudiants.destroy', $etudiantB))->assertForbidden();

        // Le dossier académique de la faculté B est aussi protégé
        $dossierB = DossierAcademique::create([
            'etudiant_id' => $etudiantB->id,
            'matricule' => 'MAT-B-002',
            'date_archivage' => '2025-09-01',
            'statut' => 'actif',
        ]);
        $this->get(route('admin.archives.show', $dossierB))->assertForbidden();
        $this->get(route('admin.archives.export', $dossierB))->assertForbidden();
    }

    public function test_admin_faculte_ne_peut_pas_modifier_les_notes_d_une_autre_faculte(): void
    {
        $this->creerAdminFaculte($this->faculteA);

        $etudiantB = $this->creerEtudiant($this->faculteB, 'BK666661');
        $noteB = $this->creerNote($etudiantB);

        $this->patch(route('admin.archives.notes.update', $noteB), [
            'note' => 18,
            'raison' => 'Tentative inter-facultés',
        ])->assertForbidden();

        $this->delete(route('admin.archives.notes.destroy', $noteB))->assertForbidden();

        // ... mais agit librement sur les notes de SA faculté
        $etudiantA = $this->creerEtudiant($this->faculteA, 'BK666662');
        $noteA = $this->creerNote($etudiantA);

        $this->patch(route('admin.archives.notes.update', $noteA), [
            'note' => 15,
            'raison' => 'Correction de test',
        ])->assertRedirect();

        $this->assertDatabaseHas('notes', ['id' => $noteA->id, 'note' => 15]);
    }

    public function test_admin_faculte_ne_peut_exporter_que_sa_faculte(): void
    {
        $adminFaculte = $this->creerAdminFaculte($this->faculteA);

        $etudiantA = $this->creerEtudiant($this->faculteA, 'BK777771');
        $this->creerEtudiant($this->faculteB, 'BK777772');

        // Token API de l'admin_faculte : authentification ≠ autorisation,
        // le périmètre faculté s'applique aussi à l'API (§17).
        $token = $adminFaculte->createToken('test-admin-faculte', ['students:read', 'exports:create'])->plainTextToken;

        // La liste ne contient QUE sa faculté
        $liste = $this->get('/api/v1/students', ['Authorization' => 'Bearer '.$token]);
        $liste->assertOk();
        $this->assertSame(1, $liste->json('meta.total'));
        $this->assertSame($etudiantA->id, $liste->json('data.0.id'));

        // BOLA : la fiche d'un étudiant d'une autre faculté → 404
        $etudiantB = Etudiant::where('cin', 'BK777772')->first();
        $this->get('/api/v1/students/'.$etudiantB->id, ['Authorization' => 'Bearer '.$token])
            ->assertNotFound();

        // Export global demandé (toutes facultés) → forcé à SA faculté
        $export = $this->post('/api/v1/exports', [
            'type' => 'students',
            'format' => 'csv',
            'filters' => ['faculte' => 'FB'], // Faculté B : doit être écrasé par FA
        ], ['Authorization' => 'Bearer '.$token]);

        $export->assertStatus(201);
        $this->assertSame(1, $export->json('report.total'), "L'export doit contenir uniquement l'étudiant de la faculté A.");
    }

    public function test_admin_faculte_n_a_pas_les_fonctions_administratives_globales(): void
    {
        $this->creerAdminFaculte($this->faculteA);

        // Gestion des administrateurs : Super Admin uniquement
        $this->get(route('admin.administrateurs.index'))->assertForbidden();
        $this->get(route('admin.administrateurs.create'))->assertForbidden();
        $this->post(route('admin.administrateurs.store'), [])->assertForbidden();

        // Imports/exports multicritères web : permission configuration.manage absente
        $this->post(route('admin.exports.generate'), [])->assertForbidden();
        $this->post(route('admin.integrations.import'), [])->assertForbidden();
    }

    /* ── Super Admin : vision globale (§15/§20) ────────────────── */

    public function test_super_admin_accede_a_toutes_les_facultes(): void
    {
        $this->creerSuperAdmin();

        $etudiantA = $this->creerEtudiant($this->faculteA, 'BK888881');
        $etudiantB = $this->creerEtudiant($this->faculteB, 'BK888882');

        $this->get(route('admin.etudiants.index'))
            ->assertOk()
            ->assertSee($etudiantA->num_inscription, false)
            ->assertSee($etudiantB->num_inscription, false);

        $this->get(route('admin.etudiants.show', $etudiantB))->assertOk();
        $this->patch(route('admin.etudiants.updateStatut', $etudiantB), ['statut' => 'valide'])->assertRedirect();
        $this->assertSame('valide', $etudiantB->fresh()->statut);
    }

    public function test_super_admin_gere_les_administrateurs_et_leur_rattachement(): void
    {
        $this->creerSuperAdmin();

        $reponse = $this->post(route('admin.administrateurs.store'), [
            'name' => 'Admin Faculté B',
            'email' => 'admin.fb@universite.test',
            'password' => 'Motdepasse@123',
            'password_confirmation' => 'Motdepasse@123',
            'role' => 'admin_faculte',
            'faculte_id' => $this->faculteB->id,
        ]);

        $reponse->assertRedirect(route('admin.administrateurs.index'));

        // La liste rend correctement la fiche du nouveau compte
        $this->get(route('admin.administrateurs.index'))
            ->assertOk()
            ->assertSee('Admin Faculté B', false);

        $cree = User::where('email', 'admin.fb@universite.test')->first();
        $this->assertNotNull($cree);
        $this->assertSame('admin_faculte', $cree->role);
        $this->assertSame($this->faculteB->id, $cree->faculte_id);

        // Le nouveau compte est immédiatement fonctionnel et scopé
        $etudiantB = $this->creerEtudiant($this->faculteB, 'BK999991');
        $this->creerEtudiant($this->faculteA, 'BK999992');
        $this->actingAs($cree);
        $this->get(route('admin.etudiants.index'))
            ->assertOk()
            ->assertSee($etudiantB->num_inscription, false);
    }

    public function test_super_admin_ne_peut_pas_se_desactiver_lui_meme(): void
    {
        $superAdmin = $this->creerSuperAdmin();

        $this->patch(route('admin.administrateurs.statut', $superAdmin))->assertForbidden();
        $this->assertTrue($superAdmin->fresh()->actif);
    }

    /* ── Rôle et statut : TOUJOURS de la base, jamais du client (§8/§10) ── */

    public function test_admin_desactive_est_refuse_au_back_office(): void
    {
        $admin = $this->creerAdminFaculte($this->faculteA, ['actif' => false]);

        // La route de connexion est protégée par `guest` : on repasse
        // en invité avant de tester le refus de connexion.
        $this->app['auth']->forgetGuards();

        // Connexion refusée malgré des identifiants valides
        $this->post(route('admin.connexion.login'), [
            'email' => 'admin.fa@universite.test',
            'password' => 'Motdepasse@123',
        ])->assertSessionHasErrors('email');

        // Et même en contournant le login, le middleware bloque
        $this->actingAs($admin);
        $this->get(route('admin.etudiants.index'))->assertForbidden();
    }

    public function test_admin_faculte_sans_rattachement_est_refuse(): void
    {
        $user = User::creerCompteBackOffice([
            'name' => 'Admin Orphelin',
            'email' => 'admin.orphelin@universite.test',
            'password' => Hash::make('Motdepasse@123'),
            'faculte_id' => null,
        ], 'admin_faculte');

        // La route de connexion est protégée par `guest` : repasser en
        // invité avant de tester le refus de connexion.
        $this->app['auth']->forgetGuards();

        // Login refusé...
        $this->post(route('admin.connexion.login'), [
            'email' => 'admin.orphelin@universite.test',
            'password' => 'Motdepasse@123',
        ])->assertSessionHasErrors('email');

        // ... et middleware aussi
        $this->actingAs($user);
        $this->get(route('admin.etudiants.index'))->assertForbidden();
    }

    public function test_escalade_de_privileges_par_mass_assignment_est_impossible(): void
    {
        $this->creerSuperAdmin();

        // Rôle hors matrice RBAC → refusé
        $this->post(route('admin.administrateurs.store'), [
            'name' => 'Pirate',
            'email' => 'pirate@universite.test',
            'password' => 'Motdepasse@123',
            'password_confirmation' => 'Motdepasse@123',
            'role' => 'role_invente_par_le_client',
        ])->assertSessionHasErrors('role');

        // admin_faculte sans faculté → refusé
        $this->post(route('admin.administrateurs.store'), [
            'name' => 'Pirate 2',
            'email' => 'pirate2@universite.test',
            'password' => 'Motdepasse@123',
            'password_confirmation' => 'Motdepasse@123',
            'role' => 'admin_faculte',
        ])->assertSessionHasErrors('faculte_id');

        // Champs non validés (actif=false, id) ignorés à la création
        $this->post(route('admin.administrateurs.store'), [
            'name' => 'Agent Légitime',
            'email' => 'agent.legitime@universite.test',
            'password' => 'Motdepasse@123',
            'password_confirmation' => 'Motdepasse@123',
            'role' => 'agent_scolarite',
            'actif' => false,
            'id' => 9999,
        ])->assertRedirect(route('admin.administrateurs.index'));

        $cree = User::where('email', 'agent.legitime@universite.test')->first();
        $this->assertTrue($cree->actif, 'actif n\'est pas injectable : la valeur par défaut saine est conservée.');
        $this->assertNotSame(9999, $cree->id);
    }

    public function test_un_admin_faculte_ne_peut_pas_creer_de_compte_administrateur(): void
    {
        $this->creerAdminFaculte($this->faculteA);

        // Authorization de la FormRequest + middleware super_admin
        $this->post(route('admin.administrateurs.store'), [
            'name' => 'Escalade',
            'email' => 'escalade@universite.test',
            'password' => 'Motdepasse@123',
            'password_confirmation' => 'Motdepasse@123',
            'role' => 'super_admin',
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'escalade@universite.test']);
    }

    /* ── §11 : le CIN d'un administrateur n'est jamais un étudiant ── */

    public function test_le_cin_d_un_administrateur_n_est_pas_recherche_comme_etudiant(): void
    {
        User::creerCompteBackOffice([
            'name' => 'Admin Scolarité',
            'email' => 'admin.scolarite@universite.test',
            'password' => Hash::make('Motdepasse@123'),
        ], 'admin_scolarite');

        // Un identifiant d'admin n'existe pas dans le registre étudiant
        // (second facteur requis par la validation — aucune donnée révélée)
        $this->postJson(route('inscription.verifierCin'), [
            'cin' => '65000001',
            'date_naissance' => '2000-01-01',
        ])
            ->assertOk()
            ->assertExactJson(['exists' => false]);
    }
}
