<?php

namespace Tests\Feature;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\OnlineRequests\Models\OnlineRequest;
use App\Modules\OnlineRequests\Models\RequestType;
use App\Modules\OnlineRequests\Services\OnlineRequestService;
use App\Modules\OnlineRequests\Services\RequestDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * PDF des demandes : génération à l'approbation, téléchargement
 * sécurisé, contenu whitelisté, audit.
 */
class OnlineRequestPdfTest extends TestCase
{
    use RefreshDatabase;

    protected Faculte $faculte;

    protected User $admin;

    protected Etudiant $etudiant;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.online-requests.active' => true]);
        Storage::fake('private');

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences et Techniques',
            'code' => 'FST',
            'description' => 'Sciences et technologies',
            'active' => true,
        ]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité Test',
            'email' => 'scolarite.test@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->etudiant = Etudiant::create([
            'cin' => 'BK930001',
            'nom' => 'Bennani',
            'prenom' => 'Salma',
            'email' => 'bk930001@example.com',
            'telephone' => '+212600000000',
            'date_naissance' => '2004-03-15',
            'sexe' => 'F',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => '12 Rue de la Liberté',
            'ville' => 'Casablanca',
            'code_postal' => '20000',
            'annee_bac' => '2024',
            'mention_bac' => 'Bien',
            'password' => 'motdepasse1',
        ]);
        $this->etudiant->forceFill(['num_inscription' => 'INS-2026-BK930001'])->save();
        $this->etudiant->definirStatut('valide');
        $this->etudiant = $this->etudiant->fresh();
    }

    protected function demandeApprouvee(): OnlineRequest
    {
        $service = $this->app->make(OnlineRequestService::class);
        $type = RequestType::where('code', 'ATTESTATION_SCOLARITE')->firstOrFail();
        $demande = $service->creer($this->etudiant, [
            'request_type_id' => $type->id,
            'objet' => 'Attestation',
        ]);
        $service->soumettre($demande);
        $service->recevoir($demande);
        $service->examiner($demande);

        $this->actingAs($this->admin)->patch(route('admin.demandes.approuver', $demande));

        return $demande->fresh();
    }

    public function test_approbation_genere_pdf_reference_sha(): void
    {
        $demande = $this->demandeApprouvee();

        $document = $demande->documents()->firstOrFail();
        $this->assertStringStartsWith('PDF-'.$demande->reference, $document->reference);
        $this->assertSame(64, strlen($document->sha256));
        Storage::disk('private')->assertExists($document->storage_path);
        $this->assertFalse(Storage::disk('public')->exists($document->storage_path));

        $this->assertDatabaseHas('historique_academique', [
            'action' => 'online_request.document_generated',
        ]);
    }

    public function test_telechargement_pdf_admin_et_etudiant(): void
    {
        $demande = $this->demandeApprouvee();

        $this->actingAs($this->admin)->get(route('admin.demandes.document.download', $demande))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        session(['etudiant_auth_id' => $this->etudiant->id]);
        $this->get(route('etudiant.demandes.document.download', $demande))
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_contenu_pdf_whiteliste_a4(): void
    {
        $service = $this->app->make(RequestDocumentService::class);
        $demande = OnlineRequest::firstOrNew([]);
        $demande->fill([
            'etudiant_id' => $this->etudiant->id,
            'request_type_id' => RequestType::where('code', 'ATTESTATION_SCOLARITE')->firstOrFail()->id,
            'objet' => 'Test',
        ]);
        $demande->reference = 'REQ-2026-000001';
        $demande->status = 'approved';

        $html = view('modules.online-requests.pdf.attestation', $service->donnees($demande))->render();

        $this->assertStringContainsString('A4', $html);
        $this->assertStringContainsString('counter(page)', $html);
        $this->assertStringContainsString('Bennani', $html);
        $this->assertStringContainsString('REQ-2026-000001', $html);

        foreach (['motdepasse1', '$2y$', 'remember_token', 'storage_path', 'APP_KEY'] as $interdit) {
            $this->assertStringNotContainsString($interdit, $html);
        }
    }
}
