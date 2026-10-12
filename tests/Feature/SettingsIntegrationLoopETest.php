<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\Parametre;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOOP E — Intégration métier : `notifications.purge_jours` pilote la
 * purge hebdomadaire (l'option explicite prime).
 */
class SettingsIntegrationLoopETest extends TestCase
{
    use RefreshDatabase;

    private function etudiantLuDepuis(int $jours): Etudiant
    {
        $faculte = Faculte::create(['nom' => 'FA', 'code' => 'FA'.uniqid(), 'description' => 'T', 'active' => true]);
        $etudiant = Etudiant::create([
            'cin' => 'BK'.random_int(100000, 999999),
            'nom' => 'Purge', 'prenom' => 'Test',
            'email' => uniqid('pg.', true).'@example.com',
            'sexe' => 'M', 'faculte_id' => $faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2025-2026',
            'adresse' => '1 rue', 'ville' => 'Rabat',
            'code_postal' => '10000', 'annee_bac' => '2024',
            'password' => 'motdepasse1',
        ]);
        app(NotificationService::class)->notifierEtudiant($etudiant, 'test', 'Titre', 'Message');
        AppNotification::where('etudiant_id', $etudiant->id)->update(['read_at' => now()->subDays($jours)]);

        return $etudiant;
    }

    public function test_purge_utilise_le_parametre_par_defaut(): void
    {
        Parametre::where('cle', 'notifications.purge_jours')->update(['valeur' => '30']);
        $etudiant = $this->etudiantLuDepuis(40);

        // 40 jours > 30 (paramètre) mais < 180 (ancien défaut codé en dur).
        $this->artisan('notifications:purger')->assertSuccessful();
        $this->assertSame(0, AppNotification::where('etudiant_id', $etudiant->id)->count());
    }

    public function test_option_explicite_prime_sur_parametre(): void
    {
        Parametre::where('cle', 'notifications.purge_jours')->update(['valeur' => '30']);
        $etudiant = $this->etudiantLuDepuis(40);

        $this->artisan('notifications:purger', ['--jours' => 180])->assertSuccessful();
        $this->assertSame(1, AppNotification::where('etudiant_id', $etudiant->id)->count());
    }
}
