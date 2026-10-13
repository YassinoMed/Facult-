<?php

namespace Tests\Feature;

use App\Services\ParametreService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * LOOP D — Preuve MySQL deux connexions (R-C-01) à travers le service.
 *
 * T1 (hold) exécute `ParametreService::set()` ; un listener synchronisé
 * sur l'INSERT de version rejoue T2 (autre PDO) qui relit l'ancienne
 * valeur entre `forget` et commit ; après commit, la lecture finale doit
 * voir la nouvelle valeur (le `forget` post-commit purge le re-scellement).
 * Sans le fix : lecture finale obsolète (RED).
 *
 * Exécution EXCLUSIVE : SETTINGS_CONC_MYSQL=1, base scratch
 * `settings_conc_scratch` (jamais dev/prod). Sinon : skip.
 */
class SettingsCacheRaceMysqlTest extends TestCase
{
    public function test_relecture_concurrente_ne_rescelle_pas(): void
    {
        if (env('SETTINGS_CONC_MYSQL') !== '1') {
            $this->markTestSkipped('Course cache MySQL : SETTINGS_CONC_MYSQL=1 requis (base scratch dédiée).');
        }

        $admin = array_merge(config('database.connections.mysql'), ['database' => null]);
        config(['database.connections.settings_conc_admin' => $admin]);
        DB::connection('settings_conc_admin')->statement('CREATE DATABASE IF NOT EXISTS settings_conc_scratch');
        config(['database.connections.settings_conc' => array_merge(
            config('database.connections.mysql'), ['database' => 'settings_conc_scratch']
        )]);
        config(['database.connections.settings_conc_hold' => array_merge(
            config('database.connections.mysql'), ['database' => 'settings_conc_scratch']
        )]);
        $this->assertSame('settings_conc_scratch', DB::connection('settings_conc')->getDatabaseName(),
            'Garde-fou : jamais le dataset dev.');

        Artisan::call('migrate', ['--database' => 'settings_conc', '--force' => true]);
        $defaut = config('database.default');
        $service = app(ParametreService::class);

        try {
            DB::setDefaultConnection('settings_conc');
            DB::table('parametres')->updateOrInsert(
                ['cle' => 'etablissement.nom'],
                ['valeur' => 'Université', 'type' => 'texte', 'categorie' => 'etablissement', 'created_at' => now(), 'updated_at' => now()]
            );
            DB::table('parametre_versions')->whereIn(
                'parametre_id',
                DB::table('parametres')->where('cle', 'etablissement.nom')->select('id')
            )->delete();
            Cache::forget('parametre.etablissement.nom');

            $phase = 'attente_version';
            DB::listen(function ($requete) use ($service, &$phase): void {
                $sql = (string) $requete->sql;
                if ($phase === 'attente_version' && str_contains($sql, 'parametre_versions')) {
                    // INSERT de version vu : la prochaine lecture `parametres`
                    // est le `fresh()` post-`forget` — fenêtre de course.
                    $phase = 'attente_relecture';

                    return;
                }
                if ($phase === 'attente_relecture' && str_contains($sql, 'from `parametres`')) {
                    $phase = 'termine';
                    $phase = 'termine';
                    // T2 : autre PDO, transaction distincte → lit l'ancienne
                    // valeur (T1 non commité) et la re-scelle en cache.
                    DB::setDefaultConnection('settings_conc');
                    $service->get('etablissement.nom');
                    DB::setDefaultConnection('settings_conc_hold');
                }
            });

            // T1 : écriture réelle via le service (transaction + verrou).
            DB::setDefaultConnection('settings_conc_hold');
            $service->set('etablissement.nom', 'Course', null);

            DB::setDefaultConnection('settings_conc');
            $this->assertSame('Course', $service->get('etablissement.nom'));
            $this->assertSame('termine', $phase, 'Le scénario T2 aurait dû se déclencher.');
        } finally {
            DB::setDefaultConnection($defaut);
        }
    }
}
