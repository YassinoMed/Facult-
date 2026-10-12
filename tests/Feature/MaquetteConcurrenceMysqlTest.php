<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Modules\Referentiel\Models\Maquette;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Services\MaquetteService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * LOOP 2 — Deux activations concurrentes au niveau SERVICE : preuve
 * sur MySQL RÉEL que la bascule atomique + `lockForUpdate` ne laisse
 * jamais deux maquettes actives. Même garde-fous que
 * `MaquetteRaceMysqlTest` (scratch `maquette_race_scratch`, gate
 * `MAQUETTE_RACE_MYSQL=1`, jamais le dataset dev, nettoyage explicite
 * car les fils commitent).
 */
class MaquetteConcurrenceMysqlTest extends TestCase
{
    private int $maquetteA;

    private int $maquetteB;

    protected function setUp(): void
    {
        parent::setUp();

        if (env('MAQUETTE_RACE_MYSQL') !== '1') {
            $this->markTestSkipped('Preuve MySQL réelle : MAQUETTE_RACE_MYSQL=1 requis (base scratch dédiée).');
        }

        $admin = array_merge(config('database.connections.mysql'), ['database' => null]);
        config(['database.connections.maquette_conc_admin' => $admin]);
        DB::connection('maquette_conc_admin')->statement('CREATE DATABASE IF NOT EXISTS maquette_race_scratch');

        config(['database.connections.maquette_conc' => array_merge(
            config('database.connections.mysql'), ['database' => 'maquette_race_scratch']
        )]);
        $this->assertSame('maquette_race_scratch', DB::connection('maquette_conc')->getDatabaseName(),
            'Garde-fou : ce test ne doit jamais toucher le dataset dev.');

        Artisan::call('migrate', ['--database' => 'maquette_conc', '--force' => true]);
        DB::setDefaultConnection('maquette_conc');

        // Les fils commitent (transiter) : purge des résidus d'un run
        // interrompu AVANT de planter le décor (idempotent).
        $this->purger();

        $faculte = Faculte::create(['nom' => 'F Conc', 'code' => 'FCONC', 'active' => true]);
        $programme = Programme::create([
            'faculte_id' => $faculte->id, 'code' => 'CONC', 'nom' => 'Programme concurrence',
            'statut' => 'actif', 'actif' => true,
        ]);
        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);

        foreach ([1, 2] as $version) {
            $maquette = Maquette::create([
                'programme_id' => $programme->id, 'annee_universitaire_id' => $annee->id,
                'version' => $version, 'statut' => 'approved',
            ]);

            if ($version === 1) {
                $this->maquetteA = $maquette->id;
            } else {
                $this->maquetteB = $maquette->id;
            }
        }
    }

    protected function tearDown(): void
    {
        try {
            $this->purger();
        } catch (\Throwable) {
            // Nettoyage best-effort (le garde-fou setUp couvre le reste).
        }

        parent::tearDown();
    }

    private function purger(): void
    {
        Maquette::on('maquette_conc')->whereHas('programme', fn ($q) => $q->where('code', 'CONC'))->delete();
        Programme::on('maquette_conc')->where('code', 'CONC')->delete();
        Faculte::on('maquette_conc')->where('code', 'FCONC')->delete();
        AnneeUniversitaire::on('maquette_conc')->where('libelle', '2026-2027')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('maquettes')
                ->whereColumn('maquettes.annee_universitaire_id', 'annees_universitaires.id'))
            ->delete();
    }

    public function test_double_activation_concurrente_unique_active(): void
    {
        $acteur = function (int $maquetteId): void {
            // Poignée PDO propre au fils (jamais celle héritée du fork).
            DB::purge('maquette_conc');
            DB::reconnect('maquette_conc');
            DB::setDefaultConnection('maquette_conc');

            try {
                app(MaquetteService::class)->transiter(
                    Maquette::findOrFail($maquetteId), 'active', null
                );
            } catch (\DomainException $e) {
                // Refus propre (ex. activation concurrente à rejouer) :
                // le fils sort en code 3, le parent tranche sur l'état.
                file_put_contents('./tmp/tmpdir/conc-child-'.$maquetteId.'.txt', 'DOMAIN: '.$e->getMessage());
                exit(3);
            } catch (\Throwable $e) {
                file_put_contents('./tmp/tmpdir/conc-child-'.$maquetteId.'.txt',
                    $e::class.': '.$e->getMessage());
                exit(1);
            }
            exit(0);
        };

        $pid1 = pcntl_fork();
        $this->assertNotSame(-1, $pid1, 'Fork impossible.');

        if ($pid1 === 0) {
            $acteur($this->maquetteA);
        }

        $pid2 = pcntl_fork();
        $this->assertNotSame(-1, $pid2, 'Fork impossible.');

        if ($pid2 === 0) {
            $acteur($this->maquetteB);
        }

        pcntl_waitpid($pid1, $statut1);
        pcntl_waitpid($pid2, $statut2);

        // Sorties admises : 0 (bascule ordonnée) ou 3 (refus propre
        // « réessayez ») — jamais d'exception brute ni de 500.
        $sorties = [pcntl_wexitstatus($statut1), pcntl_wexitstatus($statut2)];
        $this->assertEmpty(array_diff($sorties, [0, 3]), 'Sortie inattendue : '.implode(',', $sorties));

        // Quel que soit l'entrelacement : EXACTEMENT une active (jamais
        // deux, jamais zéro — le perdant est archivé ou resté approved).
        $etats = Maquette::on('maquette_conc')->whereIn('id', [$this->maquetteA, $this->maquetteB])
            ->pluck('statut')->all();
        $this->assertCount(2, $etats);
        $this->assertSame(1, count(array_filter($etats, fn ($s) => $s === 'active')), 'Double-active ou aucune active après course : '.implode(',', $etats));
        $this->assertEmpty(array_diff($etats, ['active', 'archived', 'approved']), 'État incohérent : '.implode(',', $etats));
    }
}
