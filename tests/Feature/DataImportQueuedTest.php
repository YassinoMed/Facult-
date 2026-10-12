<?php

namespace Tests\Feature;

use App\Jobs\ExecuteDataImportJob;
use App\Models\Faculte;
use App\Models\ImportJob;
use App\Models\ImportReport;
use App\Modules\Schedule\Models\Room;
use App\Services\DataExchange\DataImportService;
use App\Services\DataExchange\DefinitionRegistry;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ActAsScolarite;
use Tests\TestCase;

/**
 * C2 (LOT 3) : gros imports DataExchange → file d'attente avec staging
 * persisté (Phase 17). Seuil existant `integrations.queue_threshold`.
 * Synchrone sous le seuil : compatibilité HTTP/UX inchangée.
 */
class DataImportQueuedTest extends TestCase
{
    use ActAsScolarite, RefreshDatabase;

    private function preparerGrosImport(): string
    {
        // Dépendance métier de la définition `salles` : faculté existante.
        Faculte::create(['nom' => 'FA', 'code' => 'FA', 'description' => 'T', 'active' => true]);
        $this->scolarite('admin_scolarite');

        // Seuil dépassé dès la première ligne : tout import realiste file.
        config(['integrations.queue_threshold' => 0]);

        $fichier = UploadedFile::fake()->createWithContent(
            'salles.csv',
            "code;nom;capacite\nA1;Salle A1;40\nA2;Salle A2;50\n",
            'text/csv'
        );

        $preview = $this->post(route('admin.donnees.import.preview', 'salles'), ['fichier' => $fichier])
            ->assertOk();

        return (string) $preview->viewData('token');
    }

    public function test_gros_volume_mis_en_file_avec_staging_persiste(): void
    {
        Queue::fake();
        $token = $this->preparerGrosImport();

        $this->post(route('admin.donnees.import.confirmer', 'salles'), [
            'token' => $token, 'mode' => 'creer',
        ])->assertRedirect(route('admin.donnees.historique'));

        // Phase 1 : staging Phase 17 + dispatch — rien écrit en métier.
        $job = ImportJob::where('entite', 'salles')->firstOrFail();
        $this->assertSame('en_file', $job->statut);
        $this->assertSame(2, (int) $job->total_lignes);
        $this->assertSame(2, $job->lignes()->count());
        $this->assertSame(0, Room::whereIn('code', ['A1', 'A2'])->count());
        Queue::assertPushed(ExecuteDataImportJob::class, fn ($tache) => $tache->jobId === $job->id);
    }

    public function test_execution_du_job_persiste_et_notifie(): void
    {
        $token = $this->preparerGrosImport();
        // Driver sync (défaut des tests) : dispatch = exécution immédiate.
        $this->post(route('admin.donnees.import.confirmer', 'salles'), [
            'token' => $token, 'mode' => 'creer',
        ])->assertRedirect();

        $job = ImportJob::where('entite', 'salles')->firstOrFail();
        $this->assertSame('termine', $job->fresh()?->statut ?? $job->statut);
        $this->assertSame(2, Room::whereIn('code', ['A1', 'A2'])->count());
        $this->assertDatabaseHas('notifications', [
            'user_id' => $job->auteur_id, 'type' => 'import_termine',
        ]);
    }

    public function test_double_dispatch_ne_duplique_pas_l_execution(): void
    {
        $token = $this->preparerGrosImport();

        $this->post(route('admin.donnees.import.confirmer', 'salles'), [
            'token' => $token, 'mode' => 'creer',
        ])->assertRedirect();

        $job = ImportJob::where('entite', 'salles')->firstOrFail();

        $handler = new ExecuteDataImportJob($job->id);
        $imports = app(DataImportService::class);
        $definitions = app(DefinitionRegistry::class);
        $notifications = app(NotificationService::class);

        $handler->handle($imports, $definitions, $notifications);
        $handler->handle($imports, $definitions, $notifications);

        // Une seule exécution (garde atomique), aucune ligne dupliquee.
        $this->assertSame(2, Room::whereIn('code', ['A1', 'A2'])->count());
        $this->assertSame(1, ImportReport::where('type', 'data.salles')->count());
    }

    public function test_sous_le_seuil_reste_synchrone(): void
    {
        Queue::fake();
        Faculte::create(['nom' => 'FA', 'code' => 'FA', 'description' => 'T', 'active' => true]);
        $this->scolarite('admin_scolarite');
        config(['integrations.queue_threshold' => 500]);

        $fichier = UploadedFile::fake()->createWithContent(
            'salles.csv', "code;nom;capacite\nZ9;Salle Z9;30\n", 'text/csv'
        );

        $preview = $this->post(route('admin.donnees.import.preview', 'salles'), ['fichier' => $fichier])
            ->assertOk();

        $this->post(route('admin.donnees.import.confirmer', 'salles'), [
            'token' => (string) $preview->viewData('token'), 'mode' => 'creer',
        ])->assertOk();

        $this->assertSame(0, ImportJob::count());
        $this->assertSame(1, Room::where('code', 'Z9')->count());
        Queue::assertNothingPushed();
    }
}
