<?php

namespace Tests\Feature;

use App\Contracts\CanalNotificationExterneInterface;
use App\Events\DossierDecisionPrise;
use App\Events\PeriodeInscriptionOuverte;
use App\Events\StudentRegistered;
use App\Jobs\AcheminerNotificationExterneJob;
use App\Models\AnneeUniversitaire;
use App\Models\AppNotification;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\RegistrationPeriod;
use App\Services\CanalNotificationExterneSimule;
use App\Services\NotificationService;
use App\Services\StudentStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * Suite de tests pour la LOOP 12 — Notifications et rappels d'échéance :
 * - Déclenchement des événements : période ouverte, dépôt reçu, échéance proche, décision prise.
 * - Idempotence et déduplication par clé unique.
 * - File d'attente (Queue) et échecs observables.
 * - Tolérance aux pannes du canal externe (fail-soft : transaction intacte).
 * - Contenu minimal et absence totale de données sensibles (zéro PII).
 */
class InscriptionNotificationsEcheancesTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    protected function setUp(): void
    {
        parent::setUp();

        CanalNotificationExterneSimule::reinitialiser();

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences et Techniques',
            'code' => 'FST',
            'active' => true,
        ]);

        $this->annee = AnneeUniversitaire::create([
            'code' => '2026-2027',
            'libelle' => 'Année universitaire 2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);
    }

    private function creerEtudiant(string $cin = 'CIN-NOTIF1', string $statut = 'en_attente'): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin,
            'nom' => 'Ben Salem',
            'prenom' => 'Anis',
            'email' => 'anis.'.uniqid().'@test.ma',
            'telephone' => '+212600112233',
            'date_naissance' => '2004-02-10',
            'sexe' => 'M',
            'adresse' => '15 Avenue Hassan II',
            'ville' => 'Rabat',
            'code_postal' => '10000',
            'annee_bac' => '2023',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'password' => 'Secret@123',
        ]);

        $etudiant->definirStatut($statut);

        return $etudiant->fresh();
    }

    // ── 1. Dépôt reçu & idempotence par clé ─────────────────────────────────────

    public function test_depot_recu_emet_notification_non_sensible_et_idempotente(): void
    {
        $etudiant = $this->creerEtudiant('CIN-DEPOT1');

        // Déclenchement de l'événement dépôt reçu
        StudentRegistered::dispatch($etudiant);

        $notif = AppNotification::where('etudiant_id', $etudiant->id)
            ->where('type', 'dossier.depot')
            ->first();

        $this->assertNotNull($notif);
        $this->assertStringContainsString('bien reçu', $notif->message);
        $this->assertSame(route('etudiant.suivi'), $notif->lien);

        // Zéro PII : aucune mention de la CIN ni du mot de passe
        $this->assertStringNotContainsString('CIN-DEPOT1', $notif->message);
        $this->assertStringNotContainsString('Secret@123', $notif->message);

        // Rejeu de l'événement → pas de duplication
        StudentRegistered::dispatch($etudiant->fresh());

        $this->assertSame(1, AppNotification::where('etudiant_id', $etudiant->id)
            ->where('type', 'dossier.depot')->count());
    }

    // ── 2. Décision prise (validé ou rejeté) ───────────────────────────────────

    public function test_decision_validation_notifie_etudiant_avec_lien_suivi(): void
    {
        $etudiant = $this->creerEtudiant('CIN-DEC-VAL');
        $serviceStatut = app(StudentStatusService::class);

        // Décision : passage à validé
        $serviceStatut->changer($etudiant, 'valide', null, 'Dossier complet et admis');

        $notif = AppNotification::where('etudiant_id', $etudiant->id)
            ->where('type', 'dossier.decision')
            ->first();

        $this->assertNotNull($notif);
        $this->assertStringContainsString('validée', $notif->titre);
        $this->assertSame(route('etudiant.suivi'), $notif->lien);
        $this->assertSame('decision:'.$etudiant->id.':valide', $notif->data['cle_dedup'] ?? null);

        // Rejeu d'événement identique → pas de duplication
        DossierDecisionPrise::dispatch($etudiant->fresh(), 'valide', 'Dossier complet et admis', null);

        $this->assertSame(1, AppNotification::where('etudiant_id', $etudiant->id)
            ->where('type', 'dossier.decision')->count());
    }

    public function test_decision_rejet_emet_message_sobre_sans_pii(): void
    {
        $etudiant = $this->creerEtudiant('CIN-DEC-REJ');
        $serviceStatut = app(StudentStatusService::class);

        $serviceStatut->changer($etudiant, 'rejete', null, 'Capacité d’accueil atteinte');

        $notif = AppNotification::where('etudiant_id', $etudiant->id)
            ->where('type', 'dossier.decision')
            ->first();

        $this->assertNotNull($notif);
        $this->assertStringContainsString('dossier', $notif->titre);
        // Message sobre orientant vers l'espace sécurisé
        $this->assertStringContainsString('espace sécurisé', $notif->message);
        $this->assertSame(route('etudiant.suivi'), $notif->lien);
    }

    // ── 3. Notification d'ouverture de période d'inscription ───────────────────

    public function test_ouverture_periode_notifie_etudiants_concernes(): void
    {
        $etudiant = $this->creerEtudiant('CIN-PER1');

        $periode = RegistrationPeriod::create([
            'annee_universitaire_id' => $this->annee->id,
            'faculte_id' => $this->faculte->id,
            'nom' => 'Campagne Principale 2026',
            'type_periode' => 'normale',
            'opened_at' => now()->subDay(),
            'closed_at' => now()->addDays(10),
            'statut' => 'active',
        ]);

        PeriodeInscriptionOuverte::dispatch($periode);

        $notif = AppNotification::where('etudiant_id', $etudiant->id)
            ->where('type', 'periode.ouverte')
            ->first();

        $this->assertNotNull($notif);
        $this->assertStringContainsString('Campagne Principale 2026', $notif->message);

        // Idempotence lors d'un second déclenchement
        PeriodeInscriptionOuverte::dispatch($periode);

        $this->assertSame(1, AppNotification::where('etudiant_id', $etudiant->id)
            ->where('type', 'periode.ouverte')->count());
    }

    // ── 4. Commande de rappel d'échéances ──────────────────────────────────────

    public function test_commande_rappel_echeances_notifie_dossiers_en_attente(): void
    {
        $etudiant = $this->creerEtudiant('CIN-ECH1', 'en_attente');

        // Période qui se clôture dans 3 jours
        $periode = RegistrationPeriod::create([
            'annee_universitaire_id' => $this->annee->id,
            'faculte_id' => $this->faculte->id,
            'nom' => 'Inscriptions Session 1',
            'type_periode' => 'normale',
            'opened_at' => now()->subDays(5),
            'closed_at' => now()->addDays(3),
            'statut' => 'active',
        ]);

        // Exécution de la commande Artisan
        $this->artisan('inscriptions:rappeler-echeances', ['--jours' => 3])
            ->expectsOutputToContain('Rappels d\'échéances traités : 1 notification(s) envoyée(s)')
            ->assertSuccessful();

        $notif = AppNotification::where('etudiant_id', $etudiant->id)
            ->where('type', 'dossier.echeance_proche')
            ->first();

        $this->assertNotNull($notif);
        $this->assertStringContainsString('Inscriptions Session 1', $notif->message);
        $this->assertSame(route('etudiant.suivi'), $notif->lien);

        // Relance de la commande le même jour → idempotent, notification non dupliquée
        $this->artisan('inscriptions:rappeler-echeances', ['--jours' => 3])
            ->expectsOutputToContain('1 ignorée(s) car déjà transmises')
            ->assertSuccessful();

        $this->assertSame(1, AppNotification::where('etudiant_id', $etudiant->id)
            ->where('type', 'dossier.echeance_proche')->count());
    }

    // ── 5. File d'attente (Queue) et observabilité des échecs ───────────────────

    public function test_acheminement_externe_reussi_met_a_jour_statut_observable(): void
    {
        Queue::fake();

        $etudiant = $this->creerEtudiant('CIN-QUEUE1');
        $service = app(NotificationService::class);

        $notif = $service->notifierEtudiant(
            $etudiant,
            'test.notification',
            'Notification Test',
            'Message sans aucune donnee sensible',
            route('etudiant.suivi')
        );

        Queue::assertPushed(AcheminerNotificationExterneJob::class);
        $this->assertSame('en_attente', $notif->data['statut_envoi'] ?? null);

        // Exécution du job d'acheminement externe
        $job = new AcheminerNotificationExterneJob($notif->id);
        $job->handle(app(CanalNotificationExterneInterface::class));

        $notifFresh = $notif->fresh();
        $this->assertSame('envoye', $notifFresh->data['statut_envoi']);
        $this->assertSame(1, $notifFresh->data['tentatives']);
        $this->assertNull($notifFresh->data['erreur']);
    }

    public function test_panne_canal_externe_reste_observable_et_fail_soft_sans_rompre_metier(): void
    {
        Queue::fake();

        $etudiant = $this->creerEtudiant('CIN-FAIL1');
        $service = app(NotificationService::class);

        $notif = $service->notifierEtudiant(
            $etudiant,
            'test.fail',
            'Test Résilience',
            'Message de test pour panne simulée'
        );

        Queue::assertPushed(AcheminerNotificationExterneJob::class);

        // Simulation d'une panne réseau de la passerelle externe
        CanalNotificationExterneSimule::simulerPanne(new RuntimeException('Passerelle SMS/SMTP indisponible (timeout 504)'));

        $job = new AcheminerNotificationExterneJob($notif->id);

        try {
            $job->handle(app(CanalNotificationExterneInterface::class));
        } catch (\Throwable) {
            // Le job propage l'exception pour permettre les retries de la queue Laravel
        }

        $notifFresh = $notif->fresh();
        $this->assertSame('echec', $notifFresh->data['statut_envoi']);
        $this->assertStringContainsString('timeout 504', $notifFresh->data['erreur']);
        $this->assertSame(1, $notifFresh->data['tentatives']);

        // Rétablissement de la passerelle et reprise
        CanalNotificationExterneSimule::simulerPanne(null);
        $service->retenterEnvoiExterne($notifFresh);

        $jobReprise = new AcheminerNotificationExterneJob($notifFresh->id);
        $jobReprise->handle(app(CanalNotificationExterneInterface::class));

        $notifApresReprise = $notifFresh->fresh();
        $this->assertSame('envoye', $notifApresReprise->data['statut_envoi']);
        $this->assertNull($notifApresReprise->data['erreur']);
    }

    // ── 6. Règle de confidentialité : blocage absolu de PII sensible ───────────

    public function test_canal_externe_interdit_formellement_toute_fuite_de_pii(): void
    {
        $etudiant = $this->creerEtudiant('CIN-SEC1');
        $canal = app(CanalNotificationExterneInterface::class);

        // Tentative de notification contenant une CIN dans le message
        $notifAvecCin = AppNotification::create([
            'etudiant_id' => $etudiant->id,
            'type' => 'test.fuite',
            'titre' => 'Alerte confidentielle',
            'message' => 'Votre CIN 08123456 est erronée', // Fuite directe de CIN
            'data' => ['statut_envoi' => 'en_attente'],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Donnée sensible détectée');

        $canal->envoyer($notifAvecCin);
    }
}
