<?php

namespace Tests\Feature;

use App\Models\AffectationProposal;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\HistoriqueAcademique;
use App\Models\User;
use App\Modules\Schedule\Models\Group;
use App\Services\AffectationAutomatiqueService;
use App\Services\AutomationMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOOP C/D — Reprise documentée (reessayer) + métriques dérivées sans PII.
 */
class AutomationMetricsTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private Faculte $autreFaculte;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->autreFaculte = Faculte::create(['nom' => 'FLSH', 'code' => 'FLSH', 'description' => 'T', 'active' => true]);
    }

    private function creerEtudiant(string $cin, ?Faculte $faculte = null): Etudiant
    {
        return Etudiant::create([
            'cin' => $cin, 'nom' => 'Benali', 'prenom' => 'Sara', 'email' => strtolower($cin).'@met.test',
            'date_naissance' => '2004-01-01', 'sexe' => 'F', 'faculte_id' => ($faculte ?? $this->faculte)->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
    }

    private function tracer(Etudiant $etudiant, string $action): void
    {
        HistoriqueAcademique::create([
            'etudiant_id' => $etudiant->id, 'action' => $action, 'entite' => 'inscription',
            'created_at' => now(),
        ]);
    }

    public function test_taux_affectation_auto_derive_des_audits(): void
    {
        $e1 = $this->creerEtudiant('MET00001');
        $e2 = $this->creerEtudiant('MET00002');
        $e3 = $this->creerEtudiant('MET00003');
        $this->tracer($e1, 'affectation.auto_assignee');
        $this->tracer($e2, 'affectation.auto_assignee');
        $this->tracer($e3, 'affectation.auto_non_assignee');

        $metriques = app(AutomationMetricsService::class)->tauxAffectationAuto();

        $this->assertSame(2, $metriques['assignees']);
        $this->assertSame(1, $metriques['non_assignees']);
        $this->assertSame(3, $metriques['total']);
        $this->assertEqualsWithDelta(66.7, $metriques['taux'], 0.1);
    }

    public function test_taux_vide_null_jamais_zero_trompeur(): void
    {
        $metriques = app(AutomationMetricsService::class)->tauxAffectationAuto();

        $this->assertSame(0, $metriques['total']);
        $this->assertNull($metriques['taux']);
    }

    public function test_taux_scope_faculte(): void
    {
        $this->tracer($this->creerEtudiant('MET00004'), 'affectation.auto_assignee');
        $this->tracer($this->creerEtudiant('MET00005', $this->autreFaculte), 'affectation.auto_non_assignee');

        $metriques = app(AutomationMetricsService::class)->tauxAffectationAuto($this->faculte->id);

        $this->assertSame(1, $metriques['total']);
        $this->assertSame(1, $metriques['assignees']);
    }

    public function test_propositions_par_statut_et_intervention(): void
    {
        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $etudiant = $this->creerEtudiant('MET00006');
        $groupe = Group::create([
            'code' => 'L1-MET', 'nom' => 'L1-MET', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'capacite' => 30, 'actif' => true,
        ]);
        foreach (['acceptee', 'modifiee', 'rejetee', 'en_attente'] as $statut) {
            AffectationProposal::create([
                'student_id' => $etudiant->id, 'annee_universitaire_id' => $annee->id,
                'proposed_group_id' => $groupe->id,
                'mode' => 'automatique', 'score' => 80, 'certitude' => 'elevee', 'statut' => $statut,
            ]);
        }

        $metriques = app(AutomationMetricsService::class)->propositions();

        $this->assertSame(4, $metriques['total']);
        $this->assertSame(1, $metriques['par_statut']['acceptee']);
        // Intervention = tout sauf acceptée directement.
        $this->assertEqualsWithDelta(75.0, $metriques['taux_intervention'], 0.1);

        $scopees = app(AutomationMetricsService::class)->propositions($this->autreFaculte->id);
        $this->assertSame(0, $scopees['total']);
        $this->assertNull($scopees['taux_intervention']);
    }

    public function test_reessayer_rejoue_sans_doublon(): void
    {
        $scolarite = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.met@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $etudiant = $this->creerEtudiant('MET00007');
        $inscription = $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $annee->id, 'niveau' => 'L1', 'statut' => 'en_cours',
        ]);

        // Sans groupe compatible : tentative + reprise documentées, sans doublon.
        $service = app(AffectationAutomatiqueService::class);
        $premier = $service->tenter($inscription, $scolarite->id);
        $second = $service->reessayer($inscription->fresh(), $scolarite->id);

        $this->assertSame($premier['statut'], $second['statut']);
    }
}
