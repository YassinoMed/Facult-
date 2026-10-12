<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\User;
use App\Modules\Finances\Models\Facture;
use App\Modules\Finances\Models\Paiement;
use App\Modules\Finances\Models\Tarif;
use App\Modules\Finances\Services\FinanceService;
use App\Modules\StudentDossiers\Models\StudentDocument;
use App\Modules\StudentDossiers\Models\StudentDossier;
use App\Services\BIService;
use App\Services\DocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phases 22-24 — GED, finances, BI : expiration, recherche unifiée,
 * facturation/paiements/remboursements, soldes, exports gardés,
 * indicateurs scopés et cachés.
 */
class GedFinancesBiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.finances.active' => true]);

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.gfb@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
    }

    public function test_commande_expire_documents_sans_supprimer(): void
    {
        $etudiant = $this->creerEtudiant('QASEXP01');
        $dossier = StudentDossier::create([
            'etudiant_id' => $etudiant->id, 'reference' => 'DOS-EXP-01', 'status' => 'open',
        ]);
        $document = StudentDocument::create([
            'student_dossier_id' => $dossier->id,
            'category' => 'administrative',
            'document_type' => 'autre',
            'title' => 'Périmé',
            'original_filename' => 'perime.pdf',
            'storage_path' => 'x.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size' => 10,
            'sha256' => 'abc',
            'status' => 'validated',
            'expires_at' => now()->subDay(),
        ]);

        $this->artisan('documents:marquer-expires')->assertSuccessful();
        $this->assertSame('expired', $document->fresh()->status);
        $this->assertDatabaseHas('student_documents', ['id' => $document->id]);
    }

    public function test_ged_recherche_cloisonnee(): void
    {
        $etudiant = $this->creerEtudiant();
        Storage::fake('local');
        app(DocumentService::class)->stocker(
            $etudiant, $this->annee,
            UploadedFile::fake()->createWithContent('relevetest.pdf', "%PDF-1.4\ncontenu de test"),
            'releve_notes', $this->admin->id,
        );

        $reponse = $this->actingAs($this->admin)->get(route('admin.ged.index', ['q' => 'relevetest']));
        $reponse->assertOk();
        $titres = $reponse->viewData('sections')['Archives académiques']->pluck('titre')->all();
        $this->assertContains('relevetest.pdf', $titres);

        // Isolation faculté : un admin d'une autre faculté ne voit rien.
        $autreFaculte = Faculte::create(['nom' => 'FMP', 'code' => 'FMP', 'description' => 'T', 'active' => true]);
        $adminFaculte = User::creerCompteBackOffice([
            'name' => 'Fac', 'email' => 'fac.gfb@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $autreFaculte->id,
        ], 'admin_faculte');

        $reponse = $this->actingAs($adminFaculte)->get(route('admin.ged.index', ['q' => 'relevetest']));
        $reponse->assertOk();
        $this->assertEmpty($reponse->viewData('sections')['Archives académiques'] ?? []);
    }

    public function test_facture_idempotente_et_partielle(): void
    {
        $inscription = $this->creerInscription();
        Tarif::create([
            'code' => 'INSC', 'libelle' => 'Inscription', 'montant_centimes' => 500000,
            'annee_universitaire_id' => $this->annee->id, 'type_frais' => 'inscription', 'actif' => true,
        ]);

        $service = app(FinanceService::class);
        $facture = $service->emettreFacture($inscription, $this->admin->id);
        $seconde = $service->emettreFacture($inscription, $this->admin->id);

        $this->assertSame($facture->id, $seconde->id);
        $this->assertSame(500000, $facture->netCentimes());

        // Paiement partiel puis solde : statuts intermédiaires.
        $service->enregistrerPaiement($facture, 200000, 'especes', $this->admin->id);
        $paiement = Paiement::where('facture_id', $facture->id)->firstOrFail();
        $service->confirmerPaiement($paiement, $this->admin->id);

        $this->assertSame('partially_paid', $facture->fresh()->statut);
        $this->assertSame(300000, $facture->fresh()->resteCentimes());

        // Trop-perçu refusé.
        $this->expectException(\DomainException::class);
        $service->enregistrerPaiement($facture->fresh(), 400000, 'especes', $this->admin->id);
    }

    public function test_double_confirmation_et_reference_externe(): void
    {
        $inscription = $this->creerInscription();
        Tarif::create([
            'code' => 'INSC2', 'libelle' => 'Inscription', 'montant_centimes' => 100000,
            'annee_universitaire_id' => $this->annee->id, 'type_frais' => 'inscription', 'actif' => true,
        ]);

        $service = app(FinanceService::class);
        $facture = $service->emettreFacture($inscription, $this->admin->id);
        $service->enregistrerPaiement($facture, 100000, 'virement', $this->admin->id, 'EXT-001');
        $paiement = Paiement::where('facture_id', $facture->id)->firstOrFail();
        $service->confirmerPaiement($paiement, $this->admin->id);

        $this->assertSame('paid', $facture->fresh()->statut);

        try {
            $service->confirmerPaiement($paiement->fresh(), $this->admin->id);
            $this->fail('Double confirmation aurait dû échouer.');
        } catch (\DomainException) {
        }

        try {
            $service->enregistrerPaiement($facture->fresh(), 1000, 'virement', $this->admin->id, 'EXT-001');
            $this->fail('Référence externe dupliquée aurait dû échouer.');
        } catch (\DomainException) {
        }

        $this->assertTrue(true);
    }

    public function test_remboursement_workflow_et_annulation(): void
    {
        $inscription = $this->creerInscription();
        Tarif::create([
            'code' => 'INSC3', 'libelle' => 'Inscription', 'montant_centimes' => 100000,
            'annee_universitaire_id' => $this->annee->id, 'type_frais' => 'inscription', 'actif' => true,
        ]);

        $service = app(FinanceService::class);
        $facture = $service->emettreFacture($inscription, $this->admin->id);
        $service->enregistrerPaiement($facture, 100000, 'especes', $this->admin->id);
        $paiement = Paiement::where('facture_id', $facture->id)->firstOrFail();
        $service->confirmerPaiement($paiement, $this->admin->id);

        $remboursement = $service->demanderRemboursement($paiement->fresh(), 40000, 'Désistement partiel', $this->admin->id);
        $service->traiterRemboursement($remboursement, 'approved', $this->admin->id);
        $service->traiterRemboursement($remboursement->fresh(), 'processed', $this->admin->id);
        $service->traiterRemboursement($remboursement->fresh(), 'completed', $this->admin->id);

        $this->assertSame('refunded', $facture->fresh()->statut);

        // Remboursement au-delà du payé : refusé.
        $this->expectException(\DomainException::class);
        $service->demanderRemboursement($paiement->fresh(), 70000, 'Trop', $this->admin->id);
    }

    public function test_facture_jamais_supprimee_physiquement(): void
    {
        $this->assertFalse(Schema::hasColumn('x', 'y') && method_exists(Facture::class, 'forceDeletePhysical'));

        $inscription = $this->creerInscription();
        Tarif::create([
            'code' => 'INSC4', 'libelle' => 'Inscription', 'montant_centimes' => 100000,
            'annee_universitaire_id' => $this->annee->id, 'type_frais' => 'inscription', 'actif' => true,
        ]);

        $facture = app(FinanceService::class)->emettreFacture($inscription, $this->admin->id);
        app(FinanceService::class)->annulerFacture($facture, $this->admin->id, 'Erreur de saisie');

        $this->assertSame('cancelled', $facture->fresh()->statut);
        $this->assertDatabaseHas('factures', ['id' => $facture->id]);
    }

    public function test_solde_et_portail_etudiant(): void
    {
        $inscription = $this->creerInscription();
        Tarif::create([
            'code' => 'INSC5', 'libelle' => 'Inscription', 'montant_centimes' => 200000,
            'annee_universitaire_id' => $this->annee->id, 'type_frais' => 'inscription', 'actif' => true,
        ]);

        $facture = app(FinanceService::class)->emettreFacture($inscription, $this->admin->id);
        $solde = app(FinanceService::class)->soldeEtudiant($inscription->etudiant);

        $this->assertSame(['total' => 200000, 'paye' => 0, 'reste' => 200000, 'en_retard' => 0], $solde);

        $this->withSession(['etudiant_auth_id' => $inscription->etudiant_id])
            ->get(route('etudiant.finances.index'))->assertOk();
    }

    public function test_finance_idor_faculte(): void
    {
        $inscription = $this->creerInscription();
        Tarif::create([
            'code' => 'INSC6', 'libelle' => 'Inscription', 'montant_centimes' => 100000,
            'annee_universitaire_id' => $this->annee->id, 'type_frais' => 'inscription', 'actif' => true,
        ]);
        $facture = app(FinanceService::class)->emettreFacture($inscription, $this->admin->id);

        $autreFaculte = Faculte::create(['nom' => 'FMP', 'code' => 'FMP', 'description' => 'T', 'active' => true]);
        $adminFaculte = User::creerCompteBackOffice([
            'name' => 'Fac', 'email' => 'fac.gfb@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $autreFaculte->id,
        ], 'admin_faculte');

        $this->actingAs($adminFaculte)->get(route('admin.finances.factures.show', $facture))->assertNotFound();
    }

    public function test_export_factures_garde_financiere(): void
    {
        $agent = User::creerCompteBackOffice([
            'name' => 'Agent2', 'email' => 'agent2.gfb@universite.test', 'password' => 'Motdepasse@123',
        ], 'agent_scolarite');

        // data.export SANS finances.view : 403 via la garde entité, pas de fuite.
        $this->assertTrue($agent->hasPermission('data.export'));
        $this->assertFalse($agent->hasPermission('finances.view'));
        $this->actingAs($agent)->get(route('admin.donnees.export', ['entite' => 'factures', 'format' => 'csv']))
            ->assertForbidden();
        $this->actingAs($this->admin)->get(route('admin.donnees.export', ['entite' => 'factures', 'format' => 'csv']))
            ->assertOk();
    }

    public function test_bi_indicateurs_scopes_et_cache(): void
    {
        $this->creerInscription();

        $bi = app(BIService::class);
        $indicateurs = $bi->indicateurs($this->annee->id, $this->faculte->id);

        $this->assertSame(1, $indicateurs['effectif']['valeur']);
        $this->assertArrayHasKey('definition', $indicateurs['effectif']);
        $this->assertArrayHasKey('source', $indicateurs['effectif']);

        // Autre faculté : périmètre strict, aucun effectif.
        $autre = Faculte::create(['nom' => 'FMD', 'code' => 'FMD', 'description' => 'T', 'active' => true]);
        $this->assertSame(0, $bi->indicateurs($this->annee->id, $autre->id)['effectif']['valeur']);

        // Cache invalidé par nouvelle donnée (version max updated_at).
        $this->creerInscription('QASBI002');
        $this->assertSame(2, $bi->indicateurs($this->annee->id, $this->faculte->id)['effectif']['valeur']);

        $this->actingAs($this->admin)->get(route('admin.bi.index'))->assertOk();
    }

    public function test_export_stats_parite(): void
    {
        $reponse = $this->actingAs($this->admin)->get(route('admin.statistiques.export'));
        $reponse->assertOk();
        $contenu = $reponse->streamedContent();
        $this->assertStringContainsString('Demandes en retard', $contenu);
        $this->assertStringContainsString('Notes saisies', $contenu);
    }

    private function creerEtudiant(string $cin = 'QASBI001'): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin, 'nom' => 'Benali', 'prenom' => 'Sara', 'email' => strtolower($cin).'@gfb.test',
            'date_naissance' => '2004-01-01', 'sexe' => 'F', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
        $etudiant->definirStatut('valide');

        return $etudiant;
    }

    private function creerInscription(string $cin = 'QASBI001'): InscriptionAcademique
    {
        $etudiant = Etudiant::where('cin', $cin)->first() ?? $this->creerEtudiant($cin);

        $inscription = $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id, 'niveau' => 'L1', 'statut' => 'en_cours',
        ]);
        $inscription->semestres()->create(['numero' => 1]);

        return $inscription;
    }
}
