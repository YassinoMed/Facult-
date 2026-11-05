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
 * ST-14 / SIM-08 — dégradation de la réussite (miroir de SIM-07).
 *
 * Les résultats attendus sont calculés À LA MAIN dans les commentaires
 * (aucune réutilisation de la formule du moteur dans les assertions).
 *
 * Périmètre synthétique : L1=10, L2=20, L3=30 (total 60), faculté A.
 */
class SimulationTemporelleSim08Test extends TestCase
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
            'name' => 'SIM08', 'email' => 'sim08-'.uniqid().'@u.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->adminB = User::creerCompteBackOffice([
            'name' => 'SIM08-B', 'email' => 'sim08b-'.uniqid().'@u.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteB->id,
        ], 'admin_faculte');
        $this->sansPermission = User::creerCompteBackOffice([
            'name' => 'SIM08-N', 'email' => 'sim08n-'.uniqid().'@u.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->habiliter($this->admin);
        $this->habiliter($this->adminB);

        // Effectifs réels synthétiques de la faculté A : L1=10, L2=20, L3=30.
        $programme = Programme::create([
            'faculte_id' => $this->faculteA->id, 'code' => 'PFST8', 'nom' => 'Prog',
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
            'horizon_annees' => 2,
            'taux' => ['passage' => 0.7, 'redoublement' => 0.1, 'abandon' => 0.2],
            'taux_degrade' => ['passage' => 0.5, 'redoublement' => 0.3, 'abandon' => 0.2],
        ];

        return $this->persisterScenario(array_merge($defauts, $criteres), $contraintes, $objectifs);
    }

    /** Même socle SANS dégradation : le jeu dégradé est retiré. */
    private function creerScenarioSansDegradation(array $criteres = [], array $contraintes = [], array $objectifs = []): ScenarioTemporel
    {
        $defauts = [
            'delta_admissions' => 0.0,
            'bornes' => ['min' => 0.0, 'max' => 0.0],
            'horizon_annees' => 2,
            'taux' => ['passage' => 0.7, 'redoublement' => 0.1, 'abandon' => 0.2],
        ];

        $criteres = array_merge($defauts, $criteres);
        unset($criteres['taux_degrade']);

        return $this->persisterScenario($criteres, $contraintes, $objectifs);
    }

    private function persisterScenario(array $criteres, array $contraintes, array $objectifs): ScenarioTemporel
    {
        return ScenarioTemporel::create([
            'code' => 'SIM08-'.uniqid(),
            'libelle' => 'Dégradation SIM-08',
            'domaine' => 'effectifs_cohortes',
            'faculte_id' => $this->faculteA->id,
            'criteres' => $criteres,
            'contraintes' => $contraintes,
            'objectifs' => $objectifs,
            'version' => 1,
            'cree_par' => $this->admin->id,
        ]);
    }

    private function executer(ScenarioTemporel $scenario, string $graine = 'graine-sim08'): array
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
            'code' => 'SIM08-DEF-'.uniqid(),
            'libelle' => 'Dégradation SIM-08 (définition)',
            'faculte_id' => $this->faculteA->id,
            'criteres' => $criteres,
            'contraintes' => [],
            'objectifs' => [['type' => 'stabilite', 'poids' => 1]],
        ];
    }

    /* ── E. Validation mathématique indépendante ─────────────────────── */

    public function test_sim08_baisse_puisee_dans_le_redoublement_accumulation_et_diplomes_perdus(): void
    {
        // Référence manuelle (calcul hors moteur) :
        // base L1=10, L2=20, L3=30 ; delta = 0 ; H = 2 ; admissions [10, 10].
        // Dégradé {0.5, 0.3, 0.2} → totaux [60, 43, 36] ; sortants [15, 10].
        // Référence {0.7, 0.1, 0.2} → totaux [60, 37, 28] ; sortants [21, 12].
        // Écarts +6/+8 (étudiants bloqués) ; écart final +8 ;
        // effet sortants (15+10)-(21+12) = -8 (diplômés perdus).
        // Snapshots dégradés : p1 {13, 11, 19} ; p2 {14, 10, 12}.
        $scenario = $this->creerScenario();

        $execution = $this->executer($scenario, 'sim08-ref-accumulation');
        $variante = $execution['resultat']['classement'][0];

        $this->assertSame([60, 43, 36], $variante['totaux']);
        $this->assertSame(36, $variante['total_final']);
        $this->assertSame([60, 37, 28], $variante['totaux_reference_reussite']);

        $this->assertSame(0.2, $variante['baisse_passage']);
        $this->assertArrayNotHasKey('hausse_passage', $variante);

        $this->assertSame([
            ['pas' => 1, 'total_simule' => 43, 'total_reference' => 37, 'ecart' => 6],
            ['pas' => 2, 'total_simule' => 36, 'total_reference' => 28, 'ecart' => 8],
        ], $variante['ecarts_reussite_par_periode']);
        $this->assertSame(8, $variante['ecart_reussite_final']);

        $this->assertSame([
            ['pas' => 1, 'sortants' => 15, 'sortants_reference' => 21],
            ['pas' => 2, 'sortants' => 10, 'sortants_reference' => 12],
        ], $variante['sortants_par_periode']);
        $this->assertSame(-8, $variante['effet_sortants_cumules']);

        $this->assertSame([
            ['pas' => 1, 'admissions' => 10],
            ['pas' => 2, 'admissions' => 10],
        ], $variante['admissions_par_periode']);

        $snapshots = SnapshotExecution::where('execution_temporelle_id', $execution['id'])
            ->orderBy('pas')->get();
        $this->assertSame(['L1' => 13, 'L2' => 11, 'L3' => 19], $snapshots->firstWhere('pas', 1)->etat['cohortes']);
        $this->assertSame(['L1' => 14, 'L2' => 10, 'L3' => 12], $snapshots->firstWhere('pas', 2)->etat['cohortes']);
    }

    public function test_sim08_baisse_absorbee_par_le_reste_effets_negatifs_sur_les_effectifs(): void
    {
        // Référence manuelle (calcul hors moteur) :
        // delta = 0 ; H = 1 ; admissions [10].
        // Dégradé {0.5, 0.2, 0.1} (somme 0.8) → total 37 ; sortants [15].
        //   L3 : 15/6/autres 9 ; L2 : 10/4/6 ; L1 : 5/2/3.
        //   L1 = 2+10 = 12 ; L2 = 4+5 = 9 ; L3 = 6+10 = 16 ; total 37.
        // Référence {0.7, 0.2, 0.1} (somme 1.0) → total 43 ; sortants [21].
        //   L3 : 21/6/3 ; L2 : 14/4/2 ; L1 : 7/2/1.
        //   L1 = 2+10 = 12 ; L2 = 4+7 = 11 ; L3 = 6+14 = 20 ; total 43.
        // Écart [-6] ; effet sortants -6 ; baisse 0.2.
        $scenario = $this->creerScenario([
            'horizon_annees' => 1,
            'taux' => ['passage' => 0.7, 'redoublement' => 0.2, 'abandon' => 0.1],
            'taux_degrade' => ['passage' => 0.5, 'redoublement' => 0.2, 'abandon' => 0.1],
        ]);

        $execution = $this->executer($scenario, 'sim08-ref-reste');
        $variante = $execution['resultat']['classement'][0];

        $this->assertSame([60, 37], $variante['totaux']);
        $this->assertSame([60, 43], $variante['totaux_reference_reussite']);
        $this->assertSame([
            ['pas' => 1, 'total_simule' => 37, 'total_reference' => 43, 'ecart' => -6],
        ], $variante['ecarts_reussite_par_periode']);
        $this->assertSame(-6, $variante['ecart_reussite_final']);
        $this->assertSame([
            ['pas' => 1, 'sortants' => 15, 'sortants_reference' => 21],
        ], $variante['sortants_par_periode']);
        $this->assertSame(-6, $variante['effet_sortants_cumules']);
        $this->assertSame(0.2, $variante['baisse_passage']);
    }

    public function test_sim08_admissions_identiques_quelle_que_soit_la_reussite(): void
    {
        // Référence manuelle (calcul hors moteur) :
        // delta = 0.10 → admissions [11, 12] dans les DEUX trajectoires.
        // Dégradé {0.5, 0.3, 0.2} → totaux [60, 44, 38] ; sortants [15, 10].
        // Référence {0.7, 0.1, 0.2} → totaux [60, 38, 30] ; sortants [21, 12].
        // Écarts [+6, +8] ; effet sortants -8.
        $scenario = $this->creerScenario([
            'delta_admissions' => 0.10,
            'bornes' => ['min' => 0.0, 'max' => 0.5],
        ]);

        $execution = $this->executer($scenario, 'sim08-delta');
        $variante = $execution['resultat']['classement'][0];

        $this->assertSame([11, 12], array_column($variante['admissions_par_periode'], 'admissions'));
        $this->assertSame([60, 44, 38], $variante['totaux']);
        $this->assertSame([60, 38, 30], $variante['totaux_reference_reussite']);
        $this->assertSame([6, 8], array_column($variante['ecarts_reussite_par_periode'], 'ecart'));
        $this->assertSame(8, $variante['ecart_reussite_final']);
        $this->assertSame(-8, $variante['effet_sortants_cumules']);
    }

    /* ── C. Validation du contrat au moment de la définition ─────────── */

    public function test_sim08_definition_refuse_baisse_absente_ou_invalide_et_incompatibilites(): void
    {
        $service = app(ServiceSimulationTemporelle::class);
        $base = [
            'delta_admissions' => 0.0,
            'bornes' => ['min' => 0.0, 'max' => 0.0],
            'horizon_annees' => 2,
            'taux' => ['passage' => 0.7, 'redoublement' => 0.1, 'abandon' => 0.2],
            'taux_degrade' => ['passage' => 0.5, 'redoublement' => 0.3, 'abandon' => 0.2],
        ];

        // Passage égal ou supérieur : pas une dégradation.
        $this->assertRefuseDefinition(
            fn () => $service->definir($this->donneesDefinition(array_merge($base, [
                'taux_degrade' => ['passage' => 0.7, 'redoublement' => 0.1, 'abandon' => 0.2],
            ])), $this->admin->id),
            'strictement inférieur'
        );
        $this->assertRefuseDefinition(
            fn () => $service->definir($this->donneesDefinition(array_merge($base, [
                'taux_degrade' => ['passage' => 0.8, 'redoublement' => 0.1, 'abandon' => 0.1],
            ])), $this->admin->id),
            'strictement inférieur'
        );

        // Somme dégradée > 1.
        $this->assertRefuseDefinition(
            fn () => $service->definir($this->donneesDefinition(array_merge($base, [
                'taux_degrade' => ['passage' => 0.6, 'redoublement' => 0.4, 'abandon' => 0.1],
            ])), $this->admin->id),
            '> 1'
        );

        // Taux dégradé hors [0,1].
        $this->assertRefuseDefinition(
            fn () => $service->definir($this->donneesDefinition(array_merge($base, [
                'taux_degrade' => ['passage' => 1.5, 'redoublement' => 0.0, 'abandon' => 0.0],
            ])), $this->admin->id),
            'hors [0,1]'
        );

        // Jeu dégradé incomplet.
        $this->assertRefuseDefinition(
            fn () => $service->definir($this->donneesDefinition(array_merge($base, [
                'taux_degrade' => ['passage' => 0.5, 'redoublement' => 0.3],
            ])), $this->admin->id),
            'doit fournir'
        );

        // Référence absente alors que le jeu dégradé est fourni.
        $sansReference = $base;
        unset($sansReference['taux']);
        $this->assertRefuseDefinition(
            fn () => $service->definir($this->donneesDefinition($sansReference), $this->admin->id),
            'taux de référence requis'
        );

        // Incompatibilités : gel (SIM-03), choc (SIM-04), cible (SIM-05), creux (SIM-06).
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
        $this->assertRefuseDefinition(
            fn () => $service->definir($this->donneesDefinition(array_merge($base, [
                'profondeur_baisse' => 0.2, 'duree_baisse_annees' => 1, 'duree_recuperation_annees' => 1,
            ])), $this->admin->id),
            'SIM-06'
        );

        // Exclusion mutuelle : amélioration + dégradation simultanées.
        $this->assertRefuseDefinition(
            fn () => $service->definir($this->donneesDefinition(array_merge($base, [
                'taux_ameliore' => ['passage' => 0.8, 'redoublement' => 0.1, 'abandon' => 0.1],
            ])), $this->admin->id),
            'SIM-08'
        );

        $this->assertSame(0, ScenarioTemporel::count());
    }

    /* ── B/D. Déterminisme, idempotence et rétrocompatibilité ────────── */

    public function test_sim08_idempotence_et_absence_de_cles_sans_taux_degrade(): void
    {
        $scenario = $this->creerScenario();

        $premier = $this->executer($scenario, 'graine-sim08-idemp');
        $second = $this->executer($scenario, 'graine-sim08-idemp');

        $this->assertSame($premier['id'], $second['id']);
        $this->assertSame($premier['hash_resultat'], $second['hash_resultat']);
        $this->assertSame(
            $premier['resultat']['classement'][0]['ecarts_reussite_par_periode'],
            $second['resultat']['classement'][0]['ecarts_reussite_par_periode']
        );
        $this->assertSame(1, ExecutionTemporelle::where('scenario_temporel_id', $scenario->id)->count());

        // Autre graine = nouvelle exécution, variante 0 identique.
        $autre = $this->executer($scenario, 'graine-sim08-autre');
        $this->assertNotSame($premier['id'], $autre['id']);
        $this->assertSame(
            $premier['resultat']['classement'][0]['totaux'],
            $autre['resultat']['classement'][0]['totaux']
        );

        // Rétrocompatibilité : sans jeu dégradé, aucune clé de réussite et
        // la trajectoire unique suit les taux déclarés [60, 37, 28].
        $sansDegradation = $this->creerScenarioSansDegradation();
        $executionSans = $this->executer($sansDegradation, 'sim08-sans-degradation');
        $varianteSans = $executionSans['resultat']['classement'][0];

        $this->assertArrayNotHasKey('baisse_passage', $varianteSans);
        $this->assertArrayNotHasKey('hausse_passage', $varianteSans);
        $this->assertArrayNotHasKey('totaux_reference_reussite', $varianteSans);
        $this->assertArrayNotHasKey('ecarts_reussite_par_periode', $varianteSans);
        $this->assertArrayNotHasKey('ecart_reussite_final', $varianteSans);
        $this->assertArrayNotHasKey('sortants_par_periode', $varianteSans);
        $this->assertArrayNotHasKey('effet_sortants_cumules', $varianteSans);
        $this->assertSame([60, 37, 28], $varianteSans['totaux']);
    }

    public function test_sim08_analyses_sensibilite_et_monte_carlo_autour_des_taux_degrades(): void
    {
        // La trajectoire principale suit {0.5, 0.3, 0.2} : l'analyse OAT
        // doit exposer une valeur de base du passage à 0.5 (dégradée),
        // jamais 0.7 (référence), sous peine de décrire une autre exécution.
        $scenario = $this->creerScenario();

        $execution = $this->executer($scenario, 'sim08-analyses');
        $sensibilite = $execution['resultat']['sensibilite']['parametres_classes'];

        $lignePassage = null;
        foreach ($sensibilite as $ligne) {
            if ($ligne['parametre'] === 'taux_passage') {
                $lignePassage = $ligne;
            }
        }
        $this->assertNotNull($lignePassage);
        $this->assertSame(0.5, $lignePassage['valeur_base']);

        $ligneRedoublement = null;
        foreach ($sensibilite as $ligne) {
            if ($ligne['parametre'] === 'taux_redoublement') {
                $ligneRedoublement = $ligne;
            }
        }
        $this->assertNotNull($ligneRedoublement);
        $this->assertSame(0.3, $ligneRedoublement['valeur_base']);

        $this->assertSame(50, $execution['resultat']['monte_carlo']['nb_iterations']);
        $this->assertFalse((bool) $execution['resultat']['monte_carlo']['taux_absents']);
    }

    /* ── F. Sécurité et lecture seule ────────────────────────────────── */

    public function test_sim08_permissions_isolation_facultaire_et_zero_ecriture_metier(): void
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

        $this->executer($scenario, 'sim08-ro');

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
        $this->assertSame(3, SnapshotExecution::count()); // pas 0, 1, 2
    }
}
