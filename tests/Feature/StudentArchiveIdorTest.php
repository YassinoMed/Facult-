<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\DocumentAcademique;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * IDOR archive (§42, §50) : A→A = 200, A→B = 404 (convention : périmètre
 * avant permission). Notes, inscriptions, documents, diplômes, certificats.
 */
class StudentArchiveIdorTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private User $adminA;

    private Etudiant $etudiantA;

    private Etudiant $etudiantB;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.student-archive.active' => true]);

        $this->faculteA = Faculte::create(['nom' => 'FSTA', 'code' => 'FSTA', 'description' => 'T', 'active' => true]);
        $this->faculteB = Faculte::create(['nom' => 'FSTB', 'code' => 'FSTB', 'description' => 'T', 'active' => true]);
        $this->adminA = User::creerCompteBackOffice([
            'name' => 'FacA', 'email' => 'faca.idor@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteA->id,
        ], 'admin_faculte');

        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);

        $this->etudiantA = $this->creerEtudiant('IDA00001', $this->faculteA->id, $annee->id);
        $this->etudiantB = $this->creerEtudiant('IDB00001', $this->faculteB->id, $annee->id);

        Storage::disk('local')->put('archives/ib.pdf', '%PDF-1.4 b');
        DocumentAcademique::create([
            'etudiant_id' => $this->etudiantB->id,
            'annee_universitaire_id' => $annee->id,
            'type_document' => 'diplome',
            'nom_original' => 'diplome-b.pdf',
            'chemin' => 'archives/ib.pdf',
            'mime_type' => 'application/pdf',
            'taille' => 10,
            'hash_fichier' => hash('sha256', 'ib'),
            'uploaded_by' => null,
        ]);
    }

    #[DataProvider('fournisseurOnglets')]
    public function test_a_vers_a_200_et_b_404(string $onglet): void
    {
        $this->actingAs($this->adminA)->get(route('admin.etudiants.archive', [
            $this->etudiantA, 'onglet' => $onglet,
        ]))->assertOk();

        $this->actingAs($this->adminA)->get(route('admin.etudiants.archive', [
            $this->etudiantB, 'onglet' => $onglet,
        ]))->assertNotFound();
    }

    /** @return array<string, array{string}> */
    public static function fournisseurOnglets(): array
    {
        return [
            'general' => ['general'],
            'academique (notes)' => ['academique'],
            'inscriptions' => ['inscriptions'],
            'documents (diplômes, certificats)' => ['documents'],
            'timeline' => ['timeline'],
        ];
    }

    public function test_download_document_b_refuse(): void
    {
        $document = DocumentAcademique::where('etudiant_id', $this->etudiantB->id)->firstOrFail();

        // UUID connu hors périmètre → 403 (policies GED existantes).
        $this->actingAs($this->adminA)->get(route('admin.archives.documents.download', $document))
            ->assertForbidden();
    }

    private function creerEtudiant(string $cin, int $faculteId, int $anneeId): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin, 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => strtolower($cin).'@idor.test',
            'date_naissance' => '2004-01-01', 'sexe' => 'F', 'faculte_id' => $faculteId,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
        $etudiant->definirStatut('valide');
        $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $anneeId, 'niveau' => 'L1', 'statut' => 'en_cours',
        ]);

        return $etudiant;
    }
}
