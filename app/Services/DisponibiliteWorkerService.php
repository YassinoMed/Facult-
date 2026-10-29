<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Throwable;

/**
 * Service de détection de disponibilité des workers de file d'attente (§52).
 *
 * Permet d'arbitrer intelligemment entre traitement asynchrone (mise en file)
 * et repli synchrone automatique (fallback) lorsqu'aucun worker actif
 * ne consomme la file d'attente (évite le blocage des imports/exports > 500).
 */
class DisponibiliteWorkerService
{
    public const CLE_CACHE_BATTEMENT = 'queue:worker:battement';

    public const TTL_BATTEMENT_SECONDES = 45;

    /**
     * Enregistre un battement de cœur du worker actif.
     */
    public function enregistrerBattement(): void
    {
        try {
            Cache::put(self::CLE_CACHE_BATTEMENT, now()->timestamp, self::TTL_BATTEMENT_SECONDES);
        } catch (Throwable) {
            // Fail-soft : ne jamais casser la boucle du worker si le cache est temporairement inaccessible.
        }
    }

    /**
     * Vérifie si un worker de file d'attente est actuellement actif.
     */
    public function workerActif(): bool
    {
        // 1. Si la connexion configurée est synchrone, la file s'exécute toujours immédiatement.
        if (Config::get('queue.default') === 'sync') {
            return true;
        }

        // 2. Si un fake de queue est actif (tests automatisés), considérer comme actif pour préserver Queue::fake().
        if (Queue::getFacadeRoot() instanceof QueueFake) {
            return true;
        }

        // 3. Vérifier le battement de cœur récent en cache (méthode principale ultra-rapide).
        try {
            $dernierBattement = Cache::get(self::CLE_CACHE_BATTEMENT);
            if ($dernierBattement !== null && (now()->timestamp - (int) $dernierBattement) <= self::TTL_BATTEMENT_SECONDES) {
                return true;
            }
        } catch (Throwable) {
            // Fall-through vers la détection système.
        }

        // 4. Détection par processus système (si shell_exec est disponible).
        if ($this->detecterProcessusSysteme()) {
            return true;
        }

        return false;
    }

    /**
     * Arbitre si une opération volumineuse doit être mise en file ou exécutée en synchrone.
     *
     * @param  int  $totalElements  Nombre d'éléments (lignes, enregistrements, etc.)
     * @param  int|null  $seuil  Seuil de bascule (par défaut integrations.queue_threshold)
     * @return bool True = mettre en file d'attente, False = exécuter en synchrone
     */
    public function doitBasculerEnFile(int $totalElements, ?int $seuil = null): bool
    {
        $seuilEffectif = $seuil ?? (int) Config::get('integrations.queue_threshold', 500);

        // Sous le seuil : traitement synchrone nominal.
        if ($totalElements <= $seuilEffectif) {
            return false;
        }

        // Si le repli automatique est explicitement désactivé par configuration.
        if (! (bool) Config::get('integrations.auto_fallback_synchrone', true)) {
            return true;
        }

        // Si un worker est actif : mise en file d'attente nominale.
        if ($this->workerActif()) {
            return true;
        }

        // Aucun worker actif : repli synchrone automatique pour éviter tout blocage.
        Log::info('integrations.queue.repli_synchrone_automatique', [
            'total_elements' => $totalElements,
            'seuil' => $seuilEffectif,
            'raison' => 'aucun_worker_actif',
        ]);

        // Augmenter le temps limite d'exécution si possible pour éviter un timeout HTTP.
        // Jamais sous PHPUnit : le plafond fuirait sur tout le processus de test
        // (R-13/1) et ferait avorter des suites longues sans rapport.
        if (! app()->runningUnitTests()
            && function_exists('set_time_limit')
            && ! in_array('set_time_limit', explode(',', (string) ini_get('disable_functions')), true)) {
            @set_time_limit(300);
        }

        return false;
    }

    /**
     * Recherche un processus worker actif sur le système hôte.
     */
    private function detecterProcessusSysteme(): bool
    {
        if (! function_exists('shell_exec')) {
            return false;
        }

        $desactivees = explode(',', (string) ini_get('disable_functions'));
        if (in_array('shell_exec', array_map('trim', $desactivees), true)) {
            return false;
        }

        try {
            // Recherche de processus queue:work ou queue:listen sur Mac/Linux.
            $sortie = @shell_exec("pgrep -f 'artisan queue:(work|listen)' 2>/dev/null");

            return ! empty(trim((string) $sortie));
        } catch (Throwable) {
            return false;
        }
    }
}
