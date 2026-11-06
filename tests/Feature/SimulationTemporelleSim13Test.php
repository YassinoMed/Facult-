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
use App\Modules\SimulationTemporelle\Support\CatalogueScenarios;
use App\Support\Iam\MoteurAutorisation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * ST-14 / SIM-13 — crise académique combinée (configuration du descripteur
 * générique, plusieurs dégradations simultanées).
 *
 * Les résultats attendus sont calculés À LA MAIN dans les commentaires
 * (aucune réutilisation de la formule du moteur dans les assertions).
 *
 * Périmètre synthétique : L1=10, L2=20, L3=30 (total 60), faculté A.
 *
 * Règles de domaine SIM-13 : paire focale dégradante (passage/baisse,
 * redoublement/hausse, abandon/hausse), AUCUNE amélioration, au moins DEUX
 * axes strictement dégradés. `abandon` n'est pas réinjecté ; tout effet
 * provient des mouvements joints déclarés, jamais d'une causalité inventée.
 */
class SimulationTemporelleSim13Test extends TestCase
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
            'name' => 'SIM13', 'email' => 'sim13-'.uniqid().'@u.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->adminB = User::creerCompteBackOffice([
            'name' => 'SIM13-B', 'email' => 'sim13b-'.uniqid().'@u.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteB->id,
        ], 'admin_faculte');
        $this->sansPermission = User::creerCompteBackOffice([
            'name' => 'SIM13-N', 'email' => 'sim13n-'.uniqid().'@u.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->habiliter($this->admin);
        $this->habiliter($this->adminB);

        // Effectifs réels synthétiques de la faculté A : L1=10, L2=20, L3=30.
        $programme = Programme::create([
            'faculte_id' => $this->faculteA->id, 'code' => 'PFST13', 'nom' => 'Prog',
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
            'taux_modifie' => ['passage' => 0.5, 'redoublement' => 0.3, 'abandon' => 0.2],
            'axe_variation' => 'redoublement',
            'sens_variation' => 'hausse',
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
            'code' => 'SIM13-'.uniqid(),
            'libelle' => 'Crise académique combinée SIM-13',
            'domaine' => 'effectifs_cohortes',
            'faculte_id' => $this->faculteA->id,
            'criteres' => $criteres,
            'contraintes' => $contraintes,
            'objectifs' => $objectifs,
            'version' => 1,
            'cree_par' => $this->admin->id,
        ]);
    }

    private function executer(ScenarioTemporel $scenario, string $graine = 'graine-sim13'): array
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

    private function assertRefuseCatalogue(callable $action, string $messagePart): void
    {
        try {
            $action();
        } catch (\DomainException $e) {
            $this->assertStringContainsString($messagePart, $e->getMessage());

            return;
        }

        $this->fail('Une DomainException catalogue était attendue.');
    }

    private function donneesDefinition(array $criteres): array
    {
        return [
            'code' => 'SIM13-DEF-'.uniqid(),
            'libelle' => 'Crise académique combinée SIM-13 (définition)',
            'faculte_id' => $this->faculteA->id,
            'criteres' => $criteres,
            'contraintes' => [],
            'objectifs' => [['type' => 'stabilite', 'poids' => 1]],
        ];
    }

    /** Paramètres catalogue valides SIM-13 (focal redoublement/hausse). */
    private function parametresCatalogue(array $surcharges = []): array
    {
        return array_merge([
            'delta_admissions' => 0.0,
            'bornes' => ['min' => 0.0, 'max' => 0.0],
            'horizon_annees' => 2,
            'taux' => ['passage' => 0.6, 'redoublement' => 0.2, 'abandon' => 0.2],
            'taux_modifie' => ['passage' => 0.5, 'redoublement' => 0.3, 'abandon' => 0.2],
            'axe_variation' => 'redoublement',
            'sens_variation' => 'hausse',
        ], $surcharges);
    }

    /* ── E. Validation mathématique indépendante ─────────────────────── */

    public function test_sim13_crise_combinee_reference_calculee_independamment(): void
    {
        // Référence manuelle (calcul hors moteur) :
        // base L1=10, L2=20, L3=30 ; delta = 0 ; H = 2 ; admissions [10, 10].
        // Référence {0.6, 0.2, 0.2} → totaux [60, 40, 31] ; sortants [18, 11].
        //   p1 : L1=12, L2=10, L3=18 (40) ; p2 : [12, 9, 10] (31).
        // Modifié {0.5, 0.3, 0.2} (passage↓ + redoublement↑ : 2 strictes)
        //   → totaux [60, 43, 36] ; sortants [15, 10].
        //   p1 : L1=13, L2=11, L3=19 (43) ; p2 : [14, 10, 12] (36).
        // Écarts [+3, +5] ; écart final +5 ;
        // effet sortants (15+10)-(18+11) = -4 ; hausse_redoublement = 0.1.
        $scenario = $this->creerScenario();

        $execution = $this->executer($scenario, 'sim13-ref-combinee');
        $variante = $execution['resultat']['classement'][0];

        $this->assertSame([60, 43, 36], $variante['totaux']);
        $this->assertSame(36, $variante['total_final']);
        $this->assertSame([60, 40, 31], $variante['totaux_reference_reussite']);

        $this->assertSame(0.1, $variante['hausse_redoublement']);
        $this->assertArrayNotHasKey('baisse_redoublement', $variante);
        $this->assertArrayNotHasKey('hausse_passage', $variante);
        $this->assertArrayNotHasKey('baisse_passage', $variante);
        $this->assertArrayNotHasKey('hausse_abandon', $variante);
        $this->assertArrayNotHasKey('baisse_abandon', $variante);

        $this->assertSame([
            ['pas' => 1, 'total_simule' => 43, 'total_reference' => 40, 'ecart' => 3],
            ['pas' => 2, 'total_simule' => 36, 'total_reference' => 31, 'ecart' => 5],
        ], $variante['ecarts_reussite_par_periode']);
        $this->assertSame(5, $variante['ecart_reussite_final']);

        $this->assertSame([
            ['pas' => 1, 'sortants' => 15, 'sortants_reference' => 18],
            ['pas' => 2, 'sortants' => 10, 'sortants_reference' => 11],
        ], $variante['sortants_par_periode']);
        $this->assertSame(-4, $variante['effet_sortants_cumules']);

        $this->assertSame([
            ['pas' => 1, 'admissions' => 10],
            ['pas' => 2, 'admissions' => 10],
        ], $variante['admissions_par_periode']);

        $snapshots = SnapshotExecution::where('execution_temporelle_id', $execution['id'])
            ->orderBy('pas')->get();
        $this->assertSame(['L1' => 13, 'L2' => 11, 'L3' => 19], $snapshots->firstWhere('pas', 1)->etat['cohortes']);
        $this->assertSame(['L1' => 14, 'L2' => 10, 'L3' => 12], $snapshots->firstWhere('pas', 2)->etat['cohortes']);
    }

    public function test_sim13_focal_abandon_et_annulation_partielle_des_effets(): void
    {
        // Référence manuelle (calcul hors moteur) :
        // base L1=10, L2=20, L3=30 ; delta = 0 ; H = 2 ; admissions [10, 10].
        // Référence {0.6, 0.2, 0.2} → [60, 40, 31] ; sortants [18, 11].
        // Modifié {0.4, 0.3, 0.3} (3 strictes : passage↓, redoublement↑,
        //   abandon↑), focal abandon/hausse → hausse_abandon = 0.1.
        //   p1 : L1=13, L2=10, L3=17 (40) ; p2 : [14, 8, 9] (31).
        // Les dégradations combinées ne sont pas additives : les écarts de
        // totaux s'annulent [0, 0] alors que les sortants chutent
        // (12+7)-(18+11) = -10.
        $scenario = $this->creerScenario([
            'taux_modifie' => ['passage' => 0.4, 'redoublement' => 0.3, 'abandon' => 0.3],
            'axe_variation' => 'abandon',
            'sens_variation' => 'hausse',
        ]);

        $execution = $this->executer($scenario, 'sim13-ref-abandon');
        $variante = $execution['resultat']['classement'][0];

        $this->assertSame([60, 40, 31], $variante['totaux']);
        $this->assertSame([60, 40, 31], $variante['totaux_reference_reussite']);
        $this->assertSame(0.1, $variante['hausse_abandon']);
        $this->assertArrayNotHasKey('hausse_redoublement', $variante);
        $this->assertArrayNotHasKey('baisse_passage', $variante);

        $this->assertSame([
            ['pas' => 1, 'total_simule' => 40, 'total_reference' => 40, 'ecart' => 0],
            ['pas' => 2, 'total_simule' => 31, 'total_reference' => 31, 'ecart' => 0],
        ], $variante['ecarts_reussite_par_periode']);
        $this->assertSame(0, $variante['ecart_reussite_final']);

        $this->assertSame([
            ['pas' => 1, 'sortants' => 12, 'sortants_reference' => 18],
            ['pas' => 2, 'sortants' => 7, 'sortants_reference' => 11],
        ], $variante['sortants_par_periode']);
        $this->assertSame(-10, $variante['effet_sortants_cumules']);
    }

    public function test_sim13_admissions_identiques_quelle_que_soit_la_crise(): void
    {
        // Référence manuelle (calcul hors moteur) :
        // delta = +0.10 → admissions [11, 12] dans les DEUX trajectoires.
        // Référence {0.6, 0.2, 0.2} → totaux [60, 41, 35] ; sortants [18, 11].
        //   p1 : L1=13, L2=10, L3=18 (41) ; p2 : [15, 10, 10] (35).
        // Modifié {0.5, 0.3, 0.2} → totaux [60, 44, 38] ; sortants [15, 10].
        //   p1 : L1=14, L2=11, L3=19 (44) ; p2 : [16, 10, 12] (38).
        // Écarts [+3, +3] ; écart final +3 ;
        // effet sortants (15+10)-(18+11) = -4 ; hausse_redoublement = 0.1.
        $scenario = $this->creerScenario([
            'delta_admissions' => 0.10,
            'bornes' => ['min' => 0.0, 'max' => 0.5],
        ]);

        $execution = $this->executer($scenario, 'sim13-delta');
        $variante = $execution['resultat']['classement'][0];

        $this->assertSame([11, 12], array_column($variante['admissions_par_periode'], 'admissions'));
        $this->assertSame([60, 44, 38], $variante['totaux']);
        $this->assertSame([60, 41, 35], $variante['totaux_reference_reussite']);
        $this->assertSame([3, 3], array_column($variante['ecarts_reussite_par_periode'], 'ecart'));
        $this->assertSame(3, $variante['ecart_reussite_final']);
        $this->assertSame(-4, $variante['effet_sortants_cumules']);
        $this->assertSame([15, 10], array_column($variante['sortants_par_periode'], 'sortants'));
        $this->assertSame(0.1, $variante['hausse_redoublement']);
    }

    /* ── C. Validation du contrat au moment de la définition ─────────── */

    public function test_sim13_definition_refuse_profils_non_combines_et_invalides(): void
    {
        $service = app(ServiceSimulationTemporelle::class);
        $base = [
            'delta_admissions' => 0.0,
            'bornes' => ['min' => 0.0, 'max' => 0.0],
            'horizon_annees' => 2,
            'taux' => ['passage' => 0.6, 'redoublement' => 0.2, 'abandon' => 0.2],
            'taux_modifie' => ['passage' => 0.5, 'redoublement' => 0.3, 'abandon' => 0.2],
            'axe_variation' => 'redoublement',
            'sens_variation' => 'hausse',
        ];

        // Axe focal non strict (moteur : contrat du descripteur).
        $this->assertRefuseDefinition(
            fn () => $service->definir($this->donneesDefinition(array_merge($base, [
                'taux_modifie' => ['passage' => 0.5, 'redoublement' => 0.2, 'abandon' => 0.2],
            ])), $this->admin->id),
            'strictement supérieur'
        );

        // Somme modifiée > 1.
        $this->assertRefuseDefinition(
            fn () => $service->definir($this->donneesDefinition(array_merge($base, [
                'taux_modifie' => ['passage' => 0.5, 'redoublement' => 0.4, 'abandon' => 0.2],
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
                'taux_modifie' => ['passage' => 0.5, 'redoublement' => 0.3],
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
                'taux_degrade' => ['passage' => 0.4, 'redoublement' => 0.3, 'abandon' => 0.2],
            ])), $this->admin->id),
            'SIM-08'
        );

        $this->assertSame(0, ScenarioTemporel::count());
    }

    public function test_sim13_catalogue_refuse_les_regles_de_domaine_hors_contrat(): void
    {
        // Jeu valide accepté tel quel.
        $valides = $this->parametresCatalogue();
        $this->assertSame($valides, CatalogueScenarios::validerParametres('SIM-13', $valides));

        // Paire focale non dégradante (le moteur accepterait) : refusée.
        $this->assertRefuseCatalogue(
            fn () => CatalogueScenarios::validerParametres('SIM-13', $this->parametresCatalogue([
                'taux_modifie' => ['passage' => 0.5, 'redoublement' => 0.3, 'abandon' => 0.1],
                'axe_variation' => 'abandon',
                'sens_variation' => 'baisse',
            ])),
            'dégradation'
        );

        // Amélioration du passage : refusée (aucune amélioration admise).
        $this->assertRefuseCatalogue(
            fn () => CatalogueScenarios::validerParametres('SIM-13', $this->parametresCatalogue([
                'taux_modifie' => ['passage' => 0.7, 'redoublement' => 0.1, 'abandon' => 0.2],
            ])),
            'amélioration'
        );

        // Une seule dégradation stricte (l'axe focal) : refusée.
        $this->assertRefuseCatalogue(
            fn () => CatalogueScenarios::validerParametres('SIM-13', $this->parametresCatalogue([
                'taux_modifie' => ['passage' => 0.5, 'redoublement' => 0.2, 'abandon' => 0.2],
                'axe_variation' => 'passage',
                'sens_variation' => 'baisse',
            ])),
            'deux axes'
        );

        // Somme modifiée > 1 : refusée.
        $this->assertRefuseCatalogue(
            fn () => CatalogueScenarios::validerParametres('SIM-13', $this->parametresCatalogue([
                'taux_modifie' => ['passage' => 0.6, 'redoublement' => 0.4, 'abandon' => 0.3],
            ])),
            '> 1'
        );

        // Axe ou sens hors valeurs du schéma : refusés.
        $this->assertRefuseCatalogue(
            fn () => CatalogueScenarios::validerParametres('SIM-13', $this->parametresCatalogue(['axe_variation' => 'moyenne'])),
            'non autorisée'
        );
        $this->assertRefuseCatalogue(
            fn () => CatalogueScenarios::validerParametres('SIM-13', $this->parametresCatalogue(['sens_variation' => 'stable'])),
            'non autorisée'
        );

        // Trio partiel ou absent : refusé par le schéma.
        $sansModifie = $this->parametresCatalogue();
        unset($sansModifie['taux_modifie']);
        $this->assertRefuseCatalogue(
            fn () => CatalogueScenarios::validerParametres('SIM-13', $sansModifie),
            'taux_modifie'
        );

        // Incompatibilités structurelles : clés inconnues du schéma SIM-13.
        foreach ([
            ['duree_gel_annees' => 1],
            ['pas_exceptionnel' => 1, 'admissions_exceptionnelles' => 5],
            ['taux_cible_croissance' => 0.05],
            ['profondeur_baisse' => 0.2, 'duree_baisse_annees' => 1, 'duree_recuperation_annees' => 1],
            ['taux_ameliore' => ['passage' => 0.8, 'redoublement' => 0.1, 'abandon' => 0.1]],
            ['taux_degrade' => ['passage' => 0.4, 'redoublement' => 0.3, 'abandon' => 0.2]],
        ] as $clesInterdites) {
            $this->assertRefuseCatalogue(
                fn () => CatalogueScenarios::validerParametres('SIM-13', $this->parametresCatalogue($clesInterdites)),
                'inconnu'
            );
        }

        // Schéma publié : jeu modifié requis, axe et sens multi-valeurs.
        $schema = CatalogueScenarios::schemaSim13();
        $this->assertTrue($schema['taux']['requis']);
        $this->assertTrue($schema['taux_modifie']['requis']);
        $this->assertSame(['passage', 'redoublement', 'abandon'], $schema['axe_variation']['valeurs']);
        $this->assertSame(['hausse', 'baisse'], $schema['sens_variation']['valeurs']);
    }

    /* ── B/D. Déterminisme, idempotence et rétrocompatibilité ────────── */

    public function test_sim13_idempotence_et_absence_de_cles_sans_variation(): void
    {
        $scenario = $this->creerScenario();

        $premier = $this->executer($scenario, 'graine-sim13-idemp');
        $second = $this->executer($scenario, 'graine-sim13-idemp');

        $this->assertSame($premier['id'], $second['id']);
        $this->assertSame($premier['hash_resultat'], $second['hash_resultat']);
        $this->assertSame(
            $premier['resultat']['classement'][0]['ecarts_reussite_par_periode'],
            $second['resultat']['classement'][0]['ecarts_reussite_par_periode']
        );
        $this->assertSame(1, ExecutionTemporelle::where('scenario_temporel_id', $scenario->id)->count());

        // Autre graine = nouvelle exécution, variante 0 identique.
        $autre = $this->executer($scenario, 'graine-sim13-autre');
        $this->assertNotSame($premier['id'], $autre['id']);
        $this->assertSame(
            $premier['resultat']['classement'][0]['totaux'],
            $autre['resultat']['classement'][0]['totaux']
        );

        // Rétrocompatibilité : sans variation, aucune clé de réussite et
        // la trajectoire unique suit les taux déclarés [60, 40, 31].
        $sansVariation = $this->creerScenarioSansVariation();
        $executionSans = $this->executer($sansVariation, 'sim13-sans-variation');
        $varianteSans = $executionSans['resultat']['classement'][0];

        foreach ([
            'hausse_abandon', 'baisse_abandon', 'hausse_redoublement', 'baisse_redoublement',
            'hausse_passage', 'baisse_passage', 'totaux_reference_reussite',
            'ecarts_reussite_par_periode', 'ecart_reussite_final', 'sortants_par_periode', 'effet_sortants_cumules',
        ] as $cle) {
            $this->assertArrayNotHasKey($cle, $varianteSans);
        }
        $this->assertSame([60, 40, 31], $varianteSans['totaux']);
    }

    public function test_sim13_analyses_sensibilite_et_monte_carlo_autour_des_taux_modifies(): void
    {
        // La trajectoire principale suit {0.5, 0.3, 0.2} : l'analyse OAT
        // doit exposer un passage de base à 0.5 et un redoublement à 0.3
        // (valeurs MODIFIÉES), jamais 0.6 / 0.2 (référence). L'abandon
        // n'étant pas un flux réinjecté, aucune ligne taux_abandon ne doit
        // être inventée.
        $scenario = $this->creerScenario();

        $execution = $this->executer($scenario, 'sim13-analyses');
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
        $this->assertSame(0.5, $lignePassage['valeur_base']);
        $this->assertNotNull($ligneRedoublement);
        $this->assertSame(0.3, $ligneRedoublement['valeur_base']);

        // Aucune sensibilité inventée pour l'abandon (hors modèle de progression).
        $lignesAbandon = array_filter(
            $sensibilite,
            fn (array $ligne) => $ligne['parametre'] === 'taux_abandon'
        );
        $this->assertCount(0, $lignesAbandon);

        $this->assertSame(50, $execution['resultat']['monte_carlo']['nb_iterations']);
        $this->assertFalse((bool) $execution['resultat']['monte_carlo']['taux_absents']);
    }

    /* ── F. Sécurité et lecture seule ────────────────────────────────── */

    public function test_sim13_permissions_isolation_facultaire_et_zero_ecriture_metier(): void
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

        $this->executer($scenario, 'sim13-ro');

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
