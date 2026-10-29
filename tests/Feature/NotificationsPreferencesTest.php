<?php

namespace Tests\Feature;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Services\PreferenceNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOT 02 — exposition HTTP des préférences de notification
 * (PreferenceNotificationService::definir(), jamais exposée avant) :
 * consultation opt-out par catégorie, enregistrement, catégories
 * institutionnelles FORCÉES (aucune écriture, jamais désactivables),
 * isolation stricte par session (anti-IDOR).
 */
class NotificationsPreferencesTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private static int $sequence = 99050;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create([
            'nom' => 'Faculté Préférences', 'code' => 'PREF',
            'description' => 'Réservée aux tests de préférences', 'active' => true,
        ]);
    }

    public function test_page_affiche_les_categories_et_force_les_obligatoires(): void
    {
        $etudiant = $this->creerEtudiant();
        session(['etudiant_auth_id' => $etudiant->id]);

        $this->get(route('etudiant.notifications.preferences'))
            ->assertOk()
            ->assertSee('Résultats et délibérations')
            ->assertSee('Documents officiels')
            ->assertSee('Annonces')
            ->assertSee('non désactivable');
    }

    public function test_optout_sauf_categories_obligatoires_par_http(): void
    {
        $etudiant = $this->creerEtudiant();
        session(['etudiant_auth_id' => $etudiant->id]);

        // Résultats COCHÉ volontairement : sa préférence ne doit JAMAIS
        // être écrite (catégorie obligatoire, non désactivable).
        // Cochée = actif, non-cochée = opt-out explicite.
        $this->post(route('etudiant.notifications.preferences.update'), [
            'preferences' => ['resultats' => 'resultats', 'annonces' => 'annonces', 'inscriptions' => 'inscriptions'],
        ])->assertRedirect(route('etudiant.notifications.preferences'))->assertSessionHas('success');

        $this->assertDatabaseHas('notification_preferences', ['etudiant_id' => $etudiant->id, 'categorie' => 'annonces', 'actif' => 1]);
        $this->assertDatabaseHas('notification_preferences', ['etudiant_id' => $etudiant->id, 'categorie' => 'inscriptions', 'actif' => 1]);
        $this->assertDatabaseHas('notification_preferences', ['etudiant_id' => $etudiant->id, 'categorie' => 'demandes', 'actif' => 0]);
        $this->assertDatabaseMissing('notification_preferences', ['etudiant_id' => $etudiant->id, 'categorie' => 'resultats']);
        $this->assertDatabaseMissing('notification_preferences', ['etudiant_id' => $etudiant->id, 'categorie' => 'documents_officiels']);
    }

    public function test_optout_enregistre_puis_respecte_par_le_service(): void
    {
        $etudiant = $this->creerEtudiant();
        session(['etudiant_auth_id' => $etudiant->id]);

        // Annonces décochée (absente du tableau : opt-out explicite),
        // demandes + inscriptions cochées. Le défaut « actif » ne vaut
        // qu'absence de préférence enregistrée (le service le couvre déjà).
        $this->post(route('etudiant.notifications.preferences.update'), [
            'preferences' => ['demandes' => 'demandes', 'inscriptions' => 'inscriptions'],
        ])->assertRedirect();

        $service = app(PreferenceNotificationService::class);
        $this->assertFalse($service->notifiable($etudiant->id, null, 'annonces'));
        $this->assertTrue($service->notifiable($etudiant->id, null, 'demandes'));
        $this->assertTrue($service->notifiable($etudiant->id, null, 'inscriptions'));
        $this->assertTrue($service->notifiable($etudiant->id, null, 'resultats')); // forcé malgré tout
    }

    public function test_anti_idor_isolation_des_sessions(): void
    {
        $a = $this->creerEtudiant();
        $autre = $this->creerEtudiant();
        session(['etudiant_auth_id' => $a->id]);

        $this->post(route('etudiant.notifications.preferences.update'), ['preferences' => []])->assertRedirect();

        $this->assertDatabaseHas('notification_preferences', ['etudiant_id' => $a->id, 'categorie' => 'annonces', 'actif' => 0]);
        $this->assertDatabaseMissing('notification_preferences', ['etudiant_id' => $autre->id]);
    }

    public function test_categorie_inconnue_par_client_est_ignoree(): void
    {
        $etudiant = $this->creerEtudiant();
        session(['etudiant_auth_id' => $etudiant->id]);

        $this->post(route('etudiant.notifications.preferences.update'), [
            'preferences' => ['categorie_inconnue' => 'categorie_inconnue', 'annonces' => 'annonces'],
        ])->assertRedirect();

        $this->assertDatabaseMissing('notification_preferences', ['etudiant_id' => $etudiant->id, 'categorie' => 'categorie_inconnue']);
        $this->assertDatabaseHas('notification_preferences', ['etudiant_id' => $etudiant->id, 'categorie' => 'annonces', 'actif' => 1]);
        $this->assertDatabaseHas('notification_preferences', ['etudiant_id' => $etudiant->id, 'categorie' => 'demandes', 'actif' => 0]);
    }

    private function creerEtudiant(): Etudiant
    {
        $suffixe = ++self::$sequence;

        $etudiant = Etudiant::create([
            'cin' => 'BK'.self::$sequence,
            'nom' => 'Test',
            'prenom' => 'Etudiant',
            'email' => 'pref'.$suffixe.'@example.com',
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
        $etudiant->forceFill(['num_inscription' => 'INS-2026-PREF'.$suffixe])->save();
        $etudiant->definirStatut('valide');

        return $etudiant->fresh();
    }
}
