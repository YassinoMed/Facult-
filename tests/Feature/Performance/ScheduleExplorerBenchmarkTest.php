<?php

namespace Tests\Feature\Performance;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use App\Modules\Schedule\Services\ScheduleExplorerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * SCHEDULE-07 / SCHEDULE-11 — Explorateurs à grande échelle : benchmark
 * reproductible sur base SQLite en mémoire (test) — les chiffres sont des
 * comparaisons RELATIVES, pas des SLA de production (documenté, jamais
 * présenté comme mesure MySQL prod). Volume par défaut : 1000 créneaux
 * (`BENCH_CR` pour varier). Garde-fous toujours exécutés : anti-N+1.
 */
class ScheduleExplorerBenchmarkTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private ScheduleExplorerService $service;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.schedule.active' => true]);

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'x', 'active' => true]);
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Sco', 'email' => 'sco.bench@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->service = app(ScheduleExplorerService::class);
    }

    /**
     * Jeu déterministe : P plannings × C créneaux (enseignants/salles
     * distribués), un seul insert en vrac par table (fixture rapide).
     * Défaut : 50 plannings × 20 créneaux = 1000 créneaux actifs.
     */
    private function constituer(int $plannings = 50, int $creneauxParPlanning = 20): int
    {
        $matieres = [];
        for ($m = 1; $m <= 10; $m++) {
            $matieres[] = Matiere::create(['code' => "BENCH{$m}", 'nom' => "Matière {$m}", 'niveau' => 'L1', 'semestre' => 1])->id;
        }
        $enseignants = [];
        for ($t = 1; $t <= 40; $t++) {
            $enseignants[] = Teacher::create(['nom' => "Nom{$t}", 'prenom' => "Prenom{$t}", 'actif' => true])->id;
        }
        $salles = [];
        for ($r = 1; $r <= 20; $r++) {
            $salles[] = Room::create(['code' => "S{$r}", 'nom' => "Salle {$r}", 'capacite' => 40, 'actif' => true])->id;
        }

        $lignes = [];
        for ($g = 1; $g <= $plannings; $g++) {
            $groupe = Group::create([
                'code' => "GB{$g}", 'nom' => "Groupe B{$g}", 'faculte_id' => $this->faculte->id,
                'niveau' => 'L1', 'actif' => true,
            ]);
            $planning = Schedule::create([
                'annee_universitaire_id' => $this->annee->id, 'semestre' => 1,
                'groupe_id' => $groupe->id, 'nom' => "Pl {$g}", 'actif' => true,
            ]);

            for ($c = 0; $c < $creneauxParPlanning; $c++) {
                $lignes[] = [
                    'schedule_id' => $planning->id,
                    'matiere_id' => $matieres[$c % 10],
                    'teacher_id' => $enseignants[$c % 40],
                    'room_id' => $salles[($c + $g) % 20],
                    'day_of_week' => ScheduleSlot::JOURS[$c % 7],
                    'start_time' => sprintf('%02d:00:00', 8 + ($c % 5)),
                    'end_time' => sprintf('%02d:00:00', 10 + ($c % 5)),
                    'type' => 'course', 'status' => 'scheduled',
                    'created_at' => now(), 'updated_at' => now(),
                ];
            }
        }
        foreach (array_chunk($lignes, 500) as $lot) {
            ScheduleSlot::insert($lot);
        }

        return count($lignes);
    }

    /** Garde-fou léger, toujours exécuté : les explorateurs restent plats. */
    public function test_garde_fou_100_creneaux_sans_n1(): void
    {
        $total = $this->constituer(5, 20); // 100 créneaux

        $dispo = $this->mesurer(fn () => $this->service->disponibilites('monday', '08:00', '10:00', null));
        $charge = $this->mesurer(fn () => $this->service->chargeEnseignants(null));
        $conflits = $this->mesurer(fn () => $this->service->conflitsDetectes(null));
        $occupation = $this->mesurer(fn () => $this->service->occupationSalles(null, 'monday'));

        $this->assertGreaterThanOrEqual(100, $total);
        foreach (['disponibilites' => $dispo, 'charge' => $charge, 'conflits' => $conflits, 'occupation' => $occupation] as $nom => $m) {
            $this->assertLessThan(60, $m['requetes'], "{$nom} : {$m['requetes']} requêtes (N+1 suspecté).");
            $this->assertLessThan(5000, $m['ms'], "{$nom} trop lent.");
        }
    }

    /**
     * Benchmark 1000 créneaux : mesures répétées (10 échantillons par
     * scénario, cache vidé avant chaque — pire cas honnête) + artefact JSON.
     */
    public function test_benchmark_1000_creneaux(): void
    {
        $total = $this->constituer(); // 1000 créneaux
        $this->assertSame(1000, $total);

        $scenarios = [
            'disponibilites' => fn () => $this->service->disponibilites('monday', '08:00', '10:00', null),
            'sallesLibres' => fn () => $this->service->sallesLibres('monday', '08:00', '10:00', 20),
            'chargeEnseignants' => fn () => $this->service->chargeEnseignants(null),
            'conflitsDetectes' => fn () => $this->service->conflitsDetectes(null),
            'occupationSalles' => fn () => $this->service->occupationSalles(null, 'monday'),
            'creneauxFiltres' => fn () => $this->service->creneauxFiltres($this->admin, ['day_of_week' => 'monday']),
        ];

        $rapport = ['environnement' => 'sqlite mémoire (phpunit)', 'creneaux' => $total, 'echantillons' => 10, 'scenarios' => []];

        foreach ($scenarios as $nom => $callable) {
            $temps = [];
            $requetes = 0;
            foreach (range(1, 10) as $i) {
                Cache::flush(); // pire cas : aucun cache de version/explorer
                $m = $this->mesurer($callable);
                $temps[] = round($m['ms'], 1);
                $requetes = $m['requetes'];
            }

            $rapport['scenarios'][$nom] = [
                'p50_ms' => $this->percentile($temps, 50),
                'p95_ms' => $this->percentile($temps, 95),
                'max_ms' => max($temps),
                'requetes' => $requetes,
            ];
        }

        Storage::disk('local')->put('benchmark/schedule-explorer.json', json_encode($rapport, JSON_PRETTY_PRINT));
        fwrite(STDERR, "\n[BENCH-SCHEDULE] ".json_encode($rapport['scenarios'])."\n");

        foreach ($rapport['scenarios'] as $nom => $s) {
            $this->assertLessThan(80, $s['requetes'], "{$nom} : {$s['requetes']} requêtes à 1000 créneaux.");
            $this->assertLessThan(10000, $s['max_ms'], "{$nom} trop lent ({$s['max_ms']} ms).");
        }
    }

    /**
     * @param  array<int, float>  $valeurs
     */
    private function percentile(array $valeurs, int $p): float
    {
        sort($valeurs);
        $index = (int) ceil($p / 100 * count($valeurs)) - 1;

        return $valeurs[max(0, $index)];
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
}
