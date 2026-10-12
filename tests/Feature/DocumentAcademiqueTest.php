<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\DocumentAcademique;
use App\Models\DossierAcademique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ActAsScolarite;
use Tests\TestCase;

/**
 * Documents académiques : upload sécurisé (formats, taille, nom aléatoire),
 * stockage hors public/, téléchargement contrôlé, suppression, IDOR étudiant.
 */
class DocumentAcademiqueTest extends TestCase
{
    use ActAsScolarite, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function etudiantAvecArchive(): array
    {
        $etudiant = $this->etudiantAvecDossier();
        $annee = AnneeUniversitaire::firstOrCreate(
            ['libelle' => '2025-2026'],
            ['date_debut' => '2025-09-01', 'date_fin' => '2026-07-15', 'active' => true],
        );
        $dossier = DossierAcademique::create([
            'etudiant_id' => $etudiant->id,
            'matricule' => 'MAT-DOC-'.strtoupper(substr(md5(uniqid('', true)), 0, 8)),
            'date_archivage' => now()->toDateString(),
        ]);

        return [$etudiant, $dossier, $annee];
    }

    public function test_upload_valide_stocke_hors_public_avec_nom_aleatoire_et_hash(): void
    {
        $agent = $this->scolarite('agent_scolarite');
        [$etudiant, $dossier, $annee] = $this->etudiantAvecArchive();
        $fichier = UploadedFile::fake()->createWithContent('releve_notes.pdf', "%PDF-1.4\ncontenu minimal valide\n");

        $this->post(route('admin.archives.documents.store', $dossier), [
            'type_document' => 'releve_notes',
            'annee_universitaire_id' => $annee->id,
            'document' => $fichier,
        ])->assertRedirect(route('admin.archives.show', $dossier));

        $document = DocumentAcademique::first();
        $this->assertEquals('releve_notes.pdf', $document->nom_original);
        $this->assertEquals(64, strlen($document->hash_fichier)); // SHA-256

        // Le nom interne est aléatoire : différent du nom fourni par l'utilisateur
        $this->assertStringNotContainsString('releve_notes.pdf', $document->chemin);
        $this->assertStringStartsWith("archives/etudiant_{$etudiant->id}/{$annee->id}/documents/", $document->chemin);

        // Stockage bien dans le disque privé (jamais dans public/)
        Storage::disk('local')->assertExists($document->chemin);
        $this->assertFileDoesNotExist(public_path('storage/'.$document->chemin));
    }

    public function test_upload_d_un_fichier_php_refuse(): void
    {
        $this->scolarite('admin_scolarite');
        [$etudiant, $dossier] = $this->etudiantAvecArchive();
        $fichier = UploadedFile::fake()->create('webshell.php', 10, 'text/x-php');

        $this->post(route('admin.archives.documents.store', $dossier), [
            'type_document' => 'autre',
            'document' => $fichier,
        ])->assertSessionHasErrors('document');

        $this->assertDatabaseCount('documents_academiques', 0);
        Storage::disk('local')->assertMissing("archives/etudiant_{$etudiant->id}");
    }

    public function test_upload_d_un_fichier_trop_volumineux_refuse(): void
    {
        $this->scolarite('admin_scolarite');
        [$etudiant, $dossier] = $this->etudiantAvecArchive();
        $fichier = UploadedFile::fake()->create('gros_fichier.pdf', 12 * 1024, 'application/pdf'); // 12 Mo

        $this->post(route('admin.archives.documents.store', $dossier), [
            'type_document' => 'diplome',
            'document' => $fichier,
        ])->assertSessionHasErrors('document');

        $this->assertDatabaseCount('documents_academiques', 0);
    }

    public function test_upload_reserve_aux_roles_ayant_la_permission(): void
    {
        $this->scolarite('consultation'); // documents.view/download, PAS documents.upload
        [, $dossier] = $this->etudiantAvecArchive();

        $this->post(route('admin.archives.documents.store', $dossier), [
            'type_document' => 'attestation',
            'document' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
        ])->assertForbidden();
    }

