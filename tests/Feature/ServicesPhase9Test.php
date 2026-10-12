<?php

namespace Tests\Feature;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\HistoriqueAcademique;
use App\Models\User;
use App\Modules\OnlineRequests\Models\OnlineRequest;
use App\Modules\OnlineRequests\Models\RequestType;
use App\Modules\OnlineRequests\Services\DemandesStatsService;
use App\Modules\OnlineRequests\Services\OnlineRequestService;
use App\Modules\OnlineRequests\Services\RequestAttachmentService;
use App\Modules\StudentDossiers\Contracts\AntivirusResult;
use App\Modules\StudentDossiers\Contracts\AntivirusScannerInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Phase 9 — Services étudiants (§41-42, §46, §48-49, §61, §78, §80) :
 * catalogue, workflow verrouillé, pièces versionnées + antivirus,
 * fil de discussion, analytique. Moteur existant, sans duplication.
 */
class ServicesPhase9Test extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private User $admin;

    private Etudiant $etudiant;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.online-requests.active' => true]);
        Storage::fake('private');

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.ph9@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->etudiant = Etudiant::create([
            'cin' => 'BKPH9001', 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => 'bkph9001@ph9.test', 'date_naissance' => '2004-01-01',
            'sexe' => 'F', 'faculte_id' => $this->faculte->id, 'niveau' => 'L1',
            'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
        $this->etudiant->definirStatut('valide');
    }

    private function creerDemande(string $type = 'DEMANDE_LIBRE'): OnlineRequest
    {
        return app(OnlineRequestService::class)->creer($this->etudiant, [
            'request_type_id' => RequestType::where('code', $type)->firstOrFail()->id,
            'objet' => 'Besoin administratif',
        ]);
    }

    public function test_double_transition_concurrente_serialisee(): void
    {
        $demande = $this->creerDemande();
        app(OnlineRequestService::class)->transitionner($demande, 'submitted');

        // Rejeu sur la même transition : refusé (machine à états).
        try {
            app(OnlineRequestService::class)->transitionner($demande, 'submitted');
            $this->fail('Double transition aurait dû être refusée.');
        } catch (ValidationException) {
            $this->assertSame('submitted', $demande->fresh()->status);
        }

        // Instance périmée : rechargée sous verrou, transition valide OK.
        $this->actingAs($this->admin)
            ->patch(route('admin.demandes.recevoir', $demande))
            ->assertRedirect();
        $this->assertSame('received', $demande->fresh()->status);
    }

    public function test_catalogue_crud_configuration(): void
    {
        $this->actingAs($this->admin)->post(route('admin.demandes.types.store'), [
            'code' => 'DUPLICATA_DIPLOME', 'name' => 'Duplicata de diplôme', 'processing_days' => 10,
        ])->assertRedirect();

        $type = RequestType::where('code', 'DUPLICATA_DIPLOME')->firstOrFail();
        $this->assertTrue((bool) $type->active);

        $this->actingAs($this->admin)->patch(route('admin.demandes.types.update', $type), [
            'name' => 'Duplicata', 'processing_days' => 7, 'active' => '0',
        ])->assertRedirect();
        $this->assertFalse($type->fresh()->active);

        // Utilisé par une demande : suppression refusée.
        $this->creerDemande('ATTESTATION_SCOLARITE');
        $utilise = RequestType::where('code', 'ATTESTATION_SCOLARITE')->firstOrFail();
        $this->actingAs($this->admin)->delete(route('admin.demandes.types.destroy', $utilise))
            ->assertSessionHasErrors('type');
    }

    public function test_piece_antivirus_et_versionnement(): void
    {
        $demande = $this->creerDemande();

        // Moteur infecté simulé : refus + fichier supprimé.
        app()->instance(AntivirusScannerInterface::class, new class implements AntivirusScannerInterface
        {
            public function analyser(array $contexte): AntivirusResult
            {
                return new AntivirusResult(false, 'EICAR-TEST');
            }
        });

        try {
            app(RequestAttachmentService::class)
                ->televerser($demande, UploadedFile::fake()->createWithContent('justif.pdf', '%PDF-1.4 test'), null);
            $this->fail('Pièce infectée aurait dû être refusée.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('antivirus', $e->getMessage());
        }

        $this->assertSame(0, $demande->pieces()->count());

        // Moteur sain : deux dépôts → v1 + v2 historisées.
        app()->instance(AntivirusScannerInterface::class, new class implements AntivirusScannerInterface
        {
            public function analyser(array $contexte): AntivirusResult
            {
                return new AntivirusResult(true);
            }
        });

        $service = app(RequestAttachmentService::class);
        $service->televerser($demande, UploadedFile::fake()->createWithContent('a.pdf', '%PDF-1.4 test'), null);
        $service->televerser($demande, UploadedFile::fake()->createWithContent('b.pdf', '%PDF-1.4 test'), null);

        $this->assertSame([1, 2], $demande->pieces()->orderBy('version')->pluck('version')->all());
    }

    public function test_fil_discussion_bi_directionnel_et_idor(): void
    {
        $demande = $this->creerDemande();

        $this->actingAs($this->admin)->post(route('admin.demandes.messages.store', $demande), [
            'message' => 'Précisez le semestre.',
        ])->assertRedirect();

        session(['etudiant_auth_id' => $this->etudiant->id]);
        $this->post(route('etudiant.demandes.messages.repondre', $demande), [
            'message' => 'Semestre 1, voici le détail.',
        ])->assertRedirect();

        $messages = $demande->messages()->orderBy('id')->get();
        $this->assertSame(2, $messages->count());
        $this->assertTrue($messages[0]->estAdministration());
        $this->assertFalse($messages[1]->estAdministration());

        // Audit sans contenu sensible (longueur seule en contexte).
        $this->assertTrue(HistoriqueAcademique::where('action', 'online_request.message')
            ->where('entite_id', $demande->id)->exists());

        // Étudiant B : 404 sur le fil de A.
        $autre = Etudiant::create([
            'cin' => 'BKPH9002', 'nom' => 'Autre', 'prenom' => 'Ali', 'email' => 'autre@ph9.test',
            'sexe' => 'M', 'faculte_id' => $this->faculte->id, 'niveau' => 'L1',
            'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
        session(['etudiant_auth_id' => $autre->id]);
        $this->post(route('etudiant.demandes.messages.repondre', $demande), ['message' => 'Intrus'])
            ->assertNotFound();
        $this->assertSame(2, $demande->messages()->count());
    }

    public function test_analytique_retard_et_delai(): void
    {
        $service = app(OnlineRequestService::class);
        $vieille = $this->creerDemande();
        $service->transitionner($vieille, 'submitted');
        $vieille->forceFill([
            'submitted_at' => now()->subDays(10),
            'completed_at' => now()->subDays(2),
            'status' => 'completed',
        ])->save();

        $enCours = $this->creerDemande();
        $service->transitionner($enCours, 'submitted');
        $enCours->forceFill(['submitted_at' => now()->subDays(10)])->save();

        $stats = app(DemandesStatsService::class)->statistiques(null);

        $this->assertSame(1, $stats['en_retard']);
        $this->assertEqualsWithDelta(8.0, $stats['delai_moyen_jours'], 0.1);
        $this->assertArrayHasKey('DEMANDE_LIBRE', $stats['par_service']);

        // Scope faculté : autre faculté vide.
        $autre = Faculte::create(['nom' => 'FMP', 'code' => 'FMP', 'description' => 'T', 'active' => true]);
        $this->assertSame(0, app(DemandesStatsService::class)->statistiques($autre->id)['total']);
    }

    public function test_export_demandes_metadonnees_seules(): void
    {
        $this->creerDemande();

        $reponse = $this->actingAs($this->admin)
            ->get(route('admin.donnees.export', ['entite' => 'demandes', 'format' => 'json']))
            ->assertOk();

        $contenu = (string) $reponse->streamedContent();
        $this->assertStringContainsString('DEMANDE_LIBRE', $contenu);
        $this->assertStringNotContainsString('storage_path', $contenu);
    }
}
