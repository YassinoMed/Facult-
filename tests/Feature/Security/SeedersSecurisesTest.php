<?php

namespace Tests\Feature\Security;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Support\DemoData;
use Database\Seeders\AdminSeeder;
use Database\Seeders\ArchiveSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/**
 * LOOP-SECURITY-REMEDIATION-01 — seeders sûrs : aucune donnée de
 * démonstration (comptes à mots de passe connus) ne peut être seedée hors
 * local/testing sans opt-in explicite SEED_DEMO_DATA=true, et le compte
 * super-admin initial exige ADMIN_INITIAL_PASSWORD hors démo.
 */
class SeedersSecurisesTest extends TestCase
{
    use RefreshDatabase;

    private function simulerProduction(): void
    {
        $this->app['env'] = 'production';
        config(['security.seed_demo_data' => false]);
    }

    public function test_demo_refusee_hors_local_testing_sans_opt_in(): void
    {
        $this->simulerProduction();

        $this->assertFalse(DemoData::autorisee());
    }

    public function test_demo_activee_par_opt_in_explicite(): void
    {
        $this->app['env'] = 'staging';
        config(['security.seed_demo_data' => true]);

        $this->assertTrue(DemoData::autorisee());
    }

    public function test_archive_seeder_refuse_en_production(): void
    {
        $this->simulerProduction();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('comptes de démonstration');

        app(ArchiveSeeder::class)->run();
    }

    public function test_database_seeder_ne_cree_pas_les_etudiants_demo_en_production(): void
    {
        $this->simulerProduction();

        app(DatabaseSeeder::class)->run();

        // Données de référence seedées (facultés), aucune donnée de démo.
        $this->assertGreaterThanOrEqual(8, Faculte::count());
        $this->assertSame(0, Etudiant::count());
        $this->assertSame(0, User::count());
    }

    public function test_admin_seeder_exige_le_mot_de_passe_initial_en_production(): void
    {
        $this->simulerProduction();
        config(['security.admin_initial_password' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ADMIN_INITIAL_PASSWORD est obligatoire');

        app(AdminSeeder::class)->run();
    }

    public function test_admin_seeder_refuse_un_mot_de_passe_faible_en_production(): void
    {
        $this->simulerProduction();
        config(['security.admin_initial_password' => 'court']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('trop court');

        app(AdminSeeder::class)->run();
    }

    public function test_admin_seeder_cree_le_super_admin_avec_le_mot_de_passe_fourni(): void
    {
        $this->simulerProduction();
        config(['security.admin_initial_password' => 'MotdepasseProd@2026']);

        app(AdminSeeder::class)->run();

        $admin = User::where('email', 'admin@universite.ac.ma')->firstOrFail();

        $this->assertSame('super_admin', $admin->role);
        $this->assertTrue(Hash::check('MotdepasseProd@2026', $admin->password));

        // Aucun compte de démonstration à mot de passe connu.
        $this->assertNull(User::where('email', 'admin@admin.com')->first());
    }

    public function test_admin_seeder_conserve_les_comptes_de_demo_en_local(): void
    {
        app(AdminSeeder::class)->run();

        $this->assertNotNull(User::where('email', 'admin@universite.ac.ma')->first());
        $this->assertNotNull(User::where('email', 'admin@admin.com')->first());
    }
}
