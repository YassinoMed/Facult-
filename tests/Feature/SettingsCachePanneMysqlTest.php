<?php

namespace Tests\Feature;

use App\Services\ParametreService;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * E1.6 — Panne du driver cache après commit : l'écriture déjà committée
 * réussit quand même, l'échec d'invalidation est journalisé (warning).
 *
 * Scratch MySQL `settings_conc_scratch` (commits réels) :
 * gate `SETTINGS_CONC_MYSQL=1`, sinon skip.
 */
class SettingsCachePanneMysqlTest extends TestCase
{
    public function test_panne_cache_post_commit_ecriture_reussie_et_journalisee(): void
    {
        if (env('SETTINGS_CONC_MYSQL') !== '1') {
            $this->markTestSkipped('Panne cache MySQL : SETTINGS_CONC_MYSQL=1 requis (base scratch dédiée).');
        }

        $admin = array_merge(config('database.connections.mysql'), ['database' => null]);
        config(['database.connections.settings_conc_admin' => $admin]);
        DB::connection('settings_conc_admin')->statement('CREATE DATABASE IF NOT EXISTS settings_conc_scratch');
        config(['database.connections.settings_conc' => array_merge(
            config('database.connections.mysql'), ['database' => 'settings_conc_scratch']
        )]);
        $this->assertSame('settings_conc_scratch', DB::connection('settings_conc')->getDatabaseName(),
            'Garde-fou : jamais le dataset dev.');

        Artisan::call('migrate', ['--database' => 'settings_conc', '--force' => true]);
        $defaut = config('database.default');
        $gestionnaire = app('cache');
        $alertes = [];
        Log::listen(function (MessageLogged $evenement) use (&$alertes) {
            if (($evenement->message ?? '') === 'parametre.cache_invalidation_echec') {
                $alertes[] = $evenement->context;
            }
        });

        // Store dégradé : 1er `forget` OK, suivants en panne.
        $degrade = new class extends ArrayStore
        {
            public int $appels = 0;

            public function forget($key): bool
            {
                $this->appels++;
                if ($this->appels > 1) {
                    throw new \RuntimeException('panne cache simulée');
                }

                return parent::forget($key);
            }
        };

        try {
            DB::setDefaultConnection('settings_conc');
            DB::table('parametres')->updateOrInsert(
                ['cle' => 'etablissement.nom'],
                ['valeur' => 'Université', 'type' => 'texte', 'categorie' => 'etablissement', 'created_at' => now(), 'updated_at' => now()]
            );
            Cache::swap(new Repository($degrade));

            $service = app(ParametreService::class);
            $service->set('etablissement.nom', 'Panne', null);

            // Écriture committée malgré la panne post-commit…
            $this->assertSame('Panne', DB::table('parametres')->where('cle', 'etablissement.nom')->value('valeur'));
            // …échec journalisé sans secret ni valeur.
            $this->assertCount(1, $alertes);
            $this->assertSame('etablissement.nom', $alertes[0]['cle'] ?? null);
            $this->assertArrayNotHasKey('valeur', $alertes[0]);
        } finally {
            Cache::swap($gestionnaire);
            DB::setDefaultConnection($defaut);
        }
    }
}
