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
 * ST-14 / SIM-05 — croissance supérieure à la cible saisie.
 *
 * Les résultats attendus sont calculés À LA MAIN dans les commentaires
 * (aucune réutilisation de la formule du moteur dans les assertions).
 *
 * Périmètre synthétique : L1=10, L2=20, L3=30 (total 60), faculté A.
 */
class SimulationTemporelleSim05Test extends TestCase
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
            'name' => 'SIM05', 'email' => 'sim05-'.uniqid().'@u.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->adminB = User::creerCompteBackOffice([
            'name' => 'SIM05-B', 'email' => 'sim05b-'.uniqid().'@u.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteB->id,
        ], 'admin_faculte');
        $this->sansPermission = User::creerCompteBackOffice([
            'name' => 'SIM05-N', 'email' => 'sim05n-'.uniqid().'@u.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->habiliter($this->admin);
        $this->habiliter($this->adminB);

        // Effectifs réels synthétiques de la faculté A : L1=10, L2=20, L3=30.
        $programme = Programme::create([
            'faculte_id' => $this->faculteA->id, 'code' => 'PFST5', 'nom' => 'Prog',
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
            'delta_admissions' => 0.10,
            'bornes' => ['min' => 0.0, 'max' => 0.5],
            'horizon_annees' => 3,
            'taux_cible_croissance' => 0.05,
            'taux' => ['passage' => 0.0, 'redoublement' => 1.0, 'abandon' => 0.0],
        ];

        return $this->persisterScenario(array_merge($defauts, $criteres), $contraintes, $objectifs);
    }

    /** Même socle SANS cible : la clé est retirée, y compris si fournie. */
    private function creerScenarioSansCible(array $criteres = [], array $contraintes = [], array $objectifs = []): ScenarioTemporel
    {
        $defauts = [
            'delta_admissions' => 0.10,
            'bornes' => ['min' => 0.0, 'max' => 0.5],
            'horizon_annees' => 3,
            'taux' => ['passage' => 0.0, 'redoublement' => 1.0, 'abandon' => 0.0],
        ];

        $criteres = array_merge($defauts, $criteres);
        unset($criteres['taux_cible_croissance']);

        return $this->persisterScenario($criteres, $contraintes, $objectifs);
    }

    private function persisterScenario(array $criteres, array $contraintes, array $objectifs): ScenarioTemporel
    {
        return ScenarioTemporel::create([
            'code' => 'SIM05-'.uniqid(),
            'libelle' => 'Cible SIM-05',
            'domaine' => 'effectifs_cohortes',
            'faculte_id' => $this->faculteA->id,
            'criteres' => $criteres,
            'contraintes' => $contraintes,
            'objectifs' => $objectifs,
            'version' => 1,
            'cree_par' => $this->admin->id,
        ]);
    }

    private function executer(ScenarioTemporel $scenario, string $graine = 'graine-sim05'): array
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
            'code' => 'SIM05-DEF-'.uniqid(),
            'libelle' => 'Cible SIM-05 (définition)',
            'faculte_id' => $this->faculteA->id,
            'criteres' => $criteres,
            'contraintes' => [],
            'objectifs' => [['type' => 'stabilite', 'poids' => 1]],
        ];
    }

    /* ── E. Validation mathématique indépendante ─────────────────────── */

    public function test_sim05_cible_5_pct_contre_croissance_10_pct_ecarts_par_periode(): void
    {
        // Référence manuelle (calcul hors moteur) :
        // base L1 = 10 ; delta = 0.10 ; cible = 0.05 ; horizon = 3 ;
        // taux {passage: 0, redoublement: 1, abandon: 0} = progression gelée.
        // Réelles  : p1 round(10 × 1.10) = 11 ; p2 round(10 × 1.21) = 12 ;
        //            p3 round(10 × 1.331) = 13.
        // Cibles   : p1 round(10 × 1.05) = 11 ; p2 round(10 × 1.1025) = 11 ;
        //            p3 round(10 × 1.157625) = 12.
        // Écarts   : 0 ; 1 ; 1 → cumul = 2 ; dépassement = vrai (p2, p3).
        // Totaux   : L1 = 10+11 = 21 → 71 ; 21+12 = 33 → 83 ; 33+13 = 46 → 96.
        $scenario = $this->creerScenario();

        $execution = $this->executer($scenario, 'sim05-ref-cible5');
        $variante = $execution['resultat']['classement'][0];

        $this->assertSame([60, 71, 83, 96], $variante['totaux']);
        $this->assertSame(96, $variante['total_final']);
        $this->assertSame([
            ['pas' => 1, 'admissions' => 11],
            ['pas' => 2, 'admissions' => 12],
            ['pas' => 3, 'admissions' => 13],
        ], $variante['admissions_par_periode']);

        $this->assertSame([
            ['pas' => 1, 'admissions_cible' => 11],
            ['pas' => 2, 'admissions_cible' => 11],
            ['pas' => 3, 'admissions_cible' => 12],
        ], $variante['admissions_cible_par_periode']);

        $this->assertSame([
            ['pas' => 1, 'admissions' => 11, 'admissions_cible' => 11, 'ecart' => 0, 'depassement' => false],
            ['pas' => 2, 'admissions' => 12, 'admissions_cible' => 11, 'ecart' => 1, 'depassement' => true],
            ['pas' => 3, 'admissions' => 13, 'admissions_cible' => 12, 'ecart' => 1, 'depassement' => true],
        ], $variante['ecarts_cible_par_periode']);

        $this->assertSame(2, $variante['ecart_cible_cumule']);
        $this->assertTrue($variante['depassement_cible']);

        $this->assertSame(60, $execution['resultat']['etat_initial']['total_initial']);
        $this->assertSame(['L1' => 10, 'L2' => 20, 'L3' => 30], $execution['resultat']['etat_initial']['cohortes']);
    }

    public function test_sim05_cible_10_pct_contre_croissance_20_pct_cumul_7(): void
    {
        // Référence manuelle (calcul hors moteur) :
        // delta = 0.20 ; cible = 0.10 ; horizon = 3 ; flux gelés.
        // Réelles : 12 ; 14 ; 17 (round(12), round(14.4), round(17.28)).
        // Cibles  : 11 ; 12 ; 13 (round(11), round(12.1), round(13.31)).
        // Écarts  : 1 ; 2 ; 4 → cumul = 7 ; dépassement = vrai sur les 3 pas.
        // Totaux  : L1 = 10+12 = 22 → 72 ; 22+14 = 36 → 86 ;
        //           36+17 = 53 → 103.
        $scenario = $this->creerScenario([
            'delta_admissions' => 0.20,
            'taux_cible_croissance' => 0.10,
        ]);

        $execution = $this->executer($scenario, 'sim05-ref-cible10');
        $variante = $execution['resultat']['classement'][0];

        $this->assertSame([60, 72, 86, 103], $variante['totaux']);
        $this->assertSame([
            ['pas' => 1, 'admissions' => 12],
            ['pas' => 2, 'admissions' => 14],
            ['pas' => 3, 'admissions' => 17],
        ], $variante['admissions_par_periode']);
        $this->assertSame([1, 2, 4], array_column($variante['ecarts_cible_par_periode'], 'ecart'));
        $this->assertSame([true, true, true], array_column($variante['ecarts_cible_par_periode'], 'depassement'));
        $this->assertSame(7, $variante['ecart_cible_cumule']);
        $this->assertTrue($variante['depassement_cible']);
    }

    public function test_sim05_flux_actifs_conservation_et_ecart_zero_au_premier_pas(): void
    {
        // Référence manuelle (calcul hors moteur) :
        // delta = 0.10 ; cible = 0.05 ; horizon = 2 ;
        // taux {passage: 0.5, redoublement: 0.3, abandon: 0.2}.
        // Pas 1 : admissions 11, cible 11 → écart 0.
        //   L3 (30) : 15 partent, 9 redoublent, 6 abandons ;
        //   L2 (20) : 10 partent, 6 redoublent, 4 abandons ;
        //   L1 (10) : 5 partent, 3 redoublent, 2 abandons.
        //   L1 = 3+11 = 14 ; L2 = 6+5 = 11 ; L3 = 9+10 = 19 ; total = 44.
        // Pas 2 : admissions 12, cible 11 → écart 1.
        //   L3 (19) : 10 partent, 6 redoublent, 3 abandons ;
        //   L2 (11) : 6 partent, 3 redoublent, 2 abandons ;
        //   L1 (14) : 7 partent, 4 redoublent, 3 abandons.
        //   L1 = 4+12 = 16 ; L2 = 3+7 = 10 ; L3 = 6+6 = 12 ; total = 38.
        // Cumul = 1 ; dépassement = vrai.
        $scenario = $this->creerScenario([
            'horizon_annees' => 2,
            'taux' => ['passage' => 0.5, 'redoublement' => 0.3, 'abandon' => 0.2],
        ]);

        $execution = $this->executer($scenario, 'sim05-conservation');
        $variante = $execution['resultat']['classement'][0];

        $this->assertSame([60, 44, 38], $variante['totaux']);
        $this->assertSame([
            ['pas' => 1, 'admissions' => 11, 'admissions_cible' => 11, 'ecart' => 0, 'depassement' => false],
            ['pas' => 2, 'admissions' => 12, 'admissions_cible' => 11, 'ecart' => 1, 'depassement' => true],
        ], $variante['ecarts_cible_par_periode']);
        $this->assertSame(1, $variante['ecart_cible_cumule']);

        $snapshots = SnapshotExecution::where('execution_temporelle_id', $execution['id'])
            ->orderBy('pas')->get();
        $this->assertSame(['L1' => 14, 'L2' => 11, 'L3' => 19], $snapshots->firstWhere('pas', 1)->etat['cohortes']);
        $this->assertSame(['L1' => 16, 'L2' => 10, 'L3' => 12], $snapshots->firstWhere('pas', 2)->etat['cohortes']);

        foreach ($snapshots as $snapshot) {
            $this->assertSame(
                $snapshot->etat['total'],
                array_sum($snapshot->etat['cohortes']),
                'Le total du snapshot doit égaler la somme des niveaux.'
            );
            $this->assertGreaterThanOrEqual(0, min($snapshot->etat['cohortes']));
        }
    }

    public function test_sim05_arrondi_peut_annuler_un_depassement_exact_marginal(): void
    {
        // Référence manuelle (calcul hors moteur) :
        // delta = 0.02 ; cible = 0.01 ; horizon = 1 ; flux gelés.
        // Exact : 10 × 1.02 = 10.2 > 10.1 = 10 × 1.01.
        // Arrondis : round(10.2) = 10 ; round(10.1) = 10 → écart 0.
        // Le contrat garantit delta > cible, PAS un écart entier positif :
        // depassement_cible reste faux, sans affaiblir aucune assertion.
        $scenario = $this->creerScenario([
            'delta_admissions' => 0.02,
            'bornes' => ['min' => 0.0, 'max' => 0.1],
            'taux_cible_croissance' => 0.01,
            'horizon_annees' => 1,
        ]);

        $execution = $this->executer($scenario, 'sim05-arrondi');
        $variante = $execution['resultat']['classement'][0];

        $this->assertSame([
            ['pas' => 1, 'admissions' => 10, 'admissions_cible' => 10, 'ecart' => 0, 'depassement' => false],
        ], $variante['ecarts_cible_par_periode']);
        $this->assertSame(0, $variante['ecart_cible_cumule']);
        $this->assertFalse($variante['depassement_cible']);
        $this->assertSame([60, 70], $variante['totaux']);
    }

    /* ── C. Validation du contrat au moment de la définition ─────────── */

    public function test_sim05_definition_refuse_cible_inferieure_ou_incompatible(): void
    {
        $service = app(ServiceSimulationTemporelle::class);
        $base = [
            'delta_admissions' => 0.10,
            'bornes' => ['min' => 0.0, 'max' => 0.5],
            'horizon_annees' => 3,
            'taux_cible_croissance' => 0.05,
            'taux' => ['passage' => 0.6, 'redoublement' => 0.3, 'abandon' => 0.1],
        ];

        // Cible égale au delta : aucun dépassement possible.
        $this->assertRefuseDefinition(
            fn () => $service->definir($this->donneesDefinition(array_merge($base, ['taux_cible_croissance' => 0.10])), $this->admin->id),
            'strictement supérieur'
        );

        // Cible supérieure au delta.
        $this->assertRefuseDefinition(
            fn () => $service->definir($this->donneesDefinition(array_merge($base, ['taux_cible_croissance' => 0.30])), $this->admin->id),
            'strictement supérieur'
        );

        // Cible hors bornes moteur [0 ; 2.0].
        $this->assertRefuseDefinition(
            fn () => $service->definir($this->donneesDefinition(array_merge($base, ['taux_cible_croissance' => -0.01])), $this->admin->id),
            'hors bornes'
        );
        $this->assertRefuseDefinition(
            fn () => $service->definir($this->donneesDefinition(array_merge($base, ['taux_cible_croissance' => 2.5])), $this->admin->id),
            'hors bornes'
        );

        // Combinaisons interdites : gel (SIM-03) et choc (SIM-04).
        $this->assertRefuseDefinition(
            fn () => $service->definir($this->donneesDefinition(array_merge($base, ['duree_gel_annees' => 2])), $this->admin->id),
            'SIM-03'
        );
        $this->assertRefuseDefinition(
            fn () => $service->definir($this->donneesDefinition(array_merge($base, [
                'pas_exceptionnel' => 2, 'admissions_exceptionnelles' => 5,
            ])), $this->admin->id),
            'SIM-04'
        );

        // Détournement en baisse.
        $this->assertRefuseDefinition(
            fn () => $service->definir($this->donneesDefinition(array_merge($base, [
                'delta_admissions' => -0.10, 'bornes' => ['min' => -0.2, 'max' => 0.5], 'taux_cible_croissance' => 0.0,
            ])), $this->admin->id),
            'SIM-02'
        );

        $this->assertSame(0, ScenarioTemporel::count());
    }

    /* ── B/D. Déterminisme, idempotence et rétrocompatibilité ────────── */

    public function test_sim05_idempotence_reproductibilite_et_absence_de_cle_sans_cible(): void
    {
        $scenario = $this->creerScenario();

        $premier = $this->executer($scenario, 'graine-sim05-idemp');
        $second = $this->executer($scenario, 'graine-sim05-idemp');

        $this->assertSame($premier['id'], $second['id']);
        $this->assertSame($premier['hash_resultat'], $second['hash_resultat']);
        $this->assertSame(
            $premier['resultat']['classement'][0]['ecarts_cible_par_periode'],
            $second['resultat']['classement'][0]['ecarts_cible_par_periode']
        );
        $this->assertSame(1, ExecutionTemporelle::where('scenario_temporel_id', $scenario->id)->count());

        // Autre graine = nouvelle exécution, variante 0 identique.
        $autre = $this->executer($scenario, 'graine-sim05-autre');
        $this->assertNotSame($premier['id'], $autre['id']);
        $this->assertSame(
            $premier['resultat']['classement'][0]['admissions_cible_par_periode'],
            $autre['resultat']['classement'][0]['admissions_cible_par_periode']
        );

        // Rétrocompatibilité : sans taux cible, aucune clé de cible n'est ajoutée
        // et les totaux restent identiques (la cible ne modifie pas les cohortes).
        $sansCible = $this->creerScenarioSansCible();
        $executionSans = $this->executer($sansCible, 'sim05-sans-cible');
        $varianteSans = $executionSans['resultat']['classement'][0];

        $this->assertArrayNotHasKey('admissions_cible_par_periode', $varianteSans);
        $this->assertArrayNotHasKey('ecarts_cible_par_periode', $varianteSans);
        $this->assertArrayNotHasKey('ecart_cible_cumule', $varianteSans);
        $this->assertArrayNotHasKey('depassement_cible', $varianteSans);
        $this->assertSame([60, 71, 83, 96], $varianteSans['totaux']);
    }

    /* ── F. Sécurité et lecture seule ────────────────────────────────── */

    public function test_sim05_permissions_isolation_facultaire_et_zero_ecriture_metier(): void
    {
        $scenario = $this->creerScenario(['horizon_annees' => 3]);

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

        $this->executer($scenario, 'sim05-ro');

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
        $this->assertSame(4, SnapshotExecution::count()); // pas 0, 1, 2, 3
    }
}
