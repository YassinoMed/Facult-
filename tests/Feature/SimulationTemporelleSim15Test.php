<?php

namespace Tests\Feature;

use App\Modules\SimulationTemporelle\Models\ExecutionTemporelle;
use App\Modules\SimulationTemporelle\Models\SnapshotExecution;
use App\Modules\SimulationTemporelle\Services\ServiceSimulationTemporelle;
use App\Modules\SimulationTemporelle\Support\CatalogueScenarios;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PrepareSimulationTemporelleParNiveau;
use Tests\TestCase;

/**
 * ST-14 / SIM-15 — blocage en L2 (surcharge strictement locale du niveau
 * L2, trajectoire témoin au jeu global).
 *
 * Périmètre synthétique : L1=10, L2=20, L3=30 (total 60).
 */
class SimulationTemporelleSim15Test extends TestCase
{
    use PrepareSimulationTemporelleParNiveau;
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function criteresSim15(array $surcharges = []): array
    {
        return $this->criteresBase(array_merge([
            'taux_par_niveau' => [
                'L2' => ['passage' => 0.5, 'redoublement' => 0.3, 'abandon' => 0.2],
            ],
            'niveau_focal' => 'L2',
        ], $surcharges));
    }

    public function test_sim15_blocage_l2_reference_calculee_independamment(): void
    {
        // Référence manuelle (hors moteur) : base 10/20/30, delta 0, H=2.
        // Témoin {0.6, 0.2, 0.2} → [60, 40, 31] ; sortants [18, 11].
        // Surcharge L2 {0.5, 0.3, 0.2} :
        //   p1 : L1=12, L2=12, L3=16 (40) ; p2 : L1=12, L2=11, L3=9 (32).
        // Écarts de totaux [0, +1] ; écart final +1 ;
        // sortants [18, 10] contre [18, 11] → effet -1.
        $scenario = $this->creerScenario($this->criteresSim15(), '15');

        $execution = $this->executer($scenario, 'sim15-ref');
        $variante = $execution['resultat']['classement'][0];

        $this->assertSame([60, 40, 32], $variante['totaux']);
        $this->assertSame([60, 40, 31], $variante['totaux_reference_reussite']);
        $this->assertSame(0.1, $variante['variation_niveau']);
        $this->assertSame([
            'L2' => ['passage' => 0.5, 'redoublement' => 0.3, 'abandon' => 0.2],
        ], $variante['taux_par_niveau_appliques']);
        $this->assertSame(['L2'], $variante['niveaux_surcharges']);

        $this->assertSame([
            ['pas' => 1, 'total_simule' => 40, 'total_reference' => 40, 'ecart' => 0],
            ['pas' => 2, 'total_simule' => 32, 'total_reference' => 31, 'ecart' => 1],
        ], $variante['ecarts_reussite_par_periode']);
        $this->assertSame(1, $variante['ecart_reussite_final']);

        $this->assertSame([
            ['pas' => 1, 'sortants' => 18, 'sortants_reference' => 18],
            ['pas' => 2, 'sortants' => 10, 'sortants_reference' => 11],
        ], $variante['sortants_par_periode']);
        $this->assertSame(-1, $variante['effet_sortants_cumules']);

        $this->assertSame([
            ['pas' => 1, 'niveaux' => [
                ['niveau' => 'L1', 'simule' => 12, 'reference' => 12, 'ecart' => 0],
                ['niveau' => 'L2', 'simule' => 12, 'reference' => 10, 'ecart' => 2],
                ['niveau' => 'L3', 'simule' => 16, 'reference' => 18, 'ecart' => -2],
            ]],
            ['pas' => 2, 'niveaux' => [
                ['niveau' => 'L1', 'simule' => 12, 'reference' => 12, 'ecart' => 0],
                ['niveau' => 'L2', 'simule' => 11, 'reference' => 9, 'ecart' => 2],
                ['niveau' => 'L3', 'simule' => 9, 'reference' => 10, 'ecart' => -1],
            ]],
        ], $variante['ecarts_par_niveau']);

        $this->assertSame(['L1' => 12, 'L2' => 9, 'L3' => 10], $variante['cohortes_reference_finales']);
        $this->assertSame([
            ['niveau' => 'L1', 'simule' => 12, 'reference' => 12, 'ecart' => 0],
            ['niveau' => 'L2', 'simule' => 11, 'reference' => 9, 'ecart' => 2],
            ['niveau' => 'L3', 'simule' => 9, 'reference' => 10, 'ecart' => -1],
        ], $variante['ecarts_niveaux_finaux']);
        $this->assertSame('L2', $variante['niveau_focal']);
        $this->assertSame(2, $variante['ecart_niveau_focal_final']);

        $snapshots = SnapshotExecution::where('execution_temporelle_id', $execution['id'])
            ->orderBy('pas')->get();
        $this->assertSame(['L1' => 12, 'L2' => 12, 'L3' => 16], $snapshots->firstWhere('pas', 1)->etat['cohortes']);
        $this->assertSame(['L1' => 12, 'L2' => 11, 'L3' => 9], $snapshots->firstWhere('pas', 2)->etat['cohortes']);
    }

