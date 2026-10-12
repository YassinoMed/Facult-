<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\DocumentEvent;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\User;
use App\Modules\StudentDossiers\Models\GeneratedDocument;
use App\Modules\StudentDossiers\Services\Pdf\OfficialDocumentService;
use App\Services\Documents\DocumentWorkflowService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Circuit de traitement documentaire (généré → approuvé → émis →
 * envoyé) : transitions gardées, audit + événements + notifications à
 * chaque étape, périmètre faculté, données étendues opt-in.
 */
class DocumentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Etudiant $etudiant;

    private InscriptionAcademique $inscription;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.student-dossiers.active' => true]);
        Storage::fake('private');

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.wf@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);

        $this->etudiant = Etudiant::create([
            'cin' => 'BKWF001', 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => 'bkwf001@wf.test', 'date_naissance' => '2004-01-01',
            'sexe' => 'F', 'faculte_id' => $this->faculte->id,
            'telephone' => '+212600000001',
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X',
            'ville' => 'Casa', 'code_postal' => '20000', 'annee_bac' => '2024',
            'password' => 'Etudiant@123',
        ]);
        $this->etudiant->definirStatut('valide');

        $this->inscription = InscriptionAcademique::create([
            'etudiant_id' => $this->etudiant->id, 'annee_universitaire_id' => $this->annee->id,
            'niveau' => 'L1', 'statut' => 'en_cours', 'type' => 'premiere', 'regime' => 'initiale',
        ]);
    }

    private function genererScolarite(): GeneratedDocument
    {
        return app(OfficialDocumentService::class)->generer(
            $this->etudiant, 'attestation_scolarite', $this->inscription->id, $this->admin->id
        );
    }

    private function circuit(): DocumentWorkflowService
    {
        return app(DocumentWorkflowService::class);
    }

    // ── Circuit nominal ──────────────────────────────────────────

    public function test_circuit_complet_approuver_emettre_envoyer(): void
    {
        $document = $this->genererScolarite();

        $approuve = $this->circuit()->approuver($document, $this->admin->id);
        $this->assertNotNull($approuve->approuve_le);
        $this->assertSame($this->admin->id, (int) $approuve->approuve_par);

        $emis = $this->circuit()->emettre($approuve, $this->admin->id);
        $this->assertNotNull($emis->emis_le);

        $envoye = $this->circuit()->envoyer($emis, $this->admin->id);
        $this->assertSame('valide', $envoye->statut);

        $this->assertDatabaseHas('document_events', ['document_id' => $document->id, 'evenement' => 'approuve']);
        $this->assertDatabaseHas('document_events', ['document_id' => $document->id, 'evenement' => 'emis']);
        $this->assertDatabaseHas('document_events', ['document_id' => $document->id, 'evenement' => 'envoye']);
        $this->assertDatabaseHas('historique_academique', [
            'etudiant_id' => $this->etudiant->id, 'action' => 'document.approuve',
        ]);
        $this->assertDatabaseHas('historique_academique', [
            'etudiant_id' => $this->etudiant->id, 'action' => 'document.emis',
        ]);
        $this->assertDatabaseHas('historique_academique', [
            'etudiant_id' => $this->etudiant->id, 'action' => 'document.envoye',
        ]);
        $this->assertDatabaseHas('notifications', ['type' => 'document.emis']);
        $this->assertDatabaseHas('notifications', ['type' => 'document.envoye']);
    }

    public function test_emettre_exige_approbation(): void
    {
        $document = $this->genererScolarite();

        try {
            $this->circuit()->emettre($document, $this->admin->id);
            $this->fail('Émission sans approbation aurait dû échouer.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('non approuvé', $e->getMessage());
        }

        $this->assertNull($document->fresh()->emis_le);
    }

    public function test_envoyer_exige_emission(): void
    {
        $document = $this->circuit()->approuver($this->genererScolarite(), $this->admin->id);

        try {
            $this->circuit()->envoyer($document, $this->admin->id);
            $this->fail('Envoi sans émission aurait dû échouer.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('non émis', $e->getMessage());
        }
    }

    public function test_approuver_document_revoque_refuse(): void
    {
        $document = $this->genererScolarite();
        app(OfficialDocumentService::class)->revoquer($document, 'Motif de révocation suffisant', $this->admin->id);

        try {
            $this->circuit()->approuver($document->fresh(), $this->admin->id);
            $this->fail('Approbation d’un révoqué aurait dû échouer.');
        } catch (DomainException) {
            $this->assertTrue(true);
        }
    }

    public function test_transitions_idempotentes(): void
    {
        $document = $this->genererScolarite();

        $premier = $this->circuit()->approuver($document, $this->admin->id);
        $second = $this->circuit()->approuver($premier, $this->admin->id);

        $this->assertSame($premier->id, $second->id);
        $this->assertSame(1, DocumentEvent::where('document_id', $document->id)->where('evenement', 'approuve')->count());
    }

    // ── HTTP : permissions, périmètre ────────────────────────────

    public function test_http_circuit_bout_en_bout(): void
    {
        $document = $this->genererScolarite();

        $this->actingAs($this->admin)
            ->post(route('admin.documents-center.approuver', $document))
            ->assertRedirect();
        $this->assertNotNull($document->fresh()->approuve_le);

        $this->actingAs($this->admin)
            ->post(route('admin.documents-center.emettre', $document))
            ->assertRedirect();
        $this->assertNotNull($document->fresh()->emis_le);

        $this->actingAs($this->admin)
            ->post(route('admin.documents-center.envoyer', $document))
            ->assertRedirect();
        $this->assertDatabaseHas('document_events', ['document_id' => $document->id, 'evenement' => 'envoye']);
    }

    public function test_http_emettre_sans_approbation_affiche_erreur(): void
    {
        $document = $this->genererScolarite();

        $this->actingAs($this->admin)
            ->post(route('admin.documents-center.emettre', $document))
            ->assertRedirect()
            ->assertSessionHasErrors('document');
    }

    public function test_http_sans_permission_403(): void
    {
        $agent = User::creerCompteBackOffice([
            'name' => 'Agent', 'email' => 'agent.wf@universite.test', 'password' => 'Motdepasse@123',
        ], 'agent_scolarite');

        $document = $this->genererScolarite();

        // agent_scolarite : document.view/generate/download/print, ni
        // approve ni issue ni send.
        $this->actingAs($agent)->post(route('admin.documents-center.approuver', $document))->assertForbidden();
        $this->actingAs($agent)->post(route('admin.documents-center.emettre', $document))->assertForbidden();
        $this->actingAs($agent)->post(route('admin.documents-center.envoyer', $document))->assertForbidden();
    }

    public function test_http_hors_perimetre_404(): void
    {
        $autreFaculte = Faculte::create(['nom' => 'FMP', 'code' => 'FMP', 'description' => 'M', 'active' => true]);
        $adminFaculte = User::creerCompteBackOffice([
            'name' => 'Fac', 'email' => 'fac.wf@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $autreFaculte->id,
        ], 'admin_faculte');

        $document = $this->genererScolarite();

        // admin_faculte possède document.issue : la garde de périmètre
        // (404 silencieux) est bien celle qui s'applique.
        $this->actingAs($adminFaculte)
            ->post(route('admin.documents-center.emettre', $document))
            ->assertNotFound();
    }

    // ── Données étendues ─────────────────────────────────────────

    public function test_donnees_etendues_nouveaux_types(): void
    {
        $document = $this->genererScolarite();
        $snapshot = $document->snapshot_donnees;

        $this->assertSame('+212600000001', $snapshot['telephone']);
        $this->assertSame('bkwf001@wf.test', $snapshot['email']);
        $this->assertNotEmpty($snapshot['dateInscription']);
        $this->assertSame('—', $snapshot['mention']);
    }

    public function test_donnees_types_historiques_inchangees(): void
    {
        $document = app(OfficialDocumentService::class)->generer(
            $this->etudiant, 'releve_notes', $this->inscription->id, $this->admin->id
        );
        $snapshot = $document->snapshot_donnees;

        $this->assertArrayNotHasKey('telephone', $snapshot);
        $this->assertArrayNotHasKey('mention', $snapshot);
        $this->assertArrayNotHasKey('dateInscription', $snapshot);
    }

    // ── Tableau de bord ──────────────────────────────────────────

    public function test_centre_affiche_compteurs_circuit(): void
    {
        $this->genererScolarite();

        $reponse = $this->actingAs($this->admin)->get(route('admin.documents-center.index'))->assertOk();

        $reponse->assertSee('À approuver', false);
        $reponse->assertSee('À émettre', false);
    }
}
