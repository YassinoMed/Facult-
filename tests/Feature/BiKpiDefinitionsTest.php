<?php

namespace Tests\Feature;

use App\Models\KpiDefinition;
use App\Services\BIService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * LOT 03 — Registre persistant des définitions KPI (`kpi_definitions`,
 * migration additive 2026_10_24_110000) : catalogue de code reflété et
 * versionné (dérive documentaire) + snapshot de valeur du périmètre BI.
 * Descriptive uniquement — jamais d'écriture métier.
 */
class BiKpiDefinitionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Statics partagés entre tests du même process : réinitialisation
        // de la mémoïsation fail-soft (pattern LOT 05).
        KpiDefinition::tableDisponible(true);
    }

    public function test_le_registre_reflete_les_definitions_bi_et_cockpit(): void
    {
        $resultat = app(BIService::class)->synchroniserDefinitions();

        $this->assertTrue($resultat['actif']);
        $this->assertSame(12, $resultat['bi_service']);
        $this->assertGreaterThanOrEqual(1, $resultat['cockpit']);
        $this->assertGreaterThanOrEqual(12, $resultat['creees']);

        // BI : les 12 KPI documentés de calculer() sont proposés et snapshotés.
        $bi = KpiDefinition::where('provenance', 'bi_service')->get();
        $this->assertSame(12, $bi->count());

        foreach ($bi as $def) {
            $this->assertSame(1, $def->version);
            $this->assertTrue($def->actif);
            $this->assertNotNull($def->definition_hash);
            $this->assertNotNull($def->dernier_snapshot_a);
            $this->assertArrayHasKey('valeur', $def->dernier_snapshot);
        }

        $this->assertDatabaseHas('kpi_definitions', [
            'cle' => 'effectif',
            'provenance' => 'bi_service',
        ]);
        $this->assertDatabaseHas('kpi_definitions', [
            'cle' => 'taux_passage',
            'provenance' => 'bi_service',
        ]);

        // Cockpit : définitions reflétées SANS snapshot (le calcul reste
        // la propriété de CockpitKpiService — aucune duplication).
        $cockpit = KpiDefinition::where('provenance', 'catalogue_cockpit')->get();
        $this->assertGreaterThanOrEqual(1, $cockpit->count());
        $this->assertDatabaseHas('kpi_definitions', [
            'cle' => 'effectif_total',
            'provenance' => 'catalogue_cockpit',
        ]);

        // Clé partagée entre les deux catalogues (`moyenne_mediane`) :
        // deux lignes non conflictuelles grâce à la clé unique composite.
        $this->assertSame(
            2,
            KpiDefinition::where('cle', 'moyenne_mediane')->count(),
        );
    }

    public function test_la_derive_documentaire_incremente_la_version(): void
    {
        app(BIService::class)->synchroniserDefinitions();

        $def = KpiDefinition::where(['cle' => 'effectif', 'provenance' => 'bi_service'])->firstOrFail();
        $def->fill(['definition' => 'Définition périmée (simulation de dérive)'])->save();

        $resultat = app(BIService::class)->synchroniserDefinitions();

        $this->assertSame(1, $resultat['mises_a_jour']);
        $this->assertSame(0, $resultat['creees']);

        $def->refresh();
        $this->assertStringContainsString('Étudiants du périmètre', $def->definition);
        $this->assertSame(2, $def->version);
    }

    public function test_la_double_synchronisation_est_idempotente(): void
    {
        app(BIService::class)->synchroniserDefinitions();
        $premiere = app(BIService::class)->synchroniserDefinitions();

        $this->assertSame(0, $premiere['creees']);
        $this->assertSame(0, $premiere['mises_a_jour']);

        KpiDefinition::where('version', '>', 1)->get()
            ->each(fn (KpiDefinition $d) => self::fail("Version inattendue sur {$d->cle} ({$d->provenance}) : {$d->version}"));
    }

    public function test_fail_soft_sans_table_et_migration_rejouable(): void
    {
        Schema::drop('kpi_definitions');
        KpiDefinition::tableDisponible(true);

        $resultat = app(BIService::class)->synchroniserDefinitions();

        $this->assertFalse($resultat['actif']);
        $this->assertStringContainsString('2026_10_24_110000', (string) $resultat['raison']);

        // Remise en état : la migration est rejouable (garde hasTable).
        DB::table('migrations')
            ->where('migration', 'like', '%2026_10_24_110000_creer_kpi_definitions_table%')
            ->delete();

        $this->artisan('migrate', [
            '--path' => 'database/migrations/2026_10_24_110000_creer_kpi_definitions_table.php',
            '--force' => true,
        ])->assertSuccessful();

        KpiDefinition::tableDisponible(true);

        $relance = app(BIService::class)->synchroniserDefinitions();
        $this->assertTrue($relance['actif']);
    }

    public function test_la_commande_artisan_synchronise_le_registre(): void
    {
        $this->artisan('bi:synchroniser-kpi-definitions')
            ->assertSuccessful();

        // Le registre est alimenté (BI + cockpit).
        $this->assertDatabaseHas('kpi_definitions', [
            'cle' => 'effectif',
            'provenance' => 'bi_service',
        ]);
        $this->assertDatabaseHas('kpi_definitions', [
            'cle' => 'effectif_total',
            'provenance' => 'catalogue_cockpit',
        ]);
    }
}
