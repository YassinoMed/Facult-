<?php

namespace Tests\Feature;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Recherche globale (§7) : résultats multi-entités, périmètre
 * faculté, sections gated, pas de fuite.
 */
class GlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    protected Faculte $faculteA;

    protected Faculte $faculteB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculteA = Faculte::create([
            'nom' => 'Faculté des Sciences', 'code' => 'FST',
            'description' => 'Sciences', 'active' => true,
        ]);
        $this->faculteB = Faculte::create([
            'nom' => 'Faculté de Médecine', 'code' => 'FMP',
            'description' => 'Médecine', 'active' => true,
        ]);
    }

    protected function creerCompte(string $email, string $role, ?int $faculteId = null): User
    {
        return User::creerCompteBackOffice(array_filter([
            'name' => $role, 'email' => $email, 'password' => 'Motdepasse@123',
            'faculte_id' => $faculteId,
        ]), $role);
    }

    public function test_recherche_multi_entites(): void
    {
        $etudiant = Etudiant::create([
            'cin' => 'BK980001', 'nom' => 'Recherche', 'prenom' => 'Test',
            'email' => 'bk980001@example.com', 'sexe' => 'M',
            'faculte_id' => $this->faculteA->id, 'niveau' => 'L1',
            'annee_academique' => '2026-2027', 'adresse' => 'Rue',
            'ville' => 'Casa', 'code_postal' => '20000', 'annee_bac' => '2024',
            'password' => 'motdepasse1',
        ]);
        $etudiant->forceFill(['num_inscription' => 'INS-2026-BK980001'])->save();
        $etudiant->definirStatut('valide');

        Matiere::create([
            'code' => 'RECH101', 'nom' => 'Recherche Opérationnelle',
            'coefficient' => 1, 'credits' => 1, 'niveau' => 'L1', 'semestre' => 1,
        ]);

        $admin = $this->creerCompte('sco@universite.test', 'admin_scolarite');

        $this->actingAs($admin)->get(route('admin.recherche', ['q' => 'Recherche']))
            ->assertOk()
            ->assertSee('Étudiants')
            ->assertSee('BK980001')
            ->assertSee('Matières')
            ->assertSee('RECH101');
    }

    public function test_isolation_faculte_sans_fuite(): void
    {
        $etudiantB = Etudiant::create([
            'cin' => 'BK980002', 'nom' => 'Cache', 'prenom' => 'Test',
            'email' => 'bk980002@example.com', 'sexe' => 'M',
            'faculte_id' => $this->faculteB->id, 'niveau' => 'L1',
            'annee_academique' => '2026-2027', 'adresse' => 'Rue',
            'ville' => 'Casa', 'code_postal' => '20000', 'annee_bac' => '2024',
            'password' => 'motdepasse1',
        ]);
        $etudiantB->forceFill(['num_inscription' => 'INS-2026-BK980002'])->save();
        $etudiantB->definirStatut('valide');

        $adminA = $this->creerCompte('admin.a@universite.test', 'admin_faculte', $this->faculteA->id);

        $this->actingAs($adminA)->get(route('admin.recherche', ['q' => 'BK980002']))
            ->assertOk()->assertDontSee('Cache')->assertDontSee('bk980002@example.com');
    }

    public function test_requete_trop_courte_et_invite(): void
    {
        // Invité d'abord (actingAs persiste pour le reste du test).
        $this->get(route('admin.recherche'))->assertRedirect(route('admin.connexion.show'));

        $admin = $this->creerCompte('sco2@universite.test', 'admin_scolarite');

        $this->actingAs($admin)->get(route('admin.recherche', ['q' => 'X']))
            ->assertOk()->assertSee('Recherche globale')->assertDontSee('Résultats pour');
    }
}