    public function test_sim15_definition_refuse_profils_invalides_et_incompatibles(): void
    {
        $service = app(ServiceSimulationTemporelle::class);
        $base = $this->criteresSim15();

        $this->assertRefuseDefinition(
            fn () => $service->definir($this->donneesDefinition(array_merge($base, ['taux_par_niveau' => []])), $this->admin->id),
            'objet non vide'
        );
        $this->assertRefuseDefinition(
            fn () => $service->definir($this->donneesDefinition(array_merge($base, [
                'taux_par_niveau' => ['L2' => ['passage' => 0.5, 'redoublement' => 0.3]],
            ])), $this->admin->id),
            'doit fournir'
        );
        $this->assertRefuseDefinition(
            fn () => $service->definir($this->donneesDefinition(array_merge($base, [
                'taux_par_niveau' => ['L2' => ['passage' => 1.2, 'redoublement' => 0.1, 'abandon' => 0.0]],
            ])), $this->admin->id),
            'hors [0,1]'
        );
        $this->assertRefuseDefinition(
            fn () => $service->definir($this->donneesDefinition(array_merge($base, [
                'taux_par_niveau' => ['L2' => ['passage' => 0.6, 'redoublement' => 0.5, 'abandon' => 0.2]],
            ])), $this->admin->id),
            '> 1'
        );
        $this->assertRefuseDefinition(
            fn () => $service->definir($this->donneesDefinition(array_merge($base, [
                'taux_modifie' => ['passage' => 0.5, 'redoublement' => 0.3, 'abandon' => 0.2],
                'axe_variation' => 'redoublement', 'sens_variation' => 'hausse',
            ])), $this->admin->id),
            'autre descripteur'
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
        $this->assertRefuseDefinition(
            fn () => $service->definir($this->donneesDefinition(array_merge($base, [
                'profondeur_baisse' => 0.2, 'duree_baisse_annees' => 1, 'duree_recuperation_annees' => 1,
            ])), $this->admin->id),
            'SIM-06'
        );

        $this->assertSame(0, ExecutionTemporelle::count());
    }

    public function test_sim15_catalogue_refuse_hors_contrat(): void
    {
        $valides = $this->parametresCatalogue($this->criteresSim15());
        $this->assertSame($valides, CatalogueScenarios::validerParametres('SIM-15', $valides));

        // La surcharge doit porter EXACTEMENT sur L2.
        $this->assertRefuseCatalogue(
            fn () => CatalogueScenarios::validerParametres('SIM-15', $this->parametresCatalogue(array_merge(
                $this->criteresSim15(),
                ['taux_par_niveau' => ['L1' => ['passage' => 0.5, 'redoublement' => 0.3, 'abandon' => 0.2]]],
            ))),
            'EXACTEMENT le niveau L2'
        );

        // Redoublement strictement supérieur requis.
        $this->assertRefuseCatalogue(
            fn () => CatalogueScenarios::validerParametres('SIM-15', $this->parametresCatalogue(array_merge(
                $this->criteresSim15(),
                ['taux_par_niveau' => ['L2' => ['passage' => 0.6, 'redoublement' => 0.2, 'abandon' => 0.2]]],
            ))),
            'STRICTEMENT supérieur'
        );

        // Aucune amélioration : passage supérieur ou abandon inférieur refusés.
        $this->assertRefuseCatalogue(
            fn () => CatalogueScenarios::validerParametres('SIM-15', $this->parametresCatalogue(array_merge(
                $this->criteresSim15(),
                ['taux_par_niveau' => ['L2' => ['passage' => 0.7, 'redoublement' => 0.3, 'abandon' => 0.0]]],
            ))),
            'amélioration'
        );
        $this->assertRefuseCatalogue(
            fn () => CatalogueScenarios::validerParametres('SIM-15', $this->parametresCatalogue(array_merge(
                $this->criteresSim15(),
                ['taux_par_niveau' => ['L2' => ['passage' => 0.5, 'redoublement' => 0.3, 'abandon' => 0.1]]],
            ))),
            'amélioration'
        );

        $this->assertRefuseCatalogue(
            fn () => CatalogueScenarios::validerParametres('SIM-15', $this->parametresCatalogue(array_merge(
                $this->criteresSim15(),
                ['taux_par_niveau' => ['L2' => ['passage' => 0.6, 'redoublement' => 0.5, 'abandon' => 0.2]]],
            ))),
            '> 1'
        );
        $this->assertRefuseCatalogue(
            fn () => CatalogueScenarios::validerParametres('SIM-15', $this->parametresCatalogue(array_merge(
                $this->criteresSim15(),
                ['niveau_focal' => 'L1'],
            ))),
            'non autorisée'
        );

        $schema = CatalogueScenarios::schemaSim15();
        $this->assertTrue($schema['taux']['requis']);
        $this->assertTrue($schema['taux_par_niveau']['requis']);
        $this->assertSame(['L2'], $schema['niveau_focal']['valeurs']);
        $this->assertSame(['SIM-14'], CatalogueScenarios::exiger('SIM-15')['dependances']);
    }

    public function test_sim15_idempotence_et_isolation_facultaire(): void
    {
        $scenario = $this->creerScenario($this->criteresSim15(), '15');

        $premier = $this->executer($scenario, 'sim15-idemp');
        $second = $this->executer($scenario, 'sim15-idemp');
        $this->assertSame($premier['id'], $second['id']);
        $this->assertSame($premier['hash_resultat'], $second['hash_resultat']);
        $this->assertSame(1, ExecutionTemporelle::where('scenario_temporel_id', $scenario->id)->count());

        $this->actingAs($this->sansPermission)->postJson(
            route('admin.simulation-temporelle.executer', ['id' => $scenario->id]),
            ['annee_universitaire_id' => $this->annee->id, 'graine' => 'sim15-403', 'nb_variantes' => 1],
        )->assertForbidden();
        $this->actingAs($this->adminB)->postJson(
            route('admin.simulation-temporelle.executer', ['id' => $scenario->id]),
            ['annee_universitaire_id' => $this->annee->id, 'graine' => 'sim15-403', 'nb_variantes' => 1],
        )->assertForbidden();
    }
}
