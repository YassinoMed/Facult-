<?php

namespace Tests\Feature;

use App\Modules\SimulationTemporelle\Models\ExecutionTemporelle;
use App\Modules\SimulationTemporelle\Services\ServiceSimulationTemporelle;
use App\Modules\SimulationTemporelle\Support\CatalogueScenarios;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PrepareSimulationTemporelleParNiveau;
use Tests\TestCase;

/**
 * ST-14 / SIM-19 — rééquilibrage des niveaux (au moins deux niveaux
 * surchargés, directions de passage opposées).
 *
 * Périmètre synthétique : L1=10, L2=20, L3=30 (total 60).
 */
class SimulationTemporelleSim19Test extends TestCase
{
    use PrepareSimulationTemporelleParNiveau;
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function criteresSim19(array $surcharges = []): array
    {
        return $this->criteresBase(array_merge([
            'taux_par_niveau' => [
                'L1' => ['passage' => 0.7, 'redoublement' => 0.2, 'abandon' => 0.1],
                'L2' => ['passage' => 0.5, 'redoublement' => 0.3, 'abandon' => 0.2],
            ],
        ], $surcharges));
    }

    public function test_sim19_reequilibrage_reference_calculee_independamment(): void
    {
        // Référence manuelle (hors moteur) : base 10/20/30, delta 0, H=2.
        // Témoin {0.6, 0.2, 0.2} → [60, 40, 31] ; sortants [18, 11].
        // Surcharges : L1 passage↑ {0.7, 0.2, 0.1}, L2 passage↓ {0.5, 0.3, 0.2}.
        //   p1 : L1=12, L2=13, L3=16 (41) ; p2 : L1=12, L2=12, L3=10 (34).
        // Écarts de totaux [+1, +3] ; écart final +3 ;
        // sortants [18, 10] contre [18, 11] → effet -1.
        $scenario = $this->creerScenario($this->criteresSim19(), '19');

        $variante = $this->executer($scenario, 'sim19-ref')['resultat']['classement'][0];

        $this->assertSame([60, 41, 34], $variante['totaux']);
        $this->assertSame([60, 40, 31], $variante['totaux_reference_reussite']);
        $this->assertSame(0.1, $variante['variation_niveau']);
        $this->assertSame(['L1', 'L2'], $variante['niveaux_surcharges']);

        $this->assertSame([1, 3], array_column($variante['ecarts_reussite_par_periode'], 'ecart'));
        $this->assertSame(3, $variante['ecart_reussite_final']);
        $this->assertSame([
            ['pas' => 1, 'sortants' => 18, 'sortants_reference' => 18],
            ['pas' => 2, 'sortants' => 10, 'sortants_reference' => 11],
        ], $variante['sortants_par_periode']);
        $this->assertSame(-1, $variante['effet_sortants_cumules']);

        $this->assertSame([
            ['pas' => 1, 'niveaux' => [
                ['niveau' => 'L1', 'simule' => 12, 'reference' => 12, 'ecart' => 0],
                ['niveau' => 'L2', 'simule' => 13, 'reference' => 10, 'ecart' => 3],
                ['niveau' => 'L3', 'simule' => 16, 'reference' => 18, 'ecart' => -2],
            ]],
            ['pas' => 2, 'niveaux' => [
                ['niveau' => 'L1', 'simule' => 12, 'reference' => 12, 'ecart' => 0],
                ['niveau' => 'L2', 'simule' => 12, 'reference' => 9, 'ecart' => 3],
                ['niveau' => 'L3', 'simule' => 10, 'reference' => 10, 'ecart' => 0],
            ]],
        ], $variante['ecarts_par_niveau']);
        $this->assertSame([
            ['niveau' => 'L1', 'simule' => 12, 'reference' => 12, 'ecart' => 0],
            ['niveau' => 'L2', 'simule' => 12, 'reference' => 9, 'ecart' => 3],
            ['niveau' => 'L3', 'simule' => 10, 'reference' => 10, 'ecart' => 0],
        ], $variante['ecarts_niveaux_finaux']);

        // La convergence n'est pas supposée : elle est lue dans les écarts.
        $this->assertArrayNotHasKey('niveau_focal', $variante);
    }

    public function test_sim19_niveau_focal_facultatif_restiue_ecart_cible(): void
    {
        $scenario = $this->creerScenario($this->criteresSim19(['niveau_focal' => 'L2']), '19');

        $variante = $this->executer($scenario, 'sim19-focal')['resultat']['classement'][0];

        $this->assertSame('L2', $variante['niveau_focal']);
        $this->assertSame(3, $variante['ecart_niveau_focal_final']);
    }

