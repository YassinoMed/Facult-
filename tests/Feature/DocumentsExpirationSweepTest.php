<?php

namespace Tests\Feature;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Modules\StudentDossiers\Events\DocumentExpired;
use App\Modules\StudentDossiers\Models\StudentDocument;
use App\Modules\StudentDossiers\Models\StudentDossier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Expiration documentaire (§22.15) — commande planifiée quotidiennement :
 * bascule `expired` sans suppression, ne touche ni les documents valides
 * ni ceux sans échéance, et reste idempotente en exécution répétée.
 */
class DocumentsExpirationSweepTest extends TestCase
{
    use RefreshDatabase;

    private StudentDossier $dossier;

    protected function setUp(): void
    {
        parent::setUp();

        $faculte = Faculte::create([
            'nom' => 'Faculté des Sciences', 'code' => 'FST', 'description' => 'Test', 'active' => true,
        ]);

        $etudiant = Etudiant::create([
            'cin' => 'EXP-SWEEP-01',
            'nom' => 'Test',
            'prenom' => 'Expiration',
            'email' => 'exp.sweep@example.com',
            'sexe' => 'F',
            'faculte_id' => $faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => 'Rue X',
            'ville' => 'Casablanca',
            'code_postal' => '20000',
            'annee_bac' => '2024',
            'password' => 'motdepasse1',
        ]);

        $this->dossier = StudentDossier::create([
            'etudiant_id' => $etudiant->id,
            'reference' => 'DOS-SWEEP-01',
            'status' => 'open',
        ]);
    }

    public function test_bascule_uniquement_les_documents_perimes_et_reste_idempotent(): void
    {
        Event::fake([DocumentExpired::class]);

        $perime = $this->creerDocument('validated', now()->subDay());
        $valide = $this->creerDocument('validated', now()->addYear());
        $sansEcheance = $this->creerDocument('validated', null);

        $this->artisan('documents:marquer-expires')->assertSuccessful();

        $this->assertSame('expired', $perime->fresh()->status);
        $this->assertSame('validated', $valide->fresh()->status);
        $this->assertSame('validated', $sansEcheance->fresh()->status);
        $this->assertDatabaseHas('student_documents', ['id' => $perime->id]);

        // Deuxième exécution : aucun nouvel événement, aucun changement.
        $this->artisan('documents:marquer-expires')->assertSuccessful();

        Event::assertDispatchedTimes(DocumentExpired::class, 1);
        $this->assertSame('expired', $perime->fresh()->status);
    }

    private function creerDocument(string $statut, ?\DateTimeInterface $expiresAt): StudentDocument
    {
        return StudentDocument::create([
            'student_dossier_id' => $this->dossier->id,
            'category' => 'administrative',
            'document_type' => 'autre',
            'title' => 'Document de test',
            'original_filename' => 'document.pdf',
            'storage_path' => 'documents/prive/test.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size' => 10,
            'sha256' => str_repeat('a', 64),
            'status' => $statut,
            'expires_at' => $expiresAt,
        ]);
    }
}