    public function test_telechargement_exige_l_authentification(): void
    {
        [, $dossier] = $this->etudiantAvecArchive();
        $document = DocumentAcademique::create([
            'etudiant_id' => $dossier->etudiant_id,
            'type_document' => 'attestation',
            'nom_original' => 'attestation.pdf',
            'chemin' => 'archives/etudiant_1/courant/documents/x.pdf',
            'mime_type' => 'application/pdf',
            'taille' => 1024,
            'hash_fichier' => str_repeat('a', 64),
        ]);

        $this->get(route('admin.archives.documents.download', $document))
            ->assertRedirect(route('admin.connexion.show'));
    }

    public function test_telechargement_journalise_et_restant_protege(): void
    {
        $this->scolarite('consultation');
        [$etudiant, $dossier] = $this->etudiantAvecArchive();
        Storage::disk('local')->put('archives/etudiant_1/documents/test.pdf', 'contenu fictif');

        $document = DocumentAcademique::create([
            'etudiant_id' => $etudiant->id,
            'type_document' => 'attestation',
            'nom_original' => 'attestation_scolarite.pdf',
            'chemin' => 'archives/etudiant_1/documents/test.pdf',
            'mime_type' => 'application/pdf',
            'taille' => 16,
            'hash_fichier' => hash('sha256', 'contenu fictif'),
        ]);

        $response = $this->get(route('admin.archives.documents.download', $document));

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertDatabaseHas('historique_academique', [
            'action' => 'telechargement_document',
            'entite_id' => $document->id,
        ]);
    }

    public function test_etudiant_ne_peut_telecharger_que_ses_documents(): void
    {
        [$etudiant, $dossier] = $this->etudiantAvecArchive();
        [$autre] = $this->etudiantAvecArchive(); // autre étudiant

        Storage::disk('local')->put('archives/etudiant_1/documents/a.pdf', 'a');
        Storage::disk('local')->put('archives/etudiant_2/documents/b.pdf', 'b');

        $sonDocument = DocumentAcademique::create([
            'etudiant_id' => $etudiant->id,
            'type_document' => 'attestation',
            'nom_original' => 'a.pdf', 'chemin' => 'archives/etudiant_1/documents/a.pdf',
            'mime_type' => 'application/pdf', 'taille' => 1, 'hash_fichier' => str_repeat('a', 64),
        ]);
        $documentDautrui = DocumentAcademique::create([
            'etudiant_id' => $autre->id,
            'type_document' => 'attestation',
            'nom_original' => 'b.pdf', 'chemin' => 'archives/etudiant_2/documents/b.pdf',
            'mime_type' => 'application/pdf', 'taille' => 1, 'hash_fichier' => str_repeat('b', 64),
        ]);

        $this->etudiantConnecte($etudiant);

        // Son document : autorisé
        $this->get(route('etudiant.archives.documents.download', $sonDocument))->assertOk();
        // Document d'un autre étudiant : IDOR bloqué (404, aucune fuite d'existence)
        $this->get(route('etudiant.archives.documents.download', $documentDautrui))->assertNotFound();
    }

    public function test_suppression_retire_fichier_et_ligne(): void
    {
        $this->scolarite('admin_scolarite');
        [$etudiant, $dossier] = $this->etudiantAvecArchive();
        Storage::disk('local')->put('archives/etudiant_1/documents/suppr.pdf', 'x');

        $document = DocumentAcademique::create([
            'etudiant_id' => $etudiant->id,
            'type_document' => 'certificat',
            'nom_original' => 'suppr.pdf',
            'chemin' => 'archives/etudiant_1/documents/suppr.pdf',
            'mime_type' => 'application/pdf', 'taille' => 1,
            'hash_fichier' => hash('sha256', 'x'),
        ]);

        $this->delete(route('admin.archives.documents.destroy', $document))
            ->assertRedirect(route('admin.archives.show', $dossier));

        $this->assertDatabaseMissing('documents_academiques', ['id' => $document->id]);
        Storage::disk('local')->assertMissing('archives/etudiant_1/documents/suppr.pdf');
        $this->assertDatabaseHas('historique_academique', ['action' => 'suppression_document']);
    }
}
