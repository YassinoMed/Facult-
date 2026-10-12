<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use App\Services\Affectations\MassAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOOP 38 — D-005 : Tests de contrat et de non-régression sur la compaction
 * du payload d'analyse d'affectation de masse (MassAssignmentService).
 */
class AffectationCompactionContractTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private AnneeUniversitaire $annee;

    private Programme $programmeInfo;

    private Programme $programmeMath;

    private Group $groupeA1;

    private Group $groupeA2;

    private Group $groupeB1;

    private Group $groupeMath;

    private Group $groupeL2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculteA = Faculte::create(['nom' => 'Faculté A', 'code' => 'FST-A', 'active' => true]);
        $this->faculteB = Faculte::create(['nom' => 'Faculté B', 'code' => 'FST-B', 'active' => true]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Admin Scolarite',
            'email' => 'admin.scol@universite.tn',
            'password' => 'Secret@123',
        ], 'admin_scolarite');

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->programmeInfo = Programme::create([
            'faculte_id' => $this->faculteA->id,
            'code' => 'LIC-INFO',
            'nom' => 'Licence Informatique',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
        ]);

        $this->programmeMath = Programme::create([
            'faculte_id' => $this->faculteA->id,
            'code' => 'LIC-MATH',
            'nom' => 'Licence Mathématiques',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
        ]);

        $this->groupeA1 = Group::create([
            'code' => 'L1-INFO-G1',
            'nom' => 'Groupe Info 1',
            'faculte_id' => $this->faculteA->id,
            'programme_id' => $this->programmeInfo->id,
            'niveau' => 'L1',
            'capacite' => 20,
            'actif' => true,
        ]);

        $this->groupeA2 = Group::create([
            'code' => 'L1-INFO-G2',
            'nom' => 'Groupe Info 2',
            'faculte_id' => $this->faculteA->id,
            'programme_id' => $this->programmeInfo->id,
            'niveau' => 'L1',
            'capacite' => 20,
            'actif' => true,
        ]);

        $this->groupeB1 = Group::create([
            'code' => 'L1-INFO-FB',
            'nom' => 'Groupe Faculté B',
            'faculte_id' => $this->faculteB->id,
            'programme_id' => null,
            'niveau' => 'L1',
            'capacite' => 20,
            'actif' => true,
        ]);

        $this->groupeMath = Group::create([
            'code' => 'L1-MATH-G1',
            'nom' => 'Groupe Math',
            'faculte_id' => $this->faculteA->id,
            'programme_id' => $this->programmeMath->id,
            'niveau' => 'L1',
            'capacite' => 20,
            'actif' => true,
        ]);

        $this->groupeL2 = Group::create([
            'code' => 'L2-INFO-G1',
            'nom' => 'Groupe L2 Info',
            'faculte_id' => $this->faculteA->id,
            'programme_id' => $this->programmeInfo->id,
            'niveau' => 'L2',
            'capacite' => 20,
            'actif' => true,
        ]);
    }

    private function creerEtudiant(string $cin, ?int $programmeId = null, string $niveau = 'L1', ?int $faculteId = null): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin,
            'num_inscription' => 'INS-'.$cin,
            'nom' => 'Nom'.$cin,
            'prenom' => 'Prenom'.$cin,
            'email' => 'etudiant.'.$cin.'@universite.tn',
            'date_naissance' => '2004-03-15',
            'sexe' => 'M',
            'faculte_id' => $faculteId ?? $this->faculteA->id,
            'programme_id' => $programmeId ?? $this->programmeInfo->id,
            'niveau' => $niveau,
            'annee_academique' => '2026-2027',
            'adresse' => 'Avenue Habib Bourguiba',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2023',
            'password' => 'Secret@123',
        ]);

        $etudiant->definirStatut('valide');

        $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id,
            'niveau' => $niveau,
            'statut' => 'valide',
        ]);

        return $etudiant;
    }

    public function test_compatibilite_et_incompatibilite_strictes(): void
    {
        $etudiant = $this->creerEtudiant('11223344');

        $service = app(MassAssignmentService::class);
        $analyse = $service->analyser([
            'annee_universitaire_id' => $this->annee->id,
            'faculte_id' => $this->faculteA->id,
            'programme_id' => $this->programmeInfo->id,
            'niveau' => 'L1',
            'etudiant_ids' => [$etudiant->id],
        ], $this->admin);

        $this->assertSame(1, $analyse['assigned_count']);
        $proposition = $analyse['propositions'][0];

        // 1. Groupes compatibles compacts : liste d'IDs entiers (D-005)
        $compatibles = $proposition['compatible_groups'];
        $this->assertIsArray($compatibles);
        $this->assertTrue(array_is_list($compatibles), 'compatible_groups doit être une liste compacte d IDs entiers');
        $this->assertContains($this->groupeA1->id, $compatibles);
        $this->assertContains($this->groupeA2->id, $compatibles);
        $this->assertNotContains($this->groupeB1->id, $compatibles, 'Faculté différente doit être exclue');
        $this->assertNotContains($this->groupeMath->id, $compatibles, 'Programme différent doit être exclu');
        $this->assertNotContains($this->groupeL2->id, $compatibles, 'Niveau différent doit être exclu');

        // 2. Dictionnaire partagé groups_map présent au niveau de l'analyse
        $this->assertArrayHasKey('groups_map', $analyse, 'L analyse doit fournir le dictionnaire partagé groups_map');
        $this->assertArrayHasKey($this->groupeA1->id, $analyse['groups_map']);
        $this->assertStringContainsString('L1-INFO-G1', $analyse['groups_map'][$this->groupeA1->id]);
    }

    public function test_equilibrage_capacite_deterministe_et_idempotent(): void
    {
        // 4 étudiants pour 2 groupes
        $e1 = $this->creerEtudiant('50000001');
        $e2 = $this->creerEtudiant('50000002');
        $e3 = $this->creerEtudiant('50000003');
        $e4 = $this->creerEtudiant('50000004');

        $service = app(MassAssignmentService::class);
        $criteres = [
            'annee_universitaire_id' => $this->annee->id,
            'faculte_id' => $this->faculteA->id,
            'programme_id' => $this->programmeInfo->id,
            'niveau' => 'L1',
            'etudiant_ids' => [$e1->id, $e2->id, $e3->id, $e4->id],
        ];

        $analyse1 = $service->analyser($criteres, $this->admin);
        $analyse2 = $service->analyser($criteres, $this->admin);

        // Idempotence : décisions et scores exactement identiques
        $this->assertSame($analyse1['assigned_count'], $analyse2['assigned_count']);
        $this->assertSame(4, $analyse1['assigned_count']);

        $groupesAffectes1 = array_column($analyse1['propositions'], 'proposed_group_id');
        $groupesAffectes2 = array_column($analyse2['propositions'], 'proposed_group_id');
        $this->assertSame($groupesAffectes1, $groupesAffectes2, 'L analyse doit être strictement idempotente');

        // Équilibrage : 2 dans G1 et 2 dans G2
        $counts = array_count_values($groupesAffectes1);
        $this->assertSame(2, $counts[$this->groupeA1->id]);
        $this->assertSame(2, $counts[$this->groupeA2->id]);
    }

    public function test_capacite_epuisee_genere_anomalie_sans_proposition(): void
    {
        $this->groupeA1->update(['capacite' => 1]);
        $this->groupeA2->update(['capacite' => 1]);

        $e1 = $this->creerEtudiant('60000001');
        $e2 = $this->creerEtudiant('60000002');
        $e3 = $this->creerEtudiant('60000003');

        $service = app(MassAssignmentService::class);
        $analyse = $service->analyser([
            'annee_universitaire_id' => $this->annee->id,
            'faculte_id' => $this->faculteA->id,
            'programme_id' => $this->programmeInfo->id,
            'niveau' => 'L1',
            'etudiant_ids' => [$e1->id, $e2->id, $e3->id],
        ], $this->admin);

        $this->assertSame(2, $analyse['assigned_count']);
        $this->assertSame(1, $analyse['unassigned_count']);
        $this->assertCount(1, $analyse['anomalies']);
        $this->assertSame('CAPACITE_EPUISEE', $analyse['anomalies'][0]['code']);
    }
}
