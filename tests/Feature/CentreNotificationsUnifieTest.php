<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Modules\Schedule\Models\Teacher;
use App\Services\Notifications\CentreNotificationsUnifieService;
use App\Services\Notifications\DTO\DemandeNotification;
use App\Services\PreferenceNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Suite de tests pour LOT 02 — Centre de notifications unifié.
 *
 * Couvre :
 * - Distribution multicanale (in-app, outbox, externe)
 * - Respect des préférences d'opt-out et inviolabilité des catégories obligatoires
 * - Idempotence et déduplication par clé unique
 * - Masquage rigoureux des secrets et données sensibles (PII, CIN, mots de passe, cartes)
 * - Traçabilité des statuts d'envoi et reprise sur erreur (retry)
 * - Multi-acteurs (étudiants, enseignants, administrateurs)
 */
class CentreNotificationsUnifieTest extends TestCase
{
    use RefreshDatabase;

    private CentreNotificationsUnifieService $centre;

    private PreferenceNotificationService $preferenceService;

    private Faculte $faculte;

    private Etudiant $etudiant;

    private User $agent;

    private Teacher $enseignant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->centre = app(CentreNotificationsUnifieService::class);
        $this->preferenceService = app(PreferenceNotificationService::class);

        $this->faculte = Faculte::create([
            'code' => 'FST',
            'nom' => 'Faculté des Sciences de Tunis',
            'active' => true,
        ]);

        $this->etudiant = Etudiant::create([
            'cin' => '12345678',
            'nom' => 'Trabelsi',
            'prenom' => 'Yasmine',
            'email' => 'yasmine.trabelsi@uniregistre.tn',
            'sexe' => 'F',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L2',
            'annee_academique' => '2026-2027',
            'adresse' => 'Campus Universitaire El Manar',
            'ville' => 'Tunis',
            'code_postal' => '2092',
            'annee_bac' => '2024',
            'statut' => 'valide',
        ]);

        $this->agent = User::creerCompteBackOffice([
            'name' => 'Agent Scolarité',
            'email' => 'agent.notif@uniregistre.tn',
            'password' => 'MotDePasse123!',
            'faculte_id' => $this->faculte->id,
            'actif' => true,
        ], 'agent_scolarite');

