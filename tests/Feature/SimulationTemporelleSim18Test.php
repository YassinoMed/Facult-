<?php

namespace Tests\Feature;

use App\Modules\SimulationTemporelle\Models\ExecutionTemporelle;
use App\Modules\SimulationTemporelle\Services\ServiceSimulationTemporelle;
use App\Modules\SimulationTemporelle\Support\CatalogueScenarios;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PrepareSimulationTemporelleParNiveau;
use Tests\TestCase;

/**
 * ST-14 / SIM-18 — double génération (au moins deux cohortes externes du
 * même niveau, à des pas distincts).
 *
 * Périmètre synthétique : L1=10, L2=20, L3=30 (total 60).
 * Témoin {0.6, 0.2, 0.2}, delta 0, H=2 → totaux [60, 40, 31].
 */
class SimulationTemporelleSim18Test extends TestCase
{
    use PrepareSimulationTemporelleParNiveau;
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function criteresSim18(array $surcharges = []): array
    {
        return $this->criteresBase(array_merge([
            'cohortes_identifiees' => [
                ['code' => 'C1', 'niveau_entree' => 'L1', 'pas_entree' => 1, 'effectif' => 12],
                ['code' => 'C2', 'niveau_entree' => 'L1', 'pas_entree' => 2, 'effectif' => 10],
            ],
        ], $surcharges));
    }

    public function test_sim18_double_generation_reference_calculee_independamment(): void
    {
        // Référence manuelle (hors moteur) : base 10/20/30, delta 0, H=2.
        // Témoin : p1 = 12/10/18 (40), p2 = 12/9/10 (31). Admissions = 10/pas.
        // C1=12 entre en L1 au pas 1 ; C2=10 entre en L1 au pas 2.
        //   p1 : L1=24, total 52 ; suivi C1=12, non identifiés 12.
        //   p2 : L1 24 → 14 partants + 5 redoublants ; répartition C1 :
        //        7 partants, 3 redoublants, 2 autres sorties ; puis C2 entre.
        //        admissions 10 → L1 = 5 + 10 + 10 = 25 ; L2 = 2 + 14 + 6 = 16 ;
        //        L3 = 10 → total 51.
        // Écarts au témoin : [+12, +20] ; final +20.
        // Conservation : C1 12 = (3 + 7) + 0 + 2 ; C2 10 = 10 + 0 + 0.
        $scenario = $this->creerScenario($this->criteresSim18(), '18');

        $variante = $this->executer($scenario, 'sim18-ref')['resultat']['classement'][0];

        $this->assertSame([60, 52, 51], $variante['totaux']);
        $this->assertSame(['C1', 'C2'], array_column($variante['cohortes_identifiees_appliquees'], 'code'));

        $this->assertSame([
            ['pas' => 1, 'total_simule' => 52, 'total_reference' => 40, 'ecart' => 12, 'effectif_identifie' => 12],
            ['pas' => 2, 'total_simule' => 51, 'total_reference' => 31, 'ecart' => 20, 'effectif_identifie' => 20],
        ], $variante['ecarts_cohortes_par_periode']);
        $this->assertSame(20, $variante['ecart_cohortes_final']);

        $this->assertSame([
            ['cohorte' => 'C1', 'niveau_entree' => 'L1', 'pas_entree' => 1,
                'effectif_entree' => 12, 'effectif_final' => 10,
                'effectif_final_par_niveau' => ['L1' => 3, 'L2' => 7],
                'sortants' => 0, 'autres_sorties' => 2, 'ecart_conservation' => 0],
            ['cohorte' => 'C2', 'niveau_entree' => 'L1', 'pas_entree' => 2,
                'effectif_entree' => 10, 'effectif_final' => 10,
                'effectif_final_par_niveau' => ['L1' => 10],
                'sortants' => 0, 'autres_sorties' => 0, 'ecart_conservation' => 0],
        ], $variante['bilan_cohortes']);

        $this->assertSame([
            ['pas' => 1, 'entrees' => [['cohorte' => 'C1', 'effectif' => 12]], 'total' => 12],
            ['pas' => 2, 'entrees' => [['cohorte' => 'C2', 'effectif' => 10]], 'total' => 10],
        ], $variante['entrees_cohortes_par_periode']);

        $this->assertSame([
            ['pas' => 1, 'niveaux' => [
                ['niveau' => 'L1', 'total' => 24, 'cohortes' => [['cohorte' => 'C1', 'effectif' => 12]], 'effectif_non_identifie' => 12],
                ['niveau' => 'L2', 'total' => 10, 'cohortes' => [], 'effectif_non_identifie' => 10],
                ['niveau' => 'L3', 'total' => 18, 'cohortes' => [], 'effectif_non_identifie' => 18],
            ]],
            ['pas' => 2, 'niveaux' => [
                ['niveau' => 'L1', 'total' => 25, 'cohortes' => [
                    ['cohorte' => 'C1', 'effectif' => 3],
                    ['cohorte' => 'C2', 'effectif' => 10],
                ], 'effectif_non_identifie' => 12],
                ['niveau' => 'L2', 'total' => 16, 'cohortes' => [['cohorte' => 'C1', 'effectif' => 7]], 'effectif_non_identifie' => 9],
                ['niveau' => 'L3', 'total' => 10, 'cohortes' => [], 'effectif_non_identifie' => 10],
            ]],
        ], $variante['suivi_cohortes']);

        // Coexistence MESURÉE : les deux générations ne se croisent qu'au
        // pas 2, en L1 ; aucune simultanéité n'est supposée.
        $this->assertSame([
            ['pas' => 1, 'cohortes_simultanees' => 0, 'niveaux' => []],
            ['pas' => 2, 'cohortes_simultanees' => 2, 'niveaux' => [
                ['niveau' => 'L1', 'cohortes' => ['C1', 'C2'], 'effectif' => 13],
            ]],
        ], $variante['coexistence_par_periode']);
        $this->assertSame(1, $variante['duree_coexistence']);
        $this->assertSame(2, $variante['pic_coexistence']);
    }

