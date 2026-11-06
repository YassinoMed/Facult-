<?php

namespace Tests\Feature;

use App\Modules\SimulationTemporelle\Models\ExecutionTemporelle;
use App\Modules\SimulationTemporelle\Services\ServiceSimulationTemporelle;
use App\Modules\SimulationTemporelle\Support\CatalogueScenarios;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PrepareSimulationTemporelleParNiveau;
use Tests\TestCase;

/**
 * ST-14 / SIM-16 — flux déséquilibrés entre niveaux (au moins deux
 * niveaux surchargés, au moins deux triplets distincts, aucune direction
 * imposée).
 *
 * Périmètre synthétique : L1=10, L2=20, L3=30 (total 60).
 */
class SimulationTemporelleSim16Test extends TestCase
{
    use PrepareSimulationTemporelleParNiveau;
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function criteresSim16(array $surcharges = []): array
    {
        return $this->criteresBase(array_merge([
            'taux_par_niveau' => [
                'L1' => ['passage' => 0.7, 'redoublement' => 0.2, 'abandon' => 0.1],
                'L3' => ['passage' => 0.5, 'redoublement' => 0.3, 'abandon' => 0.2],
            ],
        ], $surcharges));
    }

    public function test_sim16_flux_desequilibres_reference_calculee_independamment(): void
    {
        // Référence manuelle (hors moteur) : base 10/20/30, delta 0, H=2.
        // Témoin {0.6, 0.2, 0.2} → [60, 40, 31] ; sortants [18, 11].
        // Surcharges : L1 {0.7, 0.2, 0.1}, L3 {0.5, 0.3, 0.2}.
        //   p1 : L1=12, L2=11, L3=21 (44) ; p2 : L1=12, L2=10, L3=13 (35).
        // Écarts de totaux [+4, +4] ; écart final +4 ;
        // sortants [15, 11] contre [18, 11] → effet -3.
        $scenario = $this->creerScenario($this->criteresSim16(), '16');

        $variante = $this->executer($scenario, 'sim16-ref')['resultat']['classement'][0];

        $this->assertSame([60, 44, 35], $variante['totaux']);
        $this->assertSame([60, 40, 31], $variante['totaux_reference_reussite']);
        $this->assertSame(0.1, $variante['variation_niveau']);
        $this->assertSame(['L1', 'L3'], $variante['niveaux_surcharges']);
        $this->assertSame([
            'L1' => ['passage' => 0.7, 'redoublement' => 0.2, 'abandon' => 0.1],
            'L3' => ['passage' => 0.5, 'redoublement' => 0.3, 'abandon' => 0.2],
        ], $variante['taux_par_niveau_appliques']);

        $this->assertSame([4, 4], array_column($variante['ecarts_reussite_par_periode'], 'ecart'));
        $this->assertSame(4, $variante['ecart_reussite_final']);
        $this->assertSame([
            ['pas' => 1, 'sortants' => 15, 'sortants_reference' => 18],
            ['pas' => 2, 'sortants' => 11, 'sortants_reference' => 11],
        ], $variante['sortants_par_periode']);
        $this->assertSame(-3, $variante['effet_sortants_cumules']);

        $this->assertSame([
            ['pas' => 1, 'niveaux' => [
                ['niveau' => 'L1', 'simule' => 12, 'reference' => 12, 'ecart' => 0],
                ['niveau' => 'L2', 'simule' => 11, 'reference' => 10, 'ecart' => 1],
                ['niveau' => 'L3', 'simule' => 21, 'reference' => 18, 'ecart' => 3],
            ]],
            ['pas' => 2, 'niveaux' => [
                ['niveau' => 'L1', 'simule' => 12, 'reference' => 12, 'ecart' => 0],
                ['niveau' => 'L2', 'simule' => 10, 'reference' => 9, 'ecart' => 1],
                ['niveau' => 'L3', 'simule' => 13, 'reference' => 10, 'ecart' => 3],
            ]],
        ], $variante['ecarts_par_niveau']);
        $this->assertSame([
            ['niveau' => 'L1', 'simule' => 12, 'reference' => 12, 'ecart' => 0],
            ['niveau' => 'L2', 'simule' => 10, 'reference' => 9, 'ecart' => 1],
            ['niveau' => 'L3', 'simule' => 13, 'reference' => 10, 'ecart' => 3],
        ], $variante['ecarts_niveaux_finaux']);

        // Niveau focal facultatif : absent par défaut, aucune clé inventée.
        $this->assertArrayNotHasKey('niveau_focal', $variante);
        $this->assertArrayNotHasKey('ecart_niveau_focal_final', $variante);
    }