    public function test_sim19_definition_refuse_profils_invalides(): void
    {
        $service = app(ServiceSimulationTemporelle::class);
        $base = $this->criteresSim19();

        $this->assertRefuseDefinition(
            fn () => $service->definir($this->donneesDefinition(array_merge($base, ['taux_par_niveau' => []])), $this->admin->id),
            'objet non vide'
        );
        $this->assertRefuseDefinition(
            fn () => $service->definir($this->donneesDefinition(array_merge($base, [
                'taux_par_niveau' => ['L1' => ['passage' => 0.7, 'redoublement' => 0.2]],
            ])), $this->admin->id),
            'doit fournir'
        );
        $this->assertRefuseDefinition(
            fn () => $service->definir($this->donneesDefinition(array_merge($base, [
                'taux_par_niveau' => ['L1' => ['passage' => 1.2, 'redoublement' => 0.0, 'abandon' => 0.0]],
            ])), $this->admin->id),
            'hors [0,1]'
        );
        $this->assertRefuseDefinition(
            fn () => $service->definir($this->donneesDefinition(array_merge($base, [
                'taux_par_niveau' => ['L1' => ['passage' => 0.7, 'redoublement' => 0.2, 'abandon' => 0.1], 'L2' => ['passage' => 0.5, 'redoublement' => 0.5, 'abandon' => 0.2]],
            ])), $this->admin->id),
            '> 1'
        );
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

        $this->assertSame(0, ExecutionTemporelle::count());
    }

    public function test_sim19_catalogue_refuse_hors_contrat(): void
    {
        $valides = $this->parametresCatalogue($this->criteresSim19());
        $this->assertSame($valides, CatalogueScenarios::validerParametres('SIM-19', $valides));

        // Un seul niveau : rien à rééquilibrer.
        $this->assertRefuseCatalogue(
            fn () => CatalogueScenarios::validerParametres('SIM-19', $this->parametresCatalogue(array_merge(
                $this->criteresSim19(),
                ['taux_par_niveau' => ['L1' => ['passage' => 0.7, 'redoublement' => 0.2, 'abandon' => 0.1]]],
            ))),
            'au moins DEUX niveaux'
        );

        // Même direction (les deux accélèrent) : pas de rééquilibrage.
        $this->assertRefuseCatalogue(
            fn () => CatalogueScenarios::validerParametres('SIM-19', $this->parametresCatalogue(array_merge(
                $this->criteresSim19(),
                ['taux_par_niveau' => [
                    'L1' => ['passage' => 0.7, 'redoublement' => 0.2, 'abandon' => 0.1],
                    'L2' => ['passage' => 0.65, 'redoublement' => 0.2, 'abandon' => 0.1],
                ]],
            ))),
            'directions de passage opposées'
        );

        // Même direction (les deux ralentissent).
        $this->assertRefuseCatalogue(
            fn () => CatalogueScenarios::validerParametres('SIM-19', $this->parametresCatalogue(array_merge(
                $this->criteresSim19(),
                ['taux_par_niveau' => [
                    'L1' => ['passage' => 0.5, 'redoublement' => 0.3, 'abandon' => 0.2],
                    'L2' => ['passage' => 0.55, 'redoublement' => 0.3, 'abandon' => 0.1],
                ]],
            ))),
            'directions de passage opposées'
        );

        // Un niveau exactement au taux global ne compte pas comme direction.
        $this->assertRefuseCatalogue(
            fn () => CatalogueScenarios::validerParametres('SIM-19', $this->parametresCatalogue(array_merge(
                $this->criteresSim19(),
                ['taux_par_niveau' => [
                    'L1' => ['passage' => 0.7, 'redoublement' => 0.2, 'abandon' => 0.1],
                    'L2' => ['passage' => 0.6, 'redoublement' => 0.3, 'abandon' => 0.1],
                ]],
            ))),
            'directions de passage opposées'
        );

        $this->assertRefuseCatalogue(
            fn () => CatalogueScenarios::validerParametres('SIM-19', $this->parametresCatalogue(array_merge(
                $this->criteresSim19(),
                ['taux_par_niveau' => [
                    'L1' => ['passage' => 0.7, 'redoublement' => 0.2, 'abandon' => 0.1],
                    'L2' => ['passage' => 0.5, 'redoublement' => 0.5, 'abandon' => 0.2],
                ]],
            ))),
            '> 1'
        );

        $schema = CatalogueScenarios::schemaSim19();
        $this->assertTrue($schema['taux']['requis']);
        $this->assertTrue($schema['taux_par_niveau']['requis']);
        $this->assertFalse($schema['niveau_focal']['requis']);
        $this->assertSame(['SIM-14', 'SIM-15', 'SIM-16'], CatalogueScenarios::exiger('SIM-19')['dependances']);
    }

    public function test_sim19_idempotence_et_isolation_facultaire(): void
    {
        $scenario = $this->creerScenario($this->criteresSim19(), '19');

        $premier = $this->executer($scenario, 'sim19-idemp');
        $second = $this->executer($scenario, 'sim19-idemp');
        $this->assertSame($premier['id'], $second['id']);
        $this->assertSame($premier['hash_resultat'], $second['hash_resultat']);
        $this->assertSame(1, ExecutionTemporelle::where('scenario_temporel_id', $scenario->id)->count());

        $this->actingAs($this->sansPermission)->postJson(
            route('admin.simulation-temporelle.executer', ['id' => $scenario->id]),
            ['annee_universitaire_id' => $this->annee->id, 'graine' => 'sim19-403', 'nb_variantes' => 1],
        )->assertForbidden();
        $this->actingAs($this->adminB)->postJson(
            route('admin.simulation-temporelle.executer', ['id' => $scenario->id]),
            ['annee_universitaire_id' => $this->annee->id, 'graine' => 'sim19-403', 'nb_variantes' => 1],
        )->assertForbidden();
    }
}
