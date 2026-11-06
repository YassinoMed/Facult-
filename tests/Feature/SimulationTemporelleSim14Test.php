<?php

namespace Tests\Feature;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Schedule\Models\Group;
use App\Modules\SimulationTemporelle\Models\ExecutionTemporelle;
use App\Modules\SimulationTemporelle\Models\SnapshotExecution;
use App\Modules\SimulationTemporelle\Services\ServiceSimulationTemporelle;
use App\Modules\SimulationTemporelle\Support\CatalogueScenarios;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PrepareSimulationTemporelleParNiveau;
use Tests\TestCase;

/**
 * ST-14 / SIM-14 — blocage en L1 (surcharge strictement locale du niveau
 * L1, trajectoire témoin au jeu global).
 *
 * Les résultats attendus sont calculés À LA MAIN dans les commentaires.
 * Périmètre synthétique : L1=10, L2=20, L3=30 (total 60).
 */
class SimulationTemporelleSim14Test extends TestCase
{
    use PrepareSimulationTemporelleParNiveau;
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function criteresSim14(array $surcharges = []): array
    {
        return $this->criteresBase(array_merge([
            'taux_par_niveau' => [
                'L1' => ['passage' => 0.5, 'redoublement' => 0.3, 'abandon' => 0.2],
            ],
            'niveau_focal' => 'L1',
        ], $surcharges));
    }

    public function test_sim14_blocage_l1_reference_calculee_independamment(): void
    {
        // Référence manuelle (hors moteur) : base 10/20/30, delta 0, H=2.
        // Témoin {0.6, 0.2, 0.2} → [60, 40, 31] ; sortants [18, 11].
        // Surcharge L1 {0.5, 0.3, 0.2} :
        //   p1 : L1=13, L2=9, L3=18 (40) ; p2 : L1=14, L2=9, L3=9 (32).
        // Écarts de totaux [0, +1] ; écart final +1 ; sortants identiques.
        $scenario = $this->creerScenario($this->criteresSim14(), '14');

        $execution = $this->executer($scenario, 'sim14-ref');
        $variante = $execution['resultat']['classement'][0];

        $this->assertSame([60, 40, 32], $variante['totaux']);
        $this->assertSame([60, 40, 31], $variante['totaux_reference_reussite']);
        $this->assertSame(0.1, $variante['variation_niveau']);
        $this->assertSame([
            'L1' => ['passage' => 0.5, 'redoublement' => 0.3, 'abandon' => 0.2],
        ], $variante['taux_par_niveau_appliques']);
        $this->assertSame(['L1'], $variante['niveaux_surcharges']);

        $this->assertSame([
            ['pas' => 1, 'total_simule' => 40, 'total_reference' => 40, 'ecart' => 0],
            ['pas' => 2, 'total_simule' => 32, 'total_reference' => 31, 'ecart' => 1],
        ], $variante['ecarts_reussite_par_periode']);
        $this->assertSame(1, $variante['ecart_reussite_final']);

        $this->assertSame([
            ['pas' => 1, 'sortants' => 18, 'sortants_reference' => 18],
            ['pas' => 2, 'sortants' => 11, 'sortants_reference' => 11],
        ], $variante['sortants_par_periode']);
        $this->assertSame(0, $variante['effet_sortants_cumules']);

        $this->assertSame([
            ['pas' => 1, 'niveaux' => [
                ['niveau' => 'L1', 'simule' => 13, 'reference' => 12, 'ecart' => 1],
                ['niveau' => 'L2', 'simule' => 9, 'reference' => 10, 'ecart' => -1],
                ['niveau' => 'L3', 'simule' => 18, 'reference' => 18, 'ecart' => 0],
            ]],
            ['pas' => 2, 'niveaux' => [
                ['niveau' => 'L1', 'simule' => 14, 'reference' => 12, 'ecart' => 2],
                ['niveau' => 'L2', 'simule' => 9, 'reference' => 9, 'ecart' => 0],
                ['niveau' => 'L3', 'simule' => 9, 'reference' => 10, 'ecart' => -1],
            ]],
        ], $variante['ecarts_par_niveau']);

        $this->assertSame(['L1' => 12, 'L2' => 9, 'L3' => 10], $variante['cohortes_reference_finales']);
        $this->assertSame([
            ['niveau' => 'L1', 'simule' => 14, 'reference' => 12, 'ecart' => 2],
            ['niveau' => 'L2', 'simule' => 9, 'reference' => 9, 'ecart' => 0],
            ['niveau' => 'L3', 'simule' => 9, 'reference' => 10, 'ecart' => -1],
        ], $variante['ecarts_niveaux_finaux']);
        $this->assertSame('L1', $variante['niveau_focal']);
        $this->assertSame(2, $variante['ecart_niveau_focal_final']);

        $snapshots = SnapshotExecution::where('execution_temporelle_id', $execution['id'])
            ->orderBy('pas')->get();
        $this->assertSame(['L1' => 13, 'L2' => 9, 'L3' => 18], $snapshots->firstWhere('pas', 1)->etat['cohortes']);
        $this->assertSame(['L1' => 14, 'L2' => 9, 'L3' => 9], $snapshots->firstWhere('pas', 2)->etat['cohortes']);
    }

