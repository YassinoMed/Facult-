<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\User;
use App\Modules\StudentDossiers\Models\StudentDocument;
use App\Modules\StudentDossiers\Models\StudentDossier;
use App\Services\DocumentsRequisService;
use App\Services\InscriptionService;
use App\Services\Verification\VerificationEngine;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pièces obligatoires : matrice par type, réutilisation, états GED,
 * blocage final backend (bypass refusé), bug jointure corrigé.
 */
class DocumentsRequisTest extends TestCase
{
    use RefreshDatabase;

    private User $scolarite;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->scolarite = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.doc@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);

        config()->set('inscriptions.documents_requis_par_type', [
            'premiere' => ['cin', 'photo_identite'],
            'reinscription' => ['cin'],
            'transfert' => [],
        ]);
    }

    public function test_matrice_par_type_et_reutilisation(): void
    {
        $service = app(DocumentsRequisService::class);

        $this->assertSame(['cin', 'photo_identite'], $service->requisPour('premiere'));
        $this->assertSame(['cin'], $service->requisPour('reinscription'));
        $this->assertSame([], $service->requisPour('transfert'));

        $etudiant = $this->creerEtudiant('DOC00001');
        $this->deposer($etudiant, 'cin', 'validated');

        // CIN valide réutilisée : seule la photo manque (première).
        $bilan = $service->verifier($etudiant, 'premiere');
        $this->assertFalse($bilan['complet']);
        $this->assertSame(['photo_identite'], $bilan['manquants']);
        $this->assertSame('1 / 2 validés', $bilan['progression']);

        // Réinscription : complète (CIN suffit, déjà valide).
        $this->assertTrue($service->verifier($etudiant, 'reinscription')['complet']);
    }

    public function test_etats_bloquants_expire_rejete_attente(): void
    {
        $service = app(DocumentsRequisService::class);
        $etudiant = $this->creerEtudiant('DOC00002');
        $this->deposer($etudiant, 'cin', 'validated');
        $this->deposer($etudiant, 'photo_identite', 'pending');

        $bilan = $service->verifier($etudiant, 'premiere');
        $this->assertFalse($bilan['complet']);
        $this->assertSame(['photo_identite'], $bilan['en_attente']);

        // Upload ≠ validé : rejet puis expiration bloquent aussi.
        StudentDocument::where('document_type', 'photo_identite')->update(['status' => 'rejected', 'rejected_reason' => 'Illisible.']);
        $this->assertSame(['photo_identite'], $service->verifier($etudiant, 'premiere')['rejetes']);

        StudentDocument::where('document_type', 'photo_identite')->update(['status' => 'validated', 'expires_at' => now()->subDay()]);
        $this->assertSame(['photo_identite'], $service->verifier($etudiant, 'premiere')['expires']);
    }

    public function test_validation_bloquee_puis_autorisee(): void
    {
        $etudiant = $this->creerEtudiant('DOC00003');
        $inscription = $this->inscrire($etudiant, 'premiere');

        try {
            app(InscriptionService::class)->transiter($inscription, 'valide', $this->scolarite->id);
            $this->fail('Validation sans pièces aurait dû être bloquée.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('REGISTRATION_DOCUMENTS_INCOMPLETE', $e->getMessage());
        }

        $this->assertSame('en_cours', $inscription->fresh()->statut);

        $this->deposer($etudiant, 'cin', 'validated');
        $this->deposer($etudiant, 'photo_identite', 'validated');
        app(InscriptionService::class)->transiter($inscription->fresh(), 'valide', $this->scolarite->id);

        $this->assertSame('valide', $inscription->fresh()->statut);
    }

    public function test_matrice_vide_transition_autorisee(): void
    {
        config()->set('inscriptions.documents_requis_par_type', ['premiere' => [], 'reinscription' => [], 'transfert' => []]);

        $etudiant = $this->creerEtudiant('DOC00004');
        $inscription = $this->inscrire($etudiant, 'premiere');
        app(InscriptionService::class)->transiter($inscription, 'valide', $this->scolarite->id);

        $this->assertSame('valide', $inscription->fresh()->statut);
    }

    public function test_moteur_verification_signale_statuts(): void
    {
        config()->set('inscriptions.documents_requis_par_type', ['premiere' => ['cin']]);

        $etudiant = $this->creerEtudiant('DOC00005');
        $this->inscrire($etudiant, 'premiere');
        $this->deposer($etudiant, 'cin', 'rejected', 'Illisible.');

        $anomalies = array_filter(
            app(VerificationEngine::class)->verifier($etudiant)->anomalies,
            fn ($a) => $a->regle === 'documents.statuts'
        );

        $this->assertNotEmpty($anomalies);
    }

    private function creerEtudiant(string $cin): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin, 'nom' => 'Benali', 'prenom' => 'Sara', 'email' => strtolower($cin).'@doc.test',
            'date_naissance' => '2004-01-01', 'sexe' => 'F', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
        $etudiant->definirStatut('valide');

        return $etudiant;
    }

    private function inscrire(Etudiant $etudiant, string $type): InscriptionAcademique
    {
        return $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id, 'niveau' => 'L1',
            'statut' => 'en_cours', 'type' => $type,
        ]);
    }

    private function deposer(Etudiant $etudiant, string $type, string $status, ?string $motif = null): void
    {
        $dossier = StudentDossier::firstOrCreate(
            ['etudiant_id' => $etudiant->id],
            ['reference' => 'DOS-'.$etudiant->id.'-'.random_int(1000, 9999), 'status' => 'open']
        );

        StudentDocument::create([
            'student_dossier_id' => $dossier->id, 'category' => 'identity', 'document_type' => $type,
            'title' => $type, 'original_filename' => $type.'.pdf', 'storage_path' => 'prive/'.$type.'.pdf',
            'mime_type' => 'application/pdf', 'extension' => 'pdf', 'size' => 10,
            'sha256' => hash('sha256', $type.$etudiant->id), 'status' => $status,
            'rejected_reason' => $motif,
        ]);
    }
}
