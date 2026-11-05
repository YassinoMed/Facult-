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
 * ST-14 / SIM-10 — réduction du redoublement (variation mono-axe générique,
 * sens `baisse`).
 *
 * Les résultats attendus sont calculés À LA MAIN dans les commentaires
 * (aucune réutilisation de la formule du moteur dans les assertions).
 *
 * Périmètre synthétique : L1=10, L2=20, L3=30 (total 60), faculté A.
 */
class SimulationTemporelleSim10Test extends TestCase
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
            'name' => 'SIM10', 'email' => 'sim10-'.uniqid().'@u.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->adminB = User::creerCompteBackOffice([
            'name' => 'SIM10-B', 'email' => 'sim10b-'.uniqid().'@u.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteB->id,
        ], 'admin_faculte');
        $this->sansPermission = User::creerCompteBackOffice([
            'name' => 'SIM10-N', 'email' => 'sim10n-'.uniqid().'@u.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->habiliter($this->admin);
        $this->habiliter($this->adminB);

        // Effectifs réels synthétiques de la faculté A : L1=10, L2=20, L3=30.
        $programme = Programme::create([
            'faculte_id' => $this->faculteA->id, 'code' => 'PFST10', 'nom' => 'Prog',
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
            'taux' => ['passage' => 0.6, 'redoublement' => 0.2, 'abandon' => 0.2],
            'taux_modifie' => ['passage' => 0.6, 'redoublement' => 0.1, 'abandon' => 0.3],
            'axe_variation' => 'redoublement',
            'sens_variation' => 'baisse',
        ];

        return $this->persisterScenario(array_merge($defauts, $criteres), $contraintes, $objectifs);
    }

    /** Même socle SANS variation : le descripteur générique est retiré. */
    private function creerScenarioSansVariation(array $criteres = [], array $contraintes = [], array $objectifs = []): ScenarioTemporel
    {
        $defauts = [
            'delta_admissions' => 0.0,
            'bornes' => ['min' => 0.0, 'max' => 0.0],
            'horizon_annees' => 2,
            'taux' => ['passage' => 0.6, 'redoublement' => 0.2, 'abandon' => 0.2],
        ];

        $criteres = array_merge($defauts, $criteres);
        unset($criteres['taux_modifie'], $criteres['axe_variation'], $criteres['sens_variation']);

        return $this->persisterScenario($criteres, $contraintes, $objectifs);
    }

    private function persisterScenario(array $criteres, array $contraintes, array $objectifs): ScenarioTemporel
    {
        return ScenarioTemporel::create([
            'code' => 'SIM10-'.uniqid(),
            'libelle' => 'Réduction du redoublement SIM-10',
            'domaine' => 'effectifs_cohortes',
            'faculte_id' => $this->faculteA->id,
            'criteres' => $criteres,
            'contraintes' => $contraintes,
            'objectifs' => $objectifs,
            'version' => 1,
            'cree_par' => $this->admin->id,
        ]);
    }

    private function executer(ScenarioTemporel $scenario, string $graine = 'graine-sim10'): array
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
            'code' => 'SIM10-DEF-'.uniqid(),
            'libelle' => 'Réduction du redoublement SIM-10 (définition)',
            'faculte_id' => $this->faculteA->id,
            'criteres' => $criteres,
            'contraintes' => [],
            'objectifs' => [['type' => 'stabilite', 'poids' => 1]],
        ];
    }

    /* ── E. Validation mathématique indépendante ─────────────────────── */

    public function test_sim10_reduction_du_redoublement_reduit_les_effectifs(): void
    {
        // Référence manuelle (calcul hors moteur) :
        // base L1=10, L2=20, L3=30 ; delta = 0 ; H = 2 ; admissions [10, 10].
        // Référence {0.6, 0.2, 0.2} → totaux [60, 40, 31] ; sortants [18, 11].
        //   p1 : L1=12, L2=10, L3=18 (40) ; p2 : L1=12, L2=9, L3=10 (31).
        // Modifié {0.6, 0.1, 0.3} → totaux [60, 34, 26] ; sortants [18, 9].
        //   p1 : L1=11, L2=8, L3=15 (34) ; p2 : L1=11, L2=8, L3=7 (26).
        // Écarts [-6, -5] (effectifs réduits) ; écart final -5 ;
        // effet sortants (18+9)-(18+11) = -2 ; baisse_redoublement = 0.1.
        $scenario = $this->creerScenario();

        $execution = $this->executer($scenario, 'sim10-ref-reduction');
        $variante = $execution['resultat']['classement'][0];

        $this->assertSame([60, 34, 26], $variante['totaux']);
        $this->assertSame(26, $variante['total_final']);
        $this->assertSame([60, 40, 31], $variante['totaux_reference_reussite']);

        $this->assertSame(0.1, $variante['baisse_redoublement']);
        $this->assertArrayNotHasKey('hausse_redoublement', $variante);
        $this->assertArrayNotHasKey('baisse_abandon', $variante);
        $this->assertArrayNotHasKey('hausse_passage', $variante);
        $this->assertArrayNotHasKey('baisse_passage', $variante);

        $this->assertSame([
            ['pas' => 1, 'total_simule' => 34, 'total_reference' => 40, 'ecart' => -6],
            ['pas' => 2, 'total_simule' => 26, 'total_reference' => 31, 'ecart' => -5],
        ], $variante['ecarts_reussite_par_periode']);
        $this->assertSame(-5, $variante['ecart_reussite_final']);

        $this->assertSame([
            ['pas' => 1, 'sortants' => 18, 'sortants_reference' => 18],
            ['pas' => 2, 'sortants' => 9, 'sortants_reference' => 11],
        ], $variante['sortants_par_periode']);
        $this->assertSame(-2, $variante['effet_sortants_cumules']);

        $this->assertSame([
            ['pas' => 1, 'admissions' => 10],
            ['pas' => 2, 'admissions' => 10],
        ], $variante['admissions_par_periode']);

        $snapshots = SnapshotExecution::where('execution_temporelle_id', $execution['id'])
            ->orderBy('pas')->get();
        $this->assertSame(['L1' => 11, 'L2' => 8, 'L3' => 15], $snapshots->firstWhere('pas', 1)->etat['cohortes']);
        $this->assertSame(['L1' => 11, 'L2' => 8, 'L3' => 7], $snapshots->firstWhere('pas', 2)->etat['cohortes']);
    }

    public function test_sim10_baisse_absorbee_par_le_reste_effet_aval_nul_a_horizon_un(): void
    {
        // Référence manuelle (calcul hors moteur) :
        // delta = 0 ; H = 1 ; admissions [10].
        // Référence {0.6, 0.2, 0.1} (somme 0.9) → total 40 ; sortants [18].
        //   L3 : 18/6/3 ; L2 : 12/4/2 ; L1 : 6/2/1.
        //   L1 = 2+10 = 12 ; L2 = 4+6 = 10 ; L3 = 6+12 = 18 ; total 40.
        // Modifié {0.6, 0.1, 0.1} (somme 0.8) → total 34 ; sortants [18].
        //   L3 : 18/3/3 ; L2 : 12/2/2 ; L1 : 6/1/1.
        //   L1 = 1+10 = 11 ; L2 = 2+6 = 8 ; L3 = 3+12 = 15 ; total 34.
        // Écart [-6] ; effet sortants 0 (passage inchangé, aval non atteint).
        $scenario = $this->creerScenario([
            'horizon_annees' => 1,
            'taux' => ['passage' => 0.6, 'redoublement' => 0.2, 'abandon' => 0.1],
            'taux_modifie' => ['passage' => 0.6, 'redoublement' => 0.1, 'abandon' => 0.1],
        ]);

        $execution = $this->executer($scenario, 'sim10-ref-reste');
        $variante = $execution['resultat']['classement'][0];

        $this->assertSame([60, 34], $variante['totaux']);
        $this->assertSame([60, 40], $variante['totaux_reference_reussite']);
        $this->assertSame([
            ['pas' => 1, 'total_simule' => 34, 'total_reference' => 40, 'ecart' => -6],
        ], $variante['ecarts_reussite_par_periode']);
        $this->assertSame(-6, $variante['ecart_reussite_final']);
        $this->assertSame([
            ['pas' => 1, 'sortants' => 18, 'sortants_reference' => 18],
        ], $variante['sortants_par_periode']);
        $this->assertSame(0, $variante['effet_sortants_cumules']);
        $this->assertSame(0.1, $variante['baisse_redoublement']);
    }

    public function test_sim10_admissions_identiques_quelle_que_soit_la_reussite(): void
    {
        // Référence manuelle (calcul hors moteur) :
        // delta = 0.10 → admissions [11, 12] dans les DEUX trajectoires.
        // Référence {0.6, 0.2, 0.2} → totaux [60, 41, 35] ; sortants [18, 11].
        // Modifié {0.6, 0.1, 0.3} → totaux [60, 35, 28] ; sortants [18, 9].
        // Écarts [-6, -7] ; effet sortants -2.
        $scenario = $this->creerScenario([
            'delta_admissions' => 0.10,
            'bornes' => ['min' => 0.0, 'max' => 0.5],
        ]);

        $execution = $this->executer($scenario, 'sim10-delta');
        $variante = $execution['resultat']['classement'][0];

        $this->assertSame([11, 12], array_column($variante['admissions_par_periode'], 'admissions'));
        $this->assertSame([60, 35, 28], $variante['totaux']);
        $this->assertSame([60, 41, 35], $variante['totaux_reference_reussite']);
        $this->assertSame([-6, -7], array_column($variante['ecarts_reussite_par_periode'], 'ecart'));
        $this->assertSame(-7, $variante['ecart_reussite_final']);
        $this->assertSame(-2, $variante['effet_sortants_cumules']);
        $this->assertSame([18, 9], array_column($variante['sortants_par_periode'], 'sortants'));
    }

    /* ── C. Validation du contrat au moment de la définition ─────────── */

    public function test_sim10_definition_refuse_baisse_absente_ou_invalide_et_incompatibilites(): void
    {
        $service = app(ServiceSimulationTemporelle::class);
        $base = [
            'delta_admissions' => 0.0,
            'bornes' => ['min' => 0.0, 'max' => 0.0],
            'horizon_annees' => 2,
            'taux' => ['passage' => 0.6, 'redoublement' => 0.2, 'abandon' => 0.2],
            'taux_modifie' => ['passage' => 0.6, 'redoublement' => 0.1, 'abandon' => 0.3],
            'axe_variation' => 'redoublement',
            'sens_variation' => 'baisse',
        ];

        // Redoublement égal ou supérieur : pas une baisse.
        $this->assertRefuseDefinition(
            fn () => $service->definir($this->donneesDefinition(array_merge($base, [
                'taux_modifie' => ['passage' => 0.6, 'redoublement' => 0.2, 'abandon' => 0.2],
            ])), $this->admin->id),
            'strictement inférieur'
        );
        $this->assertRefuseDefinition(
            fn () => $service->definir($this->donneesDefinition(array_merge($base, [
                'taux_modifie' => ['passage' => 0.6, 'redoublement' => 0.3, 'abandon' => 0.1],
            ])), $this->admin->id),
            'strictement inférieur'
        );

        // Somme modifiée > 1.
        $this->assertRefuseDefinition(
            fn () => $service->definir($this->donneesDefinition(array_merge($base, [
                'taux_modifie' => ['passage' => 0.6, 'redoublement' => 0.1, 'abandon' => 0.4],
            ])), $this->admin->id),
            '> 1'
        );

        // Taux modifié hors [0,1].
        $this->assertRefuseDefinition(
            fn () => $service->definir($this->donneesDefinition(array_merge($base, [
                'taux_modifie' => ['passage' => 1.5, 'redoublement' => -0.1, 'abandon' => 0.0],
            ])), $this->admin->id),
            'hors [0,1]'
        );

        // Trio incomplet : descripteur partiel.
        $this->assertRefuseDefinition(
            fn () => $service->definir($this->donneesDefinition(array_merge($base, [
                'taux_modifie' => ['passage' => 0.6, 'redoublement' => 0.1],
            ])), $this->admin->id),
            'doit fournir'
        );
        $sansAxe = $base;
        unset($sansAxe['axe_variation']);
        $this->assertRefuseDefinition(
            fn () => $service->definir($this->donneesDefinition($sansAxe), $this->admin->id),
            'indissociables'
        );

        // Axe / sens hors vocabulaire.
        $this->assertRefuseDefinition(
            fn () => $service->definir($this->donneesDefinition(array_merge($base, ['axe_variation' => 'moyenne'])), $this->admin->id),
            'axe_variation'
        );
        $this->assertRefuseDefinition(
            fn () => $service->definir($this->donneesDefinition(array_merge($base, ['sens_variation' => 'stable'])), $this->admin->id),
            'sens_variation'
        );

        // Référence absente alors que la variation est fournie.
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

        // Exclusion mutuelle : alias passage (SIM-07 / SIM-08) simultanés.
        $this->assertRefuseDefinition(
            fn () => $service->definir($this->donneesDefinition(array_merge($base, [
                'taux_ameliore' => ['passage' => 0.8, 'redoublement' => 0.1, 'abandon' => 0.1],
            ])), $this->admin->id),
            'SIM-07'
        );
        $this->assertRefuseDefinition(
            fn () => $service->definir($this->donneesDefinition(array_merge($base, [
                'taux_degrade' => ['passage' => 0.5, 'redoublement' => 0.3, 'abandon' => 0.2],
            ])), $this->admin->id),
            'SIM-08'
        );

        $this->assertSame(0, ScenarioTemporel::count());
    }

    /* ── B/D. Déterminisme, idempotence et rétrocompatibilité ────────── */

    public function test_sim10_idempotence_et_absence_de_cles_sans_variation(): void
    {
        $scenario = $this->creerScenario();

        $premier = $this->executer($scenario, 'graine-sim10-idemp');
        $second = $this->executer($scenario, 'graine-sim10-idemp');

        $this->assertSame($premier['id'], $second['id']);
        $this->assertSame($premier['hash_resultat'], $second['hash_resultat']);
        $this->assertSame(
            $premier['resultat']['classement'][0]['ecarts_reussite_par_periode'],
            $second['resultat']['classement'][0]['ecarts_reussite_par_periode']
        );
        $this->assertSame(1, ExecutionTemporelle::where('scenario_temporel_id', $scenario->id)->count());

        // Autre graine = nouvelle exécution, variante 0 identique.
        $autre = $this->executer($scenario, 'graine-sim10-autre');
        $this->assertNotSame($premier['id'], $autre['id']);
        $this->assertSame(
            $premier['resultat']['classement'][0]['totaux'],
            $autre['resultat']['classement'][0]['totaux']
        );

        // Rétrocompatibilité : sans variation, aucune clé de réussite et
        // la trajectoire unique suit les taux déclarés [60, 40, 31].
        $sansVariation = $this->creerScenarioSansVariation();
        $executionSans = $this->executer($sansVariation, 'sim10-sans-variation');
        $varianteSans = $executionSans['resultat']['classement'][0];

        foreach ([
            'baisse_redoublement', 'hausse_redoublement', 'baisse_abandon', 'hausse_abandon',
            'hausse_passage', 'baisse_passage', 'totaux_reference_reussite',
            'ecarts_reussite_par_periode', 'ecart_reussite_final', 'sortants_par_periode', 'effet_sortants_cumules',
        ] as $cle) {
            $this->assertArrayNotHasKey($cle, $varianteSans);
        }
        $this->assertSame([60, 40, 31], $varianteSans['totaux']);
    }

    public function test_sim10_analyses_sensibilite_et_monte_carlo_autour_des_taux_modifies(): void
    {
        // La trajectoire principale suit {0.6, 0.1, 0.3} : l'analyse OAT
        // doit exposer une valeur de base du redoublement à 0.1 (modifiée),
        // jamais 0.2 (référence), sous peine de décrire une autre exécution.
        $scenario = $this->creerScenario();

        $execution = $this->executer($scenario, 'sim10-analyses');
        $sensibilite = $execution['resultat']['sensibilite']['parametres_classes'];

        $lignePassage = null;
        $ligneRedoublement = null;
        foreach ($sensibilite as $ligne) {
            if ($ligne['parametre'] === 'taux_passage') {
                $lignePassage = $ligne;
            }
            if ($ligne['parametre'] === 'taux_redoublement') {
                $ligneRedoublement = $ligne;
            }
        }
        $this->assertNotNull($lignePassage);
        $this->assertSame(0.6, $lignePassage['valeur_base']);
        $this->assertNotNull($ligneRedoublement);
        $this->assertSame(0.1, $ligneRedoublement['valeur_base']);

        $this->assertSame(50, $execution['resultat']['monte_carlo']['nb_iterations']);
        $this->assertFalse((bool) $execution['resultat']['monte_carlo']['taux_absents']);
    }

    /* ── F. Sécurité et lecture seule ────────────────────────────────── */

    public function test_sim10_permissions_isolation_facultaire_et_zero_ecriture_metier(): void
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

        $this->executer($scenario, 'sim10-ro');

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
