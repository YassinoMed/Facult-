<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\Schedule\Models\Group;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Responsive 360→1440 (§2.20, §2.24) : scroll horizontal, stats en cartes,
 * filtres repliables, actions accessibles — sans perte d'information.
 */
class ResponsiveListTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.resp@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $etudiant = Etudiant::create([
            'cin' => 'RSP00001', 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => 'rsp@resp.test', 'date_naissance' => '2004-01-01', 'sexe' => 'F',
            'faculte_id' => $this->faculte->id, 'niveau' => 'L1', 'annee_academique' => '2026-2027',
            'adresse' => 'X', 'ville' => 'Casa', 'code_postal' => '20000', 'annee_bac' => '2024',
            'password' => 'Etudiant@123',
        ]);
        $etudiant->definirStatut('valide');
        $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $annee->id, 'niveau' => 'L1', 'statut' => 'en_cours',
        ]);
    }

    public function test_registre_cartes_stats_et_vue_mobile(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.etudiants.index'))->assertOk()->getContent();

        $this->assertStringContainsString('grid-cols-2', $html);
        $this->assertStringContainsString('md:hidden', $html);
        $this->assertStringContainsString('RSP00001', $html);
    }

    public function test_listes_preview_scroll_et_stats(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.listes.preview'))->assertOk()->getContent();

        $this->assertStringContainsString('overflow-x-auto', $html);
        $this->assertStringContainsString('1 résultat(s)', $html);
    }

    public function test_groupes_tableau_scrollable_et_stats(): void
    {
        Group::create([
            'code' => 'RSP-G1', 'nom' => 'G1', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'capacite' => 30, 'actif' => true,
        ]);

        $html = $this->actingAs($this->admin)->get(route('admin.groupes.index'))->assertOk()->getContent();

        $this->assertStringContainsString('overflow-x-auto', $html);
        $this->assertStringContainsString('RSP-G1', $html);
        $this->assertStringContainsString('min-w-[760px]', $html);
    }

    public function test_vue_enregistrer_repliable(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.etudiants.index'))->assertOk()->getContent();

        $this->assertStringContainsString('<details', $html);
        $this->assertStringContainsString('Enregistrer la vue', $html);
    }

    public function test_layout_viewport_present(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.etudiants.index'))->assertOk()->getContent();

        $this->assertStringContainsString('viewport', $html);
    }
}
