<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\StudentArchive\Models\StudentSensitiveRecord;
use App\Modules\StudentArchive\Services\StudentArchiveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Timeline sensible (§19, §34) : séparée, neutre, permission-gated.
 * La standard ne révèle ni titre ni existence.
 */
class SensitiveTimelineTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private Etudiant $etudiant;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.student-archive.active' => true]);

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.stl@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->etudiant = Etudiant::create([
            'cin' => 'STL00001', 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => 'stl@stl.test', 'date_naissance' => '2004-01-01', 'sexe' => 'F',
            'faculte_id' => $this->faculte->id, 'niveau' => 'L1', 'annee_academique' => '2026-2027',
            'adresse' => 'X', 'ville' => 'Casa', 'code_postal' => '20000', 'annee_bac' => '2024',
            'password' => 'Etudiant@123',
        ]);
        $this->etudiant->definirStatut('valide');
        $this->etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $annee->id, 'niveau' => 'L1', 'statut' => 'en_cours',
        ]);

        StudentSensitiveRecord::create([
            'etudiant_id' => $this->etudiant->id, 'type' => 'disciplinaire',
            'titre' => 'TitreSecretXYZ123', 'statut' => 'en_cours',
            'created_by' => $this->admin->id,
        ]);
    }

    public function test_standard_sans_sensible(): void
    {
        $this->actingAs($this->admin);

        $timeline = app(StudentArchiveService::class)->timeline($this->etudiant);

        $this->assertCount(0, $timeline['elements']->where('categorie', 'sensible'));
        $this->assertStringNotContainsString(
            'TitreSecretXYZ123',
            $timeline['elements']->toJson()
        );
    }

    public function test_confidentiel_avec_sensible_neutre(): void
    {
        $this->actingAs($this->admin);

        $timeline = app(StudentArchiveService::class)->timeline($this->etudiant, ['sensible' => true]);

        $sensibles = $timeline['elements']->where('categorie', 'sensible');
        $this->assertCount(1, $sensibles);
        // Titre neutre : type + statut, jamais le titre métier.
        $this->assertSame('Dossier Disciplinaire — en_cours', $sensibles->first()['titre']);
    }

    public function test_sans_permission_vide(): void
    {
        $agent = User::creerCompteBackOffice([
            'name' => 'Agent', 'email' => 'agent.stl@universite.test', 'password' => 'Motdepasse@123',
        ], 'agent_scolarite');
        $this->actingAs($agent);

        $timeline = app(StudentArchiveService::class)->timeline($this->etudiant, ['sensible' => true]);

        $this->assertCount(0, $timeline['elements']->where('categorie', 'sensible'));
    }
}
