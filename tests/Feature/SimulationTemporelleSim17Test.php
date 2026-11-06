<?php

namespace Tests\Feature;

use App\Modules\SimulationTemporelle\Models\ExecutionTemporelle;
use App\Modules\SimulationTemporelle\Services\ServiceSimulationTemporelle;
use App\Modules\SimulationTemporelle\Support\CatalogueScenarios;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PrepareSimulationTemporelleParNiveau;
use Tests\TestCase;

/**
 * ST-14 / SIM-17 — cohorte exceptionnelle (UNE entrée externe déclarée).
 *
 * Périmètre synthétique : L1=10, L2=20, L3=30 (total 60).
 * Témoin {0.6, 0.2, 0.2}, delta 0, H=2 → totaux [60, 40, 31].
 */
class SimulationTemporelleSim17Test extends TestCase
{
    use PrepareSimulationTemporelleParNiveau;
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function criteresSim17(array $surcharges = []): array
    {
        return $this->criteresBase(array_merge([
            'cohortes_identifiees' => [
                ['code' => 'C1', 'niveau_entree' => 'L1', 'pas_entree' => 1, 'effectif' => 5],
            ],
        ], $surcharges));
    }

    public function test_sim17_cohorte_exceptionnelle_reference_calculee_independamment(): void
    {
        // Référence manuelle (hors moteur) : base 10/20/30, delta 0, H=2.
        // Témoin : p1 = 12/10/18 (40), p2 = 12/9/10 (31).
        // C1 (5 étudiants) entre en L1 au pas 1, APRÈS la progression :
        //   p1 : L1=17, total 45 ; suivi C1=5, non identifiés 12.
        //   p2 : L1 17 → 10 partants + 3 redoublants (plafond conservé) ;
        //        répartition C1 : 3 partants, 1 redoublant, 1 autre sortie ;
        //        L1=13 (1 C1), L2=12 (3 C1, 9 non identifiés), L3=10 → total 35.
        // Écarts au témoin : [+5, +4] ; final +4.
        // Conservation C1 : 5 = (1 + 3) + 0 sortants + 1 autre sortie.
        $scenario = $this->creerScenario($this->criteresSim17(), '17');

        $variante = $this->executer($scenario, 'sim17-ref')['resultat']['classement'][0];

        $this->assertSame([60, 45, 35], $variante['totaux']);
        $this->assertSame(['C1'], array_column($variante['cohortes_identifiees_appliquees'], 'code'));

        $this->assertSame([
            ['pas' => 1, 'total_simule' => 45, 'total_reference' => 40, 'ecart' => 5, 'effectif_identifie' => 5],
            ['pas' => 2, 'total_simule' => 35, 'total_reference' => 31, 'ecart' => 4, 'effectif_identifie' => 4],
        ], $variante['ecarts_cohortes_par_periode']);
        $this->assertSame(4, $variante['ecart_cohortes_final']);

        $this->assertSame([
            ['cohorte' => 'C1', 'niveau_entree' => 'L1', 'pas_entree' => 1,
                'effectif_entree' => 5, 'effectif_final' => 4,
                'effectif_final_par_niveau' => ['L1' => 1, 'L2' => 3],
                'sortants' => 0, 'autres_sorties' => 1, 'ecart_conservation' => 0],
        ], $variante['bilan_cohortes']);

        $this->assertSame([
            ['pas' => 1, 'entrees' => [['cohorte' => 'C1', 'effectif' => 5]], 'total' => 5],
            ['pas' => 2, 'entrees' => [], 'total' => 0],
        ], $variante['entrees_cohortes_par_periode']);
        $this->assertSame([
            ['pas' => 1, 'cohortes' => [], 'total' => 0],
            ['pas' => 2, 'cohortes' => [], 'total' => 0],
        ], $variante['sortants_cohortes_par_periode']);

        // Suivi par pas : identifiés + non identifiés = total du niveau.
        $this->assertSame([
            ['pas' => 1, 'niveaux' => [
                ['niveau' => 'L1', 'total' => 17, 'cohortes' => [['cohorte' => 'C1', 'effectif' => 5]], 'effectif_non_identifie' => 12],
                ['niveau' => 'L2', 'total' => 10, 'cohortes' => [], 'effectif_non_identifie' => 10],
                ['niveau' => 'L3', 'total' => 18, 'cohortes' => [], 'effectif_non_identifie' => 18],
            ]],
            ['pas' => 2, 'niveaux' => [
                ['niveau' => 'L1', 'total' => 13, 'cohortes' => [['cohorte' => 'C1', 'effectif' => 1]], 'effectif_non_identifie' => 12],
                ['niveau' => 'L2', 'total' => 12, 'cohortes' => [['cohorte' => 'C1', 'effectif' => 3]], 'effectif_non_identifie' => 9],
                ['niveau' => 'L3', 'total' => 10, 'cohortes' => [], 'effectif_non_identifie' => 10],
            ]],
        ], $variante['suivi_cohortes']);

        // Poids : la part identifiée est lue dans la partition, jamais supposée.
        $this->assertSame([
            ['pas' => 1, 'effectif_identifie' => 5, 'total' => 45, 'part_identifiee' => 0.1111,
                'cohortes' => [['cohorte' => 'C1', 'niveau' => 'L1', 'effectif' => 5, 'part' => 0.1111]]],
            ['pas' => 2, 'effectif_identifie' => 4, 'total' => 35, 'part_identifiee' => 0.1143,
                'cohortes' => [
                    ['cohorte' => 'C1', 'niveau' => 'L1', 'effectif' => 1, 'part' => 0.0286],
                    ['cohorte' => 'C1', 'niveau' => 'L2', 'effectif' => 3, 'part' => 0.0857],
                ]],
        ], $variante['poids_cohortes_par_periode']);

        // Une seule cohorte : aucune coexistence de générations n'est possible.
        $this->assertSame([
            ['pas' => 1, 'cohortes_simultanees' => 0, 'niveaux' => []],
            ['pas' => 2, 'cohortes_simultanees' => 0, 'niveaux' => []],
        ], $variante['coexistence_par_periode']);
        $this->assertSame(0, $variante['duree_coexistence']);
        $this->assertSame(0, $variante['pic_coexistence']);
    }

