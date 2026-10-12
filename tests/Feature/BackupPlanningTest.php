<?php

namespace Tests\Feature;

use App\Models\DatabaseBackup;
use App\Models\Parametre;
use App\Services\Sauvegardes\BackupSchedulerService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Planning administrateur des sauvegardes : défaut horaire actif,
 * fréquences, personnalisé jours/heures, valeurs invalides → défauts.
 */
class BackupPlanningTest extends TestCase
{
    use RefreshDatabase;

    private function parametre(string $cle, string $valeur, string $type = 'texte'): void
    {
        Parametre::updateOrCreate(['cle' => $cle], [
            'valeur' => $valeur, 'type' => $type,
            'categorie' => 'sauvegardes', 'description' => 'Test.',
        ]);
        Cache::forget("parametre.{$cle}");
    }

    private function succesIlYa(int $heures, string $base = '2026-10-10 10:00:00'): void
    {
        DatabaseBackup::create([
            'uuid' => (string) Str::uuid(),
            'filename' => 'db_test.sql',
            'disk' => 'local',
            'path' => '/tmp/db_test.sql',
            'database_name' => 'test',
            'connexion' => 'mysql',
            'status' => 'SUCCESS',
            'started_at' => CarbonImmutable::parse($base)->subHours($heures),
            'completed_at' => CarbonImmutable::parse($base)->subHours($heures),
        ]);
    }

    public function test_defaut_horaire_actif(): void
    {
        $plan = app(BackupSchedulerService::class)->plan();

        $this->assertTrue($plan['actif']);
        $this->assertSame('horaire', $plan['frequence']);
        $this->assertSame(1, $plan['intervalle_heures']);
        $this->assertSame(14, $plan['retention_jours']);
        $this->assertSame('local_private', $plan['destination']);
    }

    public function test_stockage_destination_replie_defaut(): void
    {
        $service = app(BackupSchedulerService::class);

        $this->assertSame('local_private', $service->stockageDestination());

        $this->parametre('sauvegarde.plan.destination', 'atlantide');

        $this->assertSame('local_private', $service->stockageDestination());
    }

    public function test_horaire_echu_sans_succes_puis_couvert(): void
    {
        $service = app(BackupSchedulerService::class);
        $maintenant = CarbonImmutable::parse('2026-10-10 10:00:00');

        $this->assertTrue($service->estDue($maintenant));

        $this->succesIlYa(0);

        $this->assertFalse($service->estDue($maintenant));
        $this->assertTrue($service->estDue($maintenant->addHour()));
    }

    public function test_frequence_toutes_6h(): void
    {
        $this->parametre('sauvegarde.plan.frequence', 'toutes_6h');
        $service = app(BackupSchedulerService::class);
        $maintenant = CarbonImmutable::parse('2026-10-10 10:00:00');

        $this->succesIlYa(5);

        $this->assertFalse($service->estDue($maintenant));

        $this->assertTrue($service->estDue($maintenant->addHour()));
    }

    public function test_desactive_jamais_du(): void
    {
        $this->parametre('sauvegarde.plan.actif', '0', 'booleen');

        $this->assertFalse(app(BackupSchedulerService::class)->estDue(CarbonImmutable::parse('2026-10-10 10:00:00')));
        $this->assertNull(app(BackupSchedulerService::class)->prochaineExecution(CarbonImmutable::parse('2026-10-10 10:00:00')));
    }

    public function test_personnalise_jours_et_creneau(): void
    {
        $this->parametre('sauvegarde.plan.frequence', 'personnalise');
        $this->parametre('sauvegarde.plan.jours', 'lundi, mercredi', 'liste');
        $this->parametre('sauvegarde.plan.heure', '08:00');

        $service = app(BackupSchedulerService::class);

        // Samedi 10/10/2026 exclu.
        $this->assertFalse($service->estDue(CarbonImmutable::parse('2026-10-10 10:00:00')));

        // Lundi 12/10/2026, 09:00, après le créneau, sans succès.
        $this->assertTrue($service->estDue(CarbonImmutable::parse('2026-10-12 09:00:00')));

        // Avant le créneau : pas dû.
        $this->assertFalse($service->estDue(CarbonImmutable::parse('2026-10-12 07:59:00')));
    }

    public function test_personnalise_deja_fait_apres_creneau(): void
    {
        $this->parametre('sauvegarde.plan.frequence', 'personnalise');
        $this->parametre('sauvegarde.plan.jours', 'lundi', 'liste');
        $this->parametre('sauvegarde.plan.heure', '08:00');

        DatabaseBackup::create([
            'uuid' => (string) Str::uuid(),
            'filename' => 'db_test.sql', 'disk' => 'local', 'path' => '/tmp/db_test.sql',
            'database_name' => 'test', 'connexion' => 'mysql', 'status' => 'SUCCESS',
            'started_at' => CarbonImmutable::parse('2026-10-12 08:05:00'),
            'completed_at' => CarbonImmutable::parse('2026-10-12 08:05:00'),
        ]);

        $this->assertFalse(app(BackupSchedulerService::class)->estDue(CarbonImmutable::parse('2026-10-12 09:00:00')));
    }

    public function test_valeurs_invalides_repliees_sur_defauts(): void
    {
        $this->parametre('sauvegarde.plan.frequence', 'toutes_les_minutes');
        $this->parametre('sauvegarde.plan.heure', '99:99');
        $this->parametre('sauvegarde.plan.jours', 'journee', 'liste');

        $plan = app(BackupSchedulerService::class)->plan();

        $this->assertSame('horaire', $plan['frequence']);
        $this->assertSame('01:00', $plan['heure']);
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], $plan['jours']);
    }

    public function test_prochaine_execution_horaire(): void
    {
        $service = app(BackupSchedulerService::class);
        $maintenant = CarbonImmutable::parse('2026-10-10 10:00:00');

        $this->succesIlYa(0, '2026-10-10 09:30:00');

        $prochaine = $service->prochaineExecution($maintenant);

        $this->assertNotNull($prochaine);
        $this->assertSame('2026-10-10 10:30:00', $prochaine->format('Y-m-d H:i:s'));
    }
}
