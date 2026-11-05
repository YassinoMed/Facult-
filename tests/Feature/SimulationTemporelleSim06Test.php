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
use App\Modules\SimulationTemporelle\Services\ServiceSimulationTemporelle;
use App\Support\Iam\MoteurAutorisation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * ST-14 / SIM-06 — récupération démographique (creux progressif puis
 * retour progressif au niveau de référence).
 *
 * Les résultats attendus sont calculés À LA MAIN dans les commentaires
 * (aucune réutilisation de la formule du moteur dans les assertions).
 *
 * Périmètre synthétique : L1=10, L2=20, L3=30 (total 60), faculté A.
 */
class SimulationTemporelleSim06Test extends TestCase
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
            'name' => 'SIM06', 'email' => 'sim06-'.uniqid().'@u.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->adminB = User::creerCompteBackOffice([
            'name' => 'SIM06-B', 'email' => 'sim06b-'.uniqid().'@u.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteB->id,
        ], 'admin_faculte');
        $this->sansPermission = User::creerCompteBackOffice([
            'name' => 'SIM06-N', 'email' => 'sim06n-'.uniqid().'@u.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->habiliter($this->admin);
        $this->habiliter($this->adminB);

        // Effectifs réels synthétiques de la faculté A : L1=10, L2=20, L3=30.
        $programme = Programme::create([
            'faculte_id' => $this->faculteA->id, 'code' => 'PFST6', 'nom' => 'Prog',
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
        foreach ([
            'simulation.view', 'simulation.gerer', 'simulation.executer',
            'simulation.export', 'simulation.explication',
            'simulation.gouvernance.proposer', 'simulation.gouvernance.decider',
        ] as $code) {
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
            'horizon_annees' => 4,
            'profondeur_baisse' => 0.2,
            'duree_baisse_annees' => 2,
            'duree_recuperation_annees' => 2,
            'taux' => ['passage' => 0.0, 'redoublement' => 1.0, 'abandon' => 0.0],
        ];

        return $this->persisterScenario(array_merge($defauts, $criteres), $contraintes, $objectifs);
    }

    /** Même socle SANS creux : le trio est retiré, y compris s'il est fourni. */
    private function creerScenarioSansRecuperation(array $criteres = [], array $contraintes = [], array $objectifs = []): ScenarioTemporel
    {
        $defauts = [
            'delta_admissions' => 0.0,
            'bornes' => ['min' => 0.0, 'max' => 0.0],
            'horizon_annees' => 4,
            'taux' => ['passage' => 0.0, 'redoublement' => 1.0, 'abandon' => 0.0],
        ];

        $criteres = array_merge($defauts, $criteres);
        unset($criteres['profondeur_baisse'], $criteres['duree_baisse_annees'], $criteres['duree_recuperation_annees']);

        return $this->persisterScenario($criteres, $contraintes, $objectifs);
    }

    private function persisterScenario(array $criteres, array $contraintes, array $objectifs): ScenarioTemporel
    {
        return ScenarioTemporel::create([
            'code' => 'SIM06-'.uniqid(),
            'libelle' => 'Récupération SIM-06',
            'domaine' => 'effectifs_cohortes',
            'faculte_id' => $this->faculteA->id,
            'criteres' => $criteres,
            'contraintes' => $contraintes,
            'objectifs' => $objectifs,
            'version' => 1,
            'cree_par' => $this->admin->id,
        ]);
    }

    private function executer(ScenarioTemporel $scenario, string $graine = 'graine-sim06'): array
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

    private function assertRefuseDefinition(callable $action, string $messagePart): void
    {
        try {
            $action();
        } catch (\DomainException $e) {
            $this->assertStringContainsString($messagePart, $e->getMessage());

            return;
        }

        $this->fail('Une DomainException était attendue.');
    }

    private function donneesDefinition(array $criteres): array
    {
        return [
            'code' => 'SIM06-DEF-'.uniqid(),
            'libelle' => 'Récupération SIM-06 (définition)',
            'faculte_id' => $this->faculteA->id,
            'criteres' => $criteres,
            'contraintes' => [],
            'objectifs' => [['type' => 'stabilite', 'poids' => 1]],
        ];
    }

    /* ── E. Validation mathématique indépendante ─────────────────────── */

    public function test_sim06_creux_20_pct_sur_reference_plate_et_retour_exact(): void
    {
        // Référence manuelle (calcul hors moteur) :
        // base L1 = 10 ; delta = 0 ; profondeur = 0.2 ; d = 2 ; r = 2 ; H = 4 ;
        // taux {passage: 0, redoublement: 1, abandon: 0} = progression gelée.
        // Référence : A_ref(p) = 10 pour tout p.
        // Facteurs  : f = [0.9, 0.8, 0.9, 1.0] (baisse, baisse, récup, récup).
        // Admissions : [9, 8, 9, 10] ; retour exact au pas 4 = d + r.
        // Écarts     : [-1, -2, -1, 0] → cumul = -4 ; creux = -2.
        // Totaux     : L1 = 10+9 = 19 → 69 ; 19+8 = 27 → 77 ;
        //              27+9 = 36 → 86 ; 36+10 = 46 → 96.
        $scenario = $this->creerScenario();

        $execution = $this->executer($scenario, 'sim06-ref-plate');
        $variante = $execution['resultat']['classement'][0];

        $this->assertSame([60, 69, 77, 86, 96], $variante['totaux']);
        $this->assertSame([
            ['pas' => 1, 'admissions' => 9],
            ['pas' => 2, 'admissions' => 8],
            ['pas' => 3, 'admissions' => 9],
            ['pas' => 4, 'admissions' => 10],
        ], $variante['admissions_par_periode']);

        $this->assertSame([
            ['pas' => 1, 'admissions_reference' => 10],
            ['pas' => 2, 'admissions_reference' => 10],
            ['pas' => 3, 'admissions_reference' => 10],
            ['pas' => 4, 'admissions_reference' => 10],
        ], $variante['admissions_reference_par_periode']);

        $this->assertSame([
            ['pas' => 1, 'admissions' => 9, 'admissions_reference' => 10, 'ecart' => -1, 'phase' => 'baisse', 'facteur' => 0.9],
            ['pas' => 2, 'admissions' => 8, 'admissions_reference' => 10, 'ecart' => -2, 'phase' => 'baisse', 'facteur' => 0.8],
            ['pas' => 3, 'admissions' => 9, 'admissions_reference' => 10, 'ecart' => -1, 'phase' => 'recuperation', 'facteur' => 0.9],
            // JSON sans zéro fraction : le facteur 1.0 est décodé en entier 1.
            ['pas' => 4, 'admissions' => 10, 'admissions_reference' => 10, 'ecart' => 0, 'phase' => 'recuperation', 'facteur' => 1],
        ], $variante['ecarts_recuperation_par_periode']);

        $this->assertSame(-4, $variante['ecart_recuperation_cumule']);
        $this->assertSame(-2, $variante['creux_recuperation']);

        $this->assertSame(60, $execution['resultat']['etat_initial']['total_initial']);
        $this->assertSame(['L1' => 10, 'L2' => 20, 'L3' => 30], $execution['resultat']['etat_initial']['cohortes']);
    }

    public function test_sim06_creux_sur_tendance_croissante_et_deficit_identique(): void
    {
        // Référence manuelle (calcul hors moteur) :
        // delta = 0.10 ; profondeur = 0.2 ; d = 2 ; r = 2 ; H = 4 ; flux gelés.
        // Référence : [11, 12, 13, 15].
        // Facteurs  : [0.9, 0.8, 0.9, 1.0].
        // Admissions : [round(9.9)=10, round(9.6)=10, round(11.7)=12, 15].
        // Écarts     : [-1, -2, -1, 0] → cumul = -4 ; creux = -2.
        // Le déficit est RELATIF au fond : identique au cas plat.
        // Totaux : L1 = 10+10 = 20 → 70 ; 20+10 = 30 → 80 ;
        //          30+12 = 42 → 92 ; 42+15 = 57 → 107.
        $scenario = $this->creerScenario([
            'delta_admissions' => 0.10,
            'bornes' => ['min' => 0.0, 'max' => 0.5],
        ]);

        $execution = $this->executer($scenario, 'sim06-ref-tendance');
        $variante = $execution['resultat']['classement'][0];

        $this->assertSame([60, 70, 80, 92, 107], $variante['totaux']);
        $this->assertSame([10, 10, 12, 15], array_column($variante['admissions_par_periode'], 'admissions'));
        $this->assertSame([11, 12, 13, 15], array_column($variante['admissions_reference_par_periode'], 'admissions_reference'));
        $this->assertSame([-1, -2, -1, 0], array_column($variante['ecarts_recuperation_par_periode'], 'ecart'));
        $this->assertSame(-4, $variante['ecart_recuperation_cumule']);
        $this->assertSame(-2, $variante['creux_recuperation']);

        // Retour EXACT à la référence au pas d + r = 4.
        $dernier = $variante['ecarts_recuperation_par_periode'][3];
        $this->assertSame($dernier['admissions_reference'], $dernier['admissions']);
        $this->assertSame(0, $dernier['ecart']);
    }

    public function test_sim06_flux_actifs_conservation_stricte(): void
    {
        // Référence manuelle (calcul hors moteur) :
        // delta = 0 ; profondeur = 0.2 ; d = 1 ; r = 1 ; H = 2 ;
        // taux {passage: 0.5, redoublement: 0.3, abandon: 0.2}.
        // Facteurs [0.8, 1.0] ; admissions [8, 10] ; référence [10, 10].
        // Pas 1 : L3 (30) → 15/9/6 ; L2 (20) → 10/6/4 ; L1 (10) → 5/3/2.
        //   L1 = 3+8 = 11 ; L2 = 6+5 = 11 ; L3 = 9+10 = 19 ; total = 41.
        // Pas 2 : L3 (19) → 10/6/3 ; L2 (11) → 6/3/2 ; L1 (11) → 6/3/2.
        //   L1 = 3+10 = 13 ; L2 = 3+6 = 9 ; L3 = 6+6 = 12 ; total = 34.
        // Écarts [-2, 0] → cumul = -2 ; creux = -2.
        $scenario = $this->creerScenario([
            'horizon_annees' => 2,
            'duree_baisse_annees' => 1,
            'duree_recuperation_annees' => 1,
            'taux' => ['passage' => 0.5, 'redoublement' => 0.3, 'abandon' => 0.2],
        ]);

        $execution = $this->executer($scenario, 'sim06-conservation');
        $variante = $execution['resultat']['classement'][0];

        $this->assertSame([60, 41, 34], $variante['totaux']);
        $this->assertSame([-2, 0], array_column($variante['ecarts_recuperation_par_periode'], 'ecart'));
        $this->assertSame(-2, $variante['ecart_recuperation_cumule']);
        $this->assertSame(-2, $variante['creux_recuperation']);

        $snapshots = SnapshotExecution::where('execution_temporelle_id', $execution['id'])
            ->orderBy('pas')->get();
        $this->assertSame(['L1' => 11, 'L2' => 11, 'L3' => 19], $snapshots->firstWhere('pas', 1)->etat['cohortes']);
        $this->assertSame(['L1' => 13, 'L2' => 9, 'L3' => 12], $snapshots->firstWhere('pas', 2)->etat['cohortes']);

        foreach ($snapshots as $snapshot) {
            $this->assertSame(
                $snapshot->etat['total'],
                array_sum($snapshot->etat['cohortes']),
                'Le total du snapshot doit égaler la somme des niveaux.'
            );
            $this->assertGreaterThanOrEqual(0, min($snapshot->etat['cohortes']));
        }
    }

    public function test_sim06_profondeur_maximale_creux_total_sans_admissions_negatives(): void
    {
        // Référence manuelle (calcul hors moteur) :
        // delta = 0 ; profondeur = 1 (borne incluse) ; d = 1 ; r = 1 ; H = 2.
        // Facteurs [0.0, 1.0] ; admissions [0, 10] ; référence [10, 10].
        // Écarts [-10, 0] → cumul = -10 ; creux = -10.
        // Totaux : L1 = 10+0 = 10 → 60 ; 10+10 = 20 → 70.
        $scenario = $this->creerScenario([
            'horizon_annees' => 2,
            'profondeur_baisse' => 1.0,
            'duree_baisse_annees' => 1,
            'duree_recuperation_annees' => 1,
        ]);

        $execution = $this->executer($scenario, 'sim06-creux-total');
        $variante = $execution['resultat']['classement'][0];

        $this->assertSame([0, 10], array_column($variante['admissions_par_periode'], 'admissions'));
        $this->assertSame([-10, 0], array_column($variante['ecarts_recuperation_par_periode'], 'ecart'));
        $this->assertSame(-10, $variante['ecart_recuperation_cumule']);
        $this->assertSame(-10, $variante['creux_recuperation']);
        $this->assertSame([60, 60, 70], $variante['totaux']);

        foreach (SnapshotExecution::where('execution_temporelle_id', $execution['id'])->get() as $snapshot) {
            $this->assertGreaterThanOrEqual(0, min($snapshot->etat['cohortes']));
        }
    }

    public function test_sim06_arrondi_standard_sur_la_frontiere_demi(): void
    {
        // Référence manuelle (calcul hors moteur) :
        // delta = 0.05 ; profondeur = 0.5 ; d = 1 ; r = 1 ; H = 2 ; flux gelés.
        // Référence : [round(10.5)=11, round(11.025)=11].
        // Facteurs [0.5, 1.0] ; admissions :
        //   pas 1 = round(11 × 0.5) = round(5.5) = 6 (arrondi standard) ;
        //   pas 2 = round(11 × 1.0) = 11.
        // Écarts [-5, 0] → cumul = -5 ; creux = -5.
        $scenario = $this->creerScenario([
            'delta_admissions' => 0.05,
            'bornes' => ['min' => 0.0, 'max' => 0.2],
            'horizon_annees' => 2,
            'profondeur_baisse' => 0.5,
            'duree_baisse_annees' => 1,
            'duree_recuperation_annees' => 1,
        ]);

        $execution = $this->executer($scenario, 'sim06-arrondi');
        $variante = $execution['resultat']['classement'][0];

        $this->assertSame([6, 11], array_column($variante['admissions_par_periode'], 'admissions'));
        $this->assertSame([11, 11], array_column($variante['admissions_reference_par_periode'], 'admissions_reference'));
        $this->assertSame([-5, 0], array_column($variante['ecarts_recuperation_par_periode'], 'ecart'));
        $this->assertSame(-5, $variante['ecart_recuperation_cumule']);
        $this->assertSame(-5, $variante['creux_recuperation']);
    }

    /* ── C. Validation du contrat au moment de la définition ─────────── */

    public function test_sim06_definition_refuse_trio_incomplet_hors_horizon_et_incompatibilites(): void
    {
        $service = app(ServiceSimulationTemporelle::class);
        $base = [
            'delta_admissions' => 0.0,
            'bornes' => ['min' => 0.0, 'max' => 0.0],
            'horizon_annees' => 4,
            'profondeur_baisse' => 0.2,
            'duree_baisse_annees' => 2,
            'duree_recuperation_annees' => 2,
            'taux' => ['passage' => 0.6, 'redoublement' => 0.3, 'abandon' => 0.1],
        ];

        // Trio indissociable.
        foreach (['profondeur_baisse', 'duree_baisse_annees', 'duree_recuperation_annees'] as $cle) {
            $incomplet = $base;
            unset($incomplet[$cle]);
            $this->assertRefuseDefinition(
                fn () => $service->definir($this->donneesDefinition($incomplet), $this->admin->id),
                'indissociables'
            );
        }

        // Bornes de forme.
        $this->assertRefuseDefinition(
            fn () => $service->definir($this->donneesDefinition(array_merge($base, ['profondeur_baisse' => 0.0])), $this->admin->id),
            'strictement positive'
        );
        $this->assertRefuseDefinition(
            fn () => $service->definir($this->donneesDefinition(array_merge($base, ['profondeur_baisse' => 1.5])), $this->admin->id),
            'au plus égale à 1'
        );
        $this->assertRefuseDefinition(
            fn () => $service->definir($this->donneesDefinition(array_merge($base, ['duree_baisse_annees' => 0])), $this->admin->id),
            'au moins égale à 1'
        );

        // Retour non observable dans l'horizon.
        $this->assertRefuseDefinition(
            fn () => $service->definir($this->donneesDefinition(array_merge($base, [
                'duree_baisse_annees' => 2, 'duree_recuperation_annees' => 3,
            ])), $this->admin->id),
            'dépassent l\'horizon'
        );

        // Incompatibilités : gel (SIM-03), choc (SIM-04), cible (SIM-05).
        $this->assertRefuseDefinition(
            fn () => $service->definir($this->donneesDefinition(array_merge($base, ['duree_gel_annees' => 1])), $this->admin->id),
            'SIM-03'
        );
        $this->assertRefuseDefinition(
            fn () => $service->definir($this->donneesDefinition(array_merge($base, [
                'pas_exceptionnel' => 1, 'admissions_exceptionnelles' => 5,
            ])), $this->admin->id),
            'SIM-04'
        );
        $this->assertRefuseDefinition(
            fn () => $service->definir($this->donneesDefinition(array_merge($base, [
                'delta_admissions' => 0.10, 'bornes' => ['min' => 0.0, 'max' => 0.5], 'taux_cible_croissance' => 0.0,
            ])), $this->admin->id),
            'SIM-05'
        );

        $this->assertSame(0, ScenarioTemporel::count());
    }

    /* ── B/D. Déterminisme, idempotence et rétrocompatibilité ────────── */

    public function test_sim06_idempotence_reproductibilite_et_absence_de_cles_sans_recuperation(): void
    {
        $scenario = $this->creerScenario();

        $premier = $this->executer($scenario, 'graine-sim06-idemp');
        $second = $this->executer($scenario, 'graine-sim06-idemp');

        $this->assertSame($premier['id'], $second['id']);
        $this->assertSame($premier['hash_resultat'], $second['hash_resultat']);
        $this->assertSame(
            $premier['resultat']['classement'][0]['ecarts_recuperation_par_periode'],
            $second['resultat']['classement'][0]['ecarts_recuperation_par_periode']
        );
        $this->assertSame(1, ExecutionTemporelle::where('scenario_temporel_id', $scenario->id)->count());

        // Autre graine = nouvelle exécution, variante 0 identique.
        $autre = $this->executer($scenario, 'graine-sim06-autre');
        $this->assertNotSame($premier['id'], $autre['id']);
        $this->assertSame(
            $premier['resultat']['classement'][0]['admissions_par_periode'],
            $autre['resultat']['classement'][0]['admissions_par_periode']
        );

        // Rétrocompatibilité : sans trio, aucune clé de récupération et les
        // admissions restent la trajectoire de fond [10, 10, 10, 10].
        $sansCreux = $this->creerScenarioSansRecuperation();
        $executionSans = $this->executer($sansCreux, 'sim06-sans-creux');
        $varianteSans = $executionSans['resultat']['classement'][0];

        $this->assertArrayNotHasKey('admissions_reference_par_periode', $varianteSans);
        $this->assertArrayNotHasKey('ecarts_recuperation_par_periode', $varianteSans);
        $this->assertArrayNotHasKey('ecart_recuperation_cumule', $varianteSans);
        $this->assertArrayNotHasKey('creux_recuperation', $varianteSans);
        $this->assertSame([10, 10, 10, 10], array_column($varianteSans['admissions_par_periode'], 'admissions'));
        $this->assertSame([60, 70, 80, 90, 100], $varianteSans['totaux']);
    }

    /* ── F. Sécurité et lecture seule ────────────────────────────────── */

    public function test_sim06_permissions_isolation_facultaire_et_zero_ecriture_metier(): void
    {
        $scenario = $this->creerScenario();

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

        // Exécution autorisée : seules les tables techniques sont écrites.
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

        $this->executer($scenario, 'sim06-ro');

        $this->assertSame($avant, [
            'etudiants' => Etudiant::count(),
            'inscriptions' => InscriptionAcademique::count(),
            'groupes' => Group::count(),
            'facultes' => Faculte::count(),
            'niveaux' => Niveau::count(),
            'scenarios' => ScenarioTemporel::count(),
        ]);
        $this->assertSame($empreinteEtudiant, $etudiantTemoin->fresh()->getAttributes());

        $this->assertSame(1, ExecutionTemporelle::count());
        $this->assertSame(5, SnapshotExecution::count()); // pas 0, 1, 2, 3, 4
    }
}
