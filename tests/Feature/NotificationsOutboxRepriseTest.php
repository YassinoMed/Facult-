<?php

namespace Tests\Feature;

use App\Contracts\CanalNotificationExterneInterface;
use App\Models\AppNotification;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Services\CanalNotificationExterneSimule;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * LOT 02 — outbox durable et reprise des envois externes :
 * `data.statut_envoi` de app_notifications est la file (écrite avant le
 * dispatch du job). Un envoi `echec` / `echec_definitif` ou un
 * `en_attente` oublié est replanifié par le scan ; un `envoye` ne l'est
 * JAMAIS (idempotence). Les états d'outbox sont construits directement
 * dans ces tests (simule une file morte avant consommation du job).
 */
class NotificationsOutboxRepriseTest extends TestCase
{
    use RefreshDatabase;

    private static int $sequence = 99000;

    protected function setUp(): void
    {
        parent::setUp();

        CanalNotificationExterneSimule::reinitialiser();

        $this->faculte = Faculte::create([
            'nom' => 'Faculté Outbox', 'code' => 'OUT',
            'description' => 'Réservée aux tests de reprise des envois', 'active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        CanalNotificationExterneSimule::reinitialiser();

        parent::tearDown();
    }

    public function test_outbox_reprend_echec_definitif_et_revient_a_envoye(): void
    {
        $notification = $this->creerNotification($this->creerEtudiant(), 'echec_definitif', 90);

        $reprises = app(NotificationService::class)->reprendreEchecsExterieurs();

        $this->assertSame(1, $reprises);
        $this->assertSame('envoye', $notification->fresh()->data['statut_envoi']);
        $this->assertCount(1, CanalNotificationExterneSimule::envoyees());

        // Idempotence : un envoi réussi n'est JAMAIS repris.
        $this->assertSame(0, app(NotificationService::class)->reprendreEchecsExterieurs());
    }

    public function test_outbox_ignore_les_statuts_fraichement_mis_a_jour(): void
    {
        $notification = $this->creerNotification($this->creerEtudiant(), 'echec', 10);

        $reprises = app(NotificationService::class)->reprendreEchecsExterieurs();

        $this->assertSame(0, $reprises);
        $this->assertSame('echec', $notification->fresh()->data['statut_envoi']);
        $this->assertCount(0, CanalNotificationExterneSimule::envoyees());
    }

    public function test_outbox_reprend_un_en_attente_oublie(): void
    {
        $notification = $this->creerNotification($this->creerEtudiant(), 'en_attente', 120);

        $reprises = app(NotificationService::class)->reprendreEchecsExterieurs();

        $this->assertSame(1, $reprises);
        $this->assertSame('envoye', $notification->fresh()->data['statut_envoi']);
    }

    public function test_outbox_ne_reprend_jamais_un_envoye(): void
    {
        $notification = $this->creerNotification($this->creerEtudiant(), 'envoye', 400);
        $reprises = app(NotificationService::class)->reprendreEchecsExterieurs();

        $this->assertSame(0, $reprises);
        $this->assertSame('envoye', $notification->fresh()->data['statut_envoi']);
        $this->assertCount(0, CanalNotificationExterneSimule::envoyees());
    }

    public function test_outbox_ne_reprend_pas_les_echecs_trop_anciens(): void
    {
        // 49 h > fenêtre max (48 h) : l'échec devient une affaire d'ops,
        // jamais de martèlement automatique d'une passerelle morte.
        $notification = $this->creerNotification($this->creerEtudiant(), 'echec_definitif', 60 * 49);

        $reprises = app(NotificationService::class)->reprendreEchecsExterieurs();

        $this->assertSame(0, $reprises);
        $this->assertSame('echec_definitif', $notification->fresh()->data['statut_envoi']);
        $this->assertCount(0, CanalNotificationExterneSimule::envoyees());
    }

    public function test_outbox_limite_par_scan_borne_le_debit(): void
    {
        $etudiants = [$this->creerEtudiant(), $this->creerEtudiant(), $this->creerEtudiant()];
        $fails = [];

        foreach ($etudiants as $etudiant) {
            $fails[] = $this->creerNotification($etudiant, 'echec_definitif', 180);
        }

        $reprises = app(NotificationService::class)->reprendreEchecsExterieurs(limite: 2);

        $this->assertSame(2, $reprises);
        $restantes = collect($fails)->map(fn ($n) => $n->fresh()->data['statut_envoi']);
        $this->assertSame(1, $restantes->filter(fn ($s) => $s === CanalNotificationExterneInterface::STATUT_ECHEC_DEFINITIF)->count());
    }

    public function test_outbox_echec_canal_toujours_traque_sans_exception(): void
    {
        CanalNotificationExterneSimule::simulerPanne(new RuntimeException('Passerelle HS'));
        $notification = $this->creerNotification($this->creerEtudiant(), 'echec_definitif', 90);

        $reprises = app(NotificationService::class)->reprendreEchecsExterieurs();

        $this->assertSame(1, $reprises);
        $donnees = $notification->fresh()->data;
        // Avec la queue sync des tests, l'exception déclenche directement
        // failed() → echec_definitif ; avec une vraie file, le statut passe
        // d'abord par à 'echec' (tentatives restantes). Ici : échec, toujours
        // tracé, et AUCUNE exception ne fuit vers l'appelant.
        $this->assertContains($donnees['statut_envoi'], [
            CanalNotificationExterneInterface::STATUT_ECHEC,
            CanalNotificationExterneInterface::STATUT_ECHEC_DEFINITIF,
        ]);
        $this->assertSame('Passerelle HS', $donnees['erreur']);
    }

    public function test_commande_reprend_echecs_replanifie_et_reussit(): void
    {
        $this->creerNotification($this->creerEtudiant(), 'echec_definitif', 90);

        $this->artisan('notifications:reprendre-echecs')
            ->expectsOutput('1 envoi(s) externe(s) replanifié(s) pour reprise.')
            ->assertSuccessful();

        $this->assertCount(1, CanalNotificationExterneSimule::envoyees());
    }

    private function creerEtudiant(): Etudiant
    {
        $suffixe = ++self::$sequence;

        $etudiant = Etudiant::create([
            'cin' => 'BK'.self::$sequence,
            'nom' => 'Test',
            'prenom' => 'Etudiant',
            'email' => 'outbox'.$suffixe.'@example.com',
            'sexe' => 'M',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => 'Rue',
            'ville' => 'Casa',
            'code_postal' => '20000',
            'annee_bac' => '2024',
            'password' => 'motdepasse1',
        ]);
        $etudiant->forceFill(['num_inscription' => 'INS-2026-OUT'.$suffixe])->save();
        $etudiant->definirStatut('valide');

        return $etudiant->fresh();
    }

    /** Construit un état d'outbox en back-datant updated_at (stale). */
    private function creerNotification(Etudiant $etudiant, string $statutEnvoi, int $minutesAge): AppNotification
    {
        $notification = AppNotification::create([
            'etudiant_id' => $etudiant->id,
            'type' => 'externe.test',
            'titre' => 'Envoi externe',
            'message' => 'Message du canal externe.',
            'data' => ['statut_envoi' => $statutEnvoi, 'tentatives' => 3],
            'priorite' => 1,
        ]);

        AppNotification::query()
            ->where('id', $notification->id)
            ->update(['updated_at' => now()->subMinutes($minutesAge)]);

        return $notification->fresh();
    }
}
