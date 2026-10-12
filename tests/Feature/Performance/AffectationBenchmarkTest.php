<?php

namespace Tests\Feature\Performance;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use App\Services\Affectations\MassAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Benchmark industriel du pipeline masse (LOOP 2) : volumes
 * 100→10000, mesures temps/SQL/mémoire par étape (analyse,
 * propositions, confirmation, export). Volumes pilotés par env :
 * `BENCH_VOLUMES=100,500` (défaut CI : 100,500,1000),
 * `BENCH_FULL=1` (balai complet local). Résultats reproductibles
 * (données déterministes) + artefact JSON `storage/app/benchmark/`.
 */
class AffectationBenchmarkTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Programme $programme;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Bench', 'email' => 'bench@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->programme = Programme::create([
            'faculte_id' => $this->faculte->id, 'code' => 'LIC-INFO', 'nom' => 'Licence Info',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
    }

    public static function volumes(): array
    {
        $brute = getenv('BENCH_FULL') ? '100,500,1000,2500,5000,10000' : (getenv('BENCH_VOLUMES') ?: '100,500,1000');
        $cas = [];

        foreach (explode(',', $brute) as $volume) {
            $volume = (int) trim($volume);
            if ($volume > 0) {
                $cas["N={$volume}"] = [$volume];
            }
        }

        return $cas;
    }

    /**
     * Constitue un volume déterministe : G groupes cap 30 + N étudiants
     * valides inscrits (bulk insert, hash unique, CIN explicites).
     *
     * @return array{etudiant_ids: array<int>, groupe_ids: array<int>}
     */
    private function constituerVolume(int $n): array
    {
        $groupes = max(2, (int) ceil($n / 30));
        $groupeIds = [];

        for ($g = 1; $g <= $groupes; $g++) {
            $groupeIds[] = Group::create([
                'code' => 'BENCH-G'.str_pad((string) $g, 3, '0', STR_PAD_LEFT),
                'nom' => "Groupe bench {$g}",
                'faculte_id' => $this->faculte->id,
                'programme_id' => $this->programme->id,
                'niveau' => 'L1',
                'capacite' => 30,
                'actif' => true,
            ])->id;
        }

        $motDePasse = Hash::make('Secret@123');
        $maintenant = now();

        foreach (array_chunk(range(1, $n), 1000) as $tranche) {
            $lignes = [];
            $inscriptions = [];

            foreach ($tranche as $i) {
                $lignes[] = [
                    'cin' => 'BK'.str_pad((string) $i, 7, '0', STR_PAD_LEFT),
                    'num_inscription' => 'INS-B'.str_pad((string) $i, 6, '0', STR_PAD_LEFT),
                    'nom' => 'Bench', 'prenom' => 'Etudiant'.$i,
                    'email' => "bench{$i}@universite.test",
                    'date_naissance' => '2004-01-15', 'sexe' => 'M',
                    'telephone' => '98000'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                    'faculte_id' => $this->faculte->id,
                    'programme_id' => $this->programme->id,
                    'niveau' => 'L1', 'annee_academique' => '2026-2027',
                    'adresse' => 'Rue X', 'ville' => 'Tunis', 'code_postal' => '1000',
                    'annee_bac' => '2023', 'password' => $motDePasse,
                    'statut' => 'en_attente',
                    'created_at' => $maintenant, 'updated_at' => $maintenant,
                ];
            }

            DB::table('etudiants')->insert($lignes);
            $ids = Etudiant::where('nom', 'Bench')->orderBy('id')->pluck('id')->slice(-count($tranche))->all();

            foreach ($ids as $id) {
                $inscriptions[] = [
                    'etudiant_id' => $id,
                    'annee_universitaire_id' => $this->annee->id,
                    'niveau' => 'L1', 'statut' => 'valide',
                    'created_at' => $maintenant, 'updated_at' => $maintenant,
                ];
            }

            DB::table('inscriptions_academiques')->insert($inscriptions);
        }

        DB::table('etudiants')->where('nom', 'Bench')->update(['statut' => 'valide']);

        return [
            'etudiant_ids' => Etudiant::where('nom', 'Bench')->orderBy('id')->pluck('id')->all(),
            'groupe_ids' => $groupeIds,
        ];
    }

    /**
     * @return array{resultat: mixed, ms: float, requetes: int, memoire_octets: int, pic_octets: int}
     */
    private function mesurer(callable $etape): array
    {
        $requetes = 0;
        DB::listen(function () use (&$requetes) {
            $requetes++;
        });
        gc_collect_cycles();
        $memoireAvant = memory_get_usage(true);
        $debut = microtime(true);
        $resultat = $etape();
        $ms = (microtime(true) - $debut) * 1000;

        return [
            'resultat' => $resultat, 'ms' => $ms, 'requetes' => $requetes,
            'memoire_octets' => memory_get_usage(true) - $memoireAvant,
            'pic_octets' => memory_get_peak_usage(true),
        ];
    }

    /**
     * Garde-fou léger (toujours exécuté, même en CI) : 100 étudiants,
     * budget requêtes strict prouvant l'absence de N+1 sur l'analyse.
     */
    public function test_garde_fou_100_analyse_sans_n1(): void
    {
        $this->constituerVolume(100);
        $service = app(MassAssignmentService::class);

        $mesure = $this->mesurer(fn () => $service->analyser([
            'annee_universitaire_id' => $this->annee->id,
            'faculte_id' => $this->faculte->id,
        ], $this->admin));

        $this->assertSame(100, $mesure['resultat']['assigned_count']);
        $this->assertLessThanOrEqual(
            130, $mesure['requetes'], "N+1 suspect : {$mesure['requetes']} requêtes pour 100 étudiants."
        );
    }

    #[\PHPUnit\Framework\Attributes\Group('performance')]
    #[DataProvider('volumes')]
    public function test_balai_pipeline_masse(int $n): void
    {
        $this->constituerVolume($n);
        $service = app(MassAssignmentService::class);
        $criteres = [
            'annee_universitaire_id' => $this->annee->id,
            'faculte_id' => $this->faculte->id,
        ];

        // TEST A — analyse (simulation sans écriture).
        $analyse = $this->mesurer(fn () => $service->analyser($criteres, $this->admin));

        // TEST D — export (rejoue l'analyse + CSV streaming).
        $export = $this->mesurer(function () use ($service, $criteres) {
            $donnees = $service->analyser($criteres, $this->admin);

            return strlen(implode("\n", array_map(
                fn ($p) => implode(';', [$p['student_numero'], $p['proposed_group_code'], $p['score']]),
                $donnees['propositions']
            )));
        });

        // TEST B — analyse + création des propositions persistées.
        $propositions = $this->mesurer(function () use ($service, $criteres) {
            $donnees = $service->analyser($criteres, $this->admin);

            return $service->creerPropositions(array_map(fn ($p) => [
                'student_id' => $p['student_id'],
                'groupe_id' => $p['proposed_group_id'],
                'score' => $p['score'],
                'certitude' => $p['certitude'],
                'reasons' => $p['reasons'],
            ], $donnees['propositions']), $this->annee->id, $this->admin);
        });

        // TEST C — confirmation transactionnelle par tranches de 5000
        // (plafond de validation, même philosophie que tranches auto).
        $tranches = array_chunk(array_map(fn ($p) => [
            'student_id' => $p['student_id'], 'groupe_id' => $p['proposed_group_id'],
        ], $analyse['resultat']['propositions']), 5000);
        $confirmation = $this->mesurer(function () use ($service, $tranches) {
            $total = ['total_demande' => 0, 'affectes' => 0, 'ignores' => 0, 'erreurs' => []];

            foreach ($tranches as $tranche) {
                $partiel = $service->confirmer($tranche, $this->annee->id, $this->admin, 'Benchmark');
                $total['total_demande'] += $partiel['total_demande'];
                $total['affectes'] += $partiel['affectes'];
                $total['ignores'] += $partiel['ignores'];
            }

            return $total;
        });

        $lignes = [
            'volume' => $n,
            'analyse_ms' => round($analyse['ms'], 1),
            'analyse_requetes' => $analyse['requetes'],
            'analyse_ms_par_etudiant' => round($analyse['ms'] / max(1, $n), 3),
            'analyse_requetes_par_etudiant' => round($analyse['requetes'] / max(1, $n), 3),
            'analyse_memoire_mo' => round($analyse['memoire_octets'] / 1048576, 2),
            'analyse_pic_mo' => round($analyse['pic_octets'] / 1048576, 2),
            'export_ms' => round($export['ms'], 1),
            'propositions_ms' => round($propositions['ms'], 1),
            'propositions_requetes' => $propositions['requetes'],
            'confirmation_ms' => round($confirmation['ms'], 1),
            'confirmation_requetes' => $confirmation['requetes'],
            'affectes' => $confirmation['resultat']['affectes'],
            'propositions_creees' => $propositions['resultat']['creees'],
        ];

        @mkdir(storage_path('app/benchmark'), 0755, true);
        file_put_contents(
            storage_path("app/benchmark/affectations-N{$n}.json"),
            json_encode($lignes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
        fwrite(STDERR, "\n[BENCH] ".json_encode($lignes, JSON_UNESCAPED_UNICODE)."\n");

        // Cohérence métier sur tout volume.
        $attendus = min($n, 5000); // plafond chargement cockpit.
        $this->assertSame($attendus, $analyse['resultat']['assigned_count']);
        $this->assertSame($attendus, $confirmation['resultat']['affectes']);
        $this->assertSame($attendus, $propositions['resultat']['creees']);

        // Budgets anti-N+1 : croissance des requêtes très inférieure
        // au volume (marges larges, SQLite CI).
        $this->assertLessThanOrEqual(60 + $n * 1.5, $analyse['requetes'], 'Analyse : N+1 suspect.');
        $this->assertLessThanOrEqual(120000, $analyse['ms'], "Analyse trop lente à N={$n}.");
    }
}
