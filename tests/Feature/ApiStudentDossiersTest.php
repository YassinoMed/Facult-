<?php

namespace Tests\Feature;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\StudentDossiers\Models\StudentDocument;
use App\Modules\StudentDossiers\Models\StudentDossier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActAsApiClient;
use Tests\TestCase;

/**
 * API v1 — dossiers administratifs permanents (§16-17, §58-59).
 *
 * Contrôles : scopes Sanctum, isolation faculté (anti-BOLA), filtres en
 * liste blanche, pagination bornée, métadonnées sans `storage_path`,
 * module désactivé = 404, identifiants invalides = 404.
 */
class ApiStudentDossiersTest extends TestCase
{
    use ActAsApiClient, RefreshDatabase;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private Etudiant $etudiantA;

    private Etudiant $etudiantB;

    private StudentDossier $dossierA;

    private StudentDossier $dossierB;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.student-dossiers.active' => true]);

        $this->faculteA = Faculte::create([
            'nom' => 'Faculté des Sciences', 'code' => 'FST', 'description' => 'Test', 'active' => true,
        ]);
        $this->faculteB = Faculte::create([
            'nom' => 'Faculté de Droit', 'code' => 'FDEG', 'description' => 'Test', 'active' => true,
        ]);

        $this->etudiantA = $this->creerEtudiant('APIDOS001', 'Ndiaye', 'Awa', $this->faculteA);
        $this->etudiantB = $this->creerEtudiant('APIDOS002', 'Fall', 'Moussa', $this->faculteB);

        $this->dossierA = StudentDossier::create([
            'etudiant_id' => $this->etudiantA->id,
            'reference' => 'DOS-API-A',
            'status' => 'open',
        ]);
        $this->dossierB = StudentDossier::create([
            'etudiant_id' => $this->etudiantB->id,
            'reference' => 'DOS-API-B',
            'status' => 'complete',
        ]);

