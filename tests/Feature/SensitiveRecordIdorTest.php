<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\StudentArchive\Models\StudentSensitiveRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * IDOR sensible (§18) : A→A autorisé, A→B refusé — page, download,
 * timeline, API, recherche, export.
 */
class SensitiveRecordIdorTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private User $adminA;

    private Etudiant $etudiantA;

    private Etudiant $etudiantB;

    private StudentSensitiveRecord $dossierB;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.student-archive.active' => true]);

        $this->faculteA = Faculte::create(['nom' => 'FSTA', 'code' => 'FSTA', 'description' => 'T', 'active' => true]);
        $this->faculteB = Faculte::create(['nom' => 'FSTB', 'code' => 'FSTB', 'description' => 'T', 'active' => true]);
        $this->adminA = User::creerCompteBackOffice([
            'name' => 'FacA', 'email' => 'faca.sen@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteA->id,
        ], 'admin_faculte');

        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);

        $this->etudiantA = $this->creerEtudiant('SNA00001', $this->faculteA->id, $annee->id);
        $this->etudiantB = $this->creerEtudiant('SNB00001', $this->faculteB->id, $annee->id);

        $this->dossierB = StudentSensitiveRecord::create([
            'etudiant_id' => $this->etudiantB->id, 'type' => 'medical',
            'titre' => 'Dossier B confidentiel', 'statut' => 'ouvert',
        ]);
    }

    public function test_archive_b_hors_scope(): void
    {
        $this->actingAs($this->adminA)->get(route('admin.etudiants.archive', [
            $this->etudiantB, 'onglet' => 'confidentiel',
        ]))->assertNotFound();
    }

    public function test_download_b_par_id_refuse(): void
    {
        $this->actingAs($this->adminA)->get(route('admin.etudiants.archive.sensible.download', [
            $this->etudiantB, $this->dossierB,
        ]))->assertNotFound();
    }

    public function test_dossier_autre_etudiant_meme_faculte_refuse(): void
    {
        // Dossier de B adressé via l'étudiant A → 404 (cohérence paire).
        $this->actingAs($this->adminA)->get(route('admin.etudiants.archive.sensible.download', [
            $this->etudiantA, $this->dossierB,
        ]))->assertNotFound();
    }

    public function test_update_b_refuse(): void
    {
        $this->actingAs($this->adminA)->patch(route('admin.etudiants.archive.sensible.update', [
            $this->etudiantB, $this->dossierB,
        ]), ['statut' => 'cloture'])->assertNotFound();

        $this->assertSame('ouvert', $this->dossierB->fresh()->statut);
    }

    private function creerEtudiant(string $cin, int $faculteId, int $anneeId): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin, 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => strtolower($cin).'@sen.test',
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
