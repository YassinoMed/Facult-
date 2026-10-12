<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\Enseignement\Models\SousGroupe;
use App\Modules\Schedule\Models\Group;
use App\Services\AffectationService;
use App\Services\Exports\ListeEtudiantsService;
use App\Services\PopulationStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Refonte populations/groupes Phase 1 : moteur central unique —
 * sous-groupes, colonnes programme/sous-groupe, répartition auto simulée,
 * routes sans-groupe/groupe-étudiants, export affectations, stats unifiées.
 */
class PopulationsGroupesRefonteTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.refonte@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
    }

    public function test_filtre_sous_groupe_et_colonnes(): void
    {
        $groupe = Group::create([
            'code' => 'G1', 'nom' => 'G1', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'capacite' => 30, 'actif' => true,
        ]);
        $sg = SousGroupe::create([
            'groupe_id' => $groupe->id, 'code' => 'G1-TD1', 'nom' => 'TD1', 'type' => 'td', 'actif' => true,
        ]);
        $avec = $this->creerEtudiant('SG00001', ['groupe_id' => $groupe->id]);
        $sans = $this->creerEtudiant('SG00002', ['groupe_id' => $groupe->id]);
        app(AffectationService::class)->affecter($avec, $groupe, $this->annee, $this->admin->id, 'a', false, $sg->id);
        app(AffectationService::class)->affecter($sans, $groupe, $this->annee, $this->admin->id, 'b');

        $service = app(ListeEtudiantsService::class);
        $ids = $service->requete(['sous_groupe_id' => $sg->id], $this->admin)->pluck('etudiants.id')->all();

        $this->assertSame([$avec->id], $ids);

        $jeu = $service->jeuDonnees($service->requete([], $this->admin), $this->admin, ['matricule', 'groupe', 'sous_groupe', 'programme']);
        $this->assertContains('sous_groupe', $jeu['entetes']);
        $this->assertContains('programme', $jeu['entetes']);
    }

    public function test_repartition_auto_equilibree_sans_ecriture(): void
    {
        $g1 = Group::create(['code' => 'GA1', 'nom' => 'A1', 'faculte_id' => $this->faculte->id, 'niveau' => 'L1', 'capacite' => 2, 'actif' => true]);
        $g2 = Group::create(['code' => 'GA2', 'nom' => 'A2', 'faculte_id' => $this->faculte->id, 'niveau' => 'L1', 'capacite' => 2, 'actif' => true]);
        $ids = [];
        for ($i = 1; $i <= 4; $i++) {
            $ids[] = $this->creerEtudiant('AUTO000'.$i)->id;
        }

        $proposition = app(AffectationService::class)->repartitionAutomatique($ids, [$g1->id, $g2->id], $this->annee);

        $this->assertCount(2, $proposition['repartition'][$g1->id]);
        $this->assertCount(2, $proposition['repartition'][$g2->id]);
        $this->assertSame([], $proposition['non_affectes']);
        // Aucune écriture pendant la simulation.
        $this->assertSame(0, $g1->effectifActuel());
        $this->assertSame(0, $g2->effectifActuel());
    }

    public function test_repartition_auto_capacite_epuisee(): void
    {
        $g1 = Group::create(['code' => 'GB1', 'nom' => 'B1', 'faculte_id' => $this->faculte->id, 'niveau' => 'L1', 'capacite' => 1, 'actif' => true]);
        $ids = [$this->creerEtudiant('AUTOB01')->id, $this->creerEtudiant('AUTOB02')->id];

        $proposition = app(AffectationService::class)->repartitionAutomatique($ids, [$g1->id], $this->annee);

        $this->assertCount(1, $proposition['repartition'][$g1->id]);
        $this->assertCount(1, $proposition['non_affectes']);
    }

    public function test_routes_sans_groupe_et_groupe_etudiants(): void
    {
        $groupe = Group::create(['code' => 'GC1', 'nom' => 'C1', 'faculte_id' => $this->faculte->id, 'niveau' => 'L1', 'capacite' => 30, 'actif' => true]);
        $affecte = $this->creerEtudiant('RTE00001', ['groupe_id' => $groupe->id]);
        app(AffectationService::class)->affecter($affecte, $groupe, $this->annee, $this->admin->id, 'a');
        $orphelin = $this->creerEtudiant('RTE00002', ['groupe_id' => null]);

        $this->actingAs($this->admin)->get(route('admin.etudiants.sans-groupe'))->assertOk()
            ->assertSee($orphelin->num_inscription)->assertDontSee($affecte->num_inscription);

        $this->actingAs($this->admin)->get(route('admin.groupes.etudiants', $groupe))->assertOk()
            ->assertSee($affecte->num_inscription);
    }

    public function test_idor_groupe_etudiants_bloque(): void
    {
        $groupe = Group::create(['code' => 'GD1', 'nom' => 'D1', 'faculte_id' => $this->faculte->id, 'niveau' => 'L1', 'actif' => true]);
        $autre = Faculte::create(['nom' => 'FMP', 'code' => 'FMP', 'description' => 'T', 'active' => true]);
        $adminFaculte = User::creerCompteBackOffice([
            'name' => 'Fac', 'email' => 'fac.refonte@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $autre->id,
        ], 'admin_faculte');

        $this->actingAs($adminFaculte)->get(route('admin.groupes.etudiants', $groupe))->assertNotFound();
        $this->actingAs($adminFaculte)->get(route('admin.etudiants.sans-groupe'))->assertOk();
    }

    public function test_export_affectations_groupes(): void
    {
        $groupe = Group::create(['code' => 'GE1', 'nom' => 'E1', 'faculte_id' => $this->faculte->id, 'niveau' => 'L1', 'actif' => true]);
        $etudiant = $this->creerEtudiant('EXPAF01');
        app(AffectationService::class)->affecter($etudiant, $groupe, $this->annee, $this->admin->id, 'a');

        $this->actingAs($this->admin)->get(route('admin.donnees.export', ['entite' => 'affectations-groupes', 'format' => 'csv']))
            ->assertOk();
    }

    public function test_stats_unifiees_capacite_et_sans_groupe(): void
    {
        $groupe = Group::create(['code' => 'GS1', 'nom' => 'S1', 'faculte_id' => $this->faculte->id, 'niveau' => 'L1', 'capacite' => 10, 'actif' => true]);
        $etudiant = $this->creerEtudiant('STA00001');
        app(AffectationService::class)->affecter($etudiant, $groupe, $this->annee, $this->admin->id, 'a');
        $this->creerEtudiant('STA00002', ['groupe_id' => null]);

        $stats = app(PopulationStatsService::class)->statistiques($this->faculte->id, null);

        $this->assertSame(1, $stats['etudiants_sans_groupe']);
        $this->assertSame(10, $stats['capacite_totale']);
        $this->assertSame(9, $stats['places_disponibles']);
        $this->assertArrayHasKey('taux_remplissage_moyen', $stats);
    }

    public function test_index_etudiants_utilise_moteur_central(): void
    {
        $groupe = Group::create(['code' => 'GH1', 'nom' => 'H1', 'faculte_id' => $this->faculte->id, 'niveau' => 'L1', 'actif' => true]);
        $cible = $this->creerEtudiant('IDX00001', ['groupe_id' => $groupe->id]);

        $this->actingAs($this->admin)->get(route('admin.etudiants.index', ['groupe_id' => $groupe->id]))
            ->assertOk()->assertSee($cible->num_inscription);
    }

    public function test_stats_groupes_unifiees_affectations_actives(): void
    {
        $groupe = Group::create([
            'code' => 'GS2', 'nom' => 'S2', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'capacite' => 10, 'actif' => true,
        ]);
        $etudiant = $this->creerEtudiant('STT00001');
        app(AffectationService::class)->affecter($etudiant, $groupe, $this->annee, $this->admin->id, 'a');

        $stats = $this->actingAs($this->admin)->get(route('admin.groupes.index'))
            ->assertOk()->viewData('stats');

        $this->assertSame(1, $stats['effectif']);
        $this->assertSame(10, $stats['capacite_totale']);
        $this->assertSame(9, $stats['places_disponibles']);
    }

    private function creerEtudiant(string $cin, array $attributs = []): Etudiant
    {
        $etudiant = Etudiant::create(array_merge([
            'cin' => $cin, 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => strtolower($cin).'@refonte.test',
            'date_naissance' => '2004-01-01', 'sexe' => 'F', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ], $attributs));
        $etudiant->definirStatut('valide');
        $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id, 'niveau' => 'L1', 'statut' => 'en_cours',
        ]);

        return $etudiant;
    }
}