        $this->creerDocument($this->dossierA, 'identity', 'carte_identite', 'Carte nationale', 'cin.pdf', 'validated');
        $this->creerDocument($this->dossierA, 'academic', 'releve_notes', 'Relevé de notes', 'releve.pdf', 'pending');
        $this->creerDocument($this->dossierB, 'identity', 'passeport', 'Passeport', 'passeport.pdf', 'validated');
    }

    public function test_liste_des_dossiers_selon_perimetre(): void
    {
        [, $token] = $this->clientApi(['archives:read']);

        $reponse = $this->getApi('/api/v1/student-dossiers', $token)->assertOk();
        $this->assertCount(2, $reponse->json('data'));

        // Jeton restreint à la faculté A : seul son dossier est visible.
        $this->resetAuthGuards();
        $tokenFaculteA = $this->tokenAdminFaculte($this->faculteA, ['archives:read']);

        $reponse = $this->getApi('/api/v1/student-dossiers?faculty=FDEG', $tokenFaculteA)->assertOk();
        $this->assertCount(1, $reponse->json('data'));
        $this->assertSame('DOS-API-A', $reponse->json('data.0.reference'));
    }

    public function test_detail_dossier_et_404_hors_perimetre(): void
    {
        [, $token] = $this->clientApi(['archives:read']);

        $this->getApi('/api/v1/student-dossiers/'.$this->dossierA->id, $token)
            ->assertOk()
            ->assertJsonPath('data.reference', 'DOS-API-A')
            ->assertJsonPath('data.documents_count', 2)
            ->assertJsonMissingPath('data.documents');

        $this->resetAuthGuards();
        $tokenFaculteA = $this->tokenAdminFaculte($this->faculteA, ['archives:read']);

        $this->getApi('/api/v1/student-dossiers/'.$this->dossierA->id, $tokenFaculteA)->assertOk();
        $this->getApi('/api/v1/student-dossiers/'.$this->dossierB->id, $tokenFaculteA)->assertNotFound();
    }

    public function test_documents_metadata_sans_chemin_interne(): void
    {
        [, $token] = $this->clientApi(['documents:read']);

        $reponse = $this->getApi('/api/v1/student-dossiers/'.$this->dossierA->id.'/documents', $token)
            ->assertOk();

        $this->assertCount(2, $reponse->json('data'));

        $corps = (string) json_encode($reponse->json());
        $this->assertStringNotContainsString('storage_path', $corps);
        $this->assertStringNotContainsString('documents/prive/', $corps);
        $this->assertStringContainsString('cin.pdf', $corps);

        // Filtre liste blanche : statut valide uniquement.
        $filtre = $this->getApi('/api/v1/student-dossiers/'.$this->dossierA->id.'/documents?status=validated', $token)
            ->assertOk();
        $this->assertCount(1, $filtre->json('data'));
        $this->assertSame('validated', $filtre->json('data.0.status'));

        // Filtre catégorie autorisé.
        $categorie = $this->getApi('/api/v1/student-dossiers/'.$this->dossierA->id.'/documents?category=academic', $token)
            ->assertOk();
        $this->assertCount(1, $categorie->json('data'));

        // Filtre arbitraire : ignoré, jamais une colonne SQL.
        $this->getApi('/api/v1/student-dossiers/'.$this->dossierA->id.'/documents?sort=storage_path', $token)
            ->assertOk();
    }

    public function test_scopes_restreints(): void
    {
        [, $tokenEtudiants] = $this->clientApi(['students:read']);
        $refus = $this->getApi('/api/v1/student-dossiers', $tokenEtudiants)
            ->assertForbidden()
            ->assertJsonPath('error', 'insufficient_scope');

        // Aucun détail d'autorisation divulgué (ni scope requis, ni scopes du token).
        $this->assertStringNotContainsString('archives:read', (string) $refus->getContent());

        $this->resetAuthGuards();
        [, $tokenArchives] = $this->clientApi(['archives:read']);
        $refus = $this->getApi('/api/v1/student-dossiers/'.$this->dossierA->id.'/documents', $tokenArchives)
            ->assertForbidden()
            ->assertJsonPath('error', 'insufficient_scope');

        $this->assertStringNotContainsString('documents:read', (string) $refus->getContent());
    }

    public function test_pagination_bornee(): void
    {
        [, $token] = $this->clientApi(['archives:read']);

        $reponse = $this->getApi('/api/v1/student-dossiers?per_page=1000', $token)->assertOk();

        $this->assertSame(100, $reponse->json('meta.per_page'));
        $this->assertSame(100, $reponse->json('meta.per_page_max'));

        $this->getApi('/api/v1/student-dossiers?per_page=0', $token)
            ->assertOk()
            ->assertJsonPath('meta.per_page', 1);
    }

    public function test_filtres_en_liste_blanche_et_recherche(): void
    {
        [, $token] = $this->clientApi(['archives:read']);

        $reponse = $this->getApi('/api/v1/student-dossiers?status=complete', $token)->assertOk();
        $this->assertCount(1, $reponse->json('data'));
        $this->assertSame('DOS-API-B', $reponse->json('data.0.reference'));

        // Statut inconnu : ignoré (aucune erreur, aucun filtre arbitraire).
        $this->getApi('/api/v1/student-dossiers?status=inconnu', $token)
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $recherche = $this->getApi('/api/v1/student-dossiers?search=APIDOS001', $token)->assertOk();
        $this->assertCount(1, $recherche->json('data'));
        $this->assertSame('DOS-API-A', $recherche->json('data.0.reference'));
    }

    public function test_module_desactive_repond_404(): void
    {
        [, $token] = $this->clientApi(['archives:read']);

        config(['modules.student-dossiers.active' => false]);

        $this->getApi('/api/v1/student-dossiers', $token)->assertNotFound();
        $this->getApi('/api/v1/student-dossiers/'.$this->dossierA->id, $token)->assertNotFound();
        $this->getApi('/api/v1/student-dossiers/'.$this->dossierA->id.'/documents', $token)->assertNotFound();
    }

    public function test_authentification_et_identifiants_invalides(): void
    {
        $this->getJson('/api/v1/student-dossiers')->assertUnauthorized();

        [, $token] = $this->clientApi(['archives:read']);

        $this->getApi('/api/v1/student-dossiers/abc', $token)->assertNotFound();
        $this->getApi('/api/v1/student-dossiers/1;DROP%20TABLE', $token)->assertNotFound();
    }

    private function creerEtudiant(string $cin, string $nom, string $prenom, Faculte $faculte): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin,
            'nom' => $nom,
            'prenom' => $prenom,
            'email' => strtolower($cin).'@example.com',
            'telephone' => '+212600000000',
            'date_naissance' => '2004-03-15',
            'sexe' => 'F',
            'faculte_id' => $faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => '12 Rue de la Liberté',
            'ville' => 'Casablanca',
            'code_postal' => '20000',
            'annee_bac' => '2024',
            'mention_bac' => 'Bien',
            'password' => 'motdepasse1',
        ]);
        $etudiant->forceFill(['num_inscription' => 'INS-2026-'.$cin])->save();

        return $etudiant->fresh();
    }

    private function creerDocument(
        StudentDossier $dossier,
        string $categorie,
        string $type,
        string $titre,
        string $fichier,
        string $statut,
    ): StudentDocument {
        return StudentDocument::create([
            'student_dossier_id' => $dossier->id,
            'category' => $categorie,
            'document_type' => $type,
            'title' => $titre,
            'original_filename' => $fichier,
            'storage_path' => 'documents/prive/'.uniqid('doc-', true).'.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size' => 1024,
            'sha256' => str_repeat('a', 64),
            'status' => $statut,
        ]);
    }

    private function tokenAdminFaculte(Faculte $faculte, array $scopes): string
    {
        $utilisateur = User::creerCompteBackOffice([
            'name' => 'Admin Faculté API',
            'email' => uniqid('api-fac-', true).'@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_faculte');

        $utilisateur->forceFill(['faculte_id' => $faculte->id])->save();

        return $utilisateur->createToken('integration-test', $scopes)->plainTextToken;
    }
}
