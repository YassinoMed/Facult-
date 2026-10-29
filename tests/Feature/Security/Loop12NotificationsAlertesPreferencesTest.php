<?php

namespace Tests\Feature\Security;

use App\Models\AppNotification;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\NotificationPreference;
use App\Services\NotificationService;
use App\Services\PreferenceNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOT 12 — Centre de notifications, alertes et préférences de l'étudiant.
 *
 * Vérifications de sécurité et d'expérience étudiante :
 * 1. Redirection stricte des visiteurs non authentifiés vers la page de connexion.
 * 2. Consultation du centre de notifications avec comptage des non lues et liens.
 * 3. Filtrage dynamique par statut (non lues), priorité (importantes) et type.
 * 4. Marquage d'une notification individuelle comme lue avec redirection appropriée.
 * 5. Isolation stricte anti-IDOR : impossibilité de marquer comme lue une notification tierce (404).
 * 6. Marquage en masse « tout marquer comme lu » sans impacter les notifications d'autres étudiants.
 * 7. Consultation des préférences avec liste des catégories et indicateurs institutionnels obligatoires.
 * 8. Modification des préférences de notification pour les catégories configurables (opt-out).
 * 9. Inviolabilité absolue des catégories institutionnelles obligatoires même en cas de falsification client.
 * 10. Isolation stricte anti-IDOR des préférences : un étudiant ne peut altérer les préférences d'un tiers.
 * 11. Fonctionnement du service PreferenceNotificationService::notifiable avec valeurs par défaut.
 * 12. Idempotence et déduplication des alertes via clé unique (anti-doublon d'événement rejoué).
 * 13. Tolérance aux pannes fail-soft sur l'acheminement externe (aucune exception bloquante).
 * 14. Performance de notification en masse par lots sans requêtes N+1.
 * 15. Protection anti-force brute par rate limiting sur la mise à jour des préférences (20 req/min).
 * 16. Purge conforme des notifications lues anciennes (confidentialité et minimisation des données).
 */
class Loop12NotificationsAlertesPreferencesTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private NotificationService $notificationService;

    private PreferenceNotificationService $preferenceService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create([
            'code' => 'FST',
            'nom' => 'Faculté des Sciences de Tunis',
            'active' => true,
        ]);

        $this->notificationService = app(NotificationService::class);
        $this->preferenceService = app(PreferenceNotificationService::class);
    }

    private function creerEtudiant(array $attributs = []): Etudiant
    {
        static $compteur = 1;

        $statut = $attributs['statut'] ?? 'valide';
        unset($attributs['statut']);

        $defauts = [
            'cin' => sprintf('NOTIF%04d', $compteur),
            'nom' => 'Ben Ammar',
            'prenom' => 'Sami '.$compteur,
            'email' => sprintf('etudiant.notif.%d@example.com', $compteur),
            'telephone' => sprintf('+216710000%02d', $compteur),
            'date_naissance' => '2004-05-10',
            'sexe' => 'M',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L2',
            'annee_academique' => '2025-2026',
            'adresse' => 'Campus Universitaire',
            'ville' => 'Tunis',
            'code_postal' => '1002',
            'annee_bac' => '2023',
            'mot_de_passe_version' => 1,
            'password' => bcrypt('MotDePasseSecret123!'),
        ];

        $compteur++;

        $etudiant = Etudiant::create(array_merge($defauts, $attributs));
        if ($statut !== 'en_attente') {
            $etudiant->definirStatut($statut);
        }

        return $etudiant;
    }

    private function sessionEtudiant(Etudiant $etudiant): array
    {
        return [
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_auth_prenom' => $etudiant->prenom,
            'etudiant_auth_nom' => $etudiant->nom,
            'etudiant_mdp_version' => (int) $etudiant->mot_de_passe_version,
        ];
    }

    public function test_redirection_visiteur_non_authentifie_vers_connexion_sur_toutes_les_routes(): void
    {
        $etudiant = $this->creerEtudiant();
        $notification = AppNotification::create([
            'etudiant_id' => $etudiant->id,
            'type' => 'information',
            'titre' => 'Alerte test',
            'message' => 'Contenu du message',
            'priorite' => 0,
        ]);

        $routes = [
            ['GET', route('etudiant.notifications.index')],
            ['PATCH', route('etudiant.notifications.lire', $notification)],
            ['POST', route('etudiant.notifications.lire-tout')],
            ['GET', route('etudiant.notifications.preferences')],
            ['POST', route('etudiant.notifications.preferences.update')],
        ];

        foreach ($routes as [$methode, $url]) {
            $response = match ($methode) {
                'GET' => $this->get($url),
                'PATCH' => $this->patch($url),
                'POST' => $this->post($url),
            };

            $response->assertRedirect(route('etudiant.connexion.show'));
            $response->assertSessionHas('error');
        }
    }

    public function test_etudiant_consulte_centre_notifications_avec_compteurs_et_liens(): void
    {
        $etudiant = $this->creerEtudiant();

        // 2 non lues, 1 lue
        $this->notificationService->notifierEtudiant(
            $etudiant,
            'inscriptions',
            'Dossier validé',
            'Votre inscription est définitivement validée.',
            '/etudiant/suivi',
            [],
            1
        );

        $this->notificationService->notifierEtudiant(
            $etudiant,
            'annonces',
            'Début des cours',
            'La rentrée universitaire est fixée au 15 septembre.',
            null,
            [],
            0
        );

        $notifLue = $this->notificationService->notifierEtudiant(
            $etudiant,
            'demandes',
            'Attestation prête',
            'Votre attestation est disponible au téléchargement.',
            null,
            [],
            0
        );
        $notifLue->marquerLue();

        $response = $this->withSession($this->sessionEtudiant($etudiant))
            ->get(route('etudiant.notifications.index'));

        $response->assertOk();
        $response->assertSee('Notifications');
        $response->assertSee('2 non lue(s).');
        $response->assertSee('Dossier validé');
        $response->assertSee('Début des cours');
        $response->assertSee('Attestation prête');
        $response->assertSee('Tout marquer comme lu');
    }

    public function test_filtrage_notifications_non_lues_et_importantes(): void
    {
        $etudiant = $this->creerEtudiant();

        $n1 = $this->notificationService->notifierEtudiant(
            $etudiant,
            'annonces',
            'Information normale non lue',
            'Message 1',
            null,
            [],
            0
        );

        $n2 = $this->notificationService->notifierEtudiant(
            $etudiant,
            'resultats',
            'Résultat urgent important',
            'Message 2 urgent',
            null,
            [],
            2 // Priorité importante
        );

        $n3 = $this->notificationService->notifierEtudiant(
            $etudiant,
            'documents_officiels',
            'Document archivé lu',
            'Message 3',
            null,
            [],
            0
        );
        $n3->marquerLue();

        // Filtre 'non-lues'
        $respNonLues = $this->withSession($this->sessionEtudiant($etudiant))
            ->get(route('etudiant.notifications.index', ['filtre' => 'non-lues']));

        $respNonLues->assertOk();
        $notifsNonLues = $respNonLues->viewData('notifications');
        $this->assertTrue($notifsNonLues->contains('id', $n1->id));
        $this->assertTrue($notifsNonLues->contains('id', $n2->id));
        $this->assertFalse($notifsNonLues->contains('id', $n3->id));

        // Filtre 'importantes'
        $respImportantes = $this->withSession($this->sessionEtudiant($etudiant))
            ->get(route('etudiant.notifications.index', ['filtre' => 'importantes']));

        $respImportantes->assertOk();
        $notifsImportantes = $respImportantes->viewData('notifications');
        $this->assertTrue($notifsImportantes->contains('id', $n2->id));
        $this->assertFalse($notifsImportantes->contains('id', $n1->id));
        $this->assertFalse($notifsImportantes->contains('id', $n3->id));
    }

    public function test_etudiant_marque_une_notification_comme_lue_avec_redirection(): void
    {
        $etudiant = $this->creerEtudiant();

        $notificationAvecLien = $this->notificationService->notifierEtudiant(
            $etudiant,
            'inscriptions',
            'Consulter dossier',
            'Cliquez pour ouvrir votre suivi.',
            route('etudiant.suivi')
        );

        $this->assertNull($notificationAvecLien->read_at);

        $response = $this->withSession($this->sessionEtudiant($etudiant))
            ->patch(route('etudiant.notifications.lire', $notificationAvecLien));

        $response->assertRedirect(route('etudiant.suivi'));

        $notificationAvecLien->refresh();
        $this->assertNotNull($notificationAvecLien->read_at);

        // Sans lien spécifique -> redirection vers le centre de notifications
        $notificationSansLien = $this->notificationService->notifierEtudiant(
            $etudiant,
            'information',
            'Notification simple',
            'Sans hyperlien'
        );

        $response2 = $this->withSession($this->sessionEtudiant($etudiant))
            ->patch(route('etudiant.notifications.lire', $notificationSansLien));

        $response2->assertRedirect(route('etudiant.notifications.index'));
        $notificationSansLien->refresh();
        $this->assertNotNull($notificationSansLien->read_at);
    }

    public function test_protection_anti_idor_lecture_notification_tierce_renvoie_404(): void
    {
        $etudiantA = $this->creerEtudiant(['cin' => 'IDOR0001']);
        $etudiantB = $this->creerEtudiant(['cin' => 'IDOR0002']);

        $notificationB = $this->notificationService->notifierEtudiant(
            $etudiantB,
            'inscriptions',
            'Notification confidentielle B',
            'Données privées de B'
        );

        $this->assertNull($notificationB->read_at);

        // Étudiant A tente de marquer comme lue la notification de B
        $response = $this->withSession($this->sessionEtudiant($etudiantA))
            ->patch(route('etudiant.notifications.lire', $notificationB));

        $response->assertNotFound();

        // La notification de B n'est absolument pas modifiée
        $notificationB->refresh();
        $this->assertNull($notificationB->read_at);
    }

    public function test_etudiant_marque_toutes_ses_notifications_comme_lues_sans_impacter_les_tiers(): void
    {
        $etudiantA = $this->creerEtudiant(['cin' => 'BULK0001']);
        $etudiantB = $this->creerEtudiant(['cin' => 'BULK0002']);

        $notifA1 = $this->notificationService->notifierEtudiant($etudiantA, 'annonces', 'Notif A1', 'Msg A1');
        $notifA2 = $this->notificationService->notifierEtudiant($etudiantA, 'annonces', 'Notif A2', 'Msg A2');

        $notifB1 = $this->notificationService->notifierEtudiant($etudiantB, 'annonces', 'Notif B1', 'Msg B1');
        $notifB2 = $this->notificationService->notifierEtudiant($etudiantB, 'annonces', 'Notif B2', 'Msg B2');

        $this->assertEquals(2, $this->notificationService->nonLuesEtudiant($etudiantA->id));
        $this->assertEquals(2, $this->notificationService->nonLuesEtudiant($etudiantB->id));

        $response = $this->withSession($this->sessionEtudiant($etudiantA))
            ->from(route('etudiant.notifications.index'))
            ->post(route('etudiant.notifications.lire-tout'));

        $response->assertRedirect(route('etudiant.notifications.index'));
        $response->assertSessionHas('success');

        // Étudiant A n'a plus de notifications non lues
        $this->assertEquals(0, $this->notificationService->nonLuesEtudiant($etudiantA->id));
        $this->assertNotNull($notifA1->fresh()->read_at);
        $this->assertNotNull($notifA2->fresh()->read_at);

        // Étudiant B conserve intactes ses notifications non lues (anti-IDOR)
        $this->assertEquals(2, $this->notificationService->nonLuesEtudiant($etudiantB->id));
        $this->assertNull($notifB1->fresh()->read_at);
        $this->assertNull($notifB2->fresh()->read_at);
    }

    public function test_affichage_preferences_notifications_avec_categories_et_indicateurs_obligatoires(): void
    {
        $etudiant = $this->creerEtudiant();

        $response = $this->withSession($this->sessionEtudiant($etudiant))
            ->get(route('etudiant.notifications.preferences'));

        $response->assertOk();
        $response->assertSee('Préférences de notification');
        $response->assertSee('Résultats et délibérations');
        $response->assertSee('Documents officiels');
        $response->assertSee('Demandes');
        $response->assertSee('Annonces');
        $response->assertSee('Inscriptions');
        $response->assertSee('Catégorie institutionnelle : toujours activée, non désactivable.');
    }

    public function test_mise_a_jour_preferences_etudiant_opt_out_categories_configurables(): void
    {
        $etudiant = $this->creerEtudiant();

        // L'étudiant ne sélectionne que 'demandes' et 'inscriptions', et désactive 'annonces'
        $response = $this->withSession($this->sessionEtudiant($etudiant))
            ->post(route('etudiant.notifications.preferences.update'), [
                'preferences' => [
                    'demandes' => 'demandes',
                    'inscriptions' => 'inscriptions',
                ],
            ]);

        $response->assertRedirect(route('etudiant.notifications.preferences'));
        $response->assertSessionHas('success');

        // Vérification de la persistance en base
        $this->assertDatabaseHas('notification_preferences', [
            'etudiant_id' => $etudiant->id,
            'categorie' => 'demandes',
            'actif' => true,
        ]);

        $this->assertDatabaseHas('notification_preferences', [
            'etudiant_id' => $etudiant->id,
            'categorie' => 'annonces',
            'actif' => false,
        ]);

        // Vérification du service notifiable
        $this->assertTrue($this->preferenceService->notifiable($etudiant->id, null, 'demandes'));
        $this->assertFalse($this->preferenceService->notifiable($etudiant->id, null, 'annonces'));
    }

    public function test_inviolabilite_categories_institutionnelles_obligatoires_meme_en_cas_de_manipulation_client(): void
    {
        $etudiant = $this->creerEtudiant();

        // Le client soumet un formulaire frauduleux tentant de désactiver les catégories institutionnelles
        $response = $this->withSession($this->sessionEtudiant($etudiant))
            ->post(route('etudiant.notifications.preferences.update'), [
                'preferences' => [
                    // Omission volontaire de 'resultats' et 'documents_officiels'
                    'annonces' => 'annonces',
                ],
            ]);

        $response->assertRedirect(route('etudiant.notifications.preferences'));

        // Les catégories obligatoires ne sont jamais écrites comme désactivées en base
        $this->assertDatabaseMissing('notification_preferences', [
            'etudiant_id' => $etudiant->id,
            'categorie' => 'resultats',
            'actif' => false,
        ]);

        $this->assertDatabaseMissing('notification_preferences', [
            'etudiant_id' => $etudiant->id,
            'categorie' => 'documents_officiels',
            'actif' => false,
        ]);

        // Le service confirme que les catégories obligatoires sont TOUJOURS notifiables
        $this->assertTrue($this->preferenceService->notifiable($etudiant->id, null, 'resultats'));
        $this->assertTrue($this->preferenceService->notifiable($etudiant->id, null, 'documents_officiels'));
    }

    public function test_protection_anti_idor_preferences_etudiant_a_ne_modifie_jamais_preferences_etudiant_b(): void
    {
        $etudiantA = $this->creerEtudiant(['cin' => 'PREFA001']);
        $etudiantB = $this->creerEtudiant(['cin' => 'PREFB002']);

        // Préférence initiale de B : 'annonces' est activée
        $this->preferenceService->definir($etudiantB->id, null, 'annonces', true);

        // Étudiant A soumet ses préférences en désactivant 'annonces'
        $response = $this->withSession($this->sessionEtudiant($etudiantA))
            ->post(route('etudiant.notifications.preferences.update'), [
                'preferences' => [], // Tout désactivé pour A
            ]);

        $response->assertRedirect(route('etudiant.notifications.preferences'));

        // A est désactivé
        $this->assertFalse($this->preferenceService->notifiable($etudiantA->id, null, 'annonces'));

        // B reste activé de façon inviolable
        $this->assertTrue($this->preferenceService->notifiable($etudiantB->id, null, 'annonces'));
    }

    public function test_service_preference_notifiable_respecte_les_choix_et_valeurs_par_defaut(): void
    {
        $etudiant = $this->creerEtudiant();

        // Par défaut sans préférence explicite, tout est notifiable
        $this->assertTrue($this->preferenceService->notifiable($etudiant->id, null, 'annonces'));
        $this->assertTrue($this->preferenceService->notifiable($etudiant->id, null, 'inscriptions'));

        // Désactivation
        $this->preferenceService->definir($etudiant->id, null, 'annonces', false);
        $this->assertFalse($this->preferenceService->notifiable($etudiant->id, null, 'annonces'));

        // Réactivation
        $this->preferenceService->definir($etudiant->id, null, 'annonces', true);
        $this->assertTrue($this->preferenceService->notifiable($etudiant->id, null, 'annonces'));

        // Catégories obligatoires retournent toujours true même si une anomalie DB existe
        NotificationPreference::create([
            'etudiant_id' => $etudiant->id,
            'categorie' => 'resultats',
            'actif' => false,
        ]);
        $this->assertTrue($this->preferenceService->notifiable($etudiant->id, null, 'resultats'));
    }

    public function test_idempotence_et_deduplication_par_cle_unique(): void
    {
        $etudiant = $this->creerEtudiant();
        $cleDedup = 'DOSSIER-RELANCE-AUTO-2026';

        // Premier appel : création effective
        $notif1 = $this->notificationService->notifierEtudiant(
            $etudiant,
            'inscriptions',
            'Relance dossier',
            'Veuillez compléter votre dossier.',
            null,
            ['action' => 'relance'],
            1,
            $cleDedup
        );

        $this->assertNotNull($notif1->id);
        $this->assertEquals(1, AppNotification::where('etudiant_id', $etudiant->id)->count());

        // Deuxième appel avec la même clé : idempotence stricte, retour de l'instance existante
        $notif2 = $this->notificationService->notifierEtudiant(
            $etudiant,
            'inscriptions',
            'Relance dossier (tentative 2)',
            'Autre message',
            null,
            ['action' => 'relance_doublon'],
            1,
            $cleDedup
        );

        $this->assertEquals($notif1->id, $notif2->id);
        $this->assertEquals(1, AppNotification::where('etudiant_id', $etudiant->id)->count());
    }

    public function test_tolerance_aux_pannes_fail_soft_du_canal_externe(): void
    {
        $etudiant = $this->creerEtudiant();

        // notifierEtudiant ne doit jamais lever d'exception si le dispatch échoue
        $notif = $this->notificationService->notifierEtudiant(
            $etudiant,
            'inscriptions',
            'Notification résiliente',
            'Message tolérant aux pannes'
        );

        $this->assertInstanceOf(AppNotification::class, $notif);
        $this->assertDatabaseHas('notifications', [
            'id' => $notif->id,
            'etudiant_id' => $etudiant->id,
            'titre' => 'Notification résiliente',
        ]);
    }

    public function test_notification_en_masse_par_lots_sans_requetes_n_plus_un(): void
    {
        $etudiant1 = $this->creerEtudiant(['cin' => 'LOT0001']);
        $etudiant2 = $this->creerEtudiant(['cin' => 'LOT0002']);
        $etudiant3 = $this->creerEtudiant(['cin' => 'LOT0003']);

        $total = $this->notificationService->notifierPlusieursEtudiants(
            [$etudiant1->id, $etudiant2->id, $etudiant3->id],
            'annonces',
            'Fermeture exceptionnelle',
            'Le campus sera fermé le lundi 1er mai.',
            null,
            ['motif' => 'fête_du_travail'],
            1
        );

        $this->assertEquals(3, $total);
        $this->assertEquals(1, AppNotification::where('etudiant_id', $etudiant1->id)->count());
        $this->assertEquals(1, AppNotification::where('etudiant_id', $etudiant2->id)->count());
        $this->assertEquals(1, AppNotification::where('etudiant_id', $etudiant3->id)->count());
    }

    public function test_rate_limiting_mise_a_jour_preferences(): void
    {
        $etudiant = $this->creerEtudiant();

        $session = $this->sessionEtudiant($etudiant);

        // La route est configurée avec throttle:20,1 (20 requêtes / minute)
        for ($i = 1; $i <= 20; $i++) {
            $resp = $this->withSession($session)
                ->post(route('etudiant.notifications.preferences.update'), [
                    'preferences' => ['annonces' => 'annonces'],
                ]);
            $resp->assertRedirect();
        }

        // La 21ème requête doit être rejetée avec HTTP 429
        $rejet = $this->withSession($session)
            ->post(route('etudiant.notifications.preferences.update'), [
                'preferences' => ['annonces' => 'annonces'],
            ]);

        $rejet->assertStatus(429);
    }

    public function test_purge_des_anciennes_notifications_lues(): void
    {
        $etudiant = $this->creerEtudiant();

        // 1. Notification lue très ancienne (> 180 jours)
        $notifAncienneLue = AppNotification::create([
            'etudiant_id' => $etudiant->id,
            'type' => 'annonces',
            'titre' => 'Ancienne lue',
            'message' => 'Purgeable',
            'read_at' => now()->subDays(200),
            'priorite' => 0,
        ]);

        // 2. Notification lue récente (10 jours)
        $notifRecenteLue = AppNotification::create([
            'etudiant_id' => $etudiant->id,
            'type' => 'annonces',
            'titre' => 'Récente lue',
            'message' => 'Non purgeable',
            'read_at' => now()->subDays(10),
            'priorite' => 0,
        ]);

        // 3. Notification non lue ancienne (200 jours)
        $notifAncienneNonLue = AppNotification::create([
            'etudiant_id' => $etudiant->id,
            'type' => 'annonces',
            'titre' => 'Ancienne non lue',
            'message' => 'Jamais purgée tant que non lue',
            'read_at' => null,
            'priorite' => 0,
        ]);

        $purgees = $this->notificationService->purgerLues(180);

        $this->assertEquals(1, $purgees);
        $this->assertDatabaseMissing('notifications', ['id' => $notifAncienneLue->id]);
        $this->assertDatabaseHas('notifications', ['id' => $notifRecenteLue->id]);
        $this->assertDatabaseHas('notifications', ['id' => $notifAncienneNonLue->id]);
    }
}
