<?php

namespace Tests\Feature;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Services\Io\StudentsImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 2 — compléments (§44) : sécurité dédiée + performance volumes.
 */
class PopulationPhase2CompleteTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private User $adminFaculte;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->adminFaculte = User::creerCompteBackOffice([
            'name' => 'Fac', 'email' => 'fac.pop2@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculte->id,
        ], 'admin_faculte');
    }

    public function test_registre_ignore_faculte_manipulee(): void
    {
        $autre = Faculte::create(['nom' => 'FMP', 'code' => 'FMP', 'description' => 'T', 'active' => true]);
        $this->creerEtudiant('RESC001', $autre->id);

        $reponse = $this->actingAs($this->adminFaculte)->get(route('admin.etudiants.index', [
            'faculte_id' => $autre->id,
        ]))->assertOk();

        $this->assertSame(0, $reponse->viewData('etudiants')->total());
    }

    public function test_sans_cin_autre_faculte_inaccessible(): void
    {
        $autre = Faculte::create(['nom' => 'FMP', 'code' => 'FMP', 'description' => 'T', 'active' => true]);
        $etudiant = $this->creerEtudiant(null, $autre->id);

        $this->actingAs($this->adminFaculte)->get(route('admin.etudiants.show', $etudiant))
            ->assertForbidden();
    }

    public function test_import_faculte_hors_perimetre_refuse(): void
    {
        Faculte::create(['nom' => 'FMP', 'code' => 'FMP', 'description' => 'T', 'active' => true]);

        $service = app(StudentsImporter::class);
        $preview = $service->valider([[
            'cin' => '', 'nom' => 'Benali', 'prenom' => 'Sara', 'email' => 'hors@perimetre.test',
            'sexe' => 'F', 'faculte' => 'FMP', 'niveau' => 'L1', 'annee_academique' => '2026-2027',
            'adresse' => 'X', 'ville' => 'Casa', 'code_postal' => '20000', 'annee_bac' => '2024',
        ]], $this->faculte->id);

        $this->assertSame(0, $preview->valides());
        $this->assertSame(0, Etudiant::where('email', 'hors@perimetre.test')->count());
    }

    public function test_registre_2000_borne(): void
    {
        foreach (range(1, 2000) as $i) {
            Etudiant::create([
                'cin' => 'PERF'.str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                'nom' => 'Nom', 'prenom' => 'Prenom'.$i, 'email' => "perf{$i}@pop.test",
                'date_naissance' => '2004-01-01', 'sexe' => 'F', 'faculte_id' => $this->faculte->id,
                'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'Casa',
                'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
            ]);
        }

        $requetes = 0;
        DB::listen(function () use (&$requetes) {
            $requetes++;
        });

        $debut = microtime(true);
        $this->actingAs($this->adminFaculte)->get(route('admin.etudiants.index'))->assertOk();
        $duree = microtime(true) - $debut;

        $this->assertLessThanOrEqual(25, $requetes, "Registre 2000 : {$requetes} requêtes.");
        $this->assertLessThan(10, $duree, "Registre 2000 : {$duree}s.");
    }

    public function test_import_1500_lignes_borne(): void
    {
        $lignes = [];
        foreach (range(1, 1500) as $i) {
            $lignes[] = [
                'cin' => 'IMP'.str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                'nom' => 'Nom', 'prenom' => 'P'.$i, 'email' => "imp{$i}@pop.test",
                'sexe' => 'F', 'faculte' => 'FST', 'niveau' => 'L1', 'annee_academique' => '2026-2027',
                'adresse' => 'X', 'ville' => 'Casa', 'code_postal' => '20000', 'annee_bac' => '2024',
            ];
        }

        $debut = microtime(true);
        $preview = app(StudentsImporter::class)->valider($lignes);
        $duree = microtime(true) - $debut;

        $this->assertSame(1500, $preview->valides());
        $this->assertLessThan(30, $duree, "Preview 1500 : {$duree}s.");
    }

    public function test_dashboard_ventilations_bornees(): void
    {
        $this->creerEtudiant('DASH001', $this->faculte->id);

        $requetes = 0;
        DB::listen(function () use (&$requetes) {
            $requetes++;
        });

        $reponse = $this->actingAs($this->adminFaculte)->get(route('admin.population.stats'))->assertOk();

        // Budget : 20 requêtes métier + 1 lookup préférences du layout
        // (Phase 7 §7.4, cache 1 h en production — 0 requête au-delà
        // du premier rendu). Tout N+1 ferait exploser ce budget.
        $this->assertLessThanOrEqual(21, $requetes, "Dashboard : {$requetes} requêtes.");
        $this->assertArrayHasKey('par_niveau', $reponse->viewData('ventilations'));
    }

    private function creerEtudiant(?string $cin, int $faculteId): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin, 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => strtolower($cin ?? uniqid('sanscin')).'@pop.test',
            'date_naissance' => '2004-01-01', 'sexe' => 'F', 'faculte_id' => $faculteId,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
        $etudiant->definirStatut('valide');

        return $etudiant;
    }
}
