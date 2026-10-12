<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleImport;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\ActAsApiClient;
use Tests\TestCase;

/**
 * API d'import des plannings (§13-17) : auth, scope, dry-run,
 * confirmation transactionnelle, rollback, throttle.
 */
class ScheduleImportApiTest extends TestCase
{
    use ActAsApiClient, RefreshDatabase;

    protected Faculte $faculte;

    protected Group $groupe;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.schedule.active' => true]);

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences', 'code' => 'FST',
            'description' => 'Sciences', 'active' => true,
        ]);
        $this->groupe = Group::create([
            'code' => 'GI2-A', 'nom' => 'GI2 A', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'filiere' => 'Informatique', 'actif' => true,
        ]);
        AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30', 'active' => true,
        ]);
        Matiere::create([
            'code' => 'WEB101', 'nom' => 'Développement Web', 'coefficient' => 2,
            'credits' => 6, 'niveau' => 'L1', 'semestre' => 1,
        ]);
        Teacher::create(['nom' => 'Enseignant', 'prenom' => 'A']);
        Room::create(['code' => 'B12']);
    }

    protected function csv(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('p.csv', implode("\n", [
            'academic_year,semester,group,day,start_time,end_time,course,teacher,room,type',
            '2026/2027,S1,GI2-A,monday,08:00,10:00,WEB101,Enseignant A,B12,course',
        ]), 'text/csv');
    }

    public function test_sans_token_401_et_sans_scope_403(): void
    {
        $this->postJson('/api/v1/schedules/import/preview', [])->assertUnauthorized();

        [, $token] = $this->clientApi(['students:read']);
        $this->postApi('/api/v1/schedules/import/preview', ['fichier' => $this->csv()], $token)
            ->assertForbidden();
    }

    public function test_dry_run_sans_ecriture(): void
    {
        [, $token] = $this->clientApi(['schedules:import']);

        $reponse = $this->postApi('/api/v1/schedules/import/preview', [
            'fichier' => $this->csv(), 'dry_run' => true,
        ], $token);

        $reponse->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('total_rows', 1)
            ->assertJsonPath('errors', 0);

        $this->assertSame(0, Schedule::count());
        $this->assertSame(0, ScheduleImport::count());
    }

    public function test_confirm_importe_transactionnel(): void
    {
        [, $token] = $this->clientApi(['schedules:import']);

        $reponse = $this->postApi('/api/v1/schedules/import/confirm', [
            'fichier' => $this->csv(), 'mode' => 'creer',
        ], $token);

        $reponse->assertOk()->assertJsonPath('importes', 1);
        $this->assertSame(1, Schedule::firstOrFail()->slots()->count());

        $rapport = ScheduleImport::firstOrFail();
        $this->assertSame('api', $rapport->integration);
        $this->assertSame('completed', $rapport->status);
    }

    public function test_confirm_invalide_et_remplacement_sans_confirmation(): void
    {
        [, $token] = $this->clientApi(['schedules:import']);

        // Fichier en conflit avec lui-même.
        $doublon = UploadedFile::fake()->createWithContent('p.csv', implode("\n", [
            'academic_year,semester,group,day,start_time,end_time,course,teacher,room,type',
            '2026/2027,S1,GI2-A,monday,08:00,10:00,WEB101,Enseignant A,B12,course',
            '2026/2027,S1,GI2-A,monday,09:00,11:00,WEB101,Enseignant A,B12,course',
        ]), 'text/csv');

        $this->postApi('/api/v1/schedules/import/confirm', [
            'fichier' => $doublon, 'mode' => 'creer',
        ], $token)->assertStatus(422);
        $this->assertSame(0, Schedule::count());

        // Import OK puis remplacement sans confirmation → 422, intact.
        $this->postApi('/api/v1/schedules/import/confirm', [
            'fichier' => $this->csv(), 'mode' => 'creer',
        ], $token)->assertOk();

        $this->postApi('/api/v1/schedules/import/confirm', [
            'fichier' => $this->csv(), 'mode' => 'remplacer',
        ], $token)->assertStatus(422);
        $this->assertSame(1, Schedule::count());
    }
}
