<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Modules\Referentiel\Models\Maquette;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Services\MaquetteService;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * LOOP A3 — Verrous d'activation FOR UPDATE : preuve sur MySQL RÉEL.
 *
 * SQLite ne verrouille pas → `skip` hors MySQL, et n'agit JAMAIS sur
 * le dataset de dev : base scratch dédiée (`maquette_race_scratch`,
 * créée au besoin par le test) via `MAQUETTE_RACE_MYSQL=1`, rollback
 * en tearDown. Commande :
 *
 *   MAQUETTE_RACE_MYSQL=1 php artisan test --filter=MaquetteRaceMysqlTest
 */
class MaquetteRaceMysqlTest extends TestCase
{
    private Connection $conn;

    private Maquette $maquette;

    protected function setUp(): void
    {
        parent::setUp();

        if (env('MAQUETTE_RACE_MYSQL') !== '1') {
            $this->markTestSkipped('Preuve MySQL réelle : MAQUETTE_RACE_MYSQL=1 requis (base scratch dédiée).');
        }

        $admin = array_merge(config('database.connections.mysql'), ['database' => null]);
        config(['database.connections.maquette_race_admin' => $admin]);
        DB::connection('maquette_race_admin')->statement('CREATE DATABASE IF NOT EXISTS maquette_race_scratch');

        config(['database.connections.maquette_race' => array_merge(
            config('database.connections.mysql'), ['database' => 'maquette_race_scratch']
        )]);
        // `array_merge`, PAS `+` : la connexion mysql porte déjà une clé
        // `database` (celle du .env) et l'opérateur `+` garderait la valeur
        // de gauche → la « seconde poignée » viserait une AUTRE base et le
        // test NOWAIT deviendrait un faux négatif (R-13/2).
        config(['database.connections.maquette_race_2' => array_merge(
            config('database.connections.mysql'), ['database' => 'maquette_race_scratch']
        )]);
        $this->conn = DB::connection('maquette_race');
        $this->assertSame('maquette_race_scratch', $this->conn->getDatabaseName(),
            'Garde-fou : ce test ne doit jamais toucher le dataset dev.');

        Artisan::call('migrate', ['--database' => 'maquette_race', '--force' => true]);

        DB::setDefaultConnection('maquette_race');
        $this->conn->beginTransaction();

        $faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'active' => true]);
        $programme = Programme::create([
            'faculte_id' => $faculte->id, 'code' => 'RACE', 'nom' => 'Programme course',
            'statut' => 'actif', 'actif' => true,
        ]);
        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->maquette = Maquette::create([
            'programme_id' => $programme->id, 'annee_universitaire_id' => $annee->id,
            'version' => 1, 'statut' => 'approved',
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->conn)) {
            $this->conn->rollBack();
        }

        parent::tearDown();
    }

    public function test_activation_emet_for_update_et_reste_unique(): void
    {
        $vus = [];
        DB::listen(function ($requete) use (&$vus) {
            $vus[] = $requete->sql;
        });

        app(MaquetteService::class)->transiter($this->maquette, 'active', null);

        $verrous = array_filter($vus, fn ($sql) => str_contains(mb_strtolower($sql), 'for update'));
        $this->assertNotEmpty($verrous, 'Aucun SELECT ... FOR UPDATE émis par transiter().');
        $this->assertSame('active', $this->maquette->fresh()->statut);
        $this->assertSame(1, Maquette::where('statut', 'active')->count());
    }

    public function test_concurrent_second_verrou_refuse_nowait(): void
    {
        // Connexion A : verrouille la ligne (transaction ouverte).
        $verrouillee = Maquette::whereKey($this->maquette->id)->lockForUpdate()->firstOrFail();
        $this->assertSame('approved', $verrouillee->statut);

        // Connexion B (autre poignée PDO) : NOWAIT échoue tant que A
        // détient le verrou — la seconde activation attendra ou
        // échouera au lieu de créer un doublon silencieux.
        try {
            Maquette::on('maquette_race_2')->whereKey($this->maquette->id)
                ->lock('for update nowait')->first();
            $this->fail('Verrou NOWAIT acquis malgré la transaction concurrente.');
        } catch (QueryException $e) {
            $this->assertTrue(true);
        }
    }

    public function test_garde_sql_refuse_la_deuxieme_active(): void
    {
        // Rempart base (consolidation §2) : même un écrit DIRECT
        // (script/import, hors transiter()) ne peut créer de 2e active.
        $this->maquette->update(['statut' => 'active']);

        try {
            Maquette::create([
                'programme_id' => $this->maquette->programme_id,
                'annee_universitaire_id' => $this->maquette->annee_universitaire_id,
                'version' => 99, 'statut' => 'active',
            ]);
            $this->fail('Deux maquettes actives acceptées malgré la garde SQL.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('maquettes_active_programme_unique', $e->getMessage());
        }

        $this->assertSame(1, Maquette::where('statut', 'active')->count());
    }

    public function test_remplacement_atomique_restre_valide_sous_garde_sql(): void
    {
        // La bascule existante (archiver les autres PUIS activer la
        // cible) reste compatible : exactement une active à la fin.
        app(MaquetteService::class)->transiter($this->maquette, 'active', null);
        $v2 = Maquette::create([
            'programme_id' => $this->maquette->programme_id,
            'annee_universitaire_id' => $this->maquette->annee_universitaire_id,
            'version' => 2, 'statut' => 'approved',
        ]);

        app(MaquetteService::class)->transiter($v2, 'active', null);

        $this->assertSame('archived', $this->maquette->fresh()->statut);
        $this->assertSame('active', $v2->fresh()->statut);
        $this->assertSame(1, Maquette::where('statut', 'active')->count());
    }
}
