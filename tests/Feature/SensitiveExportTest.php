<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\StudentArchive\Models\StudentSensitiveRecord;
use App\Services\DataExchange\DefinitionRegistry;
use App\Services\Io\Exports\ExportColumnPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Exports exempts de sensible (§30, §34) : standards inchangés, aucune
 * colonne/table sensible exposée, pas de job par module.
 */
class SensitiveExportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Etudiant $etudiant;

    protected function setUp(): void
    {
        parent::setUp();

        $faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.sexp@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->etudiant = Etudiant::create([
            'cin' => 'SEXP0001', 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => 'sexp@sexp.test', 'date_naissance' => '2004-01-01', 'sexe' => 'F',
            'faculte_id' => $faculte->id, 'niveau' => 'L1', 'annee_academique' => '2026-2027',
            'adresse' => 'X', 'ville' => 'Casa', 'code_postal' => '20000', 'annee_bac' => '2024',
            'password' => 'Etudiant@123',
        ]);
        $this->etudiant->definirStatut('valide');
        $this->etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $annee->id, 'niveau' => 'L1', 'statut' => 'en_cours',
        ]);

        StudentSensitiveRecord::create([
            'etudiant_id' => $this->etudiant->id, 'type' => 'disciplinaire',
            'titre' => 'ExportSecretDEF456', 'statut' => 'ouvert',
            'created_by' => $this->admin->id,
        ]);
    }

    public function test_listes_sans_colonne_sensible(): void
    {
        $csv = $this->actingAs($this->admin)->get(route('admin.listes.exporter', [
            'format' => 'csv',
        ]))->assertOk()->streamedContent();

        $this->assertStringNotContainsString('ExportSecretDEF456', $csv);
        $this->assertStringNotContainsString('sensible', strtolower(explode("\n", $csv)[0]));
    }

    public function test_dataexchange_ignore_sensible(): void
    {
        $entites = DefinitionRegistry::entites();

        foreach ($entites as $entite) {
            $this->assertStringNotContainsString('sensib', $entite);
            $this->assertStringNotContainsString('medic', $entite);
            $this->assertStringNotContainsString('disciplin', $entite);
        }
    }

    public function test_colonnes_confidentialite_non_exportees(): void
    {
        // La colonne existe (graduation) mais aucun export standard ne la projette.
        $this->assertTrue(Schema::hasColumn('documents_academiques', 'niveau_confidentialite'));
        $this->assertStringNotContainsString(
            'niveau_confidentialite',
            implode(',', ExportColumnPolicy::catalogue('students'))
        );
    }
}
