<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * LOOP 12-B — Benchmark volumétrie du registre (MySQL réel, scratch).
 *
 * Paliers 10k / 50k / 100k dossiers synthétiques : p50/p95 du rendu
 * index, requêtes SQL, mémoire, EXPLAIN. Artefact :
 * storage/app/benchmark/archives-volumetrie.json.
 *
 * Exécution EXCLUSIVE : ARCHIVES_BENCH=1, base scratch
 * `archives_bench_scratch` (jamais dev/prod). Sinon : skip.
 */
class ArchivesVolumetrieBenchTest extends TestCase
{
    private const PALIERS = [10000, 50000, 100000];

    public function test_benchmark_registre(): void
    {
        if (env('ARCHIVES_BENCH') !== '1') {
            $this->markTestSkipped('Benchmark volumétrie : ARCHIVES_BENCH=1 requis (base scratch dédiée).');
        }

        $admin = array_merge(config('database.connections.mysql'), ['database' => null]);
        config(['database.connections.archives_bench_admin' => $admin]);
        DB::connection('archives_bench_admin')->statement('CREATE DATABASE IF NOT EXISTS archives_bench_scratch');
        config(['database.connections.archives_bench' => array_merge(
            config('database.connections.mysql'), ['database' => 'archives_bench_scratch']
        )]);
        $this->assertSame('archives_bench_scratch', DB::connection('archives_bench')->getDatabaseName(),
            'Garde-fou : jamais le dataset dev.');

        Artisan::call('migrate', ['--database' => 'archives_bench', '--force' => true]);
        DB::setDefaultConnection('archives_bench');

        // Scratch réutilisable entre runs : purge des jeux précédents.
        $conn = DB::connection('archives_bench');
        $conn->statement('SET FOREIGN_KEY_CHECKS = 0');
        $conn->table('archives_etudiants')->truncate();
        $conn->table('etudiants')->truncate();
        $conn->statement('SET FOREIGN_KEY_CHECKS = 1');

        $tag = substr(md5(uniqid('', true)), 0, 6);
        $faculteId = $conn->table('facultes')->insertGetId([
            'nom' => 'Bench', 'code' => 'BN'.$tag, 'description' => 'T', 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $admin = User::create([
            'name' => 'Bench', 'email' => "bench12{$tag}@example.com", 'password' => 'Motdepasse@123',
        ]);
        $admin->definirRole('super_admin');
        $admin->save();
        $this->actingAs($admin);

        $resultats = ['moteur' => 'mysql', 'paliers' => []];
        $total = 0;
        foreach (self::PALIERS as $palier) {
            $this->alimenter($conn, $faculteId, $tag, $total, $palier - $total);
            $total = $palier;
            $resultats['paliers'][$palier] = $this->mesurer($palier);
        }

        $resultats['explain'] = array_map(
            fn ($ligne) => ['table' => $ligne->table, 'type' => $ligne->type, 'key' => $ligne->key, 'rows' => $ligne->rows, 'Extra' => $ligne->Extra],
            $conn->select("EXPLAIN SELECT archives_etudiants.* FROM archives_etudiants INNER JOIN etudiants ON etudiants.id = archives_etudiants.etudiant_id WHERE etudiants.nom LIKE '%BENCH%' ORDER BY etudiants.nom, archives_etudiants.id LIMIT 12")
        );

        $chemin = storage_path('app/benchmark/archives-volumetrie.json');
        @mkdir(dirname($chemin), 0755, true);
        file_put_contents($chemin, json_encode($resultats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->assertSame(100000, $resultats['paliers'][100000]['compte']);
        // Garde-fou : p95 page simple < 5 s même à 100k (borne large, charge CI).
        $this->assertLessThan(5000, $resultats['paliers'][100000]['p95_ms'], 'p95 registre à 100k dossiers.');
    }

    private function alimenter($conn, int $faculteId, string $tag, int $debut, int $nombre): void
    {
        $traites = 0;
        foreach (array_chunk(range($debut + 1, $debut + $nombre), 2000) as $tranche) {
            $etudiants = [];
            foreach ($tranche as $i) {
                $etudiants[] = [
                    'cin' => 'BN'.$tag.sprintf('%06d', $i),
                    'num_inscription' => 'INS-BN'.$tag.sprintf('%06d', $i),
                    'nom' => 'Bench'.($i % 500), 'prenom' => 'Vol'.$i,
                    'email' => "bench{$tag}-{$i}@example.com",
                    'password' => 'x', 'sexe' => 'M', 'faculte_id' => $faculteId,
                    'niveau' => 'L1', 'annee_academique' => '2025-2026',
                    'adresse' => '1 rue', 'ville' => 'Rabat',
                    'code_postal' => '10000', 'annee_bac' => '2024',
                    'statut' => 'en_attente',
                    'created_at' => now(), 'updated_at' => now(),
                ];
            }
            $conn->table('etudiants')->insert($etudiants);

            $dossiers = [];
            $ids = $conn->table('etudiants')->where('email', 'like', "bench{$tag}-%@example.com")
                ->orderBy('id')->skip($debut + $traites)->take(count($tranche))->pluck('id');
            $traites += count($tranche);
            foreach ($ids as $id) {
                $dossiers[] = [
                    'etudiant_id' => $id,
                    'matricule' => 'MAT-BN'.$tag.'-'.$id,
                    'date_archivage' => '2026-01-15',
                    'statut' => $id % 2 === 0 ? 'clos' : 'actif',
                    'created_at' => now(), 'updated_at' => now(),
                ];
            }
            $conn->table('archives_etudiants')->insert($dossiers);
        }
    }

    /**
     * @return array{compte: int, requetes: int, p50_ms: float, p95_ms: float, memoire_mo: float}
     */
    private function mesurer(int $palier): array
    {
        $compte = (int) DB::table('archives_etudiants')->count();
        $requetes = 0;
        DB::listen(function () use (&$requetes): void {
            $requetes++;
        });

        $durees = [];
        for ($r = 0; $r < 5; $r++) {
            $debut = microtime(true);
            $this->get(route('admin.archives.index'))->assertOk();
            $durees[] = (microtime(true) - $debut) * 1000;
        }
        // Page profonde (offset) : coût comparatif sans changer le contrat.
        $debut = microtime(true);
        $profonde = $this->get(route('admin.archives.index', ['page' => 1000]))->assertOk();
        $profondeMs = (microtime(true) - $debut) * 1000;

        sort($durees);

        return [
            'compte' => $compte,
            'requetes' => (int) round($requetes / 6),
            'p50_ms' => round($durees[2], 1),
            'p95_ms' => round(max($durees), 1),
            'page_profonde_ms' => round($profondeMs, 1),
            'memoire_mo' => round(memory_get_peak_usage(true) / 1048576, 1),
        ];
    }
}
