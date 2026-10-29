<?php

namespace Tests\Feature;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\HistoriqueAcademique;
use App\Models\User;
use App\Modules\Referentiel\Models\Programme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Phase A — Autorisation et sécurité des routes de la file de traitement.
 */
class FileTraitementSecuriteTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculteA;

    private Faculte $faculteB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculteA = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'active' => true]);
        $this->faculteB = Faculte::create(['nom' => 'FMT', 'code' => 'FMT', 'active' => true]);
    }

    private function compte(string $email, string $role, array $extra = []): User
    {
        return User::creerCompteBackOffice(array_merge([
            'name' => $role, 'email' => $email, 'password' => 'Secret123!',
        ], $extra), $role);
    }

    private function dossier(int $faculteId): Etudiant
    {
        static $n = 1;

        return Etudiant::create([
            'nom' => 'NomA'.$n, 'prenom' => 'Prenom', 'email' => "a{$n}@test.tn",
            'cin' => sprintf('710000%02d', $n++), 'sexe' => 'M', 'niveau' => 'L1',
            'annee_academique' => '2026-2027', 'adresse' => 'adr', 'ville' => 'Tunis',
            'code_postal' => '1000', 'annee_bac' => '2025', 'faculte_id' => $faculteId,
        ]);
    }

    public function test_route_index_publiee_sous_permission_lecture(): void
    {
        $middlewares = Route::getRoutes()->getByName('admin.inscriptions.file-traitement')
            ->gatherMiddleware();

        $this->assertContains('can:archives.view', $middlewares);
    }

    public function test_invite_redirige_vers_connexion(): void
    {
        $this->get(route('admin.inscriptions.file-traitement'))
            ->assertRedirect(route('admin.connexion.show'));
    }

    public function test_role_consultation_lecture_ok_ecriture_refusee(): void
    {
        $lecteur = $this->compte('lecteur@test.tn', 'consultation');
        $etudiant = $this->dossier($this->faculteA->id);

        $this->actingAs($lecteur)->get(route('admin.inscriptions.file-traitement'))->assertOk();

        $this->actingAs($lecteur)
            ->post(route('admin.inscriptions.file-traitement.commenter', $etudiant), [
                'commentaire' => 'Tentative sans droit suffisant.',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('historique_academique', [
            'action' => HistoriqueAcademique::ACTION_COMMENTAIRE_INSTRUCTION,
        ]);
    }

    public function test_compte_sans_role_valide_refuse(): void
    {
        $compte = $this->compte('fantome@test.tn', 'consultation');
        $compte->forceFill(['role' => 'role_inexistant'])->save();

        $this->actingAs($compte)->get(route('admin.inscriptions.file-traitement'))
            ->assertForbidden();
    }

    public function test_compte_desactive_refuse(): void
    {
        $compte = $this->compte('inactif@test.tn', 'consultation');
        $compte->forceFill(['actif' => false])->save();

        $this->actingAs($compte)->get(route('admin.inscriptions.file-traitement'))
            ->assertForbidden();
    }

    public function test_admin_faculte_sans_rattachement_refuse(): void
    {
        $compte = $this->compte('sanstatt@test.tn', 'admin_faculte');

        $this->actingAs($compte)->get(route('admin.inscriptions.file-traitement'))
            ->assertForbidden();
    }

    public function test_referentiels_programmes_scopes_et_kpis_coherents(): void
    {
        $agentA = $this->compte('agenta@test.tn', 'admin_faculte', ['faculte_id' => $this->faculteA->id]);
        $programmeA = Programme::create([
            'faculte_id' => $this->faculteA->id,
            'code' => 'LGL-A', 'nom' => 'Licence Génie Logiciel A', 'statut' => Programme::STATUT_ACTIF,
        ]);
        $programmeB = Programme::create([
            'faculte_id' => $this->faculteB->id,
            'code' => 'LGL-B', 'nom' => 'Licence Génie Logiciel B', 'statut' => Programme::STATUT_ACTIF,
        ]);
        $this->dossier($this->faculteA->id);
        $this->dossier($this->faculteB->id);

        $reponse = $this->actingAs($agentA)->get(route('admin.inscriptions.file-traitement'));
        $reponse->assertOk();

        $kpis = $reponse->viewData('kpis');
        $this->assertSame(1, $kpis['total']);

        $programmes = $reponse->viewData('programmes');
        $this->assertCount(1, $programmes);
        $this->assertSame($programmeA->id, (int) $programmes->first()->id);
        $this->assertSame($this->faculteA->id, (int) $programmes->first()->faculte_id);
        $this->assertNotContains($programmeB->id, $programmes->pluck('id')->map(fn ($id) => (int) $id)->all());

        $facultes = $reponse->viewData('facultes');
        $this->assertCount(1, $facultes);
        $this->assertSame($this->faculteA->id, (int) $facultes->first()->id);
    }

    public function test_commentaire_json_sans_fuite(): void
    {
        $scolarite = $this->compte('sco@test.tn', 'admin_scolarite');
        $etudiant = $this->dossier($this->faculteA->id);

        $reponse = $this->actingAs($scolarite)
            ->postJson(route('admin.inscriptions.file-traitement.commenter', $etudiant), [
                'commentaire' => 'Note via API interne.',
            ]);

        $reponse->assertOk()->assertJsonPath('success', true);
        $contenu = $reponse->getContent();
        $this->assertStringNotContainsString($etudiant->cin, $contenu);
        $this->assertStringNotContainsString($etudiant->email, $contenu);
    }
}
