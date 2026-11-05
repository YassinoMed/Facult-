<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\Iam\HabilitationIam;
use App\Models\InscriptionAcademique;
use App\Models\User;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use App\Modules\SimulationTemporelle\Models\ExecutionTemporelle;
use App\Modules\SimulationTemporelle\Models\ScenarioTemporel;
use App\Modules\SimulationTemporelle\Models\SnapshotExecution;
use App\Support\Iam\MoteurAutorisation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * ST-14 / SIM-02 — baisse des admissions.
 *
 * Les résultats attendus sont calculés À LA MAIN dans les commentaires
 * (pas de réutilisation de la formule du moteur dans les assertions).
 *
 * Périmètre synthétique : L1=10, L2=20, L3=30 (total 60), faculté A.
 */
class SimulationTemporelleSim02Test extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private AnneeUniversitaire $annee;

    private User $admin;

    private User $adminB;

    private User $sansPermission;

    private Niveau $niveauL1;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('modules.simulation-temporelle.active', true);

        $this->faculteA = Faculte::create(['nom' => 'Sciences', 'code' => 'FST', 'active' => true]);
        $this->faculteB = Faculte::create(['nom' => 'Droit', 'code' => 'FDEG', 'active' => true]);
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'SIM02', 'email' => 'sim02-'.uniqid().'@u.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->adminB = User::creerCompteBackOffice([
            'name' => 'SIM02-B', 'email' => 'sim02b-'.uniqid().'@u.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteB->id,
        ], 'admin_faculte');
        $this->sansPermission = User::creerCompteBackOffice([
            'name' => 'SIM02-N', 'email' => 'sim02n-'.uniqid().'@u.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->habiliter($this->admin);
        $this->habiliter($this->adminB);

        // Effectifs réels synthétiques de la faculté A : L1=10, L2=20, L3=30.
        $programme = Programme::create([
            'faculte_id' => $this->faculteA->id, 'code' => 'PFST2', 'nom' => 'Prog',
            'statut' => 'actif', 'actif' => true, 'duree_annees' => 3,
        ]);
        foreach (['L1' => 1, 'L2' => 2, 'L3' => 3] as $code => $ordre) {
            $niveau = Niveau::create([
                'programme_id' => $programme->id, 'code' => $code, 'nom' => $code,
                'ordre' => $ordre, 'actif' => true,
            ]);
            if ($code === 'L1') {
                $this->niveauL1 = $niveau;
            }
            for ($i = 0; $i < 10 * $ordre; $i++) {
                $etudiant = Etudiant::create([
                    'cin' => 'CIN-'.uniqid(), 'nom' => 'N'.uniqid(), 'prenom' => 'P',
                    'email' => 'e-'.uniqid().'@u.test', 'sexe' => 'M',
                    'faculte_id' => $this->faculteA->id, 'niveau' => $code, 'annee_academique' => '2026-2027',
                    'adresse' => 'Rue', 'ville' => 'Dakar', 'code_postal' => '10000', 'annee_bac' => '2024',
                ]);
                InscriptionAcademique::create([
                    'etudiant_id' => $etudiant->id,
                    'programme_id' => $programme->id,
                    'niveau' => $code,
                    'niveau_id' => $niveau->id,
                    'annee_universitaire_id' => $this->annee->id,
                    'statut' => 'valide',
                ]);
            }
        }

        // Faculté B : un programme suffit pour l'isolation (aucun effectif requis).
        Programme::create([
            'faculte_id' => $this->faculteB->id, 'code' => 'PFDEG2', 'nom' => 'Prog B',
            'statut' => 'actif', 'actif' => true, 'duree_annees' => 3,
        ]);
    }

    private function habiliter(User $u): void
    {
        foreach (['simulation.view', 'simulation.gerer', 'simulation.executer',
            'simulation.export', 'simulation.explication', 'simulation.gouvernance.proposer',
            'simulation.gouvernance.decider'] as $permission) {
            HabilitationIam::create([
                'user_id' => $u->id,
                'permission_code' => $permission,
                'statut' => HabilitationIam::STATUT_ACTIVE,
                'debut_le' => now(),
            ]);
        }

        MoteurAutorisation::invalider(null);
    }

    /** @param array<string, mixed> $criteres */
    private function creerScenario(array $criteres, array $contraintes = [], array $objectifs = []): ScenarioTemporel
    {
        return ScenarioTemporel::create([
            'code' => 'SIM02-'.uniqid(), 'libelle' => 'Baisse SIM-02',
            'domaine' => 'effectifs_cohortes', 'faculte_id' => $this->faculteA->id,
            'criteres' => $criteres, 'contraintes' => $contraintes, 'objectifs' => $objectifs,
            'version' => 1, 'cree_par' => $this->admin->id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function executer(ScenarioTemporel $scenario, string $graine = 'sim02', int $variantes = 1, ?User $acteur = null): array
    {
        return $this->actingAs($acteur ?? $this->admin)
            ->postJson(route('admin.simulation-temporelle.executer', ['id' => $scenario->id]), [
                'annee_universitaire_id' => $this->annee->id,
                'graine' => $graine,
                'nb_variantes' => $variantes,
            ])->assertOk()->json('execution');
    }

    /* ── E. Validation mathématique indépendante ─────────────────────── */

    public function test_sim02_baisse_composee_avec_reference_calculee_independamment(): void
    {
        // Référence manuelle (fichier, calcul hors moteur) :
        // base L1 = 10 ; delta = -0.1 ; horizon = 3 ;
        // taux explicites {passage: 0, redoublement: 1, abandon: 0} = progression gelée.
        // p1 : round(10 × 0.9^1) = 9   → L1 = 10 + 9 = 19, total = 19+20+30 = 69
        // p2 : round(10 × 0.9^2) = 8   → L1 = 19 + 8 = 27, total = 27+20+30 = 77
        // p3 : round(10 × 0.9^3) = 7   → L1 = 27 + 7 = 34, total = 34+20+30 = 84
        $scenario = $this->creerScenario([
            'delta_admissions' => -0.1,
            'bornes' => ['min' => -0.5, 'max' => 0.0],
            'horizon_annees' => 3,
            'taux' => ['passage' => 0.0, 'redoublement' => 1.0, 'abandon' => 0.0],
        ]);

        $execution = $this->executer($scenario, 'sim02-reference');
        $variante = $execution['resultat']['classement'][0];

        $this->assertSame([60, 69, 77, 84], $variante['totaux']);
        $this->assertSame(84, $variante['total_final']);
        $this->assertSame([
            ['pas' => 1, 'admissions' => 9],
            ['pas' => 2, 'admissions' => 8],
            ['pas' => 3, 'admissions' => 7],
        ], $variante['admissions_par_periode']);

        // La baisse est strictement décroissante sur les admissions.
        $admissions = array_column($variante['admissions_par_periode'], 'admissions');
        $this->assertSame([9, 8, 7], $admissions);
        $this->assertLessThan($admissions[0], $admissions[1]);
        $this->assertLessThan($admissions[1], $admissions[2]);

        // Référence historique : l'état initial n'est jamais réécrit.
        $this->assertSame(60, $execution['resultat']['etat_initial']['total_initial']);
        $this->assertSame(['L1' => 10, 'L2' => 20, 'L3' => 30], $execution['resultat']['etat_initial']['cohortes']);
    }

    public function test_sim02_borne_minimale_et_admissions_jamais_negatives(): void
    {
        // Référence manuelle (delta = -0.5 ; taux gelés) :
        // p1 : round(10 × 0.5) = 5       → L1 15, total 65
        // p2 : round(10 × 0.25) = 3      → L1 18, total 68
        // p3 : round(10 × 0.125) = 1     → L1 19, total 69
        // p4 : round(10 × 0.0625) = 1    → L1 20, total 70
        // p5 : round(10 × 0.03125) = 0   → L1 20, total 70
        // p6 : round(10 × 0.015625) = 0  → L1 20, total 70
        $scenario = $this->creerScenario([
            'delta_admissions' => -0.5,
            'bornes' => ['min' => -0.5, 'max' => 0.0],
            'horizon_annees' => 6,
            'taux' => ['passage' => 0.0, 'redoublement' => 1.0, 'abandon' => 0.0],
        ]);

        $execution = $this->executer($scenario, 'sim02-limite');
        $variante = $execution['resultat']['classement'][0];

        $this->assertSame([60, 65, 68, 69, 70, 70, 70], $variante['totaux']);
        $this->assertSame([5, 3, 1, 1, 0, 0], array_column($variante['admissions_par_periode'], 'admissions'));
        $this->assertGreaterThanOrEqual(0, min(array_column($variante['admissions_par_periode'], 'admissions')));
        $this->assertCount(6, $variante['admissions_par_periode']);
    }

    public function test_sim02_conservation_avec_taux_explicites(): void
    {
        // Référence manuelle (delta = -0.2 ; taux 0.5 / 0.3 / 0.2) :
        // p1 : admissions round(10 × 0.8) = 8
        //      L3 30 → 15 partent, 9 redoublent ; L2 20 → 10/6 ; L1 10 → 5/3
        //      L1 = 3 + 8 = 11 ; L2 = 6 + 5 = 11 ; L3 = 9 + 10 = 19 ; total 41
        //      Conservation : 60 + 8 − 15 diplômés − 12 abandons = 41
        // p2 : admissions round(10 × 0.64) = 6
        //      L1 = 3 + 6 = 9 ; L2 = 3 + 6 = 9 ; L3 = 6 + 10 = 12 ; total 30
        //      Conservation : 41 + 6 − 10 diplômés − 7 abandons = 30
        $scenario = $this->creerScenario([
            'delta_admissions' => -0.2,
            'bornes' => ['min' => -0.5, 'max' => 0.0],
            'horizon_annees' => 2,
            'taux' => ['passage' => 0.5, 'redoublement' => 0.3, 'abandon' => 0.2],
        ]);

        $execution = $this->executer($scenario, 'sim02-conservation');
        $variante = $execution['resultat']['classement'][0];

        $this->assertSame([60, 41, 30], $variante['totaux']);
        $this->assertSame([
            ['pas' => 1, 'admissions' => 8],
            ['pas' => 2, 'admissions' => 6],
        ], $variante['admissions_par_periode']);

        $snapshots = SnapshotExecution::where('execution_temporelle_id', $execution['id'])
            ->orderBy('pas')->get();
        $this->assertSame(['L1' => 11, 'L2' => 11, 'L3' => 19], $snapshots->firstWhere('pas', 1)->etat['cohortes']);
        $this->assertSame(['L1' => 9, 'L2' => 9, 'L3' => 12], $snapshots->firstWhere('pas', 2)->etat['cohortes']);

        foreach ($snapshots as $snapshot) {
            $this->assertSame(
                $snapshot->etat['total'],
                array_sum($snapshot->etat['cohortes']),
                'Le total du snapshot doit égaler la somme des niveaux.'
            );
            $this->assertGreaterThanOrEqual(0, min($snapshot->etat['cohortes']));
        }
    }

    /* ── B/D. Déterminisme, idempotence, graine ──────────────────────── */

    public function test_sim02_memes_entrees_idempotentes_et_graine_reproductible(): void
    {
        $scenario = $this->creerScenario([
            'delta_admissions' => -0.1,
            'bornes' => ['min' => -0.5, 'max' => 0.0],
            'horizon_annees' => 3,
        ]);

        $premier = $this->executer($scenario, 'graine-fixe');
        $second = $this->executer($scenario, 'graine-fixe');

        $this->assertSame($premier['id'], $second['id']);
        $this->assertSame($premier['hash_resultat'], $second['hash_resultat']);
        $this->assertSame(
            $premier['resultat']['classement'][0]['admissions_par_periode'],
            $second['resultat']['classement'][0]['admissions_par_periode']
        );
        $this->assertSame(1, ExecutionTemporelle::where('scenario_temporel_id', $scenario->id)->count());

        // Graine différente = nouvelle exécution, mais la variante de
        // référence (index 0) reste strictement identique : le delta saisi
        // ne dépend pas de la graine.
        $autre = $this->executer($scenario, 'graine-autre');
        $this->assertNotSame($premier['id'], $autre['id']);
        $this->assertSame(
            $premier['resultat']['classement'][0]['admissions_par_periode'],
            $autre['resultat']['classement'][0]['admissions_par_periode']
        );
    }

    /* ── E. Capacité et absence de modification des groupes réels ────── */

    public function test_sim02_capacite_et_absence_de_modification_des_groupes(): void
    {
        Group::create([
            'code' => 'SIM02-G1', 'nom' => 'Groupe SIM-02', 'faculte_id' => $this->faculteA->id,
            'niveau' => 'L1', 'actif' => true, 'niveau_id' => $this->niveauL1->id, 'capacite' => 15,
        ]);

        $scenario = $this->creerScenario([
            'delta_admissions' => -0.1,
            'bornes' => ['min' => -0.5, 'max' => 0.0],
            'horizon_annees' => 3,
            'taux' => ['passage' => 0.0, 'redoublement' => 1.0, 'abandon' => 0.0],
            'niveau_id' => $this->niveauL1->id,
        ]);

        $execution = $this->executer($scenario, 'sim02-capacite');

        // Le périmètre `niveau_id` restreint la simulation au niveau L1 :
        // 10 initiaux + 9+8+7 = 34 ; 34/15 → 3 groupes requis, 1 installé.
        $this->assertSame(34, $execution['resultat']['classement'][0]['total_final']);
        $capacites = $execution['resultat']['capacites'];
        $this->assertFalse($capacites['capacite_groupe_hypothese']); // capacité issue d'un groupe réel
        $this->assertSame(15, $capacites['capacite_groupe_reference']);
        $this->assertSame(3, $capacites['groupes_theoriques_requis_total']);
        $this->assertSame(2, $capacites['deficit_groupes']);
        $this->assertTrue($capacites['depassement_capacite']);
        $this->assertSame(19, $capacites['places_manquantes']);

        // Le groupe réel n'a pas été modifié par la simulation.
        $groupe = Group::where('code', 'SIM02-G1')->firstOrFail();
        $this->assertSame(15, (int) $groupe->capacite);
        $this->assertTrue((bool) $groupe->actif);
    }

    /* ── F. Sécurité et lecture seule ────────────────────────────────── */

    public function test_sim02_permissions_et_isolation_facultaire(): void
    {
        $scenario = $this->creerScenario([
            'delta_admissions' => -0.1,
            'bornes' => ['min' => -0.5, 'max' => 0.0],
            'horizon_annees' => 1,
        ]);

        // Sans permission d'exécution : 403.
        $this->actingAs($this->sansPermission)
            ->postJson(route('admin.simulation-temporelle.executer', ['id' => $scenario->id]), [
                'annee_universitaire_id' => $this->annee->id, 'graine' => 'x', 'nb_variantes' => 1,
            ])->assertForbidden();

        // Admin faculté B sur un scénario faculté A : 403 (jamais de fuite de contenu).
        $this->actingAs($this->adminB)
            ->postJson(route('admin.simulation-temporelle.executer', ['id' => $scenario->id]), [
                'annee_universitaire_id' => $this->annee->id, 'graine' => 'x', 'nb_variantes' => 1,
            ])->assertForbidden();

        $this->assertSame(0, ExecutionTemporelle::count());
    }

    public function test_sim02_zero_ecriture_metier_et_tables_techniques_seules(): void
    {
        $scenario = $this->creerScenario([
            'delta_admissions' => -0.1,
            'bornes' => ['min' => -0.5, 'max' => 0.0],
            'horizon_annees' => 2,
        ]);

        $avant = [
            'etudiants' => Etudiant::count(),
            'inscriptions' => InscriptionAcademique::count(),
            'groupes' => Group::count(),
            'facultes' => Faculte::count(),
            'niveaux' => Niveau::count(),
            'scenarios' => ScenarioTemporel::count(),
        ];
        $etudiantTemoin = Etudiant::query()->orderBy('id')->firstOrFail();
        $empreinteEtudiant = $etudiantTemoin->getAttributes();

        $this->executer($scenario, 'sim02-ro');

        $this->assertSame($avant, [
            'etudiants' => Etudiant::count(),
            'inscriptions' => InscriptionAcademique::count(),
            'groupes' => Group::count(),
            'facultes' => Faculte::count(),
            'niveaux' => Niveau::count(),
            'scenarios' => ScenarioTemporel::count(),
        ]);
        $this->assertSame($empreinteEtudiant, $etudiantTemoin->fresh()->getAttributes());

        // Seules les tables techniques du module sont écrites.
        $this->assertSame(1, ExecutionTemporelle::count());
        $this->assertSame(3, SnapshotExecution::count()); // pas 0..2
    }
}
