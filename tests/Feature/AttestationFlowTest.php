<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\DossierAcademique;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\User;
use App\Modules\StudentDossiers\Models\GeneratedDocument;
use App\Modules\StudentDossiers\Services\Pdf\OfficialDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Guichet des attestations : recherche par CIN ou matricule,
 * vérification automatique, génération, impression. Maquettes =
 * gabarits du catalogue (import responsables = LOT 3).
 */
class AttestationFlowTest extends TestCase
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
            'name' => 'Scolarité', 'email' => 'sco.att@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);

        $this->etudiant = Etudiant::create([
            'cin' => 'BKATT001', 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => 'bkatt001@att.test', 'date_naissance' => '2004-01-01',
            'sexe' => 'F', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X',
            'ville' => 'Casa', 'code_postal' => '20000', 'annee_bac' => '2024',
            'password' => 'Etudiant@123',
        ]);
        $this->etudiant->definirStatut('valide');

        DossierAcademique::create([
            'etudiant_id' => $this->etudiant->id, 'matricule' => 'MAT-ATT-01',
            'date_archivage' => now()->toDateString(), 'statut' => 'actif',
        ]);

        $this->inscription = InscriptionAcademique::create([
            'etudiant_id' => $this->etudiant->id, 'annee_universitaire_id' => $this->annee->id,
            'niveau' => 'L1', 'statut' => 'en_cours', 'type' => 'premiere', 'regime' => 'initiale',
        ]);
    }

    public function test_recherche_par_cin_affiche_checklist(): void
    {
        $reponse = $this->actingAs($this->admin)
            ->get(route('admin.attestations.index', ['q' => 'bkatt001']))
            ->assertOk();

        $reponse->assertSee('Benali', false);
        $reponse->assertSee('Attestation de scolarité', false);
        $reponse->assertSee('Générer', false);
    }

    public function test_recherche_par_matricule_trouve(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.attestations.index', ['q' => 'MAT-ATT-01']))
            ->assertOk()
            ->assertSee('Benali', false);
    }

    public function test_recherche_inconnue_signalee(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.attestations.index', ['q' => 'ZZ999999']))
            ->assertOk()
            ->assertSee('Aucun étudiant trouvé', false);
    }

    public function test_recherche_hors_perimetre_404(): void
    {
        $autreFaculte = Faculte::create(['nom' => 'FMP', 'code' => 'FMP', 'description' => 'M', 'active' => true]);
        $adminFaculte = User::creerCompteBackOffice([
            'name' => 'Fac', 'email' => 'fac.att@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $autreFaculte->id,
        ], 'admin_faculte');

        $this->actingAs($adminFaculte)
            ->get(route('admin.attestations.index', ['q' => 'BKATT001']))
            ->assertNotFound();
    }

    public function test_generer_depuis_guichet_redirige_fiche(): void
    {
        $reponse = $this->actingAs($this->admin)->post(route('admin.attestations.generer'), [
            'etudiant_id' => $this->etudiant->id,
            'inscription_id' => $this->inscription->id,
            'type' => 'attestation_scolarite',
        ])->assertRedirect();

        $this->assertStringContainsString('/admin/documents-center/', $reponse->headers->get('Location'));
        $this->assertDatabaseHas('generated_documents', [
            'type' => 'attestation_scolarite',
        ]);
    }

    public function test_generer_ineligible_affiche_blocage(): void
    {
        $this->actingAs($this->admin)->post(route('admin.attestations.generer'), [
            'etudiant_id' => $this->etudiant->id,
            'inscription_id' => $this->inscription->id,
            'type' => 'quittance',
        ])->assertRedirect()->assertSessionHasErrors('document');
    }

    public function test_imprimer_pdf_en_ligne_trace(): void
    {
        $document = app(OfficialDocumentService::class)->generer(
            $this->etudiant, 'attestation_scolarite', $this->inscription->id, $this->admin->id
        );

        $reponse = $this->actingAs($this->admin)
            ->get(route('admin.documents-center.imprimer', $document))
            ->assertOk();

        $this->assertSame('application/pdf', $reponse->headers->get('Content-Type'));
        $this->assertStringContainsString('inline', (string) $reponse->headers->get('Content-Disposition'));
        $this->assertDatabaseHas('document_events', ['document_id' => $document->id, 'evenement' => 'imprime']);
        $this->assertDatabaseHas('historique_academique', [
            'etudiant_id' => $this->etudiant->id, 'action' => 'impression_document',
        ]);
    }

    public function test_imprimer_revoque_410(): void
    {
        $service = app(OfficialDocumentService::class);
        $document = $service->generer($this->etudiant, 'attestation_scolarite', $this->inscription->id, $this->admin->id);
        $service->revoquer($document, 'Motif de révocation suffisant', $this->admin->id);

        $this->actingAs($this->admin)
            ->get(route('admin.documents-center.imprimer', $document))
            ->assertStatus(410)
            ->assertSee('Document révoqué');
    }

    public function test_telecharger_revoque_410(): void
    {
        $service = app(OfficialDocumentService::class);
        $document = $service->generer($this->etudiant, 'attestation_scolarite', $this->inscription->id, $this->admin->id);
        $service->revoquer($document, 'Motif de révocation suffisant', $this->admin->id);

        $this->actingAs($this->admin)
            ->get(route('admin.documents-center.telecharger', $document))
            ->assertStatus(410);
    }

    public function test_show_document_revoque_affiche_motif_et_desactive_actions(): void
    {
        $service = app(OfficialDocumentService::class);
        $document = $service->generer($this->etudiant, 'attestation_scolarite', $this->inscription->id, $this->admin->id);
        $service->revoquer($document, 'Motif sérieux et obligatoire', $this->admin->id);

        $reponse = $this->actingAs($this->admin)
            ->get(route('admin.documents-center.show', $document))
            ->assertOk();

        $reponse->assertSee('Motif sérieux et obligatoire');
        $reponse->assertSee('Impression désactivée (document révoqué)');
        $reponse->assertSee('Téléchargement désactivé (document révoqué)');
        $reponse->assertDontSee(route('admin.documents-center.imprimer', $document));
    }

    public function test_imprimer_sans_permission_403(): void
    {
        $consultation = User::creerCompteBackOffice([
            'name' => 'Consult', 'email' => 'consult.att@universite.test', 'password' => 'Motdepasse@123',
        ], 'consultation');

        $document = app(OfficialDocumentService::class)->generer(
            $this->etudiant, 'attestation_scolarite', $this->inscription->id, $this->admin->id
        );

        // consultation : document.view seul, pas document.print.
        $this->actingAs($consultation)
            ->get(route('admin.documents-center.imprimer', $document))
            ->assertForbidden();
    }

    public function test_nav_affiche_guichet_attestations(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Attestations', false)
            ->assertSee(route('admin.attestations.index'), false);
    }

    public function test_generation_attestation_avec_choix_langue_ar_et_fr(): void
    {
        $reponse = $this->actingAs($this->admin)->post(route('admin.attestations.generer'), [
            'etudiant_id' => $this->etudiant->id,
            'inscription_id' => $this->inscription->id,
            'type' => 'attestation_inscription',
            'langue' => 'ar',
        ]);

        $reponse->assertRedirect();
        $doc = GeneratedDocument::where('type', 'attestation_inscription')->latest('id')->first();
        $this->assertNotNull($doc);
        $this->assertSame('ar', $doc->snapshot_donnees['langue']);
        $this->assertStringContainsString('ATI-', $doc->reference);
    }
}