    public function test_sim14_admissions_identiques_et_ecart_non_additif(): void
    {
        // delta = +0.10 → admissions [11, 12] dans les DEUX trajectoires.
        // Témoin → [60, 41, 35] ; sortants [18, 11].
        // Surcharge L1 → [60, 41, 34] ; sortants [18, 11].
        // Malgré la hausse du redoublement L1, le total final baisse de 1 :
        // la baisse du passage L1 n'est pas compensée (aucune additivité).
        $scenario = $this->creerScenario($this->criteresSim14([
            'delta_admissions' => 0.10,
            'bornes' => ['min' => 0.0, 'max' => 0.5],
        ]), '14');

        $variante = $this->executer($scenario, 'sim14-delta')['resultat']['classement'][0];

        $this->assertSame([11, 12], array_column($variante['admissions_par_periode'], 'admissions'));
        $this->assertSame([60, 41, 34], $variante['totaux']);
        $this->assertSame([60, 41, 35], $variante['totaux_reference_reussite']);
        $this->assertSame([0, -1], array_column($variante['ecarts_reussite_par_periode'], 'ecart'));
        $this->assertSame(-1, $variante['ecart_reussite_final']);
        $this->assertSame(0, $variante['effet_sortants_cumules']);
        $this->assertSame([
            ['niveau' => 'L1', 'simule' => 16, 'reference' => 15, 'ecart' => 1],
            ['niveau' => 'L2', 'simule' => 9, 'reference' => 10, 'ecart' => -1],
            ['niveau' => 'L3', 'simule' => 9, 'reference' => 10, 'ecart' => -1],
        ], $variante['ecarts_niveaux_finaux']);
    }

