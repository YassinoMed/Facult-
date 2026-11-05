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
 * ST-14 / SIM-03 — gel des admissions (extension_temporelle).
 *
 * Les résultats attendus sont calculés À LA MAIN dans les commentaires
 * (pas de réutilisation de la formule du moteur dans les assertions).
 *
 * Périmètre synthétique : L1=10, L2=20, L3=30 (total 60), faculté A.
 */
class SimulationTemporelleSim03Test extends TestCase
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
            'name' => 'SIM03', 'email' => 'sim03-'.uniqid().'@u.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->adminB = User::creerCompteBackOffice([
            'name' => 'SIM03-B', 'email' => 'sim03b-'.uniqid().'@u.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteB->id,
        ], 'admin_faculte');
        $this->sansPermission = User::creerCompteBackOffice([
            'name' => 'SIM03-N', 'email' => 'sim03n-'.uniqid().'@u.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->habiliter($this->admin);
        $this->habiliter($this->adminB);

        // Effectifs réels synthétiques de la faculté A : L1=10, L2=20, L3=30.
        $programme = Programme::create([
            'faculte_id' => $this->faculteA->id, 'code' => 'PFST3', 'nom' => 'Prog',
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
    }

    private function habiliter(User $user): void
    {
        $permissions = [
            'simulation.view', 'simulation.gerer', 'simulation.executer',
            'simulation.export', 'simulation.explication',
            'simulation.gouvernance.proposer', 'simulation.gouvernance.decider',
        ];
        foreach ($permissions as $code) {
            HabilitationIam::create([
                'user_id' => $user->id,
                'permission_code' => $code,
                'statut' => HabilitationIam::STATUT_ACTIVE,
                'debut_le' => now(),
            ]);
        }
        MoteurAutorisation::invalider(null);
    }

    private function creerScenario(array $criteres = [], array $contraintes = [], array $objectifs = []): ScenarioTemporel
    {
        $defauts = [
            'delta_admissions' => 0.0,
            'bornes' => ['min' => 0.0, 'max' => 0.0],
            'horizon_annees' => 3,
            'duree_gel_annees' => 3,
            'taux' => ['passage' => 0.0, 'redoublement' => 1.0, 'abandon' => 0.0],
        ];

        return ScenarioTemporel::create([
            'code' => 'SIM03-'.uniqid(),
            'libelle' => 'Gel SIM-03',
            'domaine' => 'effectifs_cohortes',
            'faculte_id' => $this->faculteA->id,
            'criteres' => array_merge($defauts, $criteres),
            'contraintes' => $contraintes,
            'objectifs' => $objectifs,
            'version' => 1,
            'cree_par' => $this->admin->id,
        ]);
    }

    private function executer(ScenarioTemporel $scenario, string $graine = 'graine-sim03'): array
    {
        $reponse = $this->actingAs($this->admin)->postJson(
            route('admin.simulation-temporelle.executer', ['id' => $scenario->id]),
            [
                'annee_universitaire_id' => $this->annee->id,
                'graine' => $graine,
                'nb_variantes' => 1,
            ],
        );
        $reponse->assertOk();

        return $reponse->json('execution');
    }

    /* ── E. Validation mathématique indépendante ─────────────────────── */

    public function test_sim03_gel_total_sur_tout_l_horizon_avec_reference_calculee_independamment(): void
    {
        // Référence manuelle (calcul hors moteur) :
        // base L1 = 10 ; delta = 0.0 ; horizon = 3 ; duree_gel = 3 ;
        // taux explicites {passage: 0, redoublement: 1, abandon: 0} = progression gelée.
        // p1 : admissions = 0 → L1 = 10, total = 10+20+30 = 60
        // p2 : admissions = 0 → L1 = 10, total = 10+20+30 = 60
        // p3 : admissions = 0 → L1 = 10, total = 10+20+30 = 60
        $scenario = $this->creerScenario([
            'delta_admissions' => 0.0,
            'bornes' => ['min' => 0.0, 'max' => 0.0],
            'horizon_annees' => 3,
            'duree_gel_annees' => 3,
            'taux' => ['passage' => 0.0, 'redoublement' => 1.0, 'abandon' => 0.0],
        ]);

        $execution = $this->executer($scenario, 'sim03-ref-total');
        $variante = $execution['resultat']['classement'][0];

        $this->assertSame([60, 60, 60, 60], $variante['totaux']);
        $this->assertSame(60, $variante['total_final']);
        $this->assertSame([
            ['pas' => 1, 'admissions' => 0],
            ['pas' => 2, 'admissions' => 0],
            ['pas' => 3, 'admissions' => 0],
        ], $variante['admissions_par_periode']);

        $admissions = array_column($variante['admissions_par_periode'], 'admissions');
        $this->assertSame([0, 0, 0], $admissions);

        $this->assertSame(60, $execution['resultat']['etat_initial']['total_initial']);
        $this->assertSame(['L1' => 10, 'L2' => 20, 'L3' => 30], $execution['resultat']['etat_initial']['cohortes']);
    }

    public function test_sim03_gel_temporaire_avec_reprise_composee_post_gel(): void
    {
        // Référence manuelle (calcul hors moteur) :
        // base L1 = 10 ; delta = 0.1 ; horizon = 4 ; duree_gel = 2 ;
        // taux {passage: 0, redoublement: 1, abandon: 0} = progression gelée.
        // p1 (p <= 2) : admissions = 0 → L1 = 10, total = 60
        // p2 (p <= 2) : admissions = 0 → L1 = 10, total = 60
        // p3 (p > 2, pas_apres = 1) : round(10 × 1.1^1) = 11 → L1 = 10 + 11 = 21, total = 21+20+30 = 71
        // p4 (p > 2, pas_apres = 2) : round(10 × 1.1^2) = round(12.1) = 12 → L1 = 21 + 12 = 33, total = 33+20+30 = 83
        $scenario = $this->creerScenario([
            'delta_admissions' => 0.1,
            'bornes' => ['min' => 0.0, 'max' => 0.2],
            'horizon_annees' => 4,
            'duree_gel_annees' => 2,
            'taux' => ['passage' => 0.0, 'redoublement' => 1.0, 'abandon' => 0.0],
        ]);

        $execution = $this->executer($scenario, 'sim03-reprise');
        $variante = $execution['resultat']['classement'][0];

        $this->assertSame([60, 60, 60, 71, 83], $variante['totaux']);
        $this->assertSame(83, $variante['total_final']);
        $this->assertSame([
            ['pas' => 1, 'admissions' => 0],
            ['pas' => 2, 'admissions' => 0],
            ['pas' => 3, 'admissions' => 11],
            ['pas' => 4, 'admissions' => 12],
        ], $variante['admissions_par_periode']);

        $admissions = array_column($variante['admissions_par_periode'], 'admissions');
        $this->assertSame([0, 0, 11, 12], $admissions);
    }

    public function test_sim03_gel_avec_flux_et_conservation_stricte_des_effectifs(): void
    {
        // Référence manuelle (calcul hors moteur) :
        // base L1 = 10 ; delta = 0.0 ; horizon = 2 ; duree_gel = 2 ;
        // taux {passage: 0.5, redoublement: 0.3, abandon: 0.2}
        // Pas 1 :
        //   admissions = 0
        //   L3 (30) : 15 partent (diplômés), 9 redoublent, 6 abandons
        //   L2 (20) : 10 partent vers L3, 6 redoublent, 4 abandons
        //   L1 (10) : 5 partent vers L2, 3 redoublent, 2 abandons
        //   L1 = 3 + 0 = 3 ; L2 = 6 + 5 = 11 ; L3 = 9 + 10 = 19 ; total = 33
        //   Conservation : 60 + 0 - 15 - 12 = 33
        // Pas 2 :
        //   admissions = 0
        //   L3 (19) : round(19×0.5) = 10 diplômés, round(19×0.3) = 6 redoublent, 3 abandons
        //   L2 (11) : round(11×0.5) = 6 vers L3, round(11×0.3) = 3 redoublent, 2 abandons
        //   L1 (3)  : round(3×0.5) = 2 vers L2, round(3×0.3) = 1 redouble, 0 abandon
        //   L1 = 1 + 0 = 1 ; L2 = 3 + 2 = 5 ; L3 = 6 + 6 = 12 ; total = 18
        //   Conservation : 33 + 0 - 10 - 5 = 18
        $scenario = $this->creerScenario([
            'delta_admissions' => 0.0,
            'bornes' => ['min' => 0.0, 'max' => 0.0],
            'horizon_annees' => 2,
            'duree_gel_annees' => 2,
            'taux' => ['passage' => 0.5, 'redoublement' => 0.3, 'abandon' => 0.2],
        ]);

        $execution = $this->executer($scenario, 'sim03-conservation');
        $variante = $execution['resultat']['classement'][0];

        $this->assertSame([60, 33, 18], $variante['totaux']);
        $this->assertSame([
            ['pas' => 1, 'admissions' => 0],
            ['pas' => 2, 'admissions' => 0],
        ], $variante['admissions_par_periode']);

        $snapshots = SnapshotExecution::where('execution_temporelle_id', $execution['id'])
            ->orderBy('pas')->get();
        $this->assertSame(['L1' => 3, 'L2' => 11, 'L3' => 19], $snapshots->firstWhere('pas', 1)->etat['cohortes']);
        $this->assertSame(['L1' => 1, 'L2' => 5, 'L3' => 12], $snapshots->firstWhere('pas', 2)->etat['cohortes']);

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

    public function test_sim03_memes_entrees_idempotentes_et_graine_reproductible(): void
    {
        $scenario = $this->creerScenario([
            'delta_admissions' => 0.0,
            'bornes' => ['min' => 0.0, 'max' => 0.0],
            'horizon_annees' => 3,
            'duree_gel_annees' => 2,
        ]);

        $premier = $this->executer($scenario, 'graine-sim03-idemp');
        $second = $this->executer($scenario, 'graine-sim03-idemp');

        $this->assertSame($premier['id'], $second['id']);
        $this->assertSame($premier['hash_resultat'], $second['hash_resultat']);
        $this->assertSame(
            $premier['resultat']['classement'][0]['admissions_par_periode'],
            $second['resultat']['classement'][0]['admissions_par_periode']
        );
        $this->assertSame(1, ExecutionTemporelle::where('scenario_temporel_id', $scenario->id)->count());

        // Autre graine = nouvelle exécution, variante 0 identique.
        $autre = $this->executer($scenario, 'graine-sim03-autre');
        $this->assertNotSame($premier['id'], $autre['id']);
        $this->assertSame(
            $premier['resultat']['classement'][0]['admissions_par_periode'],
            $autre['resultat']['classement'][0]['admissions_par_periode']
        );
    }

    /* ── E. Capacité et allègement de pression physique par le gel ───── */

    public function test_sim03_capacite_et_diminution_de_saturation_par_gel(): void
    {
        Group::create([
            'code' => 'SIM03-G1', 'nom' => 'Groupe SIM-03', 'faculte_id' => $this->faculteA->id,
            'niveau' => 'L1', 'actif' => true, 'niveau_id' => $this->niveauL1->id, 'capacite' => 15,
        ]);

        $scenario = $this->creerScenario([
            'delta_admissions' => 0.0,
            'bornes' => ['min' => 0.0, 'max' => 0.0],
            'horizon_annees' => 2,
            'duree_gel_annees' => 2,
            'taux' => ['passage' => 0.5, 'redoublement' => 0.3, 'abandon' => 0.2],
            'niveau_id' => $this->niveauL1->id,
        ]);

        $execution = $this->executer($scenario, 'sim03-capacite');

        // Périmètre restreint à L1 :
        // Initial : 10.
        // Pas 1 : 3 (10×0.3) + 0 admis = 3.
        // Pas 2 : 1 (3×0.3) + 0 admis = 1.
        $this->assertSame(1, $execution['resultat']['classement'][0]['total_final']);
        $capacites = $execution['resultat']['capacites'];

        $this->assertFalse($capacites['capacite_groupe_hypothese']);
        $this->assertSame(15, $capacites['capacite_groupe_reference']);
        $this->assertSame(1, $capacites['groupes_theoriques_requis_total']); // ceil(1 / 15) = 1
        $this->assertSame(0, $capacites['deficit_groupes']);
        $this->assertFalse($capacites['depassement_capacite']);
        $this->assertSame(0, $capacites['places_manquantes']);

        // Le groupe réel en base n'a pas été modifié.
        $groupe = Group::where('code', 'SIM03-G1')->firstOrFail();
        $this->assertSame(15, (int) $groupe->capacite);
        $this->assertTrue((bool) $groupe->actif);
    }

    /* ── F. Sécurité et lecture seule ────────────────────────────────── */

    public function test_sim03_permissions_et_isolation_facultaire(): void
    {
        $scenario = $this->creerScenario([
            'delta_admissions' => 0.0,
            'bornes' => ['min' => 0.0, 'max' => 0.0],
            'horizon_annees' => 2,
            'duree_gel_annees' => 2,
        ]);

        // Sans permission : 403 Forbidden.
        $this->actingAs($this->sansPermission)
            ->postJson(route('admin.simulation-temporelle.executer', ['id' => $scenario->id]), [
                'annee_universitaire_id' => $this->annee->id, 'graine' => 'x', 'nb_variantes' => 1,
            ])->assertForbidden();

        // Admin faculté B sur scénario faculté A : 403 Forbidden.
        $this->actingAs($this->adminB)
            ->postJson(route('admin.simulation-temporelle.executer', ['id' => $scenario->id]), [
                'annee_universitaire_id' => $this->annee->id, 'graine' => 'x', 'nb_variantes' => 1,
            ])->assertForbidden();

        $this->assertSame(0, ExecutionTemporelle::count());
    }

    public function test_sim03_zero_ecriture_metier_et_tables_techniques_seules(): void
    {
        $scenario = $this->creerScenario([
            'delta_admissions' => 0.0,
            'bornes' => ['min' => 0.0, 'max' => 0.0],
            'horizon_annees' => 2,
            'duree_gel_annees' => 2,
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

        $this->executer($scenario, 'sim03-ro');

        $this->assertSame($avant, [
            'etudiants' => Etudiant::count(),
            'inscriptions' => InscriptionAcademique::count(),
            'groupes' => Group::count(),
            'facultes' => Faculte::count(),
            'niveaux' => Niveau::count(),
            'scenarios' => ScenarioTemporel::count(),
        ]);
        $this->assertSame($empreinteEtudiant, $etudiantTemoin->fresh()->getAttributes());

        // Seules les tables techniques sont écrites.
        $this->assertSame(1, ExecutionTemporelle::count());
        $this->assertSame(3, SnapshotExecution::count()); // pas 0, 1, 2
    }
}
