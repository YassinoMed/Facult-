<?php

namespace Tests\Feature;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOOP 8 Étape 2 — Sécurité interfacultés : contenu des réponses, pas
 * seulement les codes HTTP (HTML, JSON, CSV).
 */
class RegistreInterfacultesTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private User $agentA;

    private User $scolarite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculteA = Faculte::create(['nom' => 'FA', 'code' => 'FA', 'active' => true]);
        $this->faculteB = Faculte::create(['nom' => 'FB', 'code' => 'FB', 'active' => true]);
        $this->agentA = User::creerCompteBackOffice([
            'name' => 'A', 'email' => 'loop8a@test.tn', 'password' => 'Secret123!',
            'faculte_id' => $this->faculteA->id,
        ], 'admin_faculte');
        $this->scolarite = User::creerCompteBackOffice([
            'name' => 'S', 'email' => 'loop8s@test.tn', 'password' => 'Secret123!',
        ], 'admin_scolarite');
    }

    private function dossier(string $nom, int $faculteId, string $suffixe): Etudiant
    {
        return Etudiant::create([
            'nom' => $nom, 'prenom' => 'P'.$suffixe, 'email' => "loop8{$suffixe}@test.tn",
            'cin' => 'LOOP8'.$suffixe, 'sexe' => 'M', 'niveau' => 'L1',
            'annee_academique' => '2026-2027', 'adresse' => 'adr', 'ville' => 'Tunis',
            'code_postal' => '1000', 'annee_bac' => '2025', 'faculte_id' => $faculteId,
        ]);
    }

    public function test_fiche_etrangere_403_sans_pii_dans_html(): void
    {
        $etudiantB = $this->dossier('Zerouali', $this->faculteB->id, 'B1');

        $reponse = $this->actingAs($this->agentA)->get(route('admin.etudiants.show', $etudiantB));

        $reponse->assertForbidden();
        $reponse->assertDontSee('Zerouali', false);
        $reponse->assertDontSee('LOOP8B1', false);
        $reponse->assertDontSee('loop8B1@test.tn', false);
    }

    public function test_index_et_sans_groupe_sans_trace_faculte_b(): void
    {
        $this->dossier('Benali', $this->faculteA->id, 'A1');
        $this->dossier('ElFassi', $this->faculteB->id, 'B2');

        foreach (['admin.etudiants.index', 'admin.etudiants.sans-groupe'] as $route) {
            $reponse = $this->actingAs($this->agentA)->get(route($route));
            $reponse->assertOk();
            $reponse->assertSee('Benali', false);
            $reponse->assertDontSee('ElFassi', false);
            $reponse->assertDontSee('LOOP8B2', false);
        }
    }

    public function test_recherche_instantanee_scopee_contenu(): void
    {
        $this->dossier('Cherkaoui', $this->faculteB->id, 'B3');

        $reponse = $this->actingAs($this->agentA)
            ->get(route('admin.recherche.instantanee', ['q' => 'Cherkaoui']));
        $reponse->assertOk();

        $this->assertStringNotContainsString('Cherkaoui', $reponse->getContent());
        $this->assertStringNotContainsString('LOOP8B3', $reponse->getContent());
    }

    public function test_bulk_json_cross_faculte_403_sans_mutation(): void
    {
        $etudiantB = $this->dossier('BulkB', $this->faculteB->id, 'B4');

        $reponse = $this->actingAs($this->agentA)->postJson(route('admin.etudiants.bulkStatut'), [
            'ids' => [$etudiantB->id], 'statut' => 'valide', 'confirmation' => 'on',
        ]);

        $reponse->assertForbidden();
        $this->assertSame('en_attente', $etudiantB->fresh()->statut);
    }

    public function test_export_json_cross_faculte_403(): void
    {
        $etudiantB = $this->dossier('ExpB', $this->faculteB->id, 'B5');

        $reponse = $this->actingAs($this->agentA)->get(route('admin.etudiants.exportDossier', $etudiantB));

        $reponse->assertForbidden();
        $this->assertStringNotContainsString('ExpB', $reponse->getContent());
    }

    public function test_roles_legitimes_plein_acces_contenu(): void
    {
        $etudiantB = $this->dossier('LegitB', $this->faculteB->id, 'B6');

        // Scolarité centrale : accès complet conservé.
        $this->actingAs($this->scolarite)->get(route('admin.etudiants.show', $etudiantB))
            ->assertOk()->assertSee('LegitB', false);

        // Consultation (lecture globale) : lecture OK partout.
        $lecteur = User::creerCompteBackOffice([
            'name' => 'L', 'email' => 'loop8l@test.tn', 'password' => 'Secret123!',
        ], 'consultation');
        $this->actingAs($lecteur)->get(route('admin.etudiants.show', $etudiantB))
            ->assertOk()->assertSee('LegitB', false);
    }
}
