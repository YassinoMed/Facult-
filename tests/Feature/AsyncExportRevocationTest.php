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
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Révocation de permissions et exports différés (§24.3) : l'autorisation
 * `data.export` du demandeur est réévaluée à l'exécution du job — aucun
 * export sensible n'est produit pour un demandeur supprimé, désactivé ou
 * dont la permission a été retirée (même convention que
 * ExecuterAffectationMasseLot). Message d'échec générique, sans PII.
 */
class AsyncExportRevocationTest extends TestCase
{
    use RefreshDatabase;

    private User $scolarite;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->scolarite = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.revo@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->creerEtudiant('REV00001');
    }

    public function test_export_autorise_genere_fichier_et_notification(): void
    {
        $rapport = $this->creerRapport($this->scolarite);

        $this->executer($rapport);

        $rapport->refresh();
        $this->assertSame('completed', $rapport->status);
        $this->assertSame(1, $rapport->total_rows);
        Storage::disk('local')->assertExists($rapport->file_path);
        $this->assertDatabaseHas('notifications', ['user_id' => $this->scolarite->id, 'type' => 'export.listes']);
    }

    public function test_permission_revoquee_avant_execution_passe_le_rapport_en_echec_sans_fichier(): void
    {
        $demandeur = User::creerCompteBackOffice([
            'name' => 'Agent', 'email' => 'agent.revo@universite.test', 'password' => 'Motdepasse@123',
        ], 'agent_scolarite');
        $this->creerEtudiant('REV00002');

        // Révocation simulée entre la demande et l'exécution du job.
        $demandeur->definirRole('consultation');

        $rapport = $this->creerRapport($demandeur);
        $this->executer($rapport);

        $rapport->refresh();
        $this->assertSame('failed', $rapport->status);
        $this->assertNull($rapport->file_path);
        $this->assertSame(
            ['Demandeur introuvable, désactivé ou permission data.export retirée.'],
            $rapport->errors,
        );
        Storage::disk('local')->assertMissing("listes/exports/{$rapport->id}.csv");
        $this->assertDatabaseMissing('notifications', ['type' => 'export.listes']);
    }

    public function test_demandeur_supprime_passe_le_rapport_en_echec_sans_fichier(): void
    {
        $demandeur = User::creerCompteBackOffice([
            'name' => 'Partant', 'email' => 'partant.revo@universite.test', 'password' => 'Motdepasse@123',
        ], 'agent_scolarite');
        $this->creerEtudiant('REV00003');

        $rapport = $this->creerRapport($demandeur);
        $userId = $demandeur->id;
        $demandeur->delete();

        app(GeneratePopulationExport::class, [
            'rapportId' => $rapport->id, 'userId' => $userId, 'format' => 'csv',
            'filtres' => [], 'colonnes' => ['matricule', 'nom'], 'regroupement' => null,
        ])->handle(app(ListeEtudiantsService::class), app(NotificationService::class));

        $rapport->refresh();
        $this->assertSame('failed', $rapport->status);
        $this->assertNull($rapport->file_path);
        Storage::disk('local')->assertMissing("listes/exports/{$rapport->id}.csv");
        $this->assertDatabaseMissing('notifications', ['type' => 'export.listes']);
    }

    public function test_demandeur_desactive_passe_le_rapport_en_echec_sans_fichier(): void
    {
        $demandeur = User::creerCompteBackOffice([
            'name' => 'Suspendu', 'email' => 'suspendu.revo@universite.test', 'password' => 'Motdepasse@123',
        ], 'agent_scolarite');
        $this->creerEtudiant('REV00004');
        $demandeur->update(['actif' => false]);

        $rapport = $this->creerRapport($demandeur);
        $this->executer($rapport);

        $rapport->refresh();
        $this->assertSame('failed', $rapport->status);
        $this->assertNull($rapport->file_path);
        Storage::disk('local')->assertMissing("listes/exports/{$rapport->id}.csv");
        $this->assertDatabaseMissing('notifications', ['type' => 'export.listes']);
    }

    public function test_fichier_complete_restant_inaccessible_apres_revocation_et_expiration(): void
    {
        $demandeur = User::creerCompteBackOffice([
            'name' => 'Agent deux', 'email' => 'agent2.revo@universite.test', 'password' => 'Motdepasse@123',
        ], 'agent_scolarite');
        $autre = User::creerCompteBackOffice([
            'name' => 'Autre', 'email' => 'autre.revo@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $rapport = ImportReport::create([
            'user_id' => $demandeur->id, 'integration' => 'listes', 'direction' => 'export',
            'type' => 'listes.etudiants', 'format' => 'csv', 'external_reference' => 'EXP-REVO-DONE',
            'status' => 'completed', 'file_path' => 'listes/exports/revo-done.csv',
            'filename' => 'revo-done.csv', 'expires_at' => now()->addHours(168),
        ]);
        Storage::disk('local')->put('listes/exports/revo-done.csv', 'contenu');

        // Avant révocation : le propriétaire autorisé télécharge normalement.
        $this->actingAs($demandeur)
            ->get(route('admin.listes.exports.download', $rapport))
            ->assertOk();

        // Après révocation (+ expiration) : refus serveur, jamais de fuite,
        // y compris pour un autre utilisateur autorisé (propriété exigée).
        $demandeur->definirRole('consultation');
        $rapport->update(['expires_at' => now()->subHour()]);

        $this->actingAs($demandeur)
            ->get(route('admin.listes.exports.download', $rapport))
            ->assertForbidden();
        $this->actingAs($autre)
            ->get(route('admin.listes.exports.download', $rapport))
            ->assertForbidden();
    }

    private function creerRapport(User $demandeur): ImportReport
    {
        return ImportReport::create([
            'user_id' => $demandeur->id, 'integration' => 'listes', 'direction' => 'export',
            'type' => 'listes.etudiants', 'format' => 'csv', 'external_reference' => 'EXP-REVO',
            'status' => 'pending', 'preview' => ['filtres' => []],
            'expires_at' => now()->addHours(168),
        ]);
    }

    private function executer(ImportReport $rapport): void
    {
        app(GeneratePopulationExport::class, [
            'rapportId' => $rapport->id, 'userId' => $rapport->user_id, 'format' => 'csv',
            'filtres' => [], 'colonnes' => ['matricule', 'nom'], 'regroupement' => null,
        ])->handle(app(ListeEtudiantsService::class), app(NotificationService::class));
    }

    private function creerEtudiant(string $cin): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin, 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => strtolower($cin).'@revo.test',
            'date_naissance' => '2004-01-01', 'sexe' => 'F',
            'faculte_id' => $this->faculte->id,
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
