<?php

namespace Tests\Feature\Performance;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Services\DataExchange\DataExportService;
use App\Services\DataExchange\DataImportService;
use App\Services\Exports\ListeEtudiantsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * PHASE 10 — Mesures reproductibles du pipeline import/export : durée,
 * requêtes SQL, pic mémoire par volume (défaut CI : 100,500,1000 ;
 * `BENCH_VOLUMES` / `BENCH_FULL=1` pour le balai complet). Bornes
 * larges anti-explosion (N+1, mémoire) + artefact JSON
 * `storage/app/benchmark/import-export.json`. Ces mesures ne valent
 * que pour sqlite local, jamais comme garantie de production.
 */
class ImportExportBenchmarkTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    public static function volumes(): array
    {
        $brute = getenv('BENCH_FULL') ? '100,500,1000,2500,5000' : (getenv('BENCH_VOLUMES') ?: '100,500,1000');
        $cas = [];

        foreach (explode(',', $brute) as $volume) {
            $volume = (int) trim($volume);

            if ($volume > 0) {
                $cas["N={$volume}"] = [$volume];
            }
        }

        return $cas;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
    }

    private function fichier(string $contenu, string $nom = 'bench.csv'): UploadedFile
    {
        $chemin = tempnam(sys_get_temp_dir(), 'bench').'_'.$nom;
        file_put_contents($chemin, $contenu);

        return new UploadedFile($chemin, $nom, 'text/csv', null, true);
    }

    private function csvEtudiants(int $n, float $partExistants = 0.0, float $partErreurs = 0.0): string
    {
        $lignes = ['nom,prenom,cin,faculte'];

        for ($i = 1; $i <= $n; $i++) {
            if ($partErreurs > 0.0 && $i % (int) (1 / $partErreurs) === 0) {
                $lignes[] = ",,BADCIN{$i},FST";
            } elseif ($partExistants > 0.0 && $i % (int) (1 / $partExistants) === 0) {
                $lignes[] = "Existant{$i},Prenom{$i},BENCH{$i},FST";
            } else {
                $lignes[] = "Nom{$i},Prenom{$i},NEWCIN{$i},FST";
            }
        }

        return implode("\n", $lignes)."\n";
    }

    private function semerExistants(int $n): void
    {
        $lignes = [];

        for ($i = 1; $i <= $n; $i++) {
            $lignes[] = [
                'cin' => 'BENCH'.$i, 'num_inscription' => 'MAT-BENCH'.$i,
                'nom' => "Existant{$i}", 'prenom' => "Prenom{$i}",
                'email' => "bench{$i}@universite.test",
                'sexe' => 'M', 'faculte_id' => $this->faculte->id,
                'niveau' => 'L1', 'annee_academique' => '2026-2027',
                'adresse' => 'R', 'ville' => 'Tunis', 'code_postal' => '1000',
                'annee_bac' => '2023', 'password' => 'x',
                'statut' => 'en_attente',
                'created_at' => now(), 'updated_at' => now(),
            ];
        }

        foreach (array_chunk($lignes, 500) as $lot) {
            Etudiant::insert($lot);
        }
    }

    /**
     * @return array{duree_s: float, requetes: int, pic_mo: float}
     */
    private function mesurer(callable $operation): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $debut = microtime(true);
        $avant = memory_get_usage(true);

        $operation();

        $duree = microtime(true) - $debut;
        $requetes = count(DB::getQueryLog());
        DB::disableQueryLog();
        $pic = (memory_get_peak_usage(true) - $avant) / 1048576;

        return ['duree_s' => round($duree, 3), 'requetes' => $requetes, 'pic_mo' => round($pic, 2)];
    }

    #[DataProvider('volumes')]
    public function test_analyser_volumes(int $n): void
    {
        $this->semerExistants((int) ($n * 0.1));

        $mesure = $this->mesurer(fn () => app(DataImportService::class)->analyser(
            $this->fichier($this->csvEtudiants($n, 0.1)), 'etudiants'
        ));

        $this->consigner("analyser.N={$n}", $n, $mesure);

        // Garde-fous anti-explosion (larges : validés par les mesures).
        $this->assertLessThan(5 * $n + 20, $mesure['requetes'], 'Requêtes quasi-linéaires (référence ~2/ligne).');
        $this->assertLessThan(256, $mesure['pic_mo'], 'Mémoire bornée.');
        $this->assertLessThan(120, $mesure['duree_s'], 'Durée bornée.');
    }

    #[DataProvider('volumes')]
    public function test_analyser_plein_erreurs(int $n): void
    {
        $mesure = $this->mesurer(fn () => app(DataImportService::class)->analyser(
            $this->fichier($this->csvEtudiants($n, 0.0, 1.0)), 'etudiants'
        ));

        $this->consigner("analyser_erreurs.N={$n}", $n, $mesure);

        $this->assertLessThan(5 * $n + 20, $mesure['requetes']);
        $this->assertLessThan(256, $mesure['pic_mo']);
    }

    #[DataProvider('volumes')]
    public function test_export_jeu_donnees_volumes(int $n): void
    {
        $this->semerExistants($n);

        $service = app(ListeEtudiantsService::class);
        $admin = User::creerCompteBackOffice([
            'name' => 'Bench', 'email' => 'bench.exp@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $mesure = $this->mesurer(fn () => $service->jeuDonnees($service->requete([], $admin), $admin));

        $this->consigner("export_jeu.N={$n}", $n, $mesure);

        $this->assertLessThan(50, $mesure['requetes'], 'Export en lots eager, pas de N+1.');
        $this->assertLessThan(256, $mesure['pic_mo']);
    }

    public function test_export_api_csv_mesure(): void
    {
        $this->semerExistants(500);

        $requete = Request::create('/api/v1/data/etudiants/export/csv', 'GET');
        $requete->setUserResolver(fn () => User::creerCompteBackOffice([
            'name' => 'Bench', 'email' => 'bench.api@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite'));

        $mesure = $this->mesurer(fn () => app(DataExportService::class)->exporter($requete, 'etudiants', 'csv'));

        $this->consigner('export_api_csv.N=500', 500, $mesure);

        $this->assertLessThan(50, $mesure['requetes']);
    }

    public function test_similarite_volume_borne(): void
    {
        $this->semerExistants(200);

        // 200 homonymes exacts + 1 ligne faible : la borne (20) s'applique.
        for ($i = 1; $i <= 200; $i++) {
            Etudiant::where('id', $i)->update(['nom' => 'Homonyme', 'prenom' => 'Test']);
        }

        $mesure = $this->mesurer(fn () => app(DataImportService::class)->analyser(
            $this->fichier("nom,prenom\nHomonyme,Test\n"), 'etudiants'
        ));

        $this->consigner('similarite.N=200', 200, $mesure);

        $this->assertLessThan(50, $mesure['requetes']);
    }

    /**
     * @param  array{duree_s: float, requetes: int, pic_mo: float}  $mesure
     */
    private function consigner(string $cas, int $n, array $mesure): void
    {
        $chemin = storage_path('app/benchmark/import-export.json');
        $artefact = is_file($chemin) ? (json_decode((string) file_get_contents($chemin), true) ?: []) : [];
        $artefact[$cas] = ['n' => $n] + $mesure + ['le' => now()->toIso8601String()];
        @mkdir(dirname($chemin), 0755, true);
        file_put_contents($chemin, json_encode($artefact, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        fwrite(STDERR, "\n[BENCH-IMPORT] {$cas} duree={$mesure['duree_s']}s req={$mesure['requetes']} pic={$mesure['pic_mo']}Mo");
    }
}
