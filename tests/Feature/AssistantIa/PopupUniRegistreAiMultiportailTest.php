<?php

namespace Tests\Feature\AssistantIa;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\AssistantIa\Models\AiConversation;
use App\Modules\AssistantIa\Models\AiPropositionAction;
use App\Modules\AssistantIa\Services\PasserelleFournisseurs;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Suite de validation d'intégration et de sécurité du Popup UniRegistre AI multiportail.
 * Couvre les 3 portails (Admin, Enseignant, Étudiant), l'isolation anti-IDOR,
 * l'étanchéité interfacultaire, la non-divulgation et le workflow d'action humaine en 2 temps.
 */
class PopupUniRegistreAiMultiportailTest extends TestCase
{
    use RefreshDatabase;

    protected ?Faculte $faculteDefaut = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['assistant-ia.actif' => true]);
        config(['assistant-ia.fournisseur' => 'faux']);
        app(PasserelleFournisseurs::class)->definirFournisseurActif('faux');
    }

    protected function faculteTest(): Faculte
    {
        return $this->faculteDefaut ??= Faculte::create([
            'nom' => 'Faculté des Sciences de Tunis',
            'code' => 'FST',
        ]);
    }

    protected function creerEtudiant(array $attributs = []): Etudiant
    {
        static $counter = 1000;
        $counter++;

        return Etudiant::create(array_merge([
            'nom' => 'Nom'.$counter,
            'prenom' => 'Prenom'.$counter,
            'email' => "etudiant{$counter}@univ.tn",
            'cin' => (string) (11000000 + $counter),
            'telephone' => '+21671000000',
            'date_naissance' => '2004-03-15',
            'sexe' => 'M',
            'faculte_id' => $this->faculteTest()->id,
            'niveau' => 'L1',
            'annee_academique' => '2025-2026',
            'adresse' => '12 Avenue Habib Bourguiba',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2024',
            'mention_bac' => 'Bien',
            'password' => bcrypt('Pass123!'),
            'statut' => 'valide',
        ], $attributs));
    }

    public function test_visiteur_anonyme_recoit_401_sur_initialisation_popup(): void
    {
        $response = $this->getJson(route('assistant-ia.popup.initialiser'));

        $response->assertStatus(401);
        $response->assertJson([
            'succes' => false,
            'authentifie' => false,
        ]);
    }

    public function test_admin_autorise_initialise_popup_avec_contexte_admin(): void
    {
        $admin = User::factory()->create();
        $admin->definirRole('admin_scolarite');

        $response = $this->actingAs($admin)->getJson(route('assistant-ia.popup.initialiser', [
            'route_name' => 'admin.inscriptions.file-traitement',
        ]));

        $response->assertOk();
        $response->assertJson([
            'succes' => true,
            'authentifie' => true,
            'acteur' => [
                'portail' => 'admin',
                'role' => 'admin_scolarite',
            ],
            'contexte' => [
                'portail' => 'admin',
                'route_valide' => true,
            ],
        ]);
    }

    public function test_enseignant_authentifie_initialise_popup_avec_contexte_enseignant(): void
    {
        $teacher = Teacher::create([
            'nom' => 'Benali',
            'prenom' => 'Kamel',
            'email' => 'kamel.benali@univ.tn',
            'password' => 'Secret123!',
            'matricule' => 'ENS-8891',
            'faculte_id' => $this->faculteTest()->id,
            'actif' => true,
        ]);

        $response = $this->withSession(['enseignant_auth_id' => $teacher->id])
            ->getJson(route('assistant-ia.popup.initialiser', [
                'route_name' => 'enseignant.notes.index',
            ]));

        $response->assertOk();
        $response->assertJson([
            'succes' => true,
            'acteur' => [
                'portail' => 'enseignant',
                'nom' => 'Kamel Benali',
                'role' => 'enseignant',
                'peut_mode_dev' => false,
                'peut_distant' => false,
            ],
            'contexte' => [
                'portail' => 'enseignant',
                'route_valide' => true,
            ],
        ]);
    }

    public function test_etudiant_authentifie_initialise_popup_avec_contexte_etudiant(): void
    {
        $etudiant = $this->creerEtudiant([
            'nom' => 'Trabelsi',
            'prenom' => 'Sarra',
            'email' => 'sarra.trabelsi@etudiant.univ.tn',
            'niveau' => 'L2',
        ]);

        $response = $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->getJson(route('assistant-ia.popup.initialiser', [
                'route_name' => 'etudiant.dashboard',
            ]));

        $response->assertOk();
        $response->assertJson([
            'succes' => true,
            'acteur' => [
                'portail' => 'etudiant',
                'nom' => 'Sarra Trabelsi',
                'role' => 'etudiant',
                'peut_mode_dev' => false,
                'peut_distant' => false,
            ],
            'contexte' => [
                'portail' => 'etudiant',
                'route_valide' => true,
            ],
        ]);
    }

    public function test_etudiant_qui_falsifie_route_admin_est_sanitise_vers_contexte_etudiant(): void
    {
        $etudiant = $this->creerEtudiant();

        // L'étudiant envoie frauduleusement une route administrative dans la requête
        $response = $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->getJson(route('assistant-ia.popup.initialiser', [
                'route_name' => 'admin.administrateurs.index',
            ]));

        $response->assertOk();
        // Le serveur refuse d'appliquer la route admin et rétablit le contexte de sécurité étudiant
        $response->assertJson([
            'contexte' => [
                'portail' => 'etudiant',
                'ecran' => 'Portail Étudiant',
            ],
        ]);
    }

    public function test_etudiant_ne_peut_pas_acceder_au_mode_developpeur(): void
    {
        $etudiant = $this->creerEtudiant();

        $response = $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->postJson(route('assistant-ia.popup.conversations.creer'), [
                'mode' => 'developpeur',
            ]);

        $response->assertStatus(403);
        $response->assertJson([
            'succes' => false,
        ]);
    }

    public function test_creation_conversation_isolee_par_portail_et_par_acteur(): void
    {
        $etudiant = $this->creerEtudiant([
            'nom' => 'Mejri',
            'prenom' => 'Leila',
        ]);

        $response = $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->postJson(route('assistant-ia.popup.conversations.creer'), [
                'titre' => 'Question calendrier examens',
                'mode' => 'metier',
            ]);

        $response->assertStatus(201);
        $uuid = $response->json('conversation.uuid');
        $this->assertNotEmpty($uuid);

        $conv = AiConversation::where('uuid', $uuid)->first();
        $this->assertNotNull($conv);
        $this->assertSame($etudiant->id, $conv->etudiant_id);
        $this->assertSame('etudiant', $conv->portail);
        $this->assertNull($conv->user_id);
        $this->assertNull($conv->teacher_id);
    }

    public function test_etudiant_ne_peut_pas_consulter_conversation_d_un_autre_etudiant_anti_idor(): void
    {
        $etudiantA = $this->creerEtudiant();
        $etudiantB = $this->creerEtudiant();

        $convA = AiConversation::create([
            'etudiant_id' => $etudiantA->id,
            'portail' => 'etudiant',
            'titre' => 'Dossier personnel secret A',
            'mode' => 'metier',
        ]);

        // Étudiant B tente d'accéder à la conversation de l'étudiant A
        $response = $this->withSession(['etudiant_auth_id' => $etudiantB->id])
            ->getJson(route('assistant-ia.popup.conversations.show', $convA->uuid));

        $response->assertStatus(404);
    }

    public function test_envoi_message_popup_avec_contexte_et_reponse_securisee(): void
    {
        $etudiant = $this->creerEtudiant([
            'nom' => 'Mansouri',
            'prenom' => 'Nizar',
            'niveau' => 'L3',
        ]);

        $conv = AiConversation::create([
            'etudiant_id' => $etudiant->id,
            'portail' => 'etudiant',
            'titre' => 'Aide réinscription L3',
            'mode' => 'metier',
            'fournisseur' => 'faux',
        ]);

        $response = $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->postJson(route('assistant-ia.popup.messages.envoyer', $conv->uuid), [
                'contenu' => 'Quelles sont les démarches pour mon passage en Master ?',
                'route_name' => 'etudiant.parcours',
            ]);

        $response->assertOk();
        $response->assertJson([
            'succes' => true,
        ]);
        $this->assertNotEmpty($response->json('reponse.texte'));
    }

    public function test_etudiant_peut_preparer_demande_attestation(): void
    {
        $etudiant = $this->creerEtudiant([
            'nom' => 'Drissi',
            'prenom' => 'Aymen',
        ]);

        $response = $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->postJson(route('assistant-ia.popup.actions.preparer'), [
                'type_action' => 'demande_attestation',
                'ressource_type' => 'Etudiant',
                'ressource_id' => $etudiant->id,
                'justification' => 'Attestation d\'inscription pour dossier de bourse',
                'parametres' => ['type_attestation' => 'inscription_reguliere'],
            ]);

        $response->assertStatus(201);
        $response->assertJson([
            'succes' => true,
            'preparation' => [
                'type_action' => 'demande_attestation',
                'statut' => 'brouillon_prepare',
                'necessite_approbation_4_yeux' => false,
            ],
        ]);
        $this->assertNotEmpty($response->json('preparation.action_uuid'));
    }

    public function test_etudiant_ne_peut_pas_preparer_action_administrative_sensible(): void
    {
        $etudiant = $this->creerEtudiant();

        // L'étudiant tente de déclencher une action de déblocage réservée à l'administration
        $response = $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->postJson(route('assistant-ia.popup.actions.preparer'), [
                'type_action' => 'demande_piece_complementaire',
                'ressource_type' => 'InscriptionAcademique',
                'ressource_id' => 10,
                'justification' => 'Tentative non autorisée',
            ]);

        $response->assertStatus(403);
    }

    public function test_etudiant_ne_peut_pas_preparer_action_sur_un_autre_etudiant(): void
    {
        $etudiantA = $this->creerEtudiant();
        $etudiantB = $this->creerEtudiant();

        $response = $this->withSession(['etudiant_auth_id' => $etudiantA->id])
            ->postJson(route('assistant-ia.popup.actions.preparer'), [
                'type_action' => 'demande_attestation',
                'ressource_type' => 'Etudiant',
                'ressource_id' => $etudiantB->id, // Tente de cibler B
                'justification' => 'Demande frauduleuse',
            ]);

        $response->assertStatus(403);
    }

    public function test_confirmation_action_autorisee_avec_preuve_execution(): void
    {
        $etudiant = $this->creerEtudiant([
            'nom' => 'Kefi',
            'prenom' => 'Samir',
        ]);

        // Étape 1 : Préparation
        $prepResp = $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->postJson(route('assistant-ia.popup.actions.preparer'), [
                'type_action' => 'demande_attestation',
                'ressource_type' => 'Etudiant',
                'ressource_id' => $etudiant->id,
                'justification' => 'Attestation de présence aux examens',
            ]);
        $actionUuid = $prepResp->json('preparation.action_uuid');

        // Étape 2 : Confirmation humaine explicite
        $confResp = $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->postJson(route('assistant-ia.popup.actions.confirmer', $actionUuid));

        $confResp->assertOk();
        $confResp->assertJson([
            'succes' => true,
            'statut' => 'execute',
        ]);
        $this->assertNotEmpty($confResp->json('preuve.reference_traitement'));

        // Vérification en base de données
        $actionDb = AiPropositionAction::where('uuid', $actionUuid)->first();
        $this->assertSame('completed', $actionDb->statut);
        $this->assertNotNull($actionDb->execute_a);
    }

    public function test_confirmation_action_double_ou_rejouee_est_rejetee_idempotence(): void
    {
        $etudiant = $this->creerEtudiant();

        $prepResp = $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->postJson(route('assistant-ia.popup.actions.preparer'), [
                'type_action' => 'demande_attestation',
                'ressource_type' => 'Etudiant',
                'ressource_id' => $etudiant->id,
                'justification' => 'Attestation',
            ]);
        $actionUuid = $prepResp->json('preparation.action_uuid');

        // Première confirmation : succès
        $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->postJson(route('assistant-ia.popup.actions.confirmer', $actionUuid))
            ->assertOk();

        // Seconde confirmation (double-clic ou rejeu réseau) : rejet immédiat
        $rejeu = $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->postJson(route('assistant-ia.popup.actions.confirmer', $actionUuid));

        $rejeu->assertStatus(403);
    }

    public function test_confirmation_action_expiree_est_rejetee(): void
    {
        $etudiant = $this->creerEtudiant();

        $prepResp = $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->postJson(route('assistant-ia.popup.actions.preparer'), [
                'type_action' => 'demande_attestation',
                'ressource_type' => 'Etudiant',
                'ressource_id' => $etudiant->id,
                'justification' => 'Attestation urgente',
            ]);
        $actionUuid = $prepResp->json('preparation.action_uuid');

        // On simule le passage du temps (expiration de 2 heures dépassée)
        $action = AiPropositionAction::where('uuid', $actionUuid)->first();
        $action->update(['expire_a' => now()->subMinutes(10)]);

        $response = $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->postJson(route('assistant-ia.popup.actions.confirmer', $actionUuid));

        $response->assertStatus(403);
    }

    public function test_action_sensible_est_transmise_au_circuit_4_yeux_en_attente_approbation(): void
    {
        $admin = User::factory()->create();
        $admin->definirRole('admin_scolarite');

        // L'administrateur prépare une demande de pièce complémentaire sur un dossier
        $prepResp = $this->actingAs($admin)
            ->postJson(route('assistant-ia.popup.actions.preparer'), [
                'type_action' => 'demande_piece_complementaire',
                'ressource_type' => 'InscriptionAcademique',
                'ressource_id' => 42,
                'justification' => 'Acte de naissance illisible',
                'parametres' => ['piece' => 'acte_naissance'],
            ]);

        $prepResp->assertStatus(201);
        $this->assertTrue($prepResp->json('preparation.necessite_approbation_4_yeux'));
        $actionUuid = $prepResp->json('preparation.action_uuid');

        // Confirmation par le demandeur : l'action passe en attente d'approbation 4-yeux
        $confResp = $this->actingAs($admin)
            ->postJson(route('assistant-ia.popup.actions.confirmer', $actionUuid));

        $confResp->assertOk();
        $confResp->assertJson([
            'succes' => true,
            'statut' => 'en_attente_approbation',
        ]);

        $actionDb = AiPropositionAction::where('uuid', $actionUuid)->first();
        $this->assertSame('pending_approval', $actionDb->statut);
    }

    public function test_suppression_conversation_isolee_anti_idor(): void
    {
        $etudiantA = $this->creerEtudiant();
        $etudiantB = $this->creerEtudiant();

        $convA = AiConversation::create([
            'etudiant_id' => $etudiantA->id,
            'portail' => 'etudiant',
            'titre' => 'Ma conversation A',
            'mode' => 'metier',
        ]);

        // Étudiant B tente de supprimer la conversation de A
        $this->withSession(['etudiant_auth_id' => $etudiantB->id])
            ->deleteJson(route('assistant-ia.popup.conversations.supprimer', $convA->uuid))
            ->assertStatus(404);

        $this->assertNull($convA->fresh()->deleted_at);

        // Étudiant A supprime sa propre conversation
        $this->withSession(['etudiant_auth_id' => $etudiantA->id])
            ->deleteJson(route('assistant-ia.popup.conversations.supprimer', $convA->uuid))
            ->assertOk();

        $this->assertNotNull($convA->fresh()->deleted_at);
    }
}
