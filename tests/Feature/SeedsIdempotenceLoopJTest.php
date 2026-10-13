<?php

namespace Tests\Feature;

use App\Models\Parametre;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOOP J — Idempotence des seeds : réexécution sans écrasement.
 */
class SeedsIdempotenceLoopJTest extends TestCase
{
    use RefreshDatabase;

    public function test_reexecution_conserve_personnalisation(): void
    {
        Parametre::where('cle', 'sentinel.notif_cooldown_minutes')->update(['valeur' => '45']);

        (require database_path('migrations/2026_10_12_231817_ajouter_parametres_sentinel.php'))->up();
        (require database_path('migrations/2026_10_07_110016_ajouter_parametres_sauvegardes.php'))->up();

        $this->assertSame('45', Parametre::where('cle', 'sentinel.notif_cooldown_minutes')->value('valeur'));
        $this->assertSame(1, Parametre::where('cle', 'sentinel.notif_cooldown_minutes')->count());
    }
}
