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
 * Sécurité des demandes (§15) : IDOR/BOLA, permissions, uploads,
 * statuts non injectables, invités.
 */
class OnlineRequestSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected Faculte $faculteA;

    protected Faculte $faculteB;

    protected Etudiant $etudiantA;

    protected Etudiant $etudiantB;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.online-requests.active' => true]);
        Storage::fake('private');

        $this->faculteA = Faculte::create([
            'nom' => 'Faculté des Sciences', 'code' => 'FST',
            'description' => 'Sciences', 'active' => true,
        ]);
        $this->faculteB = Faculte::create([
            'nom' => 'Faculté de Médecine', 'code' => 'FMP',
            'description' => 'Médecine', 'active' => true,
        ]);

        $this->etudiantA = $this->creerEtudiant($this->faculteA, 'BK920001');
        $this->etudiantB = $this->creerEtudiant($this->faculteB, 'BK920002');
    }

    protected function creerEtudiant(Faculte $faculte, string $cin): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin,
            'nom' => 'Test',
            'prenom' => 'Etudiant',
            'email' => strtolower($cin).'@example.com',
            'telephone' => '+212600000000',
            'date_naissance' => '2004-03-15',
            'sexe' => 'M',
            'faculte_id' => $faculte->id,
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

    protected function creerCompte(string $email, string $role, ?int $faculteId = null): User
    {
        return User::creerCompteBackOffice(array_filter([
            'name' => $role,
            'email' => $email,
            'password' => 'Motdepasse@123',
            'faculte_id' => $faculteId,
        ]), $role);
    }

    protected function creerDemande(Etudiant $etudiant, string $statut = 'submitted'): OnlineRequest
    {
        $service = $this->app->make(OnlineRequestService::class);
        $type = RequestType::where('code', 'ATTESTATION_SCOLARITE')->firstOrFail();
        $demande = $service->creer($etudiant, [
            'request_type_id' => $type->id,
            'objet' => 'Test isolation',
        ]);

        foreach (['submitted', 'received', 'in_review', 'approved', 'completed'] as $etape) {
            if ($statut === 'draft') {
                break;
            }
            match ($etape) {
                'submitted' => $service->soumettre($demande),
                'received' => $service->recevoir($demande),
                'in_review' => $service->examiner($demande),
                'approved' => $service->approuver($demande),
                'completed' => $service->terminer($demande),
            };
            $demande->refresh();
            if ($demande->status === $statut) {
                break;
            }
        }

        return $demande->fresh();
    }

    public function test_etudiant_a_bloque_chez_b(): void
    {
        $demandeB = $this->creerDemande($this->etudiantB);

        session(['etudiant_auth_id' => $this->etudiantA->id]);

        $this->get(route('etudiant.demandes.show', $demandeB))->assertNotFound();
        $this->post(route('etudiant.demandes.submit', $demandeB))->assertNotFound();
        $this->get(route('etudiant.demandes.document.download', $demandeB))->assertNotFound();
        $this->get(route('etudiant.demandes.index'))->assertOk()->assertDontSee($demandeB->reference);
    }

    public function test_admin_faculte_bloque_hors_perimetre(): void
    {
        $adminA = $this->creerCompte('admin.a@universite.test', 'admin_faculte', $this->faculteA->id);
        $demandeB = $this->creerDemande($this->etudiantB);

        $this->actingAs($adminA)->get(route('admin.demandes.show', $demandeB))->assertNotFound();
        $this->actingAs($adminA)->patch(route('admin.demandes.approuver', $demandeB))->assertNotFound();
        $this->actingAs($adminA)->get(route('admin.demandes.index'))
            ->assertOk()->assertDontSee($demandeB->reference);
    }

    public function test_super_admin_global_et_consultation_lecture(): void
    {
        $super = $this->creerCompte('super@universite.test', 'super_admin');
        $lecteur = $this->creerCompte('lecteur@universite.test', 'consultation');
        $demandeB = $this->creerDemande($this->etudiantB);

        $this->actingAs($super)->get(route('admin.demandes.show', $demandeB))->assertOk();

        $this->actingAs($lecteur)->get(route('admin.demandes.show', $demandeB))->assertOk();
        $this->actingAs($lecteur)->patch(route('admin.demandes.approuver', $demandeB))->assertForbidden();
    }

    public function test_agent_ne_peut_pas_approuver(): void
    {
        $agent = $this->creerCompte('agent@universite.test', 'agent_scolarite');
        $demandeA = $this->creerDemande($this->etudiantA, 'in_review');

        $this->actingAs($agent)->patch(route('admin.demandes.approuver', $demandeA))->assertForbidden();
        $this->assertSame('in_review', $demandeA->fresh()->status);
    }

    public function test_statut_non_injectable_par_etudiant(): void
    {
        session(['etudiant_auth_id' => $this->etudiantA->id]);

        $type = RequestType::where('code', 'ATTESTATION_SCOLARITE')->firstOrFail();
        $this->post(route('etudiant.demandes.store'), [
            'request_type_id' => $type->id,
            'objet' => 'Tentative',
            'confirmation' => '1',
            'status' => 'approved',
            'assigned_to' => 999,
        ])->assertRedirect();

        $demande = OnlineRequest::firstOrFail();
        $this->assertSame('draft', $demande->status);
        $this->assertNull($demande->assigned_to);
    }

    public function test_piece_dangereuse_refusee(): void
    {
        session(['etudiant_auth_id' => $this->etudiantA->id]);

        $service = $this->app->make(OnlineRequestService::class);
        $type = RequestType::where('code', 'ATTESTATION_SCOLARITE')->firstOrFail();
        $demande = $service->creer($this->etudiantA, [
            'request_type_id' => $type->id,
            'objet' => 'Test piece',
        ]);

        $this->post(route('etudiant.demandes.pieces.store', $demande), [
            'piece' => UploadedFile::fake()->createWithContent('evil.php', '<?php echo 1; ?>'),
        ])->assertSessionHasErrors('piece');

        $this->assertSame(0, $demande->pieces()->count());
    }

    public function test_invites_rediriges(): void
    {
        $this->get('/admin/demandes')->assertRedirect(route('admin.connexion.show'));
        $this->get('/etudiant/demandes')->assertRedirect(route('etudiant.connexion.show'));
    }
}