    public function test_sim14_definition_refuse_profils_invalides_et_incompatibles(): void
    {
        $service = app(ServiceSimulationTemporelle::class);
        $base = $this->criteresSim14();

        $this->assertRefuseDefinition(
            fn () => $service->definir($this->donneesDefinition(array_merge($base, ['taux_par_niveau' => []])), $this->admin->id),
            'objet non vide'
        );
        $sansReference = $base;
        unset($sansReference['taux']);
        $this->assertRefuseDefinition(
            fn () => $service->definir($this->donneesDefinition($sansReference), $this->admin->id),
            'taux de référence requis'
        );
        $this->assertRefuseDefinition(
            fn () => $service->definir($this->donneesDefinition(array_merge($base, [
                'taux_par_niveau' => ['L1' => ['passage' => 0.5, 'redoublement' => 0.3]],
            ])), $this->admin->id),
            'doit fournir'
        );
        $this->assertRefuseDefinition(
            fn () => $service->definir($this->donneesDefinition(array_merge($base, [
                'taux_par_niveau' => ['L1' => ['passage' => 1.5, 'redoublement' => -0.1, 'abandon' => 0.0]],
            ])), $this->admin->id),
            'hors [0,1]'
        );
        $this->assertRefuseDefinition(
            fn () => $service->definir($this->donneesDefinition(array_merge($base, [
                'taux_par_niveau' => ['L1' => ['passage' => 0.6, 'redoublement' => 0.4, 'abandon' => 0.3]],
            ])), $this->admin->id),
            '> 1'
        );

        // Incompatibilité avec les autres descripteurs et profils temporels.
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
                'delta_admissions' => 0.10, 'bornes' => ['min' => 0.0, 'max' => 0.5],
                'taux_cible_croissance' => 0.0,
            ])), $this->admin->id),
            'SIM-05'
        );
        $this->assertRefuseDefinition(
            fn () => $service->definir($this->donneesDefinition(array_merge($base, [
                'profondeur_baisse' => 0.2, 'duree_baisse_annees' => 1, 'duree_recuperation_annees' => 1,
            ])), $this->admin->id),
            'SIM-06'
        );
        $this->assertRefuseDefinition(
            fn () => $service->definir($this->donneesDefinition(array_merge($base, [
                'taux_ameliore' => ['passage' => 0.8, 'redoublement' => 0.1, 'abandon' => 0.1],
            ])), $this->admin->id),
            'autre descripteur'
        );

        // Niveau hors périmètre : refusé à l'exécution (jamais ignoré).
        $horsPerimetre = $this->creerScenario($this->criteresSim14([
            'taux_par_niveau' => ['L4' => ['passage' => 0.5, 'redoublement' => 0.3, 'abandon' => 0.2]],
        ]), '14');
        $this->actingAs($this->admin)->postJson(
            route('admin.simulation-temporelle.executer', ['id' => $horsPerimetre->id]),
            ['annee_universitaire_id' => $this->annee->id, 'graine' => 'sim14-hp', 'nb_variantes' => 1],
        )->assertStatus(422)->assertJsonPath('erreur', fn ($message) => str_contains($message, 'absent du périmètre'));

        $this->assertSame(0, ExecutionTemporelle::count());
    }

    public function test_sim14_catalogue_refuse_hors_contrat(): void
    {
        $valides = $this->parametresCatalogue($this->criteresSim14());
        $this->assertSame($valides, CatalogueScenarios::validerParametres('SIM-14', $valides));

        // La surcharge doit porter EXACTEMENT sur L1.
        $this->assertRefuseCatalogue(
            fn () => CatalogueScenarios::validerParametres('SIM-14', $this->parametresCatalogue(array_merge(
                $this->criteresSim14(),
                ['taux_par_niveau' => ['L2' => ['passage' => 0.5, 'redoublement' => 0.3, 'abandon' => 0.2]]],
            ))),
            'EXACTEMENT le niveau L1'
        );
        $this->assertRefuseCatalogue(
            fn () => CatalogueScenarios::validerParametres('SIM-14', $this->parametresCatalogue(array_merge(
                $this->criteresSim14(),
                ['taux_par_niveau' => [
                    'L1' => ['passage' => 0.5, 'redoublement' => 0.3, 'abandon' => 0.2],
                    'L2' => ['passage' => 0.5, 'redoublement' => 0.3, 'abandon' => 0.2],
                ]],
            ))),
            'EXACTEMENT le niveau L1'
        );

        // Redoublement strictement supérieur requis.
        $this->assertRefuseCatalogue(
            fn () => CatalogueScenarios::validerParametres('SIM-14', $this->parametresCatalogue(array_merge(
                $this->criteresSim14(),
                ['taux_par_niveau' => ['L1' => ['passage' => 0.6, 'redoublement' => 0.2, 'abandon' => 0.2]]],
            ))),
            'STRICTEMENT supérieur'
        );

        // Aucune amélioration admise sur le passage ni l'abandon.
        $this->assertRefuseCatalogue(
            fn () => CatalogueScenarios::validerParametres('SIM-14', $this->parametresCatalogue(array_merge(
                $this->criteresSim14(),
                ['taux_par_niveau' => ['L1' => ['passage' => 0.7, 'redoublement' => 0.3, 'abandon' => 0.0]]],
            ))),
            'amélioration'
        );
        $this->assertRefuseCatalogue(
            fn () => CatalogueScenarios::validerParametres('SIM-14', $this->parametresCatalogue(array_merge(
                $this->criteresSim14(),
                ['taux_par_niveau' => ['L1' => ['passage' => 0.5, 'redoublement' => 0.3, 'abandon' => 0.1]]],
            ))),
            'amélioration'
        );

        // Invariant de somme par niveau.
        $this->assertRefuseCatalogue(
            fn () => CatalogueScenarios::validerParametres('SIM-14', $this->parametresCatalogue(array_merge(
                $this->criteresSim14(),
                ['taux_par_niveau' => ['L1' => ['passage' => 0.6, 'redoublement' => 0.5, 'abandon' => 0.2]]],
            ))),
            '> 1'
        );

        // Niveau focal imposé par le schéma.
        $this->assertRefuseCatalogue(
            fn () => CatalogueScenarios::validerParametres('SIM-14', $this->parametresCatalogue(array_merge(
                $this->criteresSim14(),
                ['niveau_focal' => 'L2'],
            ))),
            'non autorisée'
        );

        // Clés hors schéma (gel, choc, cible, creux, autres descripteurs).
        foreach ([
            ['duree_gel_annees' => 1],
            ['pas_exceptionnel' => 1, 'admissions_exceptionnelles' => 5],
            ['taux_cible_croissance' => 0.05],
            ['profondeur_baisse' => 0.2, 'duree_baisse_annees' => 1, 'duree_recuperation_annees' => 1],
            ['taux_modifie' => ['passage' => 0.5, 'redoublement' => 0.3, 'abandon' => 0.2]],
        ] as $clesInterdites) {
            $this->assertRefuseCatalogue(
                fn () => CatalogueScenarios::validerParametres('SIM-14', $this->parametresCatalogue(array_merge(
                    $this->criteresSim14(),
                    $clesInterdites,
                ))),
                'inconnu'
            );
        }

        $schema = CatalogueScenarios::schemaSim14();
        $this->assertTrue($schema['taux']['requis']);
        $this->assertTrue($schema['taux_par_niveau']['requis']);
        $this->assertSame(['L1'], $schema['niveau_focal']['valeurs']);
    }

    public function test_sim14_idempotence_permissions_et_lecture_seule(): void
    {
        $scenario = $this->creerScenario($this->criteresSim14(), '14');

        $premier = $this->executer($scenario, 'sim14-idemp');
        $second = $this->executer($scenario, 'sim14-idemp');
        $this->assertSame($premier['id'], $second['id']);
        $this->assertSame($premier['hash_resultat'], $second['hash_resultat']);
        $this->assertSame(1, ExecutionTemporelle::where('scenario_temporel_id', $scenario->id)->count());

        $this->actingAs($this->sansPermission)->postJson(
            route('admin.simulation-temporelle.executer', ['id' => $scenario->id]),
            ['annee_universitaire_id' => $this->annee->id, 'graine' => 'sim14-403', 'nb_variantes' => 1],
        )->assertForbidden();
        $this->actingAs($this->adminB)->postJson(
            route('admin.simulation-temporelle.executer', ['id' => $scenario->id]),
            ['annee_universitaire_id' => $this->annee->id, 'graine' => 'sim14-403', 'nb_variantes' => 1],
        )->assertForbidden();

        $avant = [
            'etudiants' => Etudiant::count(),
            'inscriptions' => InscriptionAcademique::count(),
            'groupes' => Group::count(),
            'facultes' => Faculte::count(),
            'niveaux' => Niveau::count(),
        ];
        $this->executer($scenario, 'sim14-ro');
        $this->assertSame($avant, [
            'etudiants' => Etudiant::count(),
            'inscriptions' => InscriptionAcademique::count(),
            'groupes' => Group::count(),
            'facultes' => Faculte::count(),
            'niveaux' => Niveau::count(),
        ]);
    }
}
