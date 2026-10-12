<?php

namespace Tests\Feature;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\MensReference;
use App\Models\User;
use App\Modules\Referentiel\Models\Programme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Emplacement data MENS : consultation scopée faculté (lecture seule,
 * import via le pipeline DataExchange existant).
 */
class MensReferenceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $fst;

    private Faculte $fmp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fst = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->fmp = Faculte::create(['nom' => 'FMP', 'code' => 'FMP', 'description' => 'M', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.mens@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        Programme::create(['faculte_id' => $this->fst->id, 'code' => 'LMI', 'nom' => 'Licence MI']);

        // Ligne appariée FST (CIN d'un étudiant local).
        Etudiant::create([
            'cin' => 'BKMM0001', 'nom' => 'Benali', 'prenom' => 'Sara', 'email' => 'a@a.test',
            'date_naissance' => '2004-01-01', 'sexe' => 'F', 'faculte_id' => $this->fst->id,
            'niveau' => 'L1', 'annee_academique' => '2025-2026', 'adresse' => 'X',
            'ville' => 'V', 'code_postal' => '1', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
        MensReference::create([
            'cin_normalise' => 'BKMM0001', 'cin_original' => 'BKMM0001', 'date_naissance' => '2004-01-01',
            'nom' => 'Benali', 'prenom' => 'Sara', 'annee' => '2025-2026',
            'programme_code' => 'LMI', 'source_hash' => hash('sha256', 'x1'),
        ]);
        // Ligne programme FST sans dossier local.
        MensReference::create([
            'cin_normalise' => 'BKMM0002', 'cin_original' => 'BKMM0002', 'date_naissance' => '2003-02-02',
            'nom' => 'Alaoui', 'prenom' => 'Mehdi', 'annee' => '2025-2026',
            'programme_code' => 'LMI', 'source_hash' => hash('sha256', 'x2'),
        ]);
        // Ligne hors faculté (autre programme, sans dossier).
        MensReference::create([
            'cin_normalise' => 'BKMM0003', 'cin_original' => 'BKMM0003', 'date_naissance' => '2002-03-03',
            'nom' => 'Tazi', 'prenom' => 'Lina', 'annee' => '2024-2025',
            'programme_code' => 'MED', 'source_hash' => hash('sha256', 'x3'),
        ]);
    }

    public function test_global_voit_tout_et_liens_import(): void
    {
        $reponse = $this->actingAs($this->admin)->get(route('admin.mens.index'))->assertOk();

        $reponse->assertSee('BKMM0001', false);
        $reponse->assertSee('BKMM0003', false);
        $reponse->assertSee(route('admin.donnees.import.form', 'referentiel-mens'), false);
        $reponse->assertSee(route('admin.donnees.modele', ['referentiel-mens', 'csv']), false);
    }

    public function test_restreint_ne_voit_que_sa_faculte(): void
    {
        $adminFaculte = User::creerCompteBackOffice([
            'name' => 'Fac', 'email' => 'fac.mens@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->fst->id,
        ], 'admin_faculte');

        $contenu = (string) $this->actingAs($adminFaculte)->get(route('admin.mens.index'))->assertOk()->getContent();

        $this->assertStringContainsString('BKMM0001', $contenu);
        $this->assertStringContainsString('BKMM0002', $contenu);
        $this->assertStringNotContainsString('BKMM0003', $contenu);
    }

    public function test_filtres_recherche_annee_appariement(): void
    {
        $this->actingAs($this->admin)->get(route('admin.mens.index', ['q' => 'Alaoui']))
            ->assertOk()->assertSee('BKMM0002', false);

        $contenu = (string) $this->actingAs($this->admin)
            ->get(route('admin.mens.index', ['appariement' => 'apparies']))->assertOk()->getContent();
        $this->assertStringContainsString('BKMM0001', $contenu);
        $this->assertStringNotContainsString('BKMM0002', $contenu);

        $this->actingAs($this->admin)->get(route('admin.mens.index', ['annee' => '2024-2025']))
            ->assertOk()->assertSee('BKMM0003', false);
    }

    public function test_sans_permission_403(): void
    {
        $agent = User::creerCompteBackOffice([
            'name' => 'Agent', 'email' => 'agent.mens@universite.test', 'password' => 'Motdepasse@123',
        ], 'agent_scolarite');

        // agent_scolarite : data.export seul, pas data.import.
        $this->actingAs($agent)->get(route('admin.mens.index'))->assertForbidden();
    }

    public function test_nav_affiche_emplacement(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Référentiel MENS', false)
            ->assertSee(route('admin.mens.index'), false);
    }
}
