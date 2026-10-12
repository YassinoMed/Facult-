<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\SituationEtudiant;
use App\Models\User;
use App\Modules\Schedule\Models\Group;
use App\Modules\StudentArchive\Services\StudentArchiveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Inscriptions détaillées (§16-18) : historique, affectations par année,
 * changements de parcours via situations — zéro table.
 */
class StudentArchiveRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Etudiant $etudiant;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.student-archive.active' => true]);

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.reg@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);

        $this->etudiant = Etudiant::create([
            'cin' => 'REG00001', 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => 'reg@reg.test', 'date_naissance' => '2004-01-01', 'sexe' => 'F',
            'faculte_id' => $this->faculte->id, 'niveau' => 'L1', 'annee_academique' => '2026-2027',
            'adresse' => 'X', 'ville' => 'Casa', 'code_postal' => '20000', 'annee_bac' => '2024',
            'password' => 'Etudiant@123',
        ]);
        $this->etudiant->definirStatut('valide');
        $this->etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id, 'niveau' => 'L1',
            'statut' => 'en_cours', 'type' => 'premiere',
        ]);

        SituationEtudiant::create([
            'etudiant_id' => $this->etudiant->id, 'type' => 'transfert',
            'ancienne_valeur' => 'L1 GL', 'nouvelle_valeur' => 'L1 MI',
            'motif' => 'Réorientation', 'auteur_id' => $this->admin->id,
            'date_effet' => '2026-10-15',
        ]);
    }

    public function test_inscription_detaillee_complete(): void
    {
        $parcours = app(StudentArchiveService::class)->registrations($this->etudiant);

        $this->assertCount(1, $parcours);
        $bloc = $parcours[0];

        $this->assertSame('2026-2027', $bloc['inscription']->anneeUniversitaire->libelle);
        $this->assertSame('premiere', $bloc['inscription']->type);
        $this->assertNull($bloc['affectation']);
        $this->assertCount(1, $bloc['situations']);
        $this->assertSame('L1 GL', $bloc['situations'][0]->ancienne_valeur);
        $this->assertSame('L1 MI', $bloc['situations'][0]->nouvelle_valeur);
    }

    public function test_affectation_annee_projetee(): void
    {
        $groupe = Group::create([
            'code' => 'RG1', 'nom' => 'RG1', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'actif' => true,
        ]);
        $this->etudiant->affectations()->create([
            'groupe_id' => $groupe->id, 'annee_universitaire_id' => $this->annee->id,
            'date_debut' => '2026-09-20', 'statut' => 'active',
        ]);

        $parcours = app(StudentArchiveService::class)->registrations($this->etudiant);

        $this->assertSame('RG1', $parcours[0]['affectation']->groupe->code);
    }

    public function test_onglet_inscriptions_affiche_tout(): void
    {
        $this->actingAs($this->admin)->get(route('admin.etudiants.archive', [
            $this->etudiant, 'onglet' => 'inscriptions',
        ]))->assertOk()
            ->assertSee('2026-2027')
            ->assertSee('Réorientation');
    }

    public function test_onglet_inscriptions_exige_archives_view(): void
    {
        $sansRole = User::query()->create([
            'name' => 'Sans rôle', 'email' => 'sansrole.reg@universite.test', 'password' => 'Motdepasse@123',
        ]);
        $sansRole->forceFill(['role' => 'aucun'])->save();

        $this->actingAs($sansRole)->get(route('admin.etudiants.archive', [
            $this->etudiant, 'onglet' => 'inscriptions',
        ]))->assertForbidden();
    }
}
