<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\VueSauvegardeeController;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\ExportTemplate;
use App\Models\Faculte;
use App\Models\User;
use App\Models\VueSauvegardee;
use App\Modules\Schedule\Models\Group;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Vues sauvegardées (§2.12-2.13, §2.24) : profils listes (filtres + tri +
 * colonnes + regroupement + orientation) et vues tableau `etudiants`.
 */
class SavedViewTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Group $groupe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.vues@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->groupe = Group::create([
            'code' => 'GV1', 'nom' => 'GV1', 'faculte_id' => $this->faculte->id,
            'niveau' => 'M2', 'capacite' => 30, 'actif' => true,
        ]);
        $this->creerEtudiant('VUE00001');
    }

    public function test_profil_conserve_regroupement_orientation_tri(): void
    {
        $this->actingAs($this->admin)->post(route('admin.listes.profils.store'), [
            'name' => 'M2 DSIR groupé',
            'format' => 'pdf',
            'niveau' => 'M2',
            'regroupement' => 'groupe',
            'orientation' => 'paysage',
            'tri' => 'nom',
            'sens' => 'asc',
        ])->assertSessionHasNoErrors();

        $profil = ExportTemplate::where('user_id', $this->admin->id)->where('type', 'listes')->firstOrFail();
        $this->assertSame('groupe', $profil->filters['regroupement']);
        $this->assertSame('paysage', $profil->filters['orientation']);
        $this->assertSame('nom', $profil->filters['tri']);

        $this->actingAs($this->admin)->get(route('admin.listes.preview', array_merge(
            $profil->filters, ['colonnes' => $profil->columns]
        )))->assertOk();
    }

    public function test_vue_tableau_etudiants_enregistree_et_reutilisee(): void
    {
        $this->assertContains('etudiants', VueSauvegardeeController::TABLEAUX);

        $this->actingAs($this->admin)->post(route('admin.vues.store'), [
            'tableau' => 'etudiants',
            'nom' => 'Ma vue M2',
            'filtres' => ['niveau' => 'M2'],
        ])->assertSessionHasNoErrors();

        $vue = VueSauvegardee::where('user_id', $this->admin->id)->where('tableau', 'etudiants')->firstOrFail();

        $this->actingAs($this->admin)->get(route('admin.etudiants.index', $vue->filtres))
            ->assertOk()->assertSee('Ma vue M2');
    }

    public function test_vue_reservee_proprietaire(): void
    {
        $vue = VueSauvegardee::create([
            'user_id' => $this->admin->id, 'tableau' => 'etudiants',
            'nom' => 'Privée', 'filtres' => ['niveau' => 'M2'],
        ]);

        $autre = User::creerCompteBackOffice([
            'name' => 'Autre', 'email' => 'autre.vues@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->actingAs($autre)->delete(route('admin.vues.destroy', $vue))->assertNotFound();
        $this->actingAs($this->admin)->delete(route('admin.vues.destroy', $vue))->assertSessionHasNoErrors();
    }

    private function creerEtudiant(string $cin): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin, 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => strtolower($cin).'@vues.test',
            'date_naissance' => '2004-01-01', 'sexe' => 'F', 'faculte_id' => $this->faculte->id,
            'niveau' => 'M2', 'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
        $etudiant->definirStatut('valide');
        $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id, 'niveau' => 'M2', 'statut' => 'en_cours',
        ]);

        return $etudiant;
    }
}