        $this->enseignant = Teacher::create([
            'prenom' => 'Karim',
            'nom' => 'Gharbi',
            'email' => 'karim.gharbi@uniregistre.tn',
            'faculte_id' => $this->faculte->id,
            'statut' => 'titulaire',
            'actif' => true,
        ]);
    }

    public function test_distribution_in_app_succes_et_structure(): void
    {
        $demande = new DemandeNotification(
            destinataire: $this->etudiant,
            categorie: 'inscriptions',
            evenement: 'inscription.valide',
            titre: 'Inscription confirmée',
            message: 'Votre dossier d’inscription académique a été validé avec succès.',
            lien: '/etudiant/dossier',
            canaux: ['in_app'],
            donnees: ['annee' => '2026-2027'],
            priorite: 2
        );

        $resultat = $this->centre->distribuer($demande);

        $this->assertTrue($resultat->succes);
        $this->assertEquals('envoye', $resultat->statut);
        $this->assertNotNull($resultat->notification);

        $this->assertDatabaseHas('notifications', [
            'id' => $resultat->notification->id,
            'etudiant_id' => $this->etudiant->id,
            'type' => 'inscription.valide',
            'titre' => 'Inscription confirmée',
            'priorite' => 2,
        ]);
    }

    public function test_respect_preferences_utilisateur_opt_out(): void
    {
        // L'étudiant désactive la catégorie 'annonces'
        NotificationPreference::create([
            'etudiant_id' => $this->etudiant->id,
            'categorie' => 'annonces',
            'actif' => false,
        ]);

        $demande = new DemandeNotification(
            destinataire: $this->etudiant,
            categorie: 'annonces',
            evenement: 'annonce.generale',
            titre: 'Soirée d’intégration',
            message: 'Bienvenue aux nouveaux inscrits.',
            canaux: ['in_app']
        );

        $resultat = $this->centre->distribuer($demande);

        $this->assertTrue($resultat->succes);
        $this->assertTrue($resultat->aEteIgnoreeParPreference());
        $this->assertNull($resultat->notification);

        // Aucune notification insérée en base
        $this->assertDatabaseMissing('notifications', [
            'etudiant_id' => $this->etudiant->id,
            'type' => 'annonce.generale',
        ]);
    }

    public function test_categories_obligatoires_ignorent_l_opt_out(): void
    {
        // L'étudiant tente de désactiver les résultats officiels
        NotificationPreference::create([
            'etudiant_id' => $this->etudiant->id,
            'categorie' => 'resultats',
            'actif' => false,
        ]);

        $demande = new DemandeNotification(
            destinataire: $this->etudiant,
            categorie: 'resultats',
            evenement: 'deliberation.publiee',
            titre: 'Résultats du Semestre 1',
            message: 'Vos notes et procès-verbal de délibération sont disponibles.',
            canaux: ['in_app']
        );

        $resultat = $this->centre->distribuer($demande);

        $this->assertTrue($resultat->succes);
        $this->assertEquals('envoye', $resultat->statut);
        $this->assertNotNull($resultat->notification);

        $this->assertDatabaseHas('notifications', [
            'etudiant_id' => $this->etudiant->id,
            'type' => 'deliberation.publiee',
        ]);
    }

    public function test_idempotence_et_prevention_des_doublons_par_cle(): void
    {
        $cleUnique = 'inscription_valide_2026_etudiant_'.$this->etudiant->id;

        $demande = new DemandeNotification(
            destinataire: $this->etudiant,
            categorie: 'inscriptions',
            evenement: 'inscription.valide',
            titre: 'Validation Inscription',
            message: 'Notification avec clé d’idempotence.',
            canaux: ['in_app'],
            cleDeduplication: $cleUnique
        );

        // 1er envoi : succès et création
        $resultat1 = $this->centre->distribuer($demande);
        $this->assertEquals('envoye', $resultat1->statut);
        $idInitial = $resultat1->notification->id;

        // 2e envoi avec la même clé : dédupliqué sans réinsérer
        $resultat2 = $this->centre->distribuer($demande);
        $this->assertTrue($resultat2->aEteDedupliquee());
        $this->assertEquals($idInitial, $resultat2->notification->id);

        // Une seule ligne en base
        $this->assertEquals(1, AppNotification::where('etudiant_id', $this->etudiant->id)->count());
    }

    public function test_masquage_automatique_des_donnees_sensibles_et_secrets(): void
    {
        $demande = new DemandeNotification(
            destinataire: $this->etudiant,
            categorie: 'inscriptions',
            evenement: 'securite.reinitialisation',
            titre: 'Code temporaire Bearer 99f8a8bc76d1234567890',
            message: 'Pour CIN 12345678, votre carte 4532-1234-5678-9012 a été débitée.',
            canaux: ['in_app'],
            donnees: [
                'password' => 'SecretMotDePasse123!',
                'token' => 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9',
                'carte_bancaire' => '4532123456789012',
                'annee' => '2026-2027',
            ]
        );

        $resultat = $this->centre->distribuer($demande);

        $this->assertTrue($resultat->succes);
        $notif = $resultat->notification;

        // Le titre doit avoir masqué le jeton Bearer
        $this->assertStringNotContainsString('99f8a8bc76d1234567890', $notif->titre);
        $this->assertStringContainsString('Bearer [MASQUÉ]', $notif->titre);

        // Le message doit avoir masqué la CIN et la carte bancaire
        $this->assertStringNotContainsString('12345678', $notif->message);
        $this->assertStringContainsString('CIN ********', $notif->message);
        $this->assertStringContainsString('****-****-****-9012', $notif->message);

        // Les données structurées doivent avoir masqué les clés sensibles
        $this->assertStringNotContainsString('SecretMotDePasse123!', json_encode($notif->data));
        $this->assertEquals('2026-2027', $notif->data['annee']);
    }

    public function test_distribution_multi_canaux_avec_outbox_transactionnelle(): void
    {
        $demande = new DemandeNotification(
            destinataire: $this->etudiant,
            categorie: 'resultats',
            evenement: 'pv.deliberation.disponible',
            titre: 'PV disponible',
            message: 'Le PV de délibération officiel est publié.',
            canaux: ['in_app', 'outbox'],
            cleDeduplication: 'outbox_pv_'.$this->etudiant->id
        );

        $resultat = $this->centre->distribuer($demande);

        $this->assertTrue($resultat->succes);
        $this->assertNotNull($resultat->notification);
        $this->assertNotNull($resultat->outboxMessage);

        $this->assertDatabaseHas('data_mesh_outbox', [
            'type_evenement' => 'pv.deliberation.disponible',
            'type_agregat' => 'notification',
            'agregat_id' => $resultat->notification->id,
            'cle_deduplication' => 'outbox_pv_'.$this->etudiant->id,
        ]);
    }

    public function test_suivi_de_statut_et_reprise_envoi_externe(): void
    {
        $notification = AppNotification::create([
            'etudiant_id' => $this->etudiant->id,
            'type' => 'alerte.service',
            'titre' => 'Alerte maintenance',
            'message' => 'Coupure planifiée.',
            'data' => [
                'statut_envoi' => 'echec',
                'erreur' => 'Connexion au serveur SMTP refusée',
                'tentatives' => 1,
            ],
            'priorite' => 1,
        ]);

        $statutInitial = $this->centre->statut($notification);
        $this->assertEquals('echec', $statutInitial['statut_envoi']);
        $this->assertEquals(1, $statutInitial['tentatives']);
        $this->assertStringContainsString('SMTP', $statutInitial['erreur']);

        // Test de reprise (retry)
        $repriseReussie = $this->centre->retenter($notification);
        $this->assertTrue($repriseReussie);

        $notification->refresh();
        $this->assertContains($notification->data['statut_envoi'], ['envoye', 'en_attente']);
        $this->assertNull($notification->data['erreur']);
    }

    public function test_distribution_vers_administrateur_et_enseignant(): void
    {
        // 1. Notification pour un membre du personnel administratif (User)
        $demandeUser = new DemandeNotification(
            destinataire: $this->agent,
            categorie: 'inscriptions',
            evenement: 'tache.assignee',
            titre: 'Nouvelle tâche',
            message: 'Dossier à valider.',
            canaux: ['in_app']
        );
        $resUser = $this->centre->distribuer($demandeUser);
        $this->assertTrue($resUser->succes);
        $this->assertEquals($this->agent->id, $resUser->notification->user_id);

        // 2. Notification pour un enseignant (Teacher)
        $demandeTeacher = new DemandeNotification(
            destinataire: $this->enseignant,
            categorie: 'annonces',
            evenement: 'planning.surveillance',
            titre: 'Convocation surveillance',
            message: 'Vous êtes convoqué à la salle 101.',
            canaux: ['in_app']
        );
        $resTeacher = $this->centre->distribuer($demandeTeacher);
        $this->assertTrue($resTeacher->succes);
        $this->assertEquals($this->enseignant->id, $resTeacher->notification->teacher_id);
    }
}
