<?php

namespace Tests\Feature;

use App\Jobs\GeneratePopulationExport;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\ImportReport;
use App\Models\User;
use App\Services\Exports\ListeEtudiantsService;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Exports différés (§2.7-2.11, §2.24) : seuil, job, états, historique,
 * téléchargement propriétaire, expiration.
 */
class AsyncExportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.async@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->creerEtudiant('ASY00001');
    }

    public function test_petit_export_reste_synchrone(): void
    {
        Queue::fake();

        $this->actingAs($this->admin)->get(route('admin.listes.exporter', ['format' => 'csv']))
            ->assertOk();

        Queue::assertNotPushed(GeneratePopulationExport::class);
        $this->assertSame(0, ImportReport::where('type', 'listes.etudiants')->count());
    }

    public function test_job_genere_fichier_notifie_et_historise(): void
    {
        $rapport = ImportReport::create([
            'user_id' => $this->admin->id, 'integration' => 'listes', 'direction' => 'export',
            'type' => 'listes.etudiants', 'format' => 'csv', 'external_reference' => 'EXP-TEST-ASYNC',
            'status' => 'pending', 'preview' => ['filtres' => []],
            'expires_at' => now()->addHours(168),
        ]);

        app(GeneratePopulationExport::class, [
            'rapportId' => $rapport->id, 'userId' => $this->admin->id, 'format' => 'csv',
            'filtres' => [], 'colonnes' => ['matricule', 'nom'], 'regroupement' => null,
        ])->handle(app(ListeEtudiantsService::class), app(NotificationService::class));

        $rapport->refresh();
        $this->assertSame('completed', $rapport->status);
        $this->assertSame(1, $rapport->total_rows);
        Storage::disk('local')->assertExists($rapport->file_path);
        $this->assertDatabaseHas('notifications', ['user_id' => $this->admin->id, 'type' => 'export.listes']);

        $this->actingAs($this->admin)->get(route('admin.listes.exports'))->assertOk()
            ->assertSee('EXP-TEST-ASYNC');
        $this->actingAs($this->admin)->get(route('admin.listes.exports.download', $rapport))->assertOk();
    }

    public function test_job_regroupe_par_groupe(): void
    {
        $rapport = ImportReport::create([
            'user_id' => $this->admin->id, 'integration' => 'listes', 'direction' => 'export',
            'type' => 'listes.etudiants', 'format' => 'csv', 'external_reference' => 'EXP-TEST-GRP',
            'status' => 'pending', 'preview' => [],
            'expires_at' => now()->addHours(168),
        ]);

        app(GeneratePopulationExport::class, [
            'rapportId' => $rapport->id, 'userId' => $this->admin->id, 'format' => 'csv',
            'filtres' => [], 'colonnes' => null, 'regroupement' => 'groupe',
        ])->handle(app(ListeEtudiantsService::class), app(NotificationService::class));

        $contenu = Storage::disk('local')->get($rapport->fresh()->file_path);
        $this->assertStringContainsString('section', strtolower(explode("\n", $contenu)[0]));
    }

    public function test_expiration_purgee_au_telechargement(): void
    {
        $rapport = ImportReport::create([
            'user_id' => $this->admin->id, 'integration' => 'listes', 'direction' => 'export',
            'type' => 'listes.etudiants', 'format' => 'csv', 'external_reference' => 'EXP-TEST-EXP',
            'status' => 'completed', 'file_path' => 'listes/exports/expire.csv',
            'filename' => 'expire.csv', 'expires_at' => now()->subHour(),
        ]);
        Storage::disk('local')->put('listes/exports/expire.csv', 'x');

        $this->actingAs($this->admin)->get(route('admin.listes.exports.download', $rapport))->assertNotFound();
        $this->assertSame('expired', $rapport->fresh()->status);
        Storage::disk('local')->assertMissing('listes/exports/expire.csv');
    }

    public function test_telechargement_reserve_proprietaire(): void
    {
        $rapport = ImportReport::create([
            'user_id' => $this->admin->id, 'integration' => 'listes', 'direction' => 'export',
            'type' => 'listes.etudiants', 'format' => 'csv', 'external_reference' => 'EXP-TEST-PROP',
            'status' => 'completed', 'file_path' => 'listes/exports/prop.csv',
            'filename' => 'prop.csv', 'expires_at' => now()->addHours(168),
        ]);
        Storage::disk('local')->put('listes/exports/prop.csv', 'x');

        $autre = User::creerCompteBackOffice([
            'name' => 'Autre', 'email' => 'autre.async@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->actingAs($autre)->get(route('admin.listes.exports.download', $rapport))->assertForbidden();
    }

    private function creerEtudiant(string $cin): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin, 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => strtolower($cin).'@async.test',
            'date_naissance' => '2004-01-01', 'sexe' => 'F', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
        $etudiant->definirStatut('valide');
        $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id, 'niveau' => 'L1', 'statut' => 'en_cours',
        ]);

        return $etudiant;
    }
}
