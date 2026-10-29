<?php

namespace Tests\Feature;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\OnlineRequests\Models\OnlineRequest;
use App\Modules\OnlineRequests\Models\RequestDelegation;
use App\Modules\OnlineRequests\Models\RequestType;
use App\Modules\OnlineRequests\Services\DelegationDemandeService;
use App\Modules\OnlineRequests\Services\OnlineRequestService;
use App\Modules\OnlineRequests\Services\RequestWorkflowService;
use App\Services\Workflow\Definitions\WorkflowDemandeEtudianteDefinition;
use App\Services\Workflow\MoteurWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * LOT 01 — Workflow de validation configurable (audit §F1).
 *
 * Couvre le câblage du module OnlineRequests sur le moteur de
 * workflows (définition versionnée, source unique) :
 * 1. permissions réelles par transition (topologie + RBAC + délégations) ;
 * 2. quatre yeux sur l'approbation : l'agent assigné à l'examination
 *    ne peut jamais approuver sa propre évaluation ;
 * 3. délégations de l'autorité de validation : fenêtre bornée,
 *    révocable, jamais auto-accordée, jamais chaînable ;
 * 4. conservation de la version de workflow épinglée à la création ;
 * 5. cohérence anti-dérive définition ↔ façade ↔ libellés ↔ états.
 */
class WorkflowDemandesValidationTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private Etudiant $etudiant;

    private User $admin;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.online-requests.active' => true]);
        Storage::fake('private');

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences et Techniques',
            'code' => 'FST',
            'description' => 'Sciences et techniques',
            'active' => true,
        ]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité Centrale',
            'email' => 'scolarite.lot01@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->agent = User::creerCompteBackOffice([
            'name' => 'Agent Traitement',
            'email' => 'agent.lot01@universite.test',
            'password' => 'Motdepasse@123',
        ], 'agent_scolarite');

        $etudiant = Etudiant::create([
            'cin' => 'BK930001',
            'nom' => 'Test',
            'prenom' => 'Etudiant',
            'email' => 'bk930001@example.com',
            'telephone' => '+212600000000',
            'date_naissance' => '2004-03-15',
            'sexe' => 'M',
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
        $etudiant->forceFill(['num_inscription' => 'INS-2026-BK930001'])->save();
        $etudiant->definirStatut('valide');

        $this->etudiant = $etudiant->fresh();
    }

    /**
     * Demande en examination, agent assigné paramétrable (le quatre
     * yeux se joue sur `assigned_to`).
     */
    private function demandeEnExamination(?int $assigneA = null): OnlineRequest
    {
        $service = app(OnlineRequestService::class);
        $type = RequestType::where('code', 'ATTESTATION_SCOLARITE')->firstOrFail();

        $demande = $service->creer($this->etudiant, [
            'request_type_id' => $type->id,
            'objet' => 'Test validation LOT 01',
        ]);
        $service->soumettre($demande);
        $service->recevoir($demande, $assigneA);
        $service->examiner($demande);

        return $demande->fresh();
    }

    private function deleger(User $deleguant, User $delegataire): RequestDelegation
    {
        return app(DelegationDemandeService::class)->deleguer($deleguant, $delegataire, [
            'motif' => 'Congé annuel',
            'valide_jusqua' => now()->addDays(7),
        ]);
    }

    // ───────────────────── Moteur + définition versionnée ─────────────────────

    public function test_moteur_applique_les_permissions_reelles_de_la_definition(): void
    {
        $moteur = app(MoteurWorkflowService::class);
        // L'agent assigné à l'examination est l'agent (process, sans
        // approbation) : le titulaire du pouvoir n'est pas « le maker ».
        $demande = $this->demandeEnExamination($this->agent->id);

        // Approbation : pouvoir de validation distinct du traitement.
        $this->assertTrue($moteur->peutTransiter('demande', $demande, 'approved', $this->admin));
        $this->assertFalse($moteur->peutTransiter('demande', $demande, 'approved', $this->agent));

        // Traitement : l'agent (process, sans approve) peut examiner/demander.
        $this->assertTrue($moteur->peutTransiter('demande', $demande, 'waiting_student', $this->agent));

        // Topologie : aucun saut depuis un état terminal.
        $demande->forceFill(['status' => 'rejected'])->save();
        $this->assertFalse($moteur->peutTransiter('demande', $demande->fresh(), 'completed', $this->admin));
    }

    public function test_moteur_quatre_yeux_l_agent_assigne_ne_peut_pas_approuver(): void
    {
        $moteur = app(MoteurWorkflowService::class);

        // L'agent assigné à l'examination ne peut pas approuver…
        $demande = $this->demandeEnExamination($this->admin->id);
        $this->assertFalse($moteur->peutTransiter('demande', $demande, 'approved', $this->admin));

        // …un autre titulaire du pouvoir de validation, si.
        $secondApprobateur = User::creerCompteBackOffice([
            'name' => 'Second Approbateur',
            'email' => 'second.lot01@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->assertTrue($moteur->peutTransiter('demande', $demande, 'approved', $secondApprobateur));

        // Sans agent assigné, la séparation ne s'oppose à personne.
        $demandeLibre = $this->demandeEnExamination(null);
        $this->assertTrue($moteur->peutTransiter('demande', $demandeLibre, 'approved', $this->admin));
    }

    public function test_version_de_workflow_epinglee_a_la_creation(): void
    {
        $demande = $this->demandeEnExamination();

        $definition = app(MoteurWorkflowService::class)->resoudreDefinition('demande', $demande);

        $this->assertSame(1, (int) $demande->version_workflow);
        $this->assertSame($definition->version(), (int) $demande->version_workflow);
    }

    public function test_coherence_definition_facade_libelles_etats(): void
    {
        $definition = new WorkflowDemandeEtudianteDefinition;

        $this->assertSame(OnlineRequest::STATUTS, $definition->etats());
        $this->assertSame($definition->etats(), array_keys(RequestWorkflowService::libelles()));

        // Toute transition annoncée mène à un état connu, et la façade
        // historique du module est strictement la définition (anti-dérive).
        foreach ($definition->transitions() as $source => $cibles) {
            $this->assertContains($source, $definition->etats());

            foreach ($cibles as $cible) {
                $this->assertContains($cible, $definition->etats());
            }
        }

        foreach ($definition->etats() as $source) {
            foreach ($definition->etats() as $cible) {
                $this->assertSame(
                    $definition->transitionAutorisee($source, $cible),
                    RequestWorkflowService::autorisee($source, $cible),
                    "Divergence façade/définition sur « {$source} » → « {$cible} »."
                );
            }
        }
    }

    // ───────────────────── Délégations de l'autorité de validation ─────────────────────

    public function test_delegation_active_le_delegataire_approuve_par_la_route(): void
    {
        $demande = $this->demandeEnExamination($this->admin->id);

        $this->actingAs($this->admin)->post(route('admin.demandes.delegations.store'), [
            'delegue_a' => $this->agent->id,
            'valide_jusqua' => now()->addDays(7)->format('Y-m-d H:i:s'),
            'motif' => 'Congé annuel',
        ])->assertRedirect();

        $this->assertDatabaseHas('request_delegations', [
            'delegue_par' => $this->admin->id,
            'delegue_a' => $this->agent->id,
            'revoked_at' => null,
        ]);

        // Audit de l'acte administratif (canal référentiel, sans étudiant).
        $this->assertDatabaseHas('historique_academique', [
            'action' => 'delegation_validation.creee',
            'entite' => 'request_delegation',
            'etudiant_id' => null,
        ]);

        // L'agent (process sans approve) approuve grâce à la délégation —
        // le quatre yeux tient : la demande est assignée à l'admin, pas à lui.
        $this->actingAs($this->agent)
            ->patch(route('admin.demandes.approuver', $demande))
            ->assertRedirect();

        $this->assertSame('approved', $demande->fresh()->status);
    }

    public function test_delegation_revoquee_ferme_immediatement_l_acces(): void
    {
        $delegation = $this->deleger($this->admin, $this->agent);
        $demande = $this->demandeEnExamination($this->admin->id);

        $this->actingAs($this->admin)
            ->delete(route('admin.demandes.delegations.destroy', $delegation))
            ->assertRedirect();

        $this->assertNotNull($delegation->fresh()->revoked_at);

        $this->actingAs($this->agent)
            ->patch(route('admin.demandes.approuver', $demande))
            ->assertForbidden();

        $this->assertSame('in_review', $demande->fresh()->status);
    }

    public function test_delegation_expiree_ne_compte_plus(): void
    {
        // Fenêtre échue simulée (la création de fenêtres passées est
        // refusée par le service : on fige l'état « après coup »).
        RequestDelegation::create([
            'delegue_par' => $this->admin->id,
            'delegue_a' => $this->agent->id,
            'faculte_id' => null,
            'valide_depuis' => now()->subDays(8),
            'valide_jusqua' => now()->subDay(),
            'motif' => 'Congé terminé',
            'created_by' => $this->admin->id,
        ]);

        $demande = $this->demandeEnExamination($this->admin->id);

        $this->actingAs($this->agent)
            ->patch(route('admin.demandes.approuver', $demande))
            ->assertForbidden();

        $this->assertSame('in_review', $demande->fresh()->status);
    }

    public function test_delegation_refuse_auto_duree_et_pouvoir_manquant(): void
    {
        // Auto-délégation interdite.
        $this->actingAs($this->admin)->post(route('admin.demandes.delegations.store'), [
            'delegue_a' => $this->admin->id,
            'valide_jusqua' => now()->addDays(7)->format('Y-m-d H:i:s'),
            'motif' => 'Tentative',
        ])->assertSessionHasErrors('delegue_a');

        // Fenêtre trop longue (maximum 90 jours).
        $this->actingAs($this->admin)->post(route('admin.demandes.delegations.store'), [
            'delegue_a' => $this->agent->id,
            'valide_jusqua' => now()->addDays(91)->format('Y-m-d H:i:s'),
            'motif' => 'Trop long',
        ])->assertSessionHasErrors('valide_jusqua');

        // Le délégataire ne peut pas re-déléguer (chaîne interdite :
        // il détient une délégation, pas la permission).
        $this->deleger($this->admin, $this->agent);
        $consultation = User::creerCompteBackOffice([
            'name' => 'Consultation',
            'email' => 'consultation.lot01@universite.test',
            'password' => 'Motdepasse@123',
        ], 'consultation');

        $this->actingAs($this->agent)->post(route('admin.demandes.delegations.store'), [
            'delegue_a' => $consultation->id,
            'valide_jusqua' => now()->addDays(7)->format('Y-m-d H:i:s'),
            'motif' => 'Chaîne interdite',
        ])->assertSessionHasErrors('delegue_par');

        $this->assertSame(1, RequestDelegation::count());
    }

    public function test_quatre_yeux_sapplique_au_delegataire(): void
    {
        $this->deleger($this->admin, $this->agent);

        // Le délégataire est l'agent assigné à l'examination : même
        // délégué, il ne peut pas s'auto-approuver.
        $demande = $this->demandeEnExamination($this->agent->id);

        $this->actingAs($this->agent)
            ->patch(route('admin.demandes.approuver', $demande))
            ->assertSessionHasErrors('status');

        $this->assertSame('in_review', $demande->fresh()->status);
    }

    public function test_delegation_ne_grantie_que_la_validation_pas_le_traitement(): void
    {
        $this->deleger($this->admin, $this->agent);

        $service = app(OnlineRequestService::class);
        $type = RequestType::where('code', 'ATTESTATION_SCOLARITE')->firstOrFail();
        $demande = $service->creer($this->etudiant, [
            'request_type_id' => $type->id,
            'objet' => 'Test périmètre délégation',
        ]);
        $service->soumettre($demande);

        // L'agent possède nativement `demandes.process` : il peut examiner…
        $this->actingAs($this->agent)
            ->patch(route('admin.demandes.recevoir', $demande->fresh()))
            ->assertRedirect();

        // …mais une délégation ne couvre QUE approbation/rejet : un
        // utilisateur sans `demandes.process` ne gagne rien d'autre.
        $consultation = User::creerCompteBackOffice([
            'name' => 'Consultation',
            'email' => 'consultation2.lot01@universite.test',
            'password' => 'Motdepasse@123',
        ], 'consultation');
        $this->deleger($this->admin, $consultation);

        $this->actingAs($consultation)
            ->patch(route('admin.demandes.examiner', $demande->fresh()))
            ->assertForbidden();
    }

    // ───────────────────── Défense en profondeur au service ─────────────────────

    public function test_service_verifie_permission_et_quatre_yeux_sous_verrou(): void
    {
        $service = app(OnlineRequestService::class);

        // Sans délégation, l'agent (process sans approve) est refusé
        // au niveau service, même si la couche HTTP avait été contournée.
        $demande = $this->demandeEnExamination($this->admin->id);

        try {
            $service->approuver($demande, $this->agent);
            $this->fail('Permission « demandes.approve » attendue au niveau service.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Permission « demandes.approve » requise', $exception->getMessage());
        }
        $this->assertSame('in_review', $demande->fresh()->status);

        // Quatre yeux : même un titulaire du pouvoir ne s'auto-approuve pas.
        try {
            $service->approuver($demande, $this->admin);
            $this->fail('Séparation des fonctions attendue au niveau service.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Séparation des fonctions requise', $exception->getMessage());
        }
        $this->assertSame('in_review', $demande->fresh()->status);

        // Un second porteur du pouvoir passe, sous le même verrou.
        $secondApprobateur = User::creerCompteBackOffice([
            'name' => 'Second Approbateur',
            'email' => 'second2.lot01@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $approuvee = $service->approuver($demande, $secondApprobateur);
        $this->assertSame('approved', $approuvee->status);
    }
}
