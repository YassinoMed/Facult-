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
 * Dossier disciplinaire (§13, §34) : strictement restreint, cycle de vie
 * ouvert → en_cours → cloture → archive (soft, jamais de destruction).
 */
class DisciplinaryRecordAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private Etudiant $etudiant;

    private StudentSensitiveRecord $dossier;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.student-archive.active' => true]);

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.dis@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->etudiant = Etudiant::create([
            'cin' => 'DIS00001', 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => 'dis@dis.test', 'date_naissance' => '2004-01-01', 'sexe' => 'F',
            'faculte_id' => $this->faculte->id, 'niveau' => 'L1', 'annee_academique' => '2026-2027',
            'adresse' => 'X', 'ville' => 'Casa', 'code_postal' => '20000', 'annee_bac' => '2024',
            'password' => 'Etudiant@123',
        ]);
        $this->etudiant->definirStatut('valide');
        $this->etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $annee->id, 'niveau' => 'L1', 'statut' => 'en_cours',
        ]);

        $this->dossier = StudentSensitiveRecord::create([
            'etudiant_id' => $this->etudiant->id, 'type' => 'disciplinaire',
            'titre' => 'Incident bibliothèque — commission du 14/03',
            'statut' => 'en_cours', 'niveau' => 'confidentiel',
            'date_debut' => '2026-03-10', 'created_by' => $this->admin->id,
        ]);
    }

    public function test_cycle_vie_controle(): void
    {
        foreach (['cloture', 'archive'] as $statut) {
            $this->actingAs($this->admin)->patch(route('admin.etudiants.archive.sensible.update', [
                $this->etudiant, $this->dossier,
            ]), ['statut' => $statut])->assertSessionHasNoErrors();
            $this->assertSame($statut, $this->dossier->fresh()->statut);
        }

        // Soft : la ligne existe toujours (aucune destruction silencieuse).
        $this->assertDatabaseHas('student_sensitive_records', ['id' => $this->dossier->id]);
        $this->assertNull($this->dossier->fresh()->deleted_at);
    }

    public function test_consultation_sans_permission_aveugle(): void
    {
        $consultation = User::creerCompteBackOffice([
            'name' => 'Cons', 'email' => 'cons.dis@universite.test', 'password' => 'Motdepasse@123',
        ], 'consultation');

        // Archive visible (archives.view) mais disciplinaire invisible.
        $html = $this->actingAs($consultation)->get(route('admin.etudiants.archive', $this->etudiant))
            ->assertOk()->getContent();

        $this->assertStringNotContainsString('Incident bibliothèque', $html);
        $this->assertStringNotContainsString('Confidentiel', $html);

        $this->actingAs($consultation)->get(route('admin.etudiants.archive', [
            $this->etudiant, 'onglet' => 'confidentiel',
        ]))->assertNotFound();
    }

    public function test_admin_faculte_meme_faculte_sans_acces(): void
    {
        $adminFaculte = User::creerCompteBackOffice([
            'name' => 'Fac', 'email' => 'fac.dis@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculte->id,
        ], 'admin_faculte');

        $this->actingAs($adminFaculte)->get(route('admin.etudiants.archive', [
            $this->etudiant, 'onglet' => 'confidentiel',
        ]))->assertNotFound();
    }

    public function test_validation_titre_neutre(): void
    {
        $this->actingAs($this->admin)->post(route('admin.etudiants.archive.sensible.store', $this->etudiant), [
            'type' => 'disciplinaire', 'titre' => 'X',
        ])->assertSessionHasErrors('titre');

        $this->actingAs($this->admin)->post(route('admin.etudiants.archive.sensible.store', $this->etudiant), [
            'type' => 'triche', 'titre' => 'Incident avéré',
        ])->assertSessionHasErrors('type');
    }
}
