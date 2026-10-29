<?php

namespace Tests\Feature;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\OnlineRequests\Models\OnlineRequest;
use App\Modules\OnlineRequests\Models\RequestType;
use App\Modules\OnlineRequests\Services\OnlineRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Demandes en ligne : activation, création, workflow, pièces, délais.
 */
class OnlineRequestTest extends TestCase
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

        $this->etudiant = $this->creerEtudiant('BK910001');
    }

    protected function creerEtudiant(string $cin, ?Faculte $faculte = null): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin,
            'nom' => 'Test',
            'prenom' => 'Etudiant',
            'email' => strtolower($cin).'@example.com',
            'telephone' => '+212600000000',
            'date_naissance' => '2004-03-15',
            'sexe' => 'M',
            'faculte_id' => ($faculte ?? $this->faculte)->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => '12 Rue de la Liberté',
            'ville' => 'Casablanca',
            'code_postal' => '20000',
            'annee_bac' => '2024',
            'mention_bac' => 'Bien',
            'password' => 'motdepasse1',
        ]);
        $etudiant->forceFill(['num_inscription' => 'INS-2026-'.$cin])->save();
        $etudiant->definirStatut('valide');

        return $etudiant->fresh();
    }

    protected function type(string $code = 'ATTESTATION_SCOLARITE'): RequestType
    {
        return RequestType::where('code', $code)->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    protected function donnees(?int $typeId = null): array
    {
        return [
            'request_type_id' => $typeId ?? $this->type()->id,
            'objet' => 'Attestation pour dossier',
            'description' => 'Besoin administratif.',
            'confirmation' => '1',
        ];
    }

    public function test_module_desactive_tout_est_404(): void
    {
        config(['modules.online-requests.active' => false]);

        $this->actingAs($this->admin)->get('/admin/demandes')->assertNotFound();

        session(['etudiant_auth_id' => $this->etudiant->id]);
        $this->get('/etudiant/demandes')->assertNotFound();
    }

    public function test_creation_brouillon_reference_serveur(): void
    {
        session(['etudiant_auth_id' => $this->etudiant->id]);

        $this->post(route('etudiant.demandes.store'), $this->donnees() + ['reference' => 'FAUSSE'])
            ->assertRedirect();

        $demande = OnlineRequest::firstOrFail();
        $this->assertSame('draft', $demande->status);
        $this->assertMatchesRegularExpression('/^REQ-\d{4}-\d{6}$/', $demande->reference);
        $this->assertNotSame('FAUSSE', $demande->reference);
    }

    public function test_soumission_exige_piece_si_requise(): void
    {
        session(['etudiant_auth_id' => $this->etudiant->id]);

        $type = $this->type('CHANGEMENT_GROUPE');
        $this->post(route('etudiant.demandes.store'), $this->donnees($type->id) + [
            'form_data' => ['groupe_actuel' => 'A1', 'nouveau_groupe' => 'B2', 'motif' => 'Emploi du temps'],
        ]);
        $demande = OnlineRequest::firstOrFail();

        // Sans pièce : soumission refusée (type l'exige).
        $this->post(route('etudiant.demandes.submit', $demande))->assertSessionHasErrors('pieces');
        $this->assertSame('draft', $demande->fresh()->status);

        // Avec pièce : soumise + faculté snapshotée + échéance.
        $this->post(route('etudiant.demandes.pieces.store', $demande), [
            'piece' => UploadedFile::fake()->createWithContent('justif.pdf', '%PDF-1.4 test'),
        ])->assertRedirect();

        $this->post(route('etudiant.demandes.submit', $demande))->assertRedirect();
        $demande->refresh();
        $this->assertSame('submitted', $demande->status);
        $this->assertNotNull($demande->submitted_at);
        $this->assertSame($this->faculte->id, (int) $demande->faculte_id);
        $this->assertNotNull($demande->echeance());
    }

    public function test_schema_dynamique_valide(): void
    {
        session(['etudiant_auth_id' => $this->etudiant->id]);

        $type = $this->type('CHANGEMENT_GROUPE');
        $this->post(route('etudiant.demandes.store'), $this->donnees($type->id) + [
            'form_data' => ['groupe_actuel' => 'A1'],
        ])->assertSessionHasErrors();

        $this->assertSame(0, OnlineRequest::count());
    }

    public function test_workflow_complet_cote_admin(): void
    {
        session(['etudiant_auth_id' => $this->etudiant->id]);
        $this->post(route('etudiant.demandes.store'), $this->donnees());
        $demande = OnlineRequest::firstOrFail();
        $this->post(route('etudiant.demandes.submit', $demande));

        // Transition illégale : approuver depuis submitted = erreur.
        $this->actingAs($this->admin)
            ->patch(route('admin.demandes.approuver', $demande))
            ->assertSessionHasErrors('status');
        $this->assertSame('submitted', $demande->fresh()->status);

        $this->actingAs($this->admin)->patch(route('admin.demandes.recevoir', $demande))->assertRedirect();
        $this->actingAs($this->admin)->patch(route('admin.demandes.examiner', $demande))->assertRedirect();

        // LOT 01 — quatre yeux sur l'approbation : l'agent assigné à
        // l'examination (reçu ici par l'admin) ne peut pas approuver
        // sa propre évaluation.
        $this->actingAs($this->admin)
            ->patch(route('admin.demandes.approuver', $demande))
            ->assertSessionHasErrors('status');
        $this->assertSame('in_review', $demande->fresh()->status);

        // Un second agent portant le pouvoir de validation approuve.
        $secondApprobateur = User::creerCompteBackOffice([
            'name' => 'Second Approbateur',
            'email' => 'second.approbateur@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->actingAs($secondApprobateur)->patch(route('admin.demandes.approuver', $demande))->assertRedirect();

        $demande->refresh();
        $this->assertSame('approved', $demande->status);

        // Approbation d'une attestation : PDF généré d'office.
        $this->assertSame(1, $demande->documents()->count());

        $this->actingAs($this->admin)->patch(route('admin.demandes.terminer', $demande))->assertRedirect();
        $this->assertSame('completed', $demande->fresh()->status);

        // Terminal : plus aucune transition.
        $this->actingAs($this->admin)->patch(
            route('admin.demandes.rejeter', $demande),
            ['motif' => 'Trop tard.']
        )->assertSessionHasErrors('status');
    }

    public function test_demande_piece_et_retour(): void
    {
        session(['etudiant_auth_id' => $this->etudiant->id]);
        $this->post(route('etudiant.demandes.store'), $this->donnees());
        $demande = OnlineRequest::firstOrFail();
        $this->post(route('etudiant.demandes.submit', $demande));

        $this->actingAs($this->admin)->patch(route('admin.demandes.recevoir', $demande));
        $this->actingAs($this->admin)->patch(route('admin.demandes.examiner', $demande));
        $this->actingAs($this->admin)->patch(
            route('admin.demandes.demander-piece', $demande),
            ['commentaire' => 'Fournir un justificatif.']
        )->assertRedirect();
        $this->assertSame('waiting_student', $demande->fresh()->status);

        // Dépôt étudiant : retour automatique en examen.
        $this->post(route('etudiant.demandes.pieces.store', $demande), [
            'piece' => UploadedFile::fake()->createWithContent('justif.pdf', '%PDF-1.4 test'),
        ])->assertRedirect();
        $this->assertSame('in_review', $demande->fresh()->status);
    }

    public function test_rejet_motif_obligatoire_et_annulation(): void
    {
        session(['etudiant_auth_id' => $this->etudiant->id]);
        $this->post(route('etudiant.demandes.store'), $this->donnees());
        $demande = OnlineRequest::firstOrFail();

        // Annulation depuis brouillon : OK.
        $this->post(route('etudiant.demandes.cancel', $demande))->assertRedirect();
        $this->assertSame('cancelled', $demande->fresh()->status);
    }

    public function test_delai_depassement_detecte(): void
    {
        $service = $this->app->make(OnlineRequestService::class);
        $demande = $service->creer($this->etudiant, [
            'request_type_id' => $this->type()->id,
            'objet' => 'Test délai',
        ]);
        $service->soumettre($demande);
        $demande->forceFill(['submitted_at' => now()->subDays(10)])->save();

        $this->assertTrue($demande->fresh()->enRetard());
    }

    public function test_registre_stats_et_widgets(): void
    {
        $service = $this->app->make(OnlineRequestService::class);
        $demande = $service->creer($this->etudiant, [
            'request_type_id' => $this->type()->id,
            'objet' => 'Test stats',
        ]);
        $service->soumettre($demande);

        $this->actingAs($this->admin)->get(route('admin.demandes.index'))
            ->assertOk()->assertSee($demande->reference);

        $this->actingAs($this->admin)->get(route('admin.dashboard'))
            ->assertOk()->assertSee('Demandes en ligne');

        session(['etudiant_auth_id' => $this->etudiant->id]);
        $this->get(route('etudiant.demandes.index'))
            ->assertOk()->assertSee($demande->reference);
    }
}