    public function test_sim17_definition_refuse_contrats_invalides(): void
    {
        $service = app(ServiceSimulationTemporelle::class);
        $base = $this->criteresSim17();

        $this->assertRefuseDefinition(
            fn () => $service->definir($this->donneesDefinition(array_merge($base, ['cohortes_identifiees' => []])), $this->admin->id),
            'liste non vide'
        );
        $this->assertRefuseDefinition(
            fn () => $service->definir($this->donneesDefinition(array_merge($base, [
                'cohortes_identifiees' => [
                    ['code' => 'C1', 'niveau_entree' => 'L1', 'pas_entree' => 1, 'effectif' => 5],
                    ['code' => 'C1', 'niveau_entree' => 'L1', 'pas_entree' => 2, 'effectif' => 6],
                ],
            ])), $this->admin->id),
            'dupliqué'
        );
        $this->assertRefuseDefinition(
            fn () => $service->definir($this->donneesDefinition(array_merge($base, [
                'cohortes_identifiees' => [
                    ['code' => 'C1', 'niveau_entree' => 'L1', 'pas_entree' => 1, 'effectif' => 0],
                ],
            ])), $this->admin->id),
            'au moins égal à 1'
        );
        $this->assertRefuseDefinition(
            fn () => $service->definir($this->donneesDefinition(array_merge($base, [
                'cohortes_identifiees' => [
                    ['code' => 'C1', 'niveau_entree' => 'L1', 'pas_entree' => 3, 'effectif' => 5],
                ],
            ])), $this->admin->id),
            'compris dans [1, 2]'
        );
        $this->assertRefuseDefinition(
            fn () => $service->definir($this->donneesDefinition(array_merge($base, [
                'taux_par_niveau' => ['L1' => ['passage' => 0.7, 'redoublement' => 0.2, 'abandon' => 0.1]],
            ])), $this->admin->id),
            'incompatible avec un autre descripteur'
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

        // Deux niveaux d'entrée distincts : aucune cohorte exceptionnelle unique.
        $this->assertRefuseDefinition(
            fn () => $service->definir($this->donneesDefinition(array_merge($base, [
                'cohortes_identifiees' => [
                    ['code' => 'C1', 'niveau_entree' => 'L1', 'pas_entree' => 1, 'effectif' => 5],
                    ['code' => 'C2', 'niveau_entree' => 'L2', 'pas_entree' => 2, 'effectif' => 5],
                ],
            ])), $this->admin->id),
            'MÊME niveau'
        );

        $this->assertSame(0, ExecutionTemporelle::count());
    }

    public function test_sim17_execution_refuse_niveau_absent_et_generation_complete(): void
    {
        $service = app(ServiceSimulationTemporelle::class);

        $niveauAbsent = $this->creerScenario($this->criteresSim17([
            'cohortes_identifiees' => [
                ['code' => 'C1', 'niveau_entree' => 'L9', 'pas_entree' => 1, 'effectif' => 5],
            ],
        ]), '17');

        try {
            $service->executer($niveauAbsent, $this->annee->id, 'sim17-l9', 1, $this->admin->id);
            $this->fail('Une DomainException était attendue (niveau absent).');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('absent du périmètre', $e->getMessage());
        }

        // SIM-17 : une génération complète (effectif = référence du niveau)
        // décrit SIM-18, pas une cohorte exceptionnelle.
        $generationComplete = $this->creerScenario($this->criteresSim17([
            'cohortes_identifiees' => [
                ['code' => 'C1', 'niveau_entree' => 'L1', 'pas_entree' => 1, 'effectif' => 10],
            ],
        ]), '17');

        try {
            $service->executer($generationComplete, $this->annee->id, 'sim17-full', 1, $this->admin->id);
            $this->fail('Une DomainException était attendue (génération complète).');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('doit différer', $e->getMessage());
        }
    }

    public function test_sim17_catalogue_refuse_hors_contrat(): void
    {
        $valides = $this->parametresCatalogue($this->criteresSim17());
        $this->assertSame($valides, CatalogueScenarios::validerParametres('SIM-17', $valides));

        // Plusieurs cohortes : relève de SIM-18.
        $this->assertRefuseCatalogue(
            fn () => CatalogueScenarios::validerParametres('SIM-17', $this->parametresCatalogue(array_merge(
                $this->criteresSim17(),
                ['cohortes_identifiees' => [
                    ['code' => 'C1', 'niveau_entree' => 'L1', 'pas_entree' => 1, 'effectif' => 5],
                    ['code' => 'C2', 'niveau_entree' => 'L1', 'pas_entree' => 2, 'effectif' => 6],
                ]],
            ))),
            'EXACTEMENT UNE'
        );

        $this->assertRefuseCatalogue(
            fn () => CatalogueScenarios::validerParametres('SIM-17', $this->parametresCatalogue(array_merge(
                $this->criteresSim17(),
                ['cohortes_identifiees' => [
                    ['code' => 'C1', 'niveau_entree' => 'L1', 'pas_entree' => 1, 'effectif' => 5],
                    ['code' => 'C1', 'niveau_entree' => 'L1', 'pas_entree' => 2, 'effectif' => 6],
                ]],
            ))),
            'dupliqué'
        );

        $this->assertRefuseCatalogue(
            fn () => CatalogueScenarios::validerParametres('SIM-17', $this->parametresCatalogue(array_merge(
                $this->criteresSim17(),
                ['cohortes_identifiees' => [
                    ['code' => 'C1', 'niveau_entree' => 'L1', 'pas_entree' => 1, 'effectif' => 0],
                ]],
            ))),
            'hors bornes'
        );

        $schema = CatalogueScenarios::schemaSim17();
        $this->assertTrue($schema['taux']['requis']);
        $this->assertTrue($schema['cohortes_identifiees']['requis']);
        $this->assertSame(1, $schema['cohortes_identifiees']['min_elements']);
        $this->assertSame(['SOCLE-EFFECTIFS'], CatalogueScenarios::exiger('SIM-17')['dependances']);
    }

    public function test_sim17_idempotence_et_isolation_facultaire(): void
    {
        $scenario = $this->creerScenario($this->criteresSim17(), '17');

        $premier = $this->executer($scenario, 'sim17-idemp');
        $second = $this->executer($scenario, 'sim17-idemp');
        $this->assertSame($premier['id'], $second['id']);
        $this->assertSame($premier['hash_resultat'], $second['hash_resultat']);
        $this->assertSame(1, ExecutionTemporelle::where('scenario_temporel_id', $scenario->id)->count());

        $this->actingAs($this->sansPermission)->postJson(
            route('admin.simulation-temporelle.executer', ['id' => $scenario->id]),
            ['annee_universitaire_id' => $this->annee->id, 'graine' => 'sim17-403', 'nb_variantes' => 1],
        )->assertForbidden();
        $this->actingAs($this->adminB)->postJson(
            route('admin.simulation-temporelle.executer', ['id' => $scenario->id]),
            ['annee_universitaire_id' => $this->annee->id, 'graine' => 'sim17-403', 'nb_variantes' => 1],
        )->assertForbidden();
    }
}
