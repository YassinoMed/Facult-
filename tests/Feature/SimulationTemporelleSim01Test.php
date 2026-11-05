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
 * ST-14 / SIM-01 — croissance des admissions.
 *
 * Les résultats attendus sont calculés À LA MAIN dans les commentaires
 * (pas de réutilisation de la formule du moteur dans les assertions).
 *
 * Périmètre synthétique : L1=10, L2=20, L3=30 (total 60), faculté A.
 */
class SimulationTemporelleSim01Test extends TestCase
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
            'name' => 'SIM01', 'email' => 'sim01-'.uniqid().'@u.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->adminB = User::creerCompteBackOffice([
            'name' => 'SIM01-B', 'email' => 'sim01b-'.uniqid().'@u.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteB->id,
        ], 'admin_faculte');
        $this->sansPermission = User::creerCompteBackOffice([
            'name' => 'SIM01-N', 'email' => 'sim01n-'.uniqid().'@u.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->habiliter($this->admin);
        $this->habiliter($this->adminB);

        // Effectifs réels synthétiques de la faculté A : L1=10, L2=20, L3=30.
        $programme = Programme::create([
            'faculte_id' => $this->faculteA->id, 'code' => 'PFST', 'nom' => 'Prog',
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
            'faculte_id' => $this->faculteB->id, 'code' => 'PFDEG', 'nom' => 'Prog B',
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
            'code' => 'SIM01-'.uniqid(), 'libelle' => 'Croissance SIM-01',
            'domaine' => 'effectifs_cohortes', 'faculte_id' => $this->faculteA->id,
            'criteres' => $criteres, 'contraintes' => $contraintes, 'objectifs' => $objectifs,
            'version' => 1, 'cree_par' => $this->admin->id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function executer(ScenarioTemporel $scenario, string $graine = 'sim01', int $variantes = 1, ?User $acteur = null): array
    {
        return $this->actingAs($acteur ?? $this->admin)
            ->postJson(route('admin.simulation-temporelle.executer', ['id' => $scenario->id]), [
                'annee_universitaire_id' => $this->annee->id,
                'graine' => $graine,
                'nb_variantes' => $variantes,
            ])->assertOk()->json('execution');
    }

    /* ── E. Validation mathématique indépendante ─────────────────────── */

    public function test_sim01_croissance_composee_avec_reference_calculee_independamment(): void
    {
        // Référence manuelle (fichier, calcul hors moteur) :
        // base L1 = 10 ; delta = 0.1 ; horizon = 3 ;
        // taux explicites {passage: 0, redoublement: 1, abandon: 0} = progression gelée.
        // p1 : round(10 × 1.1^1) = 11  → L1 = 10 + 11 = 21, total = 21+20+30 = 71
        // p2 : round(10 × 1.1^2) = 12  → L1 = 21 + 12 = 33, total = 33+20+30 = 83
        // p3 : round(10 × 1.1^3) = 13  → L1 = 33 + 13 = 46, total = 46+20+30 = 96
        $scenario = $this->creerScenario([
            'delta_admissions' => 0.1,
            'bornes' => ['min' => 0.0, 'max' => 1.0],
            'horizon_annees' => 3,
            'taux' => ['passage' => 0.0, 'redoublement' => 1.0, 'abandon' => 0.0],
        ]);

        $execution = $this->executer($scenario, 'sim01-reference');
        $variante = $execution['resultat']['classement'][0];

        $this->assertSame([60, 71, 83, 96], $variante['totaux']);
        $this->assertSame(96, $variante['total_final']);
        $this->assertSame([
            ['pas' => 1, 'admissions' => 11],
            ['pas' => 2, 'admissions' => 12],
            ['pas' => 3, 'admissions' => 13],
        ], $variante['admissions_par_periode']);

        // Référence historique : l'état initial n'est jamais réécrit.
        $this->assertSame(60, $execution['resultat']['etat_initial']['total_initial']);
        $this->assertSame(['L1' => 10, 'L2' => 20, 'L3' => 30], $execution['resultat']['etat_initial']['cohortes']);
    }

    public function test_sim01_admissions_constantes_et_conservation_exacte_des_effectifs(): void
    {
        // Référence manuelle (delta = 0 ; taux 0.5 / 0.3 / 0.2) :
        // p1 : admissions 10 ; L1 10→(3 redoublants + 10) 13 ; L2 20→6+5=11 ; L3 30→9+10=19 ; total 43
        // p2 : admissions 10 ; L1 13→(4 + 10) 14 ; L2 11→3+7=10 ; L3 19→6+6=12 ; total 36
        // Conservation p1 : 60 + 10 − 15 diplômés − 12 abandons = 43.
        // Conservation p2 : 43 + 10 − 10 diplômés − 7 abandons = 36.
        $scenario = $this->creerScenario([
            'delta_admissions' => 0.0,
            'bornes' => ['min' => 0.0, 'max' => 0.0],
            'horizon_annees' => 2,
            'taux' => ['passage' => 0.5, 'redoublement' => 0.3, 'abandon' => 0.2],
        ]);

        $execution = $this->executer($scenario, 'sim01-constant');
        $variante = $execution['resultat']['classement'][0];

        $this->assertSame([60, 43, 36], $variante['totaux']);
        $this->assertSame([
            ['pas' => 1, 'admissions' => 10],
            ['pas' => 2, 'admissions' => 10],
        ], $variante['admissions_par_periode']);

        // Conservation vérifiée niveau par niveau sur les snapshots signés.
        $snapshots = SnapshotExecution::where('execution_temporelle_id', $execution['id'])
            ->orderBy('pas')->get();
        $this->assertSame(['L1' => 13, 'L2' => 11, 'L3' => 19], $snapshots->firstWhere('pas', 1)->etat['cohortes']);
        $this->assertSame(['L1' => 14, 'L2' => 10, 'L3' => 12], $snapshots->firstWhere('pas', 2)->etat['cohortes']);

        foreach ($snapshots as $snapshot) {
            $this->assertSame(
                $snapshot->etat['total'],
                array_sum($snapshot->etat['cohortes']),
                'Le total du snapshot doit égaler la somme des niveaux.'
            );
            $this->assertGreaterThanOrEqual(0, min($snapshot->etat['cohortes']));
        }
    }

    public function test_sim01_horizon_minimal_et_maximal(): void
    {
        $minimal = $this->creerScenario([
            'delta_admissions' => 0.1,
            'bornes' => ['min' => 0.0, 'max' => 1.0],
            'horizon_annees' => 1,
            'taux' => ['passage' => 0.0, 'redoublement' => 1.0, 'abandon' => 0.0],
        ]);
        $execution = $this->executer($minimal, 'sim01-min');
        // p1 : round(10 × 1.1) = 11 ; total = 60 + 11 = 71.
        $this->assertSame([60, 71], $execution['resultat']['classement'][0]['totaux']);
        $this->assertSame([['pas' => 1, 'admissions' => 11]], $execution['resultat']['classement'][0]['admissions_par_periode']);

        $maximal = $this->creerScenario([
            'delta_admissions' => 0.1,
            'bornes' => ['min' => 0.0, 'max' => 1.0],
            'horizon_annees' => 6,
            'taux' => ['passage' => 0.0, 'redoublement' => 1.0, 'abandon' => 0.0],
        ]);
        $execution = $this->executer($maximal, 'sim01-max');
        // Référence manuelle : 60 + 11+12+13+15+16+18 = 145.
        $this->assertSame(145, $execution['resultat']['classement'][0]['total_final']);
        $this->assertSame([11, 12, 13, 15, 16, 18], array_column(
            $execution['resultat']['classement'][0]['admissions_par_periode'],
            'admissions'
        ));
        $this->assertCount(6, $execution['resultat']['classement'][0]['admissions_par_periode']);
    }

    /* ── B/D. Déterminisme, idempotence, graine ──────────────────────── */

    public function test_sim01_memes_entrees_idempotentes_et_graine_reproductible(): void
    {
        $scenario = $this->creerScenario([
            'delta_admissions' => 0.1,
            'bornes' => ['min' => 0.0, 'max' => 1.0],
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

    /* ── E. Écart à la cible et capacité ─────────────────────────────── */

    public function test_sim01_ecart_a_la_cible_et_groupes_requis_sans_modifier_les_groupes_reels(): void
    {
        Group::create([
            'code' => 'SIM01-G1', 'nom' => 'Groupe SIM-01', 'faculte_id' => $this->faculteA->id,
            'niveau' => 'L1', 'actif' => true, 'niveau_id' => $this->niveauL1->id, 'capacite' => 15,
        ]);

        $scenario = $this->creerScenario(
            [
                'delta_admissions' => 0.1,
                'bornes' => ['min' => 0.0, 'max' => 1.0],
                'horizon_annees' => 3,
                'taux' => ['passage' => 0.0, 'redoublement' => 1.0, 'abandon' => 0.0],
                'niveau_id' => $this->niveauL1->id,
            ],
            [],
            [['type' => 'cible_total', 'cible' => 100, 'poids' => 1.0]]
        );

        $execution = $this->executer($scenario, 'sim01-cible');

        // Le périmètre `niveau_id` restreint la simulation au niveau L1 :
        // 10 initiaux + 11+12+13 = 46 ; cible 100 → écart = −54.
        $ligneFinale = collect($execution['resultat']['comparaison']['lignes'])->last();
        $this->assertSame(46, $ligneFinale['effectif_projete']);
        $this->assertSame(-54.0, (float) $ligneFinale['ecart_cible']);
        $this->assertSame(-54.0, (float) $ligneFinale['ecart_cible_pourcentage']);

        $capacites = $execution['resultat']['capacites'];
        $this->assertFalse($capacites['capacite_groupe_hypothese']); // capacité issue d'un groupe réel
        $this->assertSame(15, $capacites['capacite_groupe_reference']);
        // 46/15 → 4 groupes requis, 1 installé → déficit 3 ; 46 − 15 = 31 places manquantes.
        $this->assertSame(4, $capacites['groupes_theoriques_requis_total']);
        $this->assertSame(3, $capacites['deficit_groupes']);
        $this->assertTrue($capacites['depassement_capacite']);
        $this->assertSame(31, $capacites['places_manquantes']);

        // Le groupe réel n'a pas été modifié par la simulation.
        $groupe = Group::where('code', 'SIM01-G1')->firstOrFail();
        $this->assertSame(15, (int) $groupe->capacite);
        $this->assertTrue((bool) $groupe->actif);
    }

    /* ── F. Sécurité et lecture seule ────────────────────────────────── */

    public function test_sim01_permissions_et_isolation_facultaire(): void
    {
        $scenario = $this->creerScenario([
            'delta_admissions' => 0.1,
            'bornes' => ['min' => 0.0, 'max' => 1.0],
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

    public function test_sim01_zero_ecriture_metier_et_tables_techniques_seules(): void
    {
        $scenario = $this->creerScenario([
            'delta_admissions' => 0.1,
            'bornes' => ['min' => 0.0, 'max' => 1.0],
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

        $this->executer($scenario, 'sim01-ro');

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
