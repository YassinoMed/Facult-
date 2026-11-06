<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\Iam\HabilitationIam;
use App\Models\InscriptionAcademique;
use App\Models\User;
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
 * ST-14.00 — contrat commun du catalogue des scénarios avancés.
 *
 * Preuves : intégrité des 44 scénarios planifiés, validation stricte des
 * paramètres du socle, hash de définition complet et déterministe,
 * permission dédiée, absence de dépendance au périmètre facultaire et
 * zéro écriture (métadonnées uniquement).
 */
class SimulationTemporelleCatalogueTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $adminRestreint;

    private User $sansPermission;

    private Faculte $faculteA;

    private Faculte $faculteB;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('modules.simulation-temporelle.active', true);

        $this->faculteA = Faculte::create(['nom' => 'Sciences', 'code' => 'FST', 'active' => true]);
        $this->faculteB = Faculte::create(['nom' => 'Droit', 'code' => 'FDEG', 'active' => true]);
        AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Catalogue', 'email' => 'cat-'.uniqid().'@u.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->adminRestreint = User::creerCompteBackOffice([
            'name' => 'Catalogue B', 'email' => 'catb-'.uniqid().'@u.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteB->id,
        ], 'admin_faculte');

        $this->sansPermission = User::creerCompteBackOffice([
            'name' => 'Sans permission', 'email' => 'catn-'.uniqid().'@u.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        foreach ([$this->admin, $this->adminRestreint] as $acteur) {
            HabilitationIam::create([
                'user_id' => $acteur->id,
                'permission_code' => 'simulation.view',
                'statut' => HabilitationIam::STATUT_ACTIVE,
                'debut_le' => now(),
            ]);
        }
        MoteurAutorisation::invalider(null);
    }

    /** @return array<string, mixed> */
    private function parametresValides(): array
    {
        return [
            'delta_admissions' => 0.1,
            'bornes' => ['min' => -0.5, 'max' => 2.0],
            'horizon_annees' => 3,
            'taux' => ['passage' => 0.7, 'redoublement' => 0.2, 'abandon' => 0.05],
        ];
    }

    /** @return array<string, mixed> */
    private function parametresBaisse(): array
    {
        return [
            'delta_admissions' => -0.1,
            'bornes' => ['min' => -0.5, 'max' => 0.0],
            'horizon_annees' => 3,
            'taux' => ['passage' => 0.7, 'redoublement' => 0.2, 'abandon' => 0.05],
        ];
    }

    private function assertRefuse(callable $action, string $messagePart = ''): void
    {
        try {
            $action();
        } catch (\DomainException $e) {
            if ($messagePart !== '') {
                $this->assertStringContainsString($messagePart, $e->getMessage());
            }

            return;
        }

        $this->fail('Une DomainException était attendue.');
    }

    public function test_catalogue_expose_45_entrees_et_une_empreinte_stable(): void
    {
        $reponse = $this->actingAs($this->admin)->getJson(route('admin.simulation-temporelle.catalogue'));

        $reponse->assertOk()
            ->assertJsonPath('contrat_version', CatalogueScenarios::VERSION_CONTRAT)
            ->assertJsonPath('version_moteur', ServiceSimulationTemporelle::VERSION_MOTEUR)
            ->assertJsonPath('scenarios.0.code', 'SOCLE-EFFECTIFS')
            ->assertJsonPath('scenarios.1.code', 'SIM-01')
            ->assertJsonPath('scenarios.1.statut', CatalogueScenarios::STATUT_DISPONIBLE)
            ->assertJsonPath('scenarios.2.code', 'SIM-02')
            ->assertJsonPath('scenarios.2.statut', CatalogueScenarios::STATUT_DISPONIBLE)
            ->assertJsonPath('scenarios.3.code', 'SIM-03')
            ->assertJsonPath('scenarios.3.statut', CatalogueScenarios::STATUT_DISPONIBLE)
            ->assertJsonPath('scenarios.4.code', 'SIM-04')
            ->assertJsonPath('scenarios.4.statut', CatalogueScenarios::STATUT_DISPONIBLE)
            ->assertJsonPath('scenarios.5.code', 'SIM-05')
            ->assertJsonPath('scenarios.5.statut', CatalogueScenarios::STATUT_DISPONIBLE)
            ->assertJsonPath('scenarios.6.code', 'SIM-06')
            ->assertJsonPath('scenarios.6.statut', CatalogueScenarios::STATUT_DISPONIBLE)
            ->assertJsonPath('scenarios.7.code', 'SIM-07')
            ->assertJsonPath('scenarios.7.statut', CatalogueScenarios::STATUT_DISPONIBLE)
            ->assertJsonPath('scenarios.8.code', 'SIM-08')
            ->assertJsonPath('scenarios.8.statut', CatalogueScenarios::STATUT_DISPONIBLE)
            ->assertJsonPath('scenarios.9.code', 'SIM-09')
            ->assertJsonPath('scenarios.9.statut', CatalogueScenarios::STATUT_DISPONIBLE)
            ->assertJsonPath('scenarios.10.code', 'SIM-10')
            ->assertJsonPath('scenarios.10.statut', CatalogueScenarios::STATUT_DISPONIBLE)
            ->assertJsonPath('scenarios.11.code', 'SIM-11')
            ->assertJsonPath('scenarios.11.statut', CatalogueScenarios::STATUT_DISPONIBLE)
            ->assertJsonPath('scenarios.12.code', 'SIM-12')
            ->assertJsonPath('scenarios.12.statut', CatalogueScenarios::STATUT_DISPONIBLE)
            ->assertJsonPath('scenarios.14.code', 'SIM-14')
            ->assertJsonPath('scenarios.14.statut', CatalogueScenarios::STATUT_DISPONIBLE)
            ->assertJsonPath('scenarios.15.code', 'SIM-15')
            ->assertJsonPath('scenarios.15.statut', CatalogueScenarios::STATUT_DISPONIBLE)
            ->assertJsonPath('scenarios.16.code', 'SIM-16')
            ->assertJsonPath('scenarios.16.statut', CatalogueScenarios::STATUT_DISPONIBLE)
            ->assertJsonPath('scenarios.17.code', 'SIM-17')
            ->assertJsonPath('scenarios.17.statut', CatalogueScenarios::STATUT_DISPONIBLE)
            ->assertJsonPath('scenarios.18.code', 'SIM-18')
            ->assertJsonPath('scenarios.18.statut', CatalogueScenarios::STATUT_DISPONIBLE)
            ->assertJsonPath('scenarios.19.code', 'SIM-19')
            ->assertJsonPath('scenarios.19.statut', CatalogueScenarios::STATUT_DISPONIBLE)
            ->assertJsonPath('scenarios.20.code', 'SIM-20')
            ->assertJsonPath('scenarios.20.statut', CatalogueScenarios::STATUT_PLANIFIE)
            ->assertJsonCount(45, 'scenarios');

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $reponse->json('empreinte'));
        $this->assertSame($reponse->json('empreinte'), CatalogueScenarios::empreinteCatalogue());
    }

    public function test_catalogue_refuse_sans_permission_dediee(): void
    {
        $this->actingAs($this->sansPermission)
            ->getJson(route('admin.simulation-temporelle.catalogue'))
            ->assertForbidden();
    }

    public function test_catalogue_est_independant_du_perimetre_facultaire_et_ne_divulgue_aucune_donnee_metier(): void
    {
        $global = $this->actingAs($this->admin)->getJson(route('admin.simulation-temporelle.catalogue'));
        $restreint = $this->actingAs($this->adminRestreint)->getJson(route('admin.simulation-temporelle.catalogue'));

        $global->assertOk();
        $restreint->assertOk();
        $this->assertSame($global->json('empreinte'), $restreint->json('empreinte'));
        $this->assertSame($global->json('scenarios'), $restreint->json('scenarios'));

        // Le catalogue est un contrat global : aucune ligne métier, aucun
        // identifiant de faculté, aucune exécution.
        $this->assertStringNotContainsString('faculte_id', $global->getContent());
        $this->assertStringNotContainsString('hash_resultat', $global->getContent());
        $this->assertStringNotContainsString('etudiants', $global->getContent());
    }

    public function test_integrite_du_catalogue_codes_versions_et_statuts(): void
    {
        $bilan = CatalogueScenarios::verifierIntegrite();

        $this->assertSame(45, $bilan['scenarios']);
        $this->assertSame(20, $bilan['disponibles']);
        $this->assertSame(25, $bilan['planifies']);
        $this->assertCount(45, array_unique($bilan['codes']));

        foreach (CatalogueScenarios::tout() as $code => $definition) {
            $this->assertSame($code, $definition['code']);
            $this->assertArrayHasKey($definition['categorie'], CatalogueScenarios::CATEGORIES);

            if ($definition['statut'] === CatalogueScenarios::STATUT_PLANIFIE) {
                $this->assertNull($definition['version']);
                $this->assertNull($definition['version_formules']);
                $this->assertNull($definition['schema']);
            }
        }

        $socle = CatalogueScenarios::exiger('SOCLE-EFFECTIFS');
        $this->assertSame(CatalogueScenarios::STATUT_DISPONIBLE, $socle['statut']);
        $this->assertSame(1, $socle['version']);
        $this->assertSame('1.0', $socle['version_formules']);
        $this->assertIsArray($socle['schema']);
        $this->assertNotSame([], $socle['schema']);

        $sim01 = CatalogueScenarios::exiger('SIM-01');
        $this->assertSame(CatalogueScenarios::STATUT_DISPONIBLE, $sim01['statut']);
        $this->assertSame(1, $sim01['version']);
        $this->assertSame('1.0', $sim01['version_formules']);
        $this->assertSame(['SOCLE-EFFECTIFS'], $sim01['dependances']);

        $sim02 = CatalogueScenarios::exiger('SIM-02');
        $this->assertSame(CatalogueScenarios::STATUT_DISPONIBLE, $sim02['statut']);
        $this->assertSame('configuration', $sim02['compatibilite']);
        $this->assertSame(1, $sim02['version']);
        $this->assertSame('1.0', $sim02['version_formules']);
        $this->assertSame(['SOCLE-EFFECTIFS'], $sim02['dependances']);
        $this->assertTrue($sim02['schema']['taux']['requis']);

        $sim03 = CatalogueScenarios::exiger('SIM-03');
        $this->assertSame(CatalogueScenarios::STATUT_DISPONIBLE, $sim03['statut']);
        $this->assertSame('extension_temporelle', $sim03['compatibilite']);
        $this->assertSame(1, $sim03['version']);
        $this->assertSame('1.0', $sim03['version_formules']);
        $this->assertSame(['SOCLE-EFFECTIFS'], $sim03['dependances']);
        $this->assertTrue($sim03['schema']['taux']['requis']);
        $this->assertTrue($sim03['schema']['duree_gel_annees']['requis']);

        $sim04 = CatalogueScenarios::exiger('SIM-04');
        $this->assertSame(CatalogueScenarios::STATUT_DISPONIBLE, $sim04['statut']);
        $this->assertSame('extension_temporelle', $sim04['compatibilite']);
        $this->assertSame(1, $sim04['version']);
        $this->assertSame('1.0', $sim04['version_formules']);
        $this->assertSame(['SOCLE-EFFECTIFS'], $sim04['dependances']);
        $this->assertTrue($sim04['schema']['taux']['requis']);
        $this->assertTrue($sim04['schema']['admissions_exceptionnelles']['requis']);
        $this->assertTrue($sim04['schema']['pas_exceptionnel']['requis']);

        $sim05 = CatalogueScenarios::exiger('SIM-05');
        $this->assertSame(CatalogueScenarios::STATUT_DISPONIBLE, $sim05['statut']);
        $this->assertSame('analyse_nouvelle', $sim05['compatibilite']);
        $this->assertSame(1, $sim05['version']);
        $this->assertSame('1.0', $sim05['version_formules']);
        $this->assertSame(['SOCLE-EFFECTIFS'], $sim05['dependances']);
        $this->assertTrue($sim05['schema']['taux']['requis']);
        $this->assertTrue($sim05['schema']['taux_cible_croissance']['requis']);

        $sim06 = CatalogueScenarios::exiger('SIM-06');
        $this->assertSame(CatalogueScenarios::STATUT_DISPONIBLE, $sim06['statut']);
        $this->assertSame('extension_temporelle', $sim06['compatibilite']);
        $this->assertSame(1, $sim06['version']);
        $this->assertSame('1.0', $sim06['version_formules']);
        $this->assertSame(['SOCLE-EFFECTIFS'], $sim06['dependances']);
        $this->assertTrue($sim06['schema']['taux']['requis']);
        $this->assertTrue($sim06['schema']['profondeur_baisse']['requis']);
        $this->assertTrue($sim06['schema']['duree_baisse_annees']['requis']);
        $this->assertTrue($sim06['schema']['duree_recuperation_annees']['requis']);

        $sim07 = CatalogueScenarios::exiger('SIM-07');
        $this->assertSame(CatalogueScenarios::STATUT_DISPONIBLE, $sim07['statut']);
        $this->assertSame('analyse_nouvelle', $sim07['compatibilite']);
        $this->assertSame(1, $sim07['version']);
        $this->assertSame('1.0', $sim07['version_formules']);
        $this->assertSame(['SOCLE-EFFECTIFS'], $sim07['dependances']);
        $this->assertTrue($sim07['schema']['taux']['requis']);
        $this->assertTrue($sim07['schema']['taux_ameliore']['requis']);

        $sim08 = CatalogueScenarios::exiger('SIM-08');
        $this->assertSame(CatalogueScenarios::STATUT_DISPONIBLE, $sim08['statut']);
        $this->assertSame('analyse_nouvelle', $sim08['compatibilite']);
        $this->assertSame(1, $sim08['version']);
        $this->assertSame('1.0', $sim08['version_formules']);
        $this->assertSame(['SOCLE-EFFECTIFS'], $sim08['dependances']);
        $this->assertTrue($sim08['schema']['taux']['requis']);
        $this->assertTrue($sim08['schema']['taux_degrade']['requis']);

        $sim09 = CatalogueScenarios::exiger('SIM-09');
        $this->assertSame(CatalogueScenarios::STATUT_DISPONIBLE, $sim09['statut']);
        $this->assertSame('analyse_nouvelle', $sim09['compatibilite']);
        $this->assertSame(1, $sim09['version']);
        $this->assertSame('1.0', $sim09['version_formules']);
        $this->assertSame(['SOCLE-EFFECTIFS'], $sim09['dependances']);
        $this->assertTrue($sim09['schema']['taux']['requis']);
        $this->assertTrue($sim09['schema']['taux_modifie']['requis']);
        $this->assertSame(['redoublement'], $sim09['schema']['axe_variation']['valeurs']);
        $this->assertSame(['hausse'], $sim09['schema']['sens_variation']['valeurs']);

        $sim10 = CatalogueScenarios::exiger('SIM-10');
        $this->assertSame(CatalogueScenarios::STATUT_DISPONIBLE, $sim10['statut']);
        $this->assertSame('analyse_nouvelle', $sim10['compatibilite']);
        $this->assertSame(1, $sim10['version']);
        $this->assertSame('1.0', $sim10['version_formules']);
        $this->assertSame(['SOCLE-EFFECTIFS'], $sim10['dependances']);
        $this->assertTrue($sim10['schema']['taux']['requis']);
        $this->assertTrue($sim10['schema']['taux_modifie']['requis']);
        $this->assertSame(['redoublement'], $sim10['schema']['axe_variation']['valeurs']);
        $this->assertSame(['baisse'], $sim10['schema']['sens_variation']['valeurs']);

        $sim11 = CatalogueScenarios::exiger('SIM-11');
        $this->assertSame(CatalogueScenarios::STATUT_DISPONIBLE, $sim11['statut']);
        $this->assertSame('analyse_nouvelle', $sim11['compatibilite']);
        $this->assertSame(1, $sim11['version']);
        $this->assertSame('1.0', $sim11['version_formules']);
        $this->assertSame(['SOCLE-EFFECTIFS'], $sim11['dependances']);
        $this->assertTrue($sim11['schema']['taux']['requis']);
        $this->assertTrue($sim11['schema']['taux_modifie']['requis']);
        $this->assertSame(['abandon'], $sim11['schema']['axe_variation']['valeurs']);
        $this->assertSame(['hausse'], $sim11['schema']['sens_variation']['valeurs']);

        $sim12 = CatalogueScenarios::exiger('SIM-12');
        $this->assertSame(CatalogueScenarios::STATUT_DISPONIBLE, $sim12['statut']);
        $this->assertSame('analyse_nouvelle', $sim12['compatibilite']);
        $this->assertSame(1, $sim12['version']);
        $this->assertSame('1.0', $sim12['version_formules']);
        $this->assertSame(['SOCLE-EFFECTIFS'], $sim12['dependances']);
        $this->assertTrue($sim12['schema']['taux']['requis']);
        $this->assertTrue($sim12['schema']['taux_modifie']['requis']);
        $this->assertSame(['abandon'], $sim12['schema']['axe_variation']['valeurs']);
        $this->assertSame(['baisse'], $sim12['schema']['sens_variation']['valeurs']);

        $sim13 = CatalogueScenarios::exiger('SIM-13');
        $this->assertSame(CatalogueScenarios::STATUT_DISPONIBLE, $sim13['statut']);
        $this->assertSame('configuration', $sim13['compatibilite']);
        $this->assertSame(1, $sim13['version']);
        $this->assertSame('1.0', $sim13['version_formules']);
        $this->assertSame(['SOCLE-EFFECTIFS'], $sim13['dependances']);
        $this->assertTrue($sim13['schema']['taux']['requis']);
        $this->assertTrue($sim13['schema']['taux_modifie']['requis']);
        $this->assertSame(['passage', 'redoublement', 'abandon'], $sim13['schema']['axe_variation']['valeurs']);
        $this->assertSame(['hausse', 'baisse'], $sim13['schema']['sens_variation']['valeurs']);
        $this->assertStringContainsString('dégrad', $sim13['schema']['taux_modifie']['description']);

        $sim14 = CatalogueScenarios::exiger('SIM-14');
        $this->assertSame(CatalogueScenarios::STATUT_DISPONIBLE, $sim14['statut']);
        $this->assertSame('extension_par_niveau', $sim14['compatibilite']);
        $this->assertSame(1, $sim14['version']);
        $this->assertSame('1.0', $sim14['version_formules']);
        $this->assertSame(['SOCLE-EFFECTIFS'], $sim14['dependances']);
        $this->assertTrue($sim14['schema']['taux']['requis']);
        $this->assertTrue($sim14['schema']['taux_par_niveau']['requis']);
        $this->assertSame(['L1'], $sim14['schema']['niveau_focal']['valeurs']);

        $sim15 = CatalogueScenarios::exiger('SIM-15');
        $this->assertSame(CatalogueScenarios::STATUT_DISPONIBLE, $sim15['statut']);
        $this->assertSame('extension_par_niveau', $sim15['compatibilite']);
        $this->assertSame(1, $sim15['version']);
        $this->assertSame('1.0', $sim15['version_formules']);
        $this->assertSame(['SIM-14'], $sim15['dependances']);
        $this->assertTrue($sim15['schema']['taux']['requis']);
        $this->assertTrue($sim15['schema']['taux_par_niveau']['requis']);
        $this->assertSame(['L2'], $sim15['schema']['niveau_focal']['valeurs']);

        $sim16 = CatalogueScenarios::exiger('SIM-16');
        $this->assertSame(CatalogueScenarios::STATUT_DISPONIBLE, $sim16['statut']);
        $this->assertSame('extension_par_niveau', $sim16['compatibilite']);
        $this->assertSame(1, $sim16['version']);
        $this->assertSame('1.0', $sim16['version_formules']);
        $this->assertSame(['SIM-14', 'SIM-15'], $sim16['dependances']);
        $this->assertTrue($sim16['schema']['taux']['requis']);
        $this->assertTrue($sim16['schema']['taux_par_niveau']['requis']);
        $this->assertFalse($sim16['schema']['niveau_focal']['requis']);

        $sim17 = CatalogueScenarios::exiger('SIM-17');
        $this->assertSame(CatalogueScenarios::STATUT_DISPONIBLE, $sim17['statut']);
        $this->assertSame('extension_cohorte', $sim17['compatibilite']);
        $this->assertSame(1, $sim17['version']);
        $this->assertSame('1.0', $sim17['version_formules']);
        $this->assertSame(['SOCLE-EFFECTIFS'], $sim17['dependances']);
        $this->assertTrue($sim17['schema']['taux']['requis']);
        $this->assertTrue($sim17['schema']['cohortes_identifiees']['requis']);
        $this->assertSame(1, $sim17['schema']['cohortes_identifiees']['min_elements']);

        $sim18 = CatalogueScenarios::exiger('SIM-18');
        $this->assertSame(CatalogueScenarios::STATUT_DISPONIBLE, $sim18['statut']);
        $this->assertSame('extension_cohorte', $sim18['compatibilite']);
        $this->assertSame(1, $sim18['version']);
        $this->assertSame('1.0', $sim18['version_formules']);
        $this->assertSame(['SIM-17'], $sim18['dependances']);
        $this->assertTrue($sim18['schema']['taux']['requis']);
        $this->assertTrue($sim18['schema']['cohortes_identifiees']['requis']);
        $this->assertSame(2, $sim18['schema']['cohortes_identifiees']['min_elements']);

        $sim19 = CatalogueScenarios::exiger('SIM-19');
        $this->assertSame(CatalogueScenarios::STATUT_DISPONIBLE, $sim19['statut']);
        $this->assertSame('extension_par_niveau', $sim19['compatibilite']);
        $this->assertSame(1, $sim19['version']);
        $this->assertSame('1.0', $sim19['version_formules']);
        $this->assertSame(['SIM-14', 'SIM-15', 'SIM-16'], $sim19['dependances']);
        $this->assertTrue($sim19['schema']['taux']['requis']);
        $this->assertTrue($sim19['schema']['taux_par_niveau']['requis']);
        $this->assertFalse($sim19['schema']['niveau_focal']['requis']);
    }

    public function test_code_inconnu_et_scenario_planifie_refuses(): void
    {
        $this->assertRefuse(fn () => CatalogueScenarios::exiger('SIM-99'), 'inconnu');
        $this->assertRefuse(fn () => CatalogueScenarios::validerParametres('SIM-20', $this->parametresValides()), 'non disponible');
        $this->assertFalse(CatalogueScenarios::estDisponible('SIM-20'));
        $this->assertTrue(CatalogueScenarios::estDisponible('SOCLE-EFFECTIFS'));
        $this->assertTrue(CatalogueScenarios::estDisponible('SIM-01'));
        $this->assertTrue(CatalogueScenarios::estDisponible('SIM-02'));
        $this->assertTrue(CatalogueScenarios::estDisponible('SIM-03'));
        $this->assertTrue(CatalogueScenarios::estDisponible('SIM-04'));
        $this->assertTrue(CatalogueScenarios::estDisponible('SIM-05'));
        $this->assertTrue(CatalogueScenarios::estDisponible('SIM-06'));
        $this->assertTrue(CatalogueScenarios::estDisponible('SIM-07'));
        $this->assertTrue(CatalogueScenarios::estDisponible('SIM-08'));
        $this->assertTrue(CatalogueScenarios::estDisponible('SIM-09'));
        $this->assertTrue(CatalogueScenarios::estDisponible('SIM-10'));
        $this->assertTrue(CatalogueScenarios::estDisponible('SIM-11'));
        $this->assertTrue(CatalogueScenarios::estDisponible('SIM-12'));
        $this->assertTrue(CatalogueScenarios::estDisponible('SIM-13'));
        $this->assertTrue(CatalogueScenarios::estDisponible('SIM-14'));
        $this->assertTrue(CatalogueScenarios::estDisponible('SIM-15'));
        $this->assertTrue(CatalogueScenarios::estDisponible('SIM-16'));
        $this->assertTrue(CatalogueScenarios::estDisponible('SIM-17'));
        $this->assertTrue(CatalogueScenarios::estDisponible('SIM-18'));
        $this->assertTrue(CatalogueScenarios::estDisponible('SIM-19'));
    }

    public function test_parametres_invalides_ou_hors_bornes_refuses(): void
    {
        $valides = $this->parametresValides();

        $this->assertRefuse(fn () => CatalogueScenarios::validerParametres('SOCLE-EFFECTIFS', ['bornes' => ['min' => -0.5, 'max' => 2.0], 'horizon_annees' => 3]), 'delta_admissions');
        $this->assertRefuse(fn () => CatalogueScenarios::validerParametres('SOCLE-EFFECTIFS', array_merge($valides, ['delta_admissions' => 2.5])), 'hors bornes');
        $this->assertRefuse(fn () => CatalogueScenarios::validerParametres('SOCLE-EFFECTIFS', array_merge($valides, ['delta_admission' => 0.1])), 'inconnu');
        $this->assertRefuse(fn () => CatalogueScenarios::validerParametres('SOCLE-EFFECTIFS', array_merge($valides, ['bornes' => ['min' => 1.0, 'max' => 0.5]])), 'incohérentes');
        $this->assertRefuse(fn () => CatalogueScenarios::validerParametres('SOCLE-EFFECTIFS', array_merge($valides, ['delta_admissions' => 0.5, 'bornes' => ['min' => 0.0, 'max' => 0.4]])), 'propres bornes');
        $this->assertRefuse(fn () => CatalogueScenarios::validerParametres('SOCLE-EFFECTIFS', array_merge($valides, ['horizon_annees' => 7])), 'hors bornes');
        $this->assertRefuse(fn () => CatalogueScenarios::validerParametres('SOCLE-EFFECTIFS', array_merge($valides, ['taux' => ['passage' => 0.8, 'redoublement' => 0.3, 'abandon' => 0.1]])), '> 1');
        $this->assertRefuse(fn () => CatalogueScenarios::validerParametres('SOCLE-EFFECTIFS', array_merge($valides, ['taux' => ['passage' => 0.7, 'redoublement' => 0.2]])), 'abandon');
        $this->assertRefuse(fn () => CatalogueScenarios::validerParametres('SOCLE-EFFECTIFS', array_merge($valides, ['groupes' => [1]])), 'sans niveau');
        $this->assertRefuse(fn () => CatalogueScenarios::validerParametres('SOCLE-EFFECTIFS', array_merge($valides, ['delta_admissions' => INF])), 'non finie');
    }

    public function test_parametres_valides_acceptes_sans_valeur_par_defaut(): void
    {
        $valides = $this->parametresValides();

        $this->assertSame($valides, CatalogueScenarios::validerParametres('SOCLE-EFFECTIFS', $valides));
        $this->assertArrayNotHasKey('niveau_id', CatalogueScenarios::validerParametres('SOCLE-EFFECTIFS', $valides));
        $this->assertArrayNotHasKey('groupes', CatalogueScenarios::validerParametres('SOCLE-EFFECTIFS', $valides));
    }

    public function test_sim01_est_une_configuration_de_croissance_stricte(): void
    {
        $valides = $this->parametresValides();
        $validesSim01 = array_merge($valides, ['bornes' => ['min' => 0.0, 'max' => 2.0]]);

        // Croissance acceptée, y compris le cas constant (delta = 0).
        $this->assertSame($validesSim01, CatalogueScenarios::validerParametres('SIM-01', $validesSim01));
        $constant = array_merge($validesSim01, ['delta_admissions' => 0.0, 'bornes' => ['min' => 0.0, 'max' => 1.0]]);
        $this->assertSame($constant, CatalogueScenarios::validerParametres('SIM-01', $constant));

        // SIM-01 ne doit pas absorber SIM-02 (baisse) ni SIM-03 (gel explicite).
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-01', array_merge($validesSim01, [
                'delta_admissions' => -0.1, 'bornes' => ['min' => -0.2, 'max' => 2.0],
            ])),
            'SIM-02'
        );
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-01', array_merge($validesSim01, ['bornes' => ['min' => -0.2, 'max' => 1.0]])),
            'SIM-02'
        );

        // Les invariants communs restent appliqués.
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-01', array_merge($validesSim01, ['taux' => ['passage' => 0.9, 'redoublement' => 0.2, 'abandon' => 0.0]])),
            '> 1'
        );
    }

    public function test_sim01_exige_des_taux_explicites_alors_que_le_socle_les_tolere(): void
    {
        $sansTaux = $this->parametresValides();
        unset($sansTaux['taux']);

        // Le socle reste permissif : taux absents = avertissement moteur.
        $this->assertSame($sansTaux, CatalogueScenarios::validerParametres('SOCLE-EFFECTIFS', $sansTaux));

        // SIM-01 revendique des effectifs projetés : les taux sont obligatoires.
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-01', $sansTaux),
            'taux'
        );

        // Un taux partiel reste refusé (les trois taux sont requis).
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-01', array_merge($sansTaux, [
                'taux' => ['passage' => 0.7, 'redoublement' => 0.2],
            ])),
            'abandon'
        );

        // Le schéma publié reflète cette différence de contrat.
        $this->assertTrue(CatalogueScenarios::schemaSim01()['taux']['requis']);
        $this->assertFalse(CatalogueScenarios::schemaSocle()['taux']['requis']);
    }

    public function test_sim02_est_une_configuration_de_baisse_stricte(): void
    {
        $valides = $this->parametresBaisse();

        // Baisse acceptée, y compris la borne moteur -0.5.
        $this->assertSame($valides, CatalogueScenarios::validerParametres('SIM-02', $valides));
        $limite = array_merge($valides, ['delta_admissions' => -0.5]);
        $this->assertSame($limite, CatalogueScenarios::validerParametres('SIM-02', $limite));

        // Stabilité (delta = 0) = SIM-01, pas SIM-02.
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-02', array_merge($valides, ['delta_admissions' => 0.0])),
            'SIM-01'
        );

        // Croissance = SIM-01.
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-02', array_merge($valides, [
                'delta_admissions' => 0.1, 'bornes' => ['min' => -0.5, 'max' => 0.5],
            ])),
            'SIM-01'
        );

        // Une borne maximale positive ouvrirait une variante de croissance.
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-02', array_merge($valides, ['bornes' => ['min' => -0.5, 'max' => 0.2]])),
            'SIM-01'
        );

        // Borne moteur et cohérence delta/bornes.
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-02', array_merge($valides, ['delta_admissions' => -0.6])),
            'hors bornes'
        );
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-02', array_merge($valides, ['bornes' => ['min' => -0.05, 'max' => 0.0]])),
            'propres bornes'
        );

        // Taux requis, comme SIM-01.
        $sansTaux = $valides;
        unset($sansTaux['taux']);
        $this->assertRefuse(fn () => CatalogueScenarios::validerParametres('SIM-02', $sansTaux), 'taux');

        // Invariants communs et clés inconnues.
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-02', array_merge($valides, [
                'taux' => ['passage' => 0.9, 'redoublement' => 0.2, 'abandon' => 0.0],
            ])),
            '> 1'
        );
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-02', array_merge($valides, ['delta_admission' => -0.1])),
            'inconnu'
        );

        // Le schéma publié reflète le contrat.
        $this->assertTrue(CatalogueScenarios::schemaSim02()['taux']['requis']);
        $this->assertFalse(CatalogueScenarios::schemaSocle()['taux']['requis']);
    }

    public function test_sim03_est_une_extension_temporelle_de_gel_strict(): void
    {
        $valides = array_merge($this->parametresValides(), [
            'delta_admissions' => 0.0,
            'bornes' => ['min' => 0.0, 'max' => 0.0],
            'horizon_annees' => 3,
            'duree_gel_annees' => 2,
            'taux' => ['passage' => 0.6, 'redoublement' => 0.3, 'abandon' => 0.1],
        ]);

        $this->assertSame($valides, CatalogueScenarios::validerParametres('SIM-03', $valides));

        // Gel complet sur tout l'horizon autorisé (duree_gel == horizon).
        $gelTotal = array_merge($valides, ['duree_gel_annees' => 3]);
        $this->assertSame($gelTotal, CatalogueScenarios::validerParametres('SIM-03', $gelTotal));

        // Durée de gel supérieure à l'horizon refusée.
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-03', array_merge($valides, ['duree_gel_annees' => 4])),
            'dépasser'
        );

        // Durée de gel nulle ou négative refusée.
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-03', array_merge($valides, ['duree_gel_annees' => 0])),
            'minimum'
        );

        // Paramètre duree_gel_annees manquant refusé.
        $sansDuree = $valides;
        unset($sansDuree['duree_gel_annees']);
        $this->assertRefuse(fn () => CatalogueScenarios::validerParametres('SIM-03', $sansDuree), 'duree_gel_annees');

        // Taux requis, comme SIM-01 et SIM-02.
        $sansTaux = $valides;
        unset($sansTaux['taux']);
        $this->assertRefuse(fn () => CatalogueScenarios::validerParametres('SIM-03', $sansTaux), 'taux');

        // Schéma publié : taux et duree_gel_annees requis.
        $schema = CatalogueScenarios::schemaSim03();
        $this->assertTrue($schema['taux']['requis']);
        $this->assertTrue($schema['duree_gel_annees']['requis']);
        $this->assertSame('années', $schema['duree_gel_annees']['unite']);
    }

    public function test_sim04_est_une_extension_temporelle_de_choc_ponctuel(): void
    {
        $valides = array_merge($this->parametresValides(), [
            'delta_admissions' => 0.0,
            'bornes' => ['min' => 0.0, 'max' => 0.0],
            'horizon_annees' => 3,
            'admissions_exceptionnelles' => 5,
            'pas_exceptionnel' => 2,
            'taux' => ['passage' => 0.6, 'redoublement' => 0.3, 'abandon' => 0.1],
        ]);

        $this->assertSame($valides, CatalogueScenarios::validerParametres('SIM-04', $valides));

        // Choc au premier comme au dernier pas de l'horizon : autorisé.
        foreach ([1, 3] as $pas) {
            $limite = array_merge($valides, ['pas_exceptionnel' => $pas]);
            $this->assertSame($limite, CatalogueScenarios::validerParametres('SIM-04', $limite));
        }

        // Pas hors horizon refusé (le choc ne serait jamais appliqué).
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-04', array_merge($valides, ['pas_exceptionnel' => 0])),
            'minimum'
        );
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-04', array_merge($valides, ['pas_exceptionnel' => 4])),
            'entre 1 et l\'horizon'
        );

        // Choc nul refusé : il relève de la trajectoire de fond.
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-04', array_merge($valides, ['admissions_exceptionnelles' => 0])),
            'minimum'
        );

        // Les deux paramètres sont indissociables.
        $sansPas = $valides;
        unset($sansPas['pas_exceptionnel']);
        $this->assertRefuse(fn () => CatalogueScenarios::validerParametres('SIM-04', $sansPas), 'pas_exceptionnel');

        $sansChoc = $valides;
        unset($sansChoc['admissions_exceptionnelles']);
        $this->assertRefuse(fn () => CatalogueScenarios::validerParametres('SIM-04', $sansChoc), 'admissions_exceptionnelles');

        // Taux requis, comme les autres scénarios d'admissions.
        $sansTaux = $valides;
        unset($sansTaux['taux']);
        $this->assertRefuse(fn () => CatalogueScenarios::validerParametres('SIM-04', $sansTaux), 'taux');

        // Schéma publié.
        $schema = CatalogueScenarios::schemaSim04();
        $this->assertTrue($schema['taux']['requis']);
        $this->assertTrue($schema['admissions_exceptionnelles']['requis']);
        $this->assertTrue($schema['pas_exceptionnel']['requis']);
        $this->assertSame('étudiants', $schema['admissions_exceptionnelles']['unite']);
        $this->assertSame('années', $schema['pas_exceptionnel']['unite']);
    }

    public function test_sim05_est_une_analyse_nouvelle_de_croissance_superieure_a_la_cible(): void
    {
        $valides = array_merge($this->parametresValides(), [
            'delta_admissions' => 0.10,
            'bornes' => ['min' => 0.0, 'max' => 0.5],
            'horizon_annees' => 3,
            'taux_cible_croissance' => 0.05,
            'taux' => ['passage' => 0.6, 'redoublement' => 0.3, 'abandon' => 0.1],
        ]);

        $this->assertSame($valides, CatalogueScenarios::validerParametres('SIM-05', $valides));

        // Cible de stagnation (0) autorisée : croissance supérieure à zéro.
        $stagnation = array_merge($valides, ['taux_cible_croissance' => 0.0]);
        $this->assertSame($stagnation, CatalogueScenarios::validerParametres('SIM-05', $stagnation));

        // Cible égale ou supérieure au delta : refusée (pas de dépassement).
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-05', array_merge($valides, ['taux_cible_croissance' => 0.10])),
            'strictement supérieur'
        );
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-05', array_merge($valides, ['taux_cible_croissance' => 0.20])),
            'strictement supérieur'
        );

        // Cible négative ou hors borne moteur : refusée par le schéma.
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-05', array_merge($valides, ['taux_cible_croissance' => -0.1])),
            'minimum'
        );
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-05', array_merge($valides, [
                'delta_admissions' => 2.0, 'bornes' => ['min' => 0.0, 'max' => 2.0], 'taux_cible_croissance' => 2.5,
            ])),
            'hors bornes'
        );

        // Cible absente : le contrat SIM-05 exige une cible saisie.
        $sansCible = $valides;
        unset($sansCible['taux_cible_croissance']);
        $this->assertRefuse(fn () => CatalogueScenarios::validerParametres('SIM-05', $sansCible), 'taux_cible_croissance');

        // Taux requis, comme les autres scénarios d'admissions.
        $sansTaux = $valides;
        unset($sansTaux['taux']);
        $this->assertRefuse(fn () => CatalogueScenarios::validerParametres('SIM-05', $sansTaux), 'taux');

        // Détournements : baisse (SIM-02) et borne minimale négative.
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-05', array_merge($valides, [
                'delta_admissions' => -0.1, 'bornes' => ['min' => -0.2, 'max' => 0.5], 'taux_cible_croissance' => 0.0,
            ])),
            'SIM-02'
        );
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-05', array_merge($valides, ['bornes' => ['min' => -0.1, 'max' => 0.5]])),
            'SIM-02'
        );

        // Gel (SIM-03) et choc (SIM-04) : clés inconnues du schéma SIM-05.
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-05', array_merge($valides, ['duree_gel_annees' => 2])),
            'inconnu'
        );
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-05', array_merge($valides, [
                'pas_exceptionnel' => 2, 'admissions_exceptionnelles' => 5,
            ])),
            'inconnu'
        );

        // Schéma publié : cible requise, bornes [0 ; 2.0], unité ratio.
        $schema = CatalogueScenarios::schemaSim05();
        $this->assertTrue($schema['taux']['requis']);
        $this->assertTrue($schema['taux_cible_croissance']['requis']);
        $this->assertSame(0.0, $schema['taux_cible_croissance']['min']);
        $this->assertSame(2.0, $schema['taux_cible_croissance']['max']);
        $this->assertSame('ratio annuel composé', $schema['taux_cible_croissance']['unite']);
    }

    public function test_sim06_est_une_extension_temporelle_de_recuperation_progressive(): void
    {
        $valides = array_merge($this->parametresValides(), [
            'delta_admissions' => 0.0,
            'bornes' => ['min' => 0.0, 'max' => 0.0],
            'horizon_annees' => 4,
            'profondeur_baisse' => 0.2,
            'duree_baisse_annees' => 2,
            'duree_recuperation_annees' => 2,
            'taux' => ['passage' => 0.6, 'redoublement' => 0.3, 'abandon' => 0.1],
        ]);

        $this->assertSame($valides, CatalogueScenarios::validerParametres('SIM-06', $valides));

        // Bornes incluses : profondeur totale (1) et phases minimales (1 + 1).
        $extreme = array_merge($valides, [
            'profondeur_baisse' => 1.0, 'duree_baisse_annees' => 1, 'duree_recuperation_annees' => 1, 'horizon_annees' => 2,
        ]);
        $this->assertSame($extreme, CatalogueScenarios::validerParametres('SIM-06', $extreme));

        // Creux nul refusé : il relève de la trajectoire de fond.
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-06', array_merge($valides, ['profondeur_baisse' => 0.0])),
            'strictement positive'
        );

        // Profondeur > 1 : bornée par le schéma (aucune admission négative).
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-06', array_merge($valides, ['profondeur_baisse' => 1.5])),
            'hors bornes'
        );

        // Durées minimales : le schéma impose ≥ 1.
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-06', array_merge($valides, ['duree_baisse_annees' => 0])),
            'minimum'
        );
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-06', array_merge($valides, ['duree_recuperation_annees' => 0])),
            'minimum'
        );

        // Retour non observable : baisse + récupération > horizon.
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-06', array_merge($valides, [
                'horizon_annees' => 3,
            ])),
            'horizon'
        );

        // Trio indissociable : schéma requis pour chaque clé.
        foreach (['profondeur_baisse', 'duree_baisse_annees', 'duree_recuperation_annees'] as $cle) {
            $incomplet = $valides;
            unset($incomplet[$cle]);
            $this->assertRefuse(fn () => CatalogueScenarios::validerParametres('SIM-06', $incomplet), $cle);
        }

        // Taux requis, comme les autres scénarios d'admissions.
        $sansTaux = $valides;
        unset($sansTaux['taux']);
        $this->assertRefuse(fn () => CatalogueScenarios::validerParametres('SIM-06', $sansTaux), 'taux');

        // Incompatibilités structurelles : gel (SIM-03), choc (SIM-04), cible (SIM-05).
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-06', array_merge($valides, ['duree_gel_annees' => 1])),
            'inconnu'
        );
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-06', array_merge($valides, [
                'pas_exceptionnel' => 1, 'admissions_exceptionnelles' => 5,
            ])),
            'inconnu'
        );
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-06', array_merge($valides, ['taux_cible_croissance' => 0.05])),
            'inconnu'
        );

        // Schéma publié : trio requis, bornes et unités.
        $schema = CatalogueScenarios::schemaSim06();
        $this->assertTrue($schema['profondeur_baisse']['requis']);
        $this->assertSame(0, $schema['profondeur_baisse']['min']);
        $this->assertSame(1, $schema['profondeur_baisse']['max']);
        $this->assertTrue($schema['duree_baisse_annees']['requis']);
        $this->assertSame(1, $schema['duree_baisse_annees']['min']);
        $this->assertTrue($schema['duree_recuperation_annees']['requis']);
        $this->assertSame('années', $schema['duree_recuperation_annees']['unite']);
    }

    public function test_sim07_est_une_analyse_nouvelle_de_reussite_amelioree(): void
    {
        $valides = array_merge($this->parametresValides(), [
            'delta_admissions' => 0.0,
            'bornes' => ['min' => 0.0, 'max' => 0.0],
            'horizon_annees' => 2,
            'taux' => ['passage' => 0.5, 'redoublement' => 0.3, 'abandon' => 0.2],
            'taux_ameliore' => ['passage' => 0.7, 'redoublement' => 0.1, 'abandon' => 0.2],
        ]);

        $this->assertSame($valides, CatalogueScenarios::validerParametres('SIM-07', $valides));

        // Hausse puisée dans le « reste » (somme de référence < 1) : autorisée.
        $reste = array_merge($valides, [
            'taux' => ['passage' => 0.5, 'redoublement' => 0.2, 'abandon' => 0.1],
            'taux_ameliore' => ['passage' => 0.7, 'redoublement' => 0.2, 'abandon' => 0.1],
        ]);
        $this->assertSame($reste, CatalogueScenarios::validerParametres('SIM-07', $reste));

        // Passage égal ou inférieur : pas une amélioration.
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-07', array_merge($valides, [
                'taux_ameliore' => ['passage' => 0.5, 'redoublement' => 0.3, 'abandon' => 0.2],
            ])),
            'strictement supérieur'
        );
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-07', array_merge($valides, [
                'taux_ameliore' => ['passage' => 0.4, 'redoublement' => 0.4, 'abandon' => 0.2],
            ])),
            'strictement supérieur'
        );

        // Somme améliorée > 1 : refusée (aucune population créée).
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-07', array_merge($valides, [
                'taux_ameliore' => ['passage' => 0.8, 'redoublement' => 0.3, 'abandon' => 0.1],
            ])),
            '> 1'
        );

        // Taux amélioré hors [0,1] : borné par le schéma.
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-07', array_merge($valides, [
                'taux_ameliore' => ['passage' => 1.5, 'redoublement' => 0.0, 'abandon' => 0.0],
            ])),
            'hors bornes'
        );

        // Jeu amélioré absent ou incomplet : refusé.
        $sansAmeliore = $valides;
        unset($sansAmeliore['taux_ameliore']);
        $this->assertRefuse(fn () => CatalogueScenarios::validerParametres('SIM-07', $sansAmeliore), 'taux_ameliore');

        $partiel = $valides;
        unset($partiel['taux_ameliore']['abandon']);
        $this->assertRefuse(fn () => CatalogueScenarios::validerParametres('SIM-07', $partiel), 'abandon');

        // Taux de référence absent : le schéma l'exige.
        $sansReference = $valides;
        unset($sansReference['taux']);
        $this->assertRefuse(fn () => CatalogueScenarios::validerParametres('SIM-07', $sansReference), 'taux');

        // Incompatibilités structurelles : gel, choc, cible, creux.
        foreach ([
            ['duree_gel_annees' => 1],
            ['pas_exceptionnel' => 1, 'admissions_exceptionnelles' => 5],
            ['taux_cible_croissance' => 0.05],
            ['profondeur_baisse' => 0.2, 'duree_baisse_annees' => 1, 'duree_recuperation_annees' => 1],
        ] as $clesInterdites) {
            $this->assertRefuse(
                fn () => CatalogueScenarios::validerParametres('SIM-07', array_merge($valides, $clesInterdites)),
                'inconnu'
            );
        }

        // Schéma publié : jeu amélioré requis et complet.
        $schema = CatalogueScenarios::schemaSim07();
        $this->assertTrue($schema['taux']['requis']);
        $this->assertTrue($schema['taux_ameliore']['requis']);
        $this->assertTrue($schema['taux_ameliore']['schema']['passage']['requis']);
        $this->assertTrue($schema['taux_ameliore']['schema']['redoublement']['requis']);
        $this->assertTrue($schema['taux_ameliore']['schema']['abandon']['requis']);
    }

    public function test_sim08_est_une_analyse_nouvelle_de_reussite_degradee(): void
    {
        $valides = array_merge($this->parametresValides(), [
            'delta_admissions' => 0.0,
            'bornes' => ['min' => 0.0, 'max' => 0.0],
            'horizon_annees' => 2,
            'taux' => ['passage' => 0.7, 'redoublement' => 0.1, 'abandon' => 0.2],
            'taux_degrade' => ['passage' => 0.5, 'redoublement' => 0.3, 'abandon' => 0.2],
        ]);

        $this->assertSame($valides, CatalogueScenarios::validerParametres('SIM-08', $valides));

        // Baisse absorbée par le « reste » (somme dégradée < 1) : autorisée.
        $reste = array_merge($valides, [
            'taux' => ['passage' => 0.7, 'redoublement' => 0.2, 'abandon' => 0.1],
            'taux_degrade' => ['passage' => 0.5, 'redoublement' => 0.2, 'abandon' => 0.1],
        ]);
        $this->assertSame($reste, CatalogueScenarios::validerParametres('SIM-08', $reste));

        // Passage égal ou supérieur : pas une dégradation.
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-08', array_merge($valides, [
                'taux_degrade' => ['passage' => 0.7, 'redoublement' => 0.1, 'abandon' => 0.2],
            ])),
            'strictement inférieur'
        );
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-08', array_merge($valides, [
                'taux_degrade' => ['passage' => 0.8, 'redoublement' => 0.1, 'abandon' => 0.1],
            ])),
            'strictement inférieur'
        );

        // Somme dégradée > 1 : refusée (aucune population créée).
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-08', array_merge($valides, [
                'taux_degrade' => ['passage' => 0.6, 'redoublement' => 0.4, 'abandon' => 0.1],
            ])),
            '> 1'
        );

        // Taux dégradé hors [0,1] : borné par le schéma.
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-08', array_merge($valides, [
                'taux_degrade' => ['passage' => 1.5, 'redoublement' => 0.0, 'abandon' => 0.0],
            ])),
            'hors bornes'
        );

        // Jeu dégradé absent ou incomplet : refusé.
        $sansDegrade = $valides;
        unset($sansDegrade['taux_degrade']);
        $this->assertRefuse(fn () => CatalogueScenarios::validerParametres('SIM-08', $sansDegrade), 'taux_degrade');

        $partiel = $valides;
        unset($partiel['taux_degrade']['abandon']);
        $this->assertRefuse(fn () => CatalogueScenarios::validerParametres('SIM-08', $partiel), 'abandon');

        // Taux de référence absent : le schéma l'exige.
        $sansReference = $valides;
        unset($sansReference['taux']);
        $this->assertRefuse(fn () => CatalogueScenarios::validerParametres('SIM-08', $sansReference), 'taux');

        // Incompatibilités structurelles : gel, choc, cible, creux, amélioration.
        foreach ([
            ['duree_gel_annees' => 1],
            ['pas_exceptionnel' => 1, 'admissions_exceptionnelles' => 5],
            ['taux_cible_croissance' => 0.05],
            ['profondeur_baisse' => 0.2, 'duree_baisse_annees' => 1, 'duree_recuperation_annees' => 1],
            ['taux_ameliore' => ['passage' => 0.8, 'redoublement' => 0.1, 'abandon' => 0.1]],
        ] as $clesInterdites) {
            $this->assertRefuse(
                fn () => CatalogueScenarios::validerParametres('SIM-08', array_merge($valides, $clesInterdites)),
                'inconnu'
            );
        }

        // Schéma publié : jeu dégradé requis et complet.
        $schema = CatalogueScenarios::schemaSim08();
        $this->assertTrue($schema['taux']['requis']);
        $this->assertTrue($schema['taux_degrade']['requis']);
        $this->assertTrue($schema['taux_degrade']['schema']['passage']['requis']);
        $this->assertTrue($schema['taux_degrade']['schema']['redoublement']['requis']);
        $this->assertTrue($schema['taux_degrade']['schema']['abandon']['requis']);
    }

    public function test_sim09_est_une_analyse_nouvelle_de_hausse_du_redoublement(): void
    {
        $valides = array_merge($this->parametresValides(), [
            'delta_admissions' => 0.0,
            'bornes' => ['min' => 0.0, 'max' => 0.0],
            'horizon_annees' => 2,
            'taux' => ['passage' => 0.6, 'redoublement' => 0.2, 'abandon' => 0.2],
            'taux_modifie' => ['passage' => 0.6, 'redoublement' => 0.3, 'abandon' => 0.1],
            'axe_variation' => 'redoublement',
            'sens_variation' => 'hausse',
        ]);

        $this->assertSame($valides, CatalogueScenarios::validerParametres('SIM-09', $valides));

        // Hausse absorbée par le « reste » (somme modifiée < 1) : autorisée.
        $reste = array_merge($valides, [
            'taux' => ['passage' => 0.6, 'redoublement' => 0.2, 'abandon' => 0.1],
            'taux_modifie' => ['passage' => 0.6, 'redoublement' => 0.3, 'abandon' => 0.1],
        ]);
        $this->assertSame($reste, CatalogueScenarios::validerParametres('SIM-09', $reste));

        // Redoublement égal ou inférieur : pas une hausse.
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-09', array_merge($valides, [
                'taux_modifie' => ['passage' => 0.6, 'redoublement' => 0.2, 'abandon' => 0.2],
            ])),
            'strictement supérieur'
        );
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-09', array_merge($valides, [
                'taux_modifie' => ['passage' => 0.7, 'redoublement' => 0.1, 'abandon' => 0.2],
            ])),
            'strictement supérieur'
        );

        // Somme modifiée > 1 : refusée (aucune population créée).
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-09', array_merge($valides, [
                'taux_modifie' => ['passage' => 0.6, 'redoublement' => 0.4, 'abandon' => 0.1],
            ])),
            '> 1'
        );

        // Taux modifié hors [0,1] : borné par le schéma.
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-09', array_merge($valides, [
                'taux_modifie' => ['passage' => 1.5, 'redoublement' => 0.0, 'abandon' => 0.0],
            ])),
            'hors bornes'
        );

        // Jeu modifié absent ou incomplet : refusé.
        $sansModifie = $valides;
        unset($sansModifie['taux_modifie']);
        $this->assertRefuse(fn () => CatalogueScenarios::validerParametres('SIM-09', $sansModifie), 'taux_modifie');

        $partiel = $valides;
        unset($partiel['taux_modifie']['abandon']);
        $this->assertRefuse(fn () => CatalogueScenarios::validerParametres('SIM-09', $partiel), 'abandon');

        // Descripteur absent : le schéma l'exige.
        $sansAxe = $valides;
        unset($sansAxe['axe_variation']);
        $this->assertRefuse(fn () => CatalogueScenarios::validerParametres('SIM-09', $sansAxe), 'axe_variation');

        $sansSens = $valides;
        unset($sansSens['sens_variation']);
        $this->assertRefuse(fn () => CatalogueScenarios::validerParametres('SIM-09', $sansSens), 'sens_variation');

        // Axe ou sens hors contrat : refusés (valeurs épinglées au schéma).
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-09', array_merge($valides, ['axe_variation' => 'abandon'])),
            'non autorisée'
        );
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-09', array_merge($valides, ['sens_variation' => 'baisse'])),
            'non autorisée'
        );

        // Taux de référence absent : le schéma l'exige.
        $sansReference = $valides;
        unset($sansReference['taux']);
        $this->assertRefuse(fn () => CatalogueScenarios::validerParametres('SIM-09', $sansReference), 'taux');

        // Incompatibilités structurelles : gel, choc, cible, creux, alias passage.
        foreach ([
            ['duree_gel_annees' => 1],
            ['pas_exceptionnel' => 1, 'admissions_exceptionnelles' => 5],
            ['taux_cible_croissance' => 0.05],
            ['profondeur_baisse' => 0.2, 'duree_baisse_annees' => 1, 'duree_recuperation_annees' => 1],
            ['taux_ameliore' => ['passage' => 0.8, 'redoublement' => 0.1, 'abandon' => 0.1]],
            ['taux_degrade' => ['passage' => 0.5, 'redoublement' => 0.3, 'abandon' => 0.2]],
        ] as $clesInterdites) {
            $this->assertRefuse(
                fn () => CatalogueScenarios::validerParametres('SIM-09', array_merge($valides, $clesInterdites)),
                'inconnu'
            );
        }

        // Schéma publié : jeu modifié requis, axe et sens épinglés.
        $schema = CatalogueScenarios::schemaSim09();
        $this->assertTrue($schema['taux']['requis']);
        $this->assertTrue($schema['taux_modifie']['requis']);
        $this->assertTrue($schema['taux_modifie']['schema']['passage']['requis']);
        $this->assertTrue($schema['taux_modifie']['schema']['redoublement']['requis']);
        $this->assertTrue($schema['taux_modifie']['schema']['abandon']['requis']);
        $this->assertSame(['redoublement'], $schema['axe_variation']['valeurs']);
        $this->assertSame(['hausse'], $schema['sens_variation']['valeurs']);
    }

    public function test_sim10_est_une_analyse_nouvelle_de_reduction_du_redoublement(): void
    {
        $valides = array_merge($this->parametresValides(), [
            'delta_admissions' => 0.0,
            'bornes' => ['min' => 0.0, 'max' => 0.0],
            'horizon_annees' => 2,
            'taux' => ['passage' => 0.6, 'redoublement' => 0.2, 'abandon' => 0.2],
            'taux_modifie' => ['passage' => 0.6, 'redoublement' => 0.1, 'abandon' => 0.3],
            'axe_variation' => 'redoublement',
            'sens_variation' => 'baisse',
        ]);

        $this->assertSame($valides, CatalogueScenarios::validerParametres('SIM-10', $valides));

        // Baisse absorbée par le « reste » (somme modifiée < 1) : autorisée.
        $reste = array_merge($valides, [
            'taux' => ['passage' => 0.6, 'redoublement' => 0.2, 'abandon' => 0.1],
            'taux_modifie' => ['passage' => 0.6, 'redoublement' => 0.1, 'abandon' => 0.1],
        ]);
        $this->assertSame($reste, CatalogueScenarios::validerParametres('SIM-10', $reste));

        // Redoublement égal ou supérieur : pas une baisse.
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-10', array_merge($valides, [
                'taux_modifie' => ['passage' => 0.6, 'redoublement' => 0.2, 'abandon' => 0.2],
            ])),
            'strictement inférieur'
        );
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-10', array_merge($valides, [
                'taux_modifie' => ['passage' => 0.6, 'redoublement' => 0.3, 'abandon' => 0.1],
            ])),
            'strictement inférieur'
        );

        // Somme modifiée > 1 : refusée (aucune population créée).
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-10', array_merge($valides, [
                'taux_modifie' => ['passage' => 0.6, 'redoublement' => 0.1, 'abandon' => 0.4],
            ])),
            '> 1'
        );

        // Taux modifié hors [0,1] : borné par le schéma.
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-10', array_merge($valides, [
                'taux_modifie' => ['passage' => 1.5, 'redoublement' => -0.1, 'abandon' => 0.0],
            ])),
            'hors bornes'
        );

        // Jeu modifié absent ou incomplet : refusé.
        $sansModifie = $valides;
        unset($sansModifie['taux_modifie']);
        $this->assertRefuse(fn () => CatalogueScenarios::validerParametres('SIM-10', $sansModifie), 'taux_modifie');

        $partiel = $valides;
        unset($partiel['taux_modifie']['abandon']);
        $this->assertRefuse(fn () => CatalogueScenarios::validerParametres('SIM-10', $partiel), 'abandon');

        // Descripteur absent : le schéma l'exige.
        $sansAxe = $valides;
        unset($sansAxe['axe_variation']);
        $this->assertRefuse(fn () => CatalogueScenarios::validerParametres('SIM-10', $sansAxe), 'axe_variation');

        $sansSens = $valides;
        unset($sansSens['sens_variation']);
        $this->assertRefuse(fn () => CatalogueScenarios::validerParametres('SIM-10', $sansSens), 'sens_variation');

        // Axe ou sens hors contrat : refusés (valeurs épinglées au schéma).
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-10', array_merge($valides, ['axe_variation' => 'abandon'])),
            'non autorisée'
        );
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-10', array_merge($valides, ['sens_variation' => 'hausse'])),
            'non autorisée'
        );

        // Taux de référence absent : le schéma l'exige.
        $sansReference = $valides;
        unset($sansReference['taux']);
        $this->assertRefuse(fn () => CatalogueScenarios::validerParametres('SIM-10', $sansReference), 'taux');

        // Incompatibilités structurelles : gel, choc, cible, creux, alias passage.
        foreach ([
            ['duree_gel_annees' => 1],
            ['pas_exceptionnel' => 1, 'admissions_exceptionnelles' => 5],
            ['taux_cible_croissance' => 0.05],
            ['profondeur_baisse' => 0.2, 'duree_baisse_annees' => 1, 'duree_recuperation_annees' => 1],
            ['taux_ameliore' => ['passage' => 0.8, 'redoublement' => 0.1, 'abandon' => 0.1]],
            ['taux_degrade' => ['passage' => 0.5, 'redoublement' => 0.3, 'abandon' => 0.2]],
        ] as $clesInterdites) {
            $this->assertRefuse(
                fn () => CatalogueScenarios::validerParametres('SIM-10', array_merge($valides, $clesInterdites)),
                'inconnu'
            );
        }

        // Schéma publié : jeu modifié requis, axe et sens épinglés.
        $schema = CatalogueScenarios::schemaSim10();
        $this->assertTrue($schema['taux']['requis']);
        $this->assertTrue($schema['taux_modifie']['requis']);
        $this->assertTrue($schema['taux_modifie']['schema']['passage']['requis']);
        $this->assertTrue($schema['taux_modifie']['schema']['redoublement']['requis']);
        $this->assertTrue($schema['taux_modifie']['schema']['abandon']['requis']);
        $this->assertSame(['redoublement'], $schema['axe_variation']['valeurs']);
        $this->assertSame(['baisse'], $schema['sens_variation']['valeurs']);
    }

    public function test_sim11_est_une_analyse_nouvelle_de_hausse_de_l_abandon(): void
    {
        $valides = array_merge($this->parametresValides(), [
            'delta_admissions' => 0.0,
            'bornes' => ['min' => 0.0, 'max' => 0.0],
            'horizon_annees' => 2,
            'taux' => ['passage' => 0.6, 'redoublement' => 0.2, 'abandon' => 0.2],
            'taux_modifie' => ['passage' => 0.6, 'redoublement' => 0.1, 'abandon' => 0.3],
            'axe_variation' => 'abandon',
            'sens_variation' => 'hausse',
        ]);

        $this->assertSame($valides, CatalogueScenarios::validerParametres('SIM-11', $valides));

        // Hausse absorbée par le slack (somme modifiée ≤ 1, p et r inchangés) :
        // autorisée — le moteur ne lui inventera aucun effet.
        $slack = array_merge($valides, [
            'taux' => ['passage' => 0.6, 'redoublement' => 0.2, 'abandon' => 0.1],
            'taux_modifie' => ['passage' => 0.6, 'redoublement' => 0.2, 'abandon' => 0.2],
        ]);
        $this->assertSame($slack, CatalogueScenarios::validerParametres('SIM-11', $slack));

        // Abandon égal ou inférieur : pas une hausse.
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-11', array_merge($valides, [
                'taux_modifie' => ['passage' => 0.6, 'redoublement' => 0.1, 'abandon' => 0.2],
            ])),
            'strictement supérieur'
        );
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-11', array_merge($valides, [
                'taux_modifie' => ['passage' => 0.6, 'redoublement' => 0.2, 'abandon' => 0.1],
            ])),
            'strictement supérieur'
        );

        // Somme modifiée > 1 : refusée (aucune population créée).
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-11', array_merge($valides, [
                'taux_modifie' => ['passage' => 0.6, 'redoublement' => 0.1, 'abandon' => 0.4],
            ])),
            '> 1'
        );

        // Taux modifié hors [0,1] : borné par le schéma.
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-11', array_merge($valides, [
                'taux_modifie' => ['passage' => 1.5, 'redoublement' => -0.1, 'abandon' => 0.0],
            ])),
            'hors bornes'
        );

        // Jeu modifié absent ou incomplet : refusé.
        $sansModifie = $valides;
        unset($sansModifie['taux_modifie']);
        $this->assertRefuse(fn () => CatalogueScenarios::validerParametres('SIM-11', $sansModifie), 'taux_modifie');

        $partiel = $valides;
        unset($partiel['taux_modifie']['passage']);
        $this->assertRefuse(fn () => CatalogueScenarios::validerParametres('SIM-11', $partiel), 'passage');

        // Descripteur absent : le schéma l'exige.
        $sansAxe = $valides;
        unset($sansAxe['axe_variation']);
        $this->assertRefuse(fn () => CatalogueScenarios::validerParametres('SIM-11', $sansAxe), 'axe_variation');

        $sansSens = $valides;
        unset($sansSens['sens_variation']);
        $this->assertRefuse(fn () => CatalogueScenarios::validerParametres('SIM-11', $sansSens), 'sens_variation');

        // Axe ou sens hors contrat : refusés (valeurs épinglées au schéma).
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-11', array_merge($valides, ['axe_variation' => 'redoublement'])),
            'non autorisée'
        );
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-11', array_merge($valides, ['sens_variation' => 'baisse'])),
            'non autorisée'
        );

        // Taux de référence absent : le schéma l'exige.
        $sansReference = $valides;
        unset($sansReference['taux']);
        $this->assertRefuse(fn () => CatalogueScenarios::validerParametres('SIM-11', $sansReference), 'taux');

        // Incompatibilités structurelles : gel, choc, cible, creux, alias passage.
        foreach ([
            ['duree_gel_annees' => 1],
            ['pas_exceptionnel' => 1, 'admissions_exceptionnelles' => 5],
            ['taux_cible_croissance' => 0.05],
            ['profondeur_baisse' => 0.2, 'duree_baisse_annees' => 1, 'duree_recuperation_annees' => 1],
            ['taux_ameliore' => ['passage' => 0.8, 'redoublement' => 0.1, 'abandon' => 0.1]],
            ['taux_degrade' => ['passage' => 0.5, 'redoublement' => 0.3, 'abandon' => 0.2]],
        ] as $clesInterdites) {
            $this->assertRefuse(
                fn () => CatalogueScenarios::validerParametres('SIM-11', array_merge($valides, $clesInterdites)),
                'inconnu'
            );
        }

        // Schéma publié : jeu modifié requis, axe et sens épinglés.
        $schema = CatalogueScenarios::schemaSim11();
        $this->assertTrue($schema['taux']['requis']);
        $this->assertTrue($schema['taux_modifie']['requis']);
        $this->assertTrue($schema['taux_modifie']['schema']['passage']['requis']);
        $this->assertTrue($schema['taux_modifie']['schema']['redoublement']['requis']);
        $this->assertTrue($schema['taux_modifie']['schema']['abandon']['requis']);
        $this->assertSame(['abandon'], $schema['axe_variation']['valeurs']);
        $this->assertSame(['hausse'], $schema['sens_variation']['valeurs']);
    }

    public function test_sim12_est_une_analyse_nouvelle_de_baisse_de_l_abandon(): void
    {
        $valides = array_merge($this->parametresValides(), [
            'delta_admissions' => 0.0,
            'bornes' => ['min' => 0.0, 'max' => 0.0],
            'horizon_annees' => 2,
            'taux' => ['passage' => 0.6, 'redoublement' => 0.2, 'abandon' => 0.2],
            'taux_modifie' => ['passage' => 0.6, 'redoublement' => 0.3, 'abandon' => 0.1],
            'axe_variation' => 'abandon',
            'sens_variation' => 'baisse',
        ]);

        $this->assertSame($valides, CatalogueScenarios::validerParametres('SIM-12', $valides));

        // Baisse absorbée par le slack (somme modifiée ≤ 1, p et r inchangés) :
        // autorisée — le moteur ne lui inventera aucun effet.
        $slack = array_merge($valides, [
            'taux' => ['passage' => 0.6, 'redoublement' => 0.2, 'abandon' => 0.2],
            'taux_modifie' => ['passage' => 0.6, 'redoublement' => 0.2, 'abandon' => 0.1],
        ]);
        $this->assertSame($slack, CatalogueScenarios::validerParametres('SIM-12', $slack));

        // Abandon égal ou supérieur : pas une baisse.
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-12', array_merge($valides, [
                'taux_modifie' => ['passage' => 0.6, 'redoublement' => 0.2, 'abandon' => 0.2],
            ])),
            'strictement inférieur'
        );
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-12', array_merge($valides, [
                'taux_modifie' => ['passage' => 0.6, 'redoublement' => 0.1, 'abandon' => 0.3],
            ])),
            'strictement inférieur'
        );

        // Somme modifiée > 1 : refusée (aucune population créée).
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-12', array_merge($valides, [
                'taux_modifie' => ['passage' => 0.6, 'redoublement' => 0.4, 'abandon' => 0.1],
            ])),
            '> 1'
        );

        // Taux modifié hors [0,1] : borné par le schéma.
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-12', array_merge($valides, [
                'taux_modifie' => ['passage' => 1.5, 'redoublement' => -0.1, 'abandon' => 0.0],
            ])),
            'hors bornes'
        );

        // Jeu modifié absent ou incomplet : refusé.
        $sansModifie = $valides;
        unset($sansModifie['taux_modifie']);
        $this->assertRefuse(fn () => CatalogueScenarios::validerParametres('SIM-12', $sansModifie), 'taux_modifie');

        $partiel = $valides;
        unset($partiel['taux_modifie']['redoublement']);
        $this->assertRefuse(fn () => CatalogueScenarios::validerParametres('SIM-12', $partiel), 'redoublement');

        // Descripteur absent : le schéma l'exige.
        $sansAxe = $valides;
        unset($sansAxe['axe_variation']);
        $this->assertRefuse(fn () => CatalogueScenarios::validerParametres('SIM-12', $sansAxe), 'axe_variation');

        $sansSens = $valides;
        unset($sansSens['sens_variation']);
        $this->assertRefuse(fn () => CatalogueScenarios::validerParametres('SIM-12', $sansSens), 'sens_variation');

        // Axe ou sens hors contrat : refusés (valeurs épinglées au schéma).
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-12', array_merge($valides, ['axe_variation' => 'redoublement'])),
            'non autorisée'
        );
        $this->assertRefuse(
            fn () => CatalogueScenarios::validerParametres('SIM-12', array_merge($valides, ['sens_variation' => 'hausse'])),
            'non autorisée'
        );

        // Taux de référence absent : le schéma l'exige.
        $sansReference = $valides;
        unset($sansReference['taux']);
        $this->assertRefuse(fn () => CatalogueScenarios::validerParametres('SIM-12', $sansReference), 'taux');

        // Incompatibilités structurelles : gel, choc, cible, creux, alias passage.
        foreach ([
            ['duree_gel_annees' => 1],
            ['pas_exceptionnel' => 1, 'admissions_exceptionnelles' => 5],
            ['taux_cible_croissance' => 0.05],
            ['profondeur_baisse' => 0.2, 'duree_baisse_annees' => 1, 'duree_recuperation_annees' => 1],
            ['taux_ameliore' => ['passage' => 0.8, 'redoublement' => 0.1, 'abandon' => 0.1]],
            ['taux_degrade' => ['passage' => 0.5, 'redoublement' => 0.3, 'abandon' => 0.2]],
        ] as $clesInterdites) {
            $this->assertRefuse(
                fn () => CatalogueScenarios::validerParametres('SIM-12', array_merge($valides, $clesInterdites)),
                'inconnu'
            );
        }

        // Schéma publié : jeu modifié requis, axe et sens épinglés.
        $schema = CatalogueScenarios::schemaSim12();
        $this->assertTrue($schema['taux']['requis']);
        $this->assertTrue($schema['taux_modifie']['requis']);
        $this->assertTrue($schema['taux_modifie']['schema']['passage']['requis']);
        $this->assertTrue($schema['taux_modifie']['schema']['redoublement']['requis']);
        $this->assertTrue($schema['taux_modifie']['schema']['abandon']['requis']);
        $this->assertSame(['abandon'], $schema['axe_variation']['valeurs']);
        $this->assertSame(['baisse'], $schema['sens_variation']['valeurs']);
    }

    public function test_hash_de_definition_deterministe_et_complet(): void
    {
        $parametres = $this->parametresValides();
        $base = CatalogueScenarios::empreinteScenario('SOCLE-EFFECTIFS', 1, $parametres, null, 'graine-1');

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $base);
        $this->assertSame($base, CatalogueScenarios::empreinteScenario('SOCLE-EFFECTIFS', 1, $parametres, null, 'graine-1'));

        $variantes = [
            CatalogueScenarios::empreinteScenario('SOCLE-EFFECTIFS', 1, $parametres, 7, 'graine-1'),
            CatalogueScenarios::empreinteScenario('SOCLE-EFFECTIFS', 1, $parametres, null, 'graine-2'),
            CatalogueScenarios::empreinteScenario('SOCLE-EFFECTIFS', 2, $parametres, null, 'graine-1'),
            CatalogueScenarios::empreinteScenario('SOCLE-EFFECTIFS', 1, array_merge($parametres, ['delta_admissions' => 0.2]), null, 'graine-1'),
            CatalogueScenarios::empreinteScenario('SOCLE-EFFECTIFS', 1, $parametres, null, 'graine-1', '9.9'),
        ];

        foreach ($variantes as $variante) {
            $this->assertNotSame($base, $variante);
        }
    }

    public function test_version_invalide_refusee(): void
    {
        $this->assertSame(1, CatalogueScenarios::validerVersion(1));
        $this->assertRefuse(fn () => CatalogueScenarios::validerVersion(0), 'Version');
        $this->assertRefuse(fn () => CatalogueScenarios::validerVersion(-1), 'Version');
    }

    public function test_catalogue_ne_modifie_aucune_table_ni_donnee_metier(): void
    {
        $tables = [
            'facultes' => Faculte::query()->count(),
            'etudiants' => Etudiant::query()->count(),
            'inscriptions_academiques' => InscriptionAcademique::query()->count(),
            'scenarios_temporels' => ScenarioTemporel::query()->count(),
            'executions_temporelles' => ExecutionTemporelle::query()->count(),
            'snapshots_executions' => SnapshotExecution::query()->count(),
        ];

        $this->actingAs($this->admin)
            ->getJson(route('admin.simulation-temporelle.catalogue'))
            ->assertOk();

        $this->assertSame($tables, [
            'facultes' => Faculte::query()->count(),
            'etudiants' => Etudiant::query()->count(),
            'inscriptions_academiques' => InscriptionAcademique::query()->count(),
            'scenarios_temporels' => ScenarioTemporel::query()->count(),
            'executions_temporelles' => ExecutionTemporelle::query()->count(),
            'snapshots_executions' => SnapshotExecution::query()->count(),
        ]);
    }
}