    public function test_sim18_definition_refuse_contrats_invalides(): void
    {
        $service = app(ServiceSimulationTemporelle::class);
        $base = $this->criteresSim18();

        $this->assertRefuseDefinition(
            fn () => $service->definir($this->donneesDefinition(array_merge($base, [
                'cohortes_identifiees' => [
                    ['code' => 'C1', 'niveau_entree' => 'L1', 'pas_entree' => 1, 'effectif' => 12],
                    ['code' => 'C2', 'niveau_entree' => 'L1', 'pas_entree' => 1, 'effectif' => 10],
                ],
            ])), $this->admin->id),
            'pas DISTINCTS'
        );
        $this->assertRefuseDefinition(
            fn () => $service->definir($this->donneesDefinition(array_merge($base, [
                'cohortes_identifiees' => [
                    ['code' => 'C1', 'niveau_entree' => 'L1', 'pas_entree' => 1, 'effectif' => 12],
                    ['code' => 'C2', 'niveau_entree' => 'L2', 'pas_entree' => 2, 'effectif' => 10],
                ],
            ])), $this->admin->id),
            'MÊME niveau'
        );

        $this->assertSame(0, ExecutionTemporelle::count());
    }

    public function test_sim18_execution_refuse_generation_inferieure_a_la_reference(): void
    {
        $service = app(ServiceSimulationTemporelle::class);

        $sousGeneree = $this->creerScenario($this->criteresSim18([
            'cohortes_identifiees' => [
                ['code' => 'C1', 'niveau_entree' => 'L1', 'pas_entree' => 1, 'effectif' => 12],
                ['code' => 'C2', 'niveau_entree' => 'L1', 'pas_entree' => 2, 'effectif' => 9],
            ],
        ]), '18');

        try {
            $service->executer($sousGeneree, $this->annee->id, 'sim18-9', 1, $this->admin->id);
            $this->fail('Une DomainException était attendue (génération inférieure à la référence).');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('au moins égal à la génération de référence', $e->getMessage());
        }
    }

    public function test_sim18_catalogue_refuse_hors_contrat(): void
    {
        $valides = $this->parametresCatalogue($this->criteresSim18());
        $this->assertSame($valides, CatalogueScenarios::validerParametres('SIM-18', $valides));

        // Une seule cohorte : relève de SIM-17 (schéma min_elements = 2).
        $this->assertRefuseCatalogue(
            fn () => CatalogueScenarios::validerParametres('SIM-18', $this->parametresCatalogue(array_merge(
                $this->criteresSim18(),
                ['cohortes_identifiees' => [
                    ['code' => 'C1', 'niveau_entree' => 'L1', 'pas_entree' => 1, 'effectif' => 12],
                ]],
            ))),
            'insuffisant'
        );

        // Même pas : aucune coexistence de générations.
        $this->assertRefuseCatalogue(
            fn () => CatalogueScenarios::validerParametres('SIM-18', $this->parametresCatalogue(array_merge(
                $this->criteresSim18(),
                ['cohortes_identifiees' => [
                    ['code' => 'C1', 'niveau_entree' => 'L1', 'pas_entree' => 1, 'effectif' => 12],
                    ['code' => 'C2', 'niveau_entree' => 'L1', 'pas_entree' => 1, 'effectif' => 10],
                ]],
            ))),
            'pas DISTINCTS'
        );

        // Niveaux différents : la double génération porte sur UN niveau.
        $this->assertRefuseCatalogue(
            fn () => CatalogueScenarios::validerParametres('SIM-18', $this->parametresCatalogue(array_merge(
                $this->criteresSim18(),
                ['cohortes_identifiees' => [
                    ['code' => 'C1', 'niveau_entree' => 'L1', 'pas_entree' => 1, 'effectif' => 12],
                    ['code' => 'C2', 'niveau_entree' => 'L2', 'pas_entree' => 2, 'effectif' => 20],
                ]],
            ))),
            'MÊME niveau'
        );

        $schema = CatalogueScenarios::schemaSim18();
        $this->assertTrue($schema['taux']['requis']);
        $this->assertTrue($schema['cohortes_identifiees']['requis']);
        $this->assertSame(2, $schema['cohortes_identifiees']['min_elements']);
        $this->assertSame(['SIM-17'], CatalogueScenarios::exiger('SIM-18')['dependances']);
    }

    public function test_sim18_idempotence_et_isolation_facultaire(): void
    {
        $scenario = $this->creerScenario($this->criteresSim18(), '18');

        $premier = $this->executer($scenario, 'sim18-idemp');
        $second = $this->executer($scenario, 'sim18-idemp');
        $this->assertSame($premier['id'], $second['id']);
        $this->assertSame($premier['hash_resultat'], $second['hash_resultat']);
        $this->assertSame(1, ExecutionTemporelle::where('scenario_temporel_id', $scenario->id)->count());

        $this->actingAs($this->sansPermission)->postJson(
            route('admin.simulation-temporelle.executer', ['id' => $scenario->id]),
            ['annee_universitaire_id' => $this->annee->id, 'graine' => 'sim18-403', 'nb_variantes' => 1],
        )->assertForbidden();
        $this->actingAs($this->adminB)->postJson(
            route('admin.simulation-temporelle.executer', ['id' => $scenario->id]),
            ['annee_universitaire_id' => $this->annee->id, 'graine' => 'sim18-403', 'nb_variantes' => 1],
        )->assertForbidden();
    }
}