    public function test_sim16_niveau_focal_facultatif_restituel_ecart_cible(): void
    {
        $scenario = $this->creerScenario($this->criteresSim16(['niveau_focal' => 'L2']), '16');

        $variante = $this->executer($scenario, 'sim16-focal')['resultat']['classement'][0];

        $this->assertSame('L2', $variante['niveau_focal']);
        $this->assertSame(1, $variante['ecart_niveau_focal_final']);
    }

    public function test_sim16_definition_refuse_profils_invalides_et_niveau_focal_inconnu(): void
    {
        $service = app(ServiceSimulationTemporelle::class);
        $base = $this->criteresSim16();

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
                'taux_par_niveau' => ['L1' => ['passage' => 0.7, 'redoublement' => 0.2, 'abandon' => 0.1], 'L3' => ['passage' => 0.5, 'redoublement' => 0.5, 'abandon' => 0.2]],
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

        // Niveau focal inconnu : refusé à l'exécution (jamais un zéro muet).
        $focalInconnu = $this->creerScenario($this->criteresSim16(['niveau_focal' => 'L4']), '16');
        $this->actingAs($this->admin)->postJson(
            route('admin.simulation-temporelle.executer', ['id' => $focalInconnu->id]),
            ['annee_universitaire_id' => $this->annee->id, 'graine' => 'sim16-focal-ko', 'nb_variantes' => 1],
        )->assertStatus(422)->assertJsonPath('erreur', fn ($message) => str_contains($message, 'niveau focal L4 absent'));

        $this->assertSame(0, ExecutionTemporelle::count());
    }

    public function test_sim16_catalogue_refuse_hors_contrat(): void
    {
        $valides = $this->parametresCatalogue($this->criteresSim16());
        $this->assertSame($valides, CatalogueScenarios::validerParametres('SIM-16', $valides));

        // Un seul niveau : relève de SIM-14/SIM-15.
        $this->assertRefuseCatalogue(
            fn () => CatalogueScenarios::validerParametres('SIM-16', $this->parametresCatalogue(array_merge(
                $this->criteresSim16(),
                ['taux_par_niveau' => ['L1' => ['passage' => 0.7, 'redoublement' => 0.2, 'abandon' => 0.1]]],
            ))),
            'au moins DEUX niveaux'
        );

        // Deux niveaux mais triplets identiques : aucun déséquilibre.
        $this->assertRefuseCatalogue(
            fn () => CatalogueScenarios::validerParametres('SIM-16', $this->parametresCatalogue(array_merge(
                $this->criteresSim16(),
                ['taux_par_niveau' => [
                    'L1' => ['passage' => 0.7, 'redoublement' => 0.2, 'abandon' => 0.1],
                    'L2' => ['passage' => 0.7, 'redoublement' => 0.2, 'abandon' => 0.1],
                ]],
            ))),
            'DEUX triplets distincts'
        );

        // Invariant de somme par niveau.
        $this->assertRefuseCatalogue(
            fn () => CatalogueScenarios::validerParametres('SIM-16', $this->parametresCatalogue(array_merge(
                $this->criteresSim16(),
                ['taux_par_niveau' => [
                    'L1' => ['passage' => 0.7, 'redoublement' => 0.2, 'abandon' => 0.1],
                    'L3' => ['passage' => 0.5, 'redoublement' => 0.5, 'abandon' => 0.2],
                ]],
            ))),
            '> 1'
        );

        // Niveau focal facultatif, mais non vide s'il est fourni.
        $this->assertRefuseCatalogue(
            fn () => CatalogueScenarios::validerParametres('SIM-16', $this->parametresCatalogue(array_merge(
                $this->criteresSim16(),
                ['niveau_focal' => '  '],
            ))),
            'ne peut pas être vide'
        );

        $schema = CatalogueScenarios::schemaSim16();
        $this->assertTrue($schema['taux']['requis']);
        $this->assertTrue($schema['taux_par_niveau']['requis']);
        $this->assertFalse($schema['niveau_focal']['requis']);
        $this->assertSame(['SIM-14', 'SIM-15'], CatalogueScenarios::exiger('SIM-16')['dependances']);
    }

    public function test_sim16_idempotence_et_absence_de_focal_par_defaut(): void
    {
        $scenario = $this->creerScenario($this->criteresSim16(), '16');

        $premier = $this->executer($scenario, 'sim16-idemp');
        $second = $this->executer($scenario, 'sim16-idemp');
        $this->assertSame($premier['id'], $second['id']);
        $this->assertSame($premier['hash_resultat'], $second['hash_resultat']);
        $this->assertSame(1, ExecutionTemporelle::where('scenario_temporel_id', $scenario->id)->count());
    }
}
