<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use App\Modules\Schedule\Services\ScheduleService;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SCHEDULE-12 — Course et verrous FOR UPDATE : preuve sur MySQL RÉEL.
 *
 * SQLite ne verrouille pas → ce test est `skip` hors MySQL, et n'agit
 * JAMAIS sur le dataset de dev : il exige une base scratch dédiée
 * (`schedule_race_scratch`, créée au besoin par le test lui-même) via
 * `SCHEDULE_RACE_MYSQL=1`. Les lignes créées sont en transaction et
 * annulées à chaque test (rollback). Commande :
 *
 *   SCHEDULE_RACE_MYSQL=1 php artisan test --filter=ScheduleRaceMysqlTest
 */
class ScheduleRaceMysqlTest extends TestCase
{
    private Connection $conn;

    private Schedule $planning;

    private ScheduleSlot $slot;

    protected function setUp(): void
    {
        parent::setUp();

        if (env('SCHEDULE_RACE_MYSQL') !== '1') {
            $this->markTestSkipped('Preuve MySQL réelle : SCHEDULE_RACE_MYSQL=1 requis (base scratch dédiée).');
        }

        // Connexion scratch UNIQUEMENT — jamais la connexion par défaut.
        $admin = array_merge(config('database.connections.mysql'), ['database' => null]);
        config(['database.connections.schedule_race_admin' => $admin]);
        DB::connection('schedule_race_admin')->statement('CREATE DATABASE IF NOT EXISTS schedule_race_scratch');

        config(['database.connections.schedule_race' => array_merge(
            config('database.connections.mysql'), ['database' => 'schedule_race_scratch']
        )]);
        // Seconde connexion PDO RÉELLE (autre nom → autre poignée) pour la course.
        config(['database.connections.schedule_race_2' => config('database.connections.schedule_race')]);
        $this->conn = DB::connection('schedule_race');
        $this->assertSame('schedule_race_scratch', $this->conn->getDatabaseName(),
            'Garde-fou : ce test ne doit jamais toucher le dataset dev.');

        Artisan::call('migrate', ['--database' => 'schedule_race', '--force' => true]);

        // Tous les modèles Eloquent suivent la connexion scratch (sinon ils
        // viseraient la mémoire sqlite du phpunit).
        DB::setDefaultConnection('schedule_race');

        // Résidus d'un run interrompu (les fils commitent, donc un arrêt
        // brutal laisse les fixtures derrière lui — déjà observé sur
        // `facultes.code=FR`) : purge idempotente AVANT la transaction.
        $this->purger();

        // Fixture transactionnelle : annulée en tearDown (aucun résidu).
        $this->conn->beginTransaction();

        $faculte = Faculte::on('schedule_race')->create(['nom' => 'FR', 'code' => 'FR', 'description' => 'x', 'active' => true]);
        $groupe = Group::on('schedule_race')->create(['code' => 'G-RACE', 'nom' => 'Groupe Race', 'faculte_id' => $faculte->id, 'niveau' => 'L1', 'actif' => true]);
        $annee = AnneeUniversitaire::on('schedule_race')->create(['libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true]);
        $this->planning = Schedule::on('schedule_race')->create([
            'annee_universitaire_id' => $annee->id, 'semestre' => 1,
            'groupe_id' => $groupe->id, 'nom' => 'Race', 'actif' => true,
        ]);
        $this->slot = ScheduleSlot::on('schedule_race')->create([
            'schedule_id' => $this->planning->id,
            'matiere_id' => Matiere::on('schedule_race')->create(['code' => 'RC1', 'nom' => 'Race', 'niveau' => 'L1', 'semestre' => 1])->id,
            'teacher_id' => Teacher::on('schedule_race')->create(['nom' => 'N', 'prenom' => 'R'])->id,
            'room_id' => Room::on('schedule_race')->create(['code' => 'R1', 'capacite' => 30, 'actif' => true])->id,
            'day_of_week' => 'monday', 'start_time' => '08:00:00', 'end_time' => '10:00:00',
            'type' => 'course', 'status' => 'scheduled',
        ]);
    }

    /**
     * Purge idempotente STRICTEMENT limitée aux codes de ce test
     * (base scratch dédiée uniquement).
     */
    private function purger(): void
    {
        $conn = DB::connection('schedule_race');

        $planningIds = $conn->table('schedules')->where('nom', 'Race')->pluck('id');
        if ($planningIds->isNotEmpty()) {
            $conn->table('schedule_slots')->whereIn('schedule_id', $planningIds)->delete();
            $conn->table('schedules')->whereIn('id', $planningIds)->delete();
        }

        $conn->table('groups')->where('code', 'G-RACE')->delete();
        $conn->table('rooms')->where('code', 'R1')->delete();
        $conn->table('matieres')->where('code', 'RC1')->delete();
        $conn->table('teachers')->where('nom', 'N')->where('prenom', 'R')->delete();
        $conn->table('annees_universitaires')->where('libelle', '2026-2027')->delete();
        $conn->table('facultes')->where('code', 'FR')->delete();
    }

    protected function tearDown(): void
    {
        if (isset($this->conn) && env('SCHEDULE_RACE_MYSQL') === '1') {
            $this->conn->rollBack();
            // Toute transaction concurrente éventuellement ouverte est purgée.
            try {
                DB::connection('schedule_race_2')->rollBack();
            } catch (\Throwable) {
                // rien à purger
            }
        }

        parent::tearDown();
    }

    /** Preuve 1 : FOR UPDATE émis dans l'ordre planning → enseignant → salle. */
    public function test_verrous_emis_dans_un_ordre_constant_anti_deadlock(): void
    {
        $ordre = [];
        DB::connection('schedule_race')->listen(function ($query) use (&$ordre) {
            if (str_contains(mb_strtolower($query->sql), 'for update')) {
                $ordre[] = $query->sql;
            }
        });

        $service = app(ScheduleService::class);
        // modifierCreneau : seule mutation qui verrouille les 3 ressources
        // (annuler/supprimer ne touchent que le planning — par conception).
        $service->modifierCreneau(
            ScheduleSlot::on('schedule_race')->findOrFail($this->slot->id),
            ['start_time' => '08:30:00'], null, 'race'
        );

        $this->assertNotEmpty($ordre, 'Aucun FOR UPDATE émis : la transaction ne verrouille rien.');
        $this->assertStringContainsString('schedules', $ordre[0], 'Le verrou planning passe en premier (ordre anti-deadlock).');
        $this->assertStringContainsString('teachers', $ordre[1] ?? '', 'Puis enseignant.');
        $this->assertStringContainsString('rooms', $ordre[2] ?? '', 'Puis salle.');
        $this->assertSame('08:30', substr((string) $this->slot->fresh()->start_time, 0, 5));
    }

    /** Preuve 2 : deux connexions — le verrou de la 1re empêche la 2e (NOWAIT). */
    public function test_verrou_planning_serialise_une_seconde_connexion(): void
    {
        $conn1 = $this->conn;
        $conn1->commit(); // fixture visible des deux connexions (scratch éphémère, jetable)
        $conn1->beginTransaction();
        // Connexion 1 pose le verrou FOR UPDATE sur le planning.
        $conn1->table('schedules')->where('id', $this->planning->id)->lockForUpdate()->first();

        $conn2 = DB::connection('schedule_race_2');
        $refuse = false;

        try {
            // Connexion 2 tente le même verrou en NOWAIT : refus immédiat MySQL.
            $conn2->select(
                'SELECT id FROM schedules WHERE id = ? FOR UPDATE NOWAIT',
                [$this->planning->id]
            );
        } catch (QueryException $e) {
            $refuse = str_contains($e->getMessage(), '3572') || str_contains(mb_strtolower($e->getMessage()), 'nowait');
        } finally {
            $conn1->rollBack(); // fixture annulée : zéro résidu, même en scratch
            try {
                $conn2->disconnect();
            } catch (\Throwable) {
                // ignoré
            }
        }

        $this->assertTrue($refuse, 'Le second verrou concurrent aurait dû être refusé (NOWAIT).');
    }
}
