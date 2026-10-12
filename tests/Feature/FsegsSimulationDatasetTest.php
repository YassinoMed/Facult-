<?php

namespace Tests\Feature;

use App\Models\AffectationGroupe;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\User;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use App\Services\Affectations\AssignmentEligibilityResolver;
use App\Services\Affectations\AssignmentSimulationService;
use App\Services\AffectationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prompt master §30 : les 10 tests, taxonomie existante
 * (REGISTRATION_NOT_FOUND, LEVEL_MISMATCH, PROGRAM_MISMATCH,
 * GROUP_FULL, verrouille, simulation sans écriture, scope, IDOR).
 */
class FsegsSimulationDatasetTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Group $groupeL2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['nom' => 'FSEGS', 'code' => 'FSEGS', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'S', 'email' => 's.sim@u.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->groupeL2 = Group::create([
            'code' => 'GES-L2-G01', 'nom' => 'G L2', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L2', 'capacite' => 30, 'actif' => true,
        ]);
    }

    private function etudiant(string $cin, string $niveau = 'L2', bool $inscrit = true): Etudiant
    {
        static $i = 0;
        $i++;

        $e = Etudiant::create([
            'cin' => $cin, 'nom' => 'N'.$i, 'prenom' => 'P'.$i, 'email' => strtolower($cin).'@sim.test',
            'date_naissance' => '2004-01-01', 'sexe' => 'F', 'faculte_id' => $this->faculte->id,
            'niveau' => $niveau, 'annee_academique' => '2026-2027', 'adresse' => 'X',
            'ville' => 'T', 'code_postal' => '1', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
        $e->definirStatut('valide');

        if ($inscrit) {
            InscriptionAcademique::create([
                'etudiant_id' => $e->id, 'annee_universitaire_id' => $this->annee->id,
                'niveau' => $niveau, 'statut' => 'en_cours', 'type' => 'premiere', 'regime' => 'initiale',
            ]);
        }

        return $e;
    }

    private function codes(Etudiant $e, Group $g): array
    {
        return array_column(array_filter(
            app(AffectationService::class)->controler($e, $g, $this->annee),
            fn (array $c) => $c['bloquant']
        ), 'code');
    }

    public function test_1_inscrit_devient_candidat(): void
    {
        $e = $this->etudiant('SIM00001');

        $this->assertContains($e->id, app(AssignmentEligibilityResolver::class)
            ->eligibles([$e->id], $this->annee)->map(fn (Etudiant $x) => $x->id)->all());
    }

    public function test_2_sans_inscription_bloque(): void
    {
        $this->assertContains('REGISTRATION_NOT_FOUND', $this->codes($this->etudiant('SIM00002', 'L2', false), $this->groupeL2));
    }

    public function test_3_l3_vers_l2_bloque(): void
    {
        $this->assertContains('LEVEL_MISMATCH', $this->codes($this->etudiant('SIM00003', 'L3'), $this->groupeL2));
    }

    public function test_4_gestion_vers_eco_bloque(): void
    {
        $programme = Programme::create([
            'faculte_id' => $this->faculte->id, 'code' => 'ECOX', 'nom' => 'Économie',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $autreProgramme = Programme::create([
            'faculte_id' => $this->faculte->id, 'code' => 'GESX', 'nom' => 'Gestion',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $e = $this->etudiant('SIM00004');
        $e->update(['programme_id' => $programme->id]);
        $this->groupeL2->update(['programme_id' => $autreProgramme->id]);

        $this->assertContains('PROGRAM_MISMATCH', $this->codes($e->fresh(), $this->groupeL2->fresh()));
    }

    public function test_5_groupe_plein_refuse(): void
    {
        $this->groupeL2->update(['capacite' => 0]);

        $this->assertContains('GROUP_FULL', $this->codes($this->etudiant('SIM00005'), $this->groupeL2->fresh()));
    }

    public function test_6_verrouillee_non_deplacee(): void
    {
        $e = $this->etudiant('SIM00006');
        app(AffectationService::class)->affecter($e, $this->groupeL2, $this->annee, $this->admin->id, 'x');
        AffectationGroupe::where('etudiant_id', $e->id)->update(['verrouille' => true]);

        $g2 = Group::create([
            'code' => 'GES-L2-G02', 'nom' => 'G2', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L2', 'capacite' => 30, 'actif' => true,
        ]);

        $this->expectException(\DomainException::class);
        app(AffectationService::class)->affecter($e, $g2, $this->annee, $this->admin->id, 'y');
    }

    public function test_7_simulation_sans_ecriture(): void
    {
        $e = $this->etudiant('SIM00007');
        $avant = AffectationGroupe::count();

        app(AssignmentSimulationService::class)->simuler([
            'annee_id' => $this->annee->id, 'groupe_ids' => [$this->groupeL2->id], 'etudiant_ids' => [$e->id],
        ], $this->admin->id, null);

        $this->assertSame($avant, AffectationGroupe::count());
    }

    public function test_8_simulation_coherente_propose(): void
    {
        $e = $this->etudiant('SIM00008');

        $simulation = app(AssignmentSimulationService::class)->simuler([
            'annee_id' => $this->annee->id, 'groupe_ids' => [$this->groupeL2->id], 'etudiant_ids' => [$e->id],
        ], $this->admin->id, null);

        $this->assertSame(1, $simulation->affectes);
    }

    public function test_9_scope_et_10_idor(): void
    {
        $e = $this->etudiant('SIM00009');
        $simulation = app(AssignmentSimulationService::class)->simuler([
            'annee_id' => $this->annee->id, 'groupe_ids' => [$this->groupeL2->id], 'etudiant_ids' => [$e->id],
        ], $this->admin->id, null);

        $autre = Faculte::create(['nom' => 'FLSH', 'code' => 'FLSH', 'description' => 'T', 'active' => true]);
        $adminFac = User::creerCompteBackOffice([
            'name' => 'F', 'email' => 'f.sim@u.test', 'password' => 'Motdepasse@123', 'faculte_id' => $autre->id,
        ], 'admin_faculte');

        // Simulation + groupe d'une autre faculté : 404 (pas d'oracle).
        $this->actingAs($adminFac)->get(route('admin.affectations.automatique.show', $simulation))->assertNotFound();
        // Étudiant d'une autre faculté : 403/404 via policy.
        $this->actingAs($adminFac)->get(route('admin.etudiants.show', $e))->assertForbidden();
    }
}
