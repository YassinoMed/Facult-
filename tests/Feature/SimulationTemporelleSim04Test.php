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
 * ST-14 / SIM-04 — admissions exceptionnelles (choc ponctuel).
 *
 * Les résultats attendus sont calculés À LA MAIN dans les commentaires
 * (aucune réutilisation de la formule du moteur dans les assertions).
 *
 * Périmètre synthétique : L1=10, L2=20, L3=30 (total 60), faculté A.
 */
class SimulationTemporelleSim04Test extends TestCase
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
            'name' => 'SIM04', 'email' => 'sim04-'.uniqid().'@u.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->adminB = User::creerCompteBackOffice([
            'name' => 'SIM04-B', 'email' => 'sim04b-'.uniqid().'@u.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteB->id,
        ], 'admin_faculte');
        $this->sansPermission = User::creerCompteBackOffice([
            'name' => 'SIM04-N', 'email' => 'sim04n-'.uniqid().'@u.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->habiliter($this->admin);
        $this->habiliter($this->adminB);

        // Effectifs réels synthétiques de la faculté A : L1=10, L2=20, L3=30.
        $programme = Programme::create([
            'faculte_id' => $this->faculteA->id, 'code' => 'PFST4', 'nom' => 'Prog',
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
            'horizon_annees' => 3,
            'admissions_exceptionnelles' => 5,
            'pas_exceptionnel' => 2,
            'taux' => ['passage' => 0.0, 'redoublement' => 1.0, 'abandon' => 0.0],
        ];

        return $this->persisterScenario(array_merge($defauts, $criteres), $contraintes, $objectifs);
    }

    /**
     * Même socle SANS choc ponctuel : les deux paramètres sont retirés,
     * y compris s'ils sont fournis par l'appelant. Aucun choc résiduel
     * implicite ne doit subsister dans une variante dite « de référence ».
     */
    private function creerScenarioSansChoc(array $criteres = [], array $contraintes = [], array $objectifs = []): ScenarioTemporel
    {
        $defauts = [
            'delta_admissions' => 0.0,
            'bornes' => ['min' => 0.0, 'max' => 0.0],
            'horizon_annees' => 3,
            'taux' => ['passage' => 0.0, 'redoublement' => 1.0, 'abandon' => 0.0],
        ];

        $criteres = array_merge($defauts, $criteres);
        unset($criteres['pas_exceptionnel'], $criteres['admissions_exceptionnelles']);

        return $this->persisterScenario($criteres, $contraintes, $objectifs);
    }

    private function persisterScenario(array $criteres, array $contraintes, array $objectifs): ScenarioTemporel
    {
        return ScenarioTemporel::create([
            'code' => 'SIM04-'.uniqid(),
            'libelle' => 'Choc SIM-04',
            'domaine' => 'effectifs_cohortes',
            'faculte_id' => $this->faculteA->id,
            'criteres' => $criteres,
            'contraintes' => $contraintes,
            'objectifs' => $objectifs,
            'version' => 1,
            'cree_par' => $this->admin->id,
        ]);
    }

    private function executer(ScenarioTemporel $scenario, string $graine = 'graine-sim04'): array
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
            'code' => 'SIM04-DEF-'.uniqid(),
            'libelle' => 'Choc SIM-04 (définition)',
            'faculte_id' => $this->faculteA->id,
            'criteres' => $criteres,
            'contraintes' => [],
            'objectifs' => [['type' => 'stabilite', 'poids' => 1]],
        ];
    }

    /* ── E. Validation mathématique indépendante ─────────────────────── */

    public function test_sim04_choc_ponctuel_exact_sans_effet_sur_la_trajectoire_de_fond(): void
    {
        // Référence manuelle (calcul hors moteur) :
        // base L1 = 10 ; delta = 0.0 ; horizon = 3 ; choc = 5 au pas 2 ;
        // taux {passage: 0, redoublement: 1, abandon: 0} = progression gelée.
        // p1 (pas ≠ 2) : admissions = round(10 × 1^1) = 10
        //   → L1 = 10 (redoublants) + 10 = 20 ; total = 20+20+30 = 70
        // p2 (choc) : admissions = 10 + 5 = 15
        //   → L1 = 20 + 15 = 35 ; total = 35+20+30 = 85
        // p3 (pas ≠ 2) : admissions = 10 (PAS 15 : le choc n'est pas composé)
        //   → L1 = 35 + 10 = 45 ; total = 45+20+30 = 95
        $scenario = $this->creerScenario();

        $execution = $this->executer($scenario, 'sim04-ref-ponctuel');
        $variante = $execution['resultat']['classement'][0];

        $this->assertSame([60, 70, 85, 95], $variante['totaux']);
        $this->assertSame(95, $variante['total_final']);
        $this->assertSame([
            ['pas' => 1, 'admissions' => 10],
            ['pas' => 2, 'admissions' => 15],
            ['pas' => 3, 'admissions' => 10],
        ], $variante['admissions_par_periode']);

        $this->assertSame(60, $execution['resultat']['etat_initial']['total_initial']);
        $this->assertSame(['L1' => 10, 'L2' => 20, 'L3' => 30], $execution['resultat']['etat_initial']['cohortes']);
    }

    public function test_sim04_choc_avec_flux_et_conservation_stricte_des_effectifs(): void
    {
        // Référence manuelle (calcul hors moteur) :
        // base L1 = 10 ; delta = 0.0 ; horizon = 2 ; choc = 5 au pas 2 ;
        // taux {passage: 0.5, redoublement: 0.3, abandon: 0.2}
        // Pas 1 (sans choc) : admissions = 10
        //   L3 (30) : 15 partent (diplômés), 9 redoublent, 6 abandons
        //   L2 (20) : 10 partent vers L3, 6 redoublent, 4 abandons
        //   L1 (10) : 5 partent vers L2, 3 redoublent, 2 abandons
        //   L1 = 3 + 10 = 13 ; L2 = 6 + 5 = 11 ; L3 = 9 + 10 = 19 ; total = 43
        //   Conservation : 60 + 10 - 15 - 12 = 43
        // Pas 2 (choc) : admissions = 10 + 5 = 15
        //   L3 (19) : round(9.5) = 10 diplômés, round(5.7) = 6 redoublent, 3 abandons
        //   L2 (11) : round(5.5) = 6 vers L3, round(3.3) = 3 redoublent, 2 abandons
        //   L1 (13) : round(6.5) = 7 vers L2, round(3.9) = 4 redoublent, 2 abandons
        //   L1 = 4 + 15 = 19 ; L2 = 3 + 7 = 10 ; L3 = 6 + 10 = 12 ; total = 41
        //   Conservation : 43 + 15 - 10 - 7 = 41
        $scenario = $this->creerScenario([
            'horizon_annees' => 2,
            'taux' => ['passage' => 0.5, 'redoublement' => 0.3, 'abandon' => 0.2],
        ]);

        $execution = $this->executer($scenario, 'sim04-conservation');
        $variante = $execution['resultat']['classement'][0];

        $this->assertSame([60, 43, 41], $variante['totaux']);
        $this->assertSame([
            ['pas' => 1, 'admissions' => 10],
            ['pas' => 2, 'admissions' => 15],
        ], $variante['admissions_par_periode']);

        $snapshots = SnapshotExecution::where('execution_temporelle_id', $execution['id'])
            ->orderBy('pas')->get();
        $this->assertSame(['L1' => 13, 'L2' => 11, 'L3' => 19], $snapshots->firstWhere('pas', 1)->etat['cohortes']);
        $this->assertSame(['L1' => 19, 'L2' => 10, 'L3' => 12], $snapshots->firstWhere('pas', 2)->etat['cohortes']);

        foreach ($snapshots as $snapshot) {
            $this->assertSame(
                $snapshot->etat['total'],
                array_sum($snapshot->etat['cohortes']),
                'Le total du snapshot doit égaler la somme des niveaux.'
            );
            $this->assertGreaterThanOrEqual(0, min($snapshot->etat['cohortes']));
        }
    }

    /* ── C. Validation du contrat au moment de la définition ─────────── */

    public function test_sim04_definition_refuse_les_chocs_incomplets_ou_hors_horizon(): void
    {
        $service = app(ServiceSimulationTemporelle::class);
        $base = [
            'delta_admissions' => 0.0,
            'bornes' => ['min' => 0.0, 'max' => 0.0],
            'horizon_annees' => 3,
            'admissions_exceptionnelles' => 5,
            'pas_exceptionnel' => 2,
            'taux' => ['passage' => 0.6, 'redoublement' => 0.3, 'abandon' => 0.1],
        ];

        // Les deux paramètres sont indissociables.
        $sansChoc = $base;
        unset($sansChoc['admissions_exceptionnelles']);
        $this->assertRefuseDefinition(
            fn () => $service->definir($this->donneesDefinition($sansChoc), $this->admin->id),
            'indissociables'
        );

        // Pas hors horizon.
        $this->assertRefuseDefinition(
            fn () => $service->definir($this->donneesDefinition(array_merge($base, ['pas_exceptionnel' => 4])), $this->admin->id),
            'entre 1 et l\'horizon'
        );

        // Choc nul.
        $this->assertRefuseDefinition(
            fn () => $service->definir($this->donneesDefinition(array_merge($base, ['admissions_exceptionnelles' => 0])), $this->admin->id),
            'strictement positif'
        );

        $this->assertSame(0, ScenarioTemporel::count());
    }

    /* ── B/D. Déterminisme, idempotence, graine ──────────────────────── */

    public function test_sim04_memes_entrees_idempotentes_et_graine_reproductible(): void
    {
        $scenario = $this->creerScenario();

        $premier = $this->executer($scenario, 'graine-sim04-idemp');
        $second = $this->executer($scenario, 'graine-sim04-idemp');

        $this->assertSame($premier['id'], $second['id']);
        $this->assertSame($premier['hash_resultat'], $second['hash_resultat']);
        $this->assertSame(
            $premier['resultat']['classement'][0]['admissions_par_periode'],
            $second['resultat']['classement'][0]['admissions_par_periode']
        );
        $this->assertSame(1, ExecutionTemporelle::where('scenario_temporel_id', $scenario->id)->count());

        // Autre graine = nouvelle exécution, variante 0 identique.
        $autre = $this->executer($scenario, 'graine-sim04-autre');
        $this->assertNotSame($premier['id'], $autre['id']);
        $this->assertSame(
            $premier['resultat']['classement'][0]['admissions_par_periode'],
            $autre['resultat']['classement'][0]['admissions_par_periode']
        );
    }

    /* ── E. Capacité : le pic exceptionnel crée un besoin supplémentaire ─ */

    public function test_sim04_capacite_et_besoin_de_groupes_au_pic(): void
    {
        Group::create([
            'code' => 'SIM04-G1', 'nom' => 'Groupe SIM-04', 'faculte_id' => $this->faculteA->id,
            'niveau' => 'L1', 'actif' => true, 'niveau_id' => $this->niveauL1->id, 'capacite' => 15,
        ]);

        $sansChoc = $this->creerScenarioSansChoc([
            'horizon_annees' => 2,
            'niveau_id' => $this->niveauL1->id,
        ]);
        $avecChoc = $this->creerScenario([
            'horizon_annees' => 2,
            'pas_exceptionnel' => 2,
            'admissions_exceptionnelles' => 5,
            'niveau_id' => $this->niveauL1->id,
        ]);

        $executionSans = $this->executer($sansChoc, 'sim04-cap-sans');
        $executionAvec = $this->executer($avecChoc, 'sim04-cap-avec');

        // Périmètre restreint à L1, progression gelée (redoublement = 1).
        // Sans choc : p1 = 10 + 10 = 20 ; p2 = 20 + 10 = 30.
        $this->assertSame(30, $executionSans['resultat']['classement'][0]['total_final']);
        $this->assertSame(2, $executionSans['resultat']['capacites']['groupes_theoriques_requis_total']);
        $this->assertSame(1, $executionSans['resultat']['capacites']['deficit_groupes']);
        $this->assertSame(15, $executionSans['resultat']['capacites']['places_manquantes']);

        // Avec choc au pas 2 : p1 = 20 ; p2 = 20 + 15 = 35.
        $this->assertSame(35, $executionAvec['resultat']['classement'][0]['total_final']);
        $this->assertSame(3, $executionAvec['resultat']['capacites']['groupes_theoriques_requis_total']);
        $this->assertSame(2, $executionAvec['resultat']['capacites']['deficit_groupes']);
        $this->assertSame(20, $executionAvec['resultat']['capacites']['places_manquantes']);
        $this->assertTrue($executionAvec['resultat']['capacites']['depassement_capacite']);

        // Le groupe réel en base n'a pas été modifié.
        $groupe = Group::where('code', 'SIM04-G1')->firstOrFail();
        $this->assertSame(15, (int) $groupe->capacite);
        $this->assertTrue((bool) $groupe->actif);
    }

    /* ── F. Sécurité et lecture seule ────────────────────────────────── */

    public function test_sim04_permissions_et_isolation_facultaire(): void
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
    }

    public function test_sim04_zero_ecriture_metier_et_tables_techniques_seules(): void
    {
        $scenario = $this->creerScenario(['horizon_annees' => 2]);

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

        $this->executer($scenario, 'sim04-ro');

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
