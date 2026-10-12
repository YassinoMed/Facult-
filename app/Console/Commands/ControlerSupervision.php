<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\NotificationService;
use App\Services\ParametreService;
use App\Services\SupervisionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Alerting ops (§21.15-17) : seuils configurables (paramètres),
 * déduplication par cooldown cache, notification super-admins.
 * Ne remplace pas les alertes métier in-app (SLA, assiduité).
 */
class ControlerSupervision extends Command
{
    protected $signature = 'supervision:controler';

    protected $description = 'Contrôle les seuils techniques et alerte (failed jobs, file, disque, erreurs API).';

    public function handle(
        SupervisionService $supervision,
        ParametreService $parametres,
        NotificationService $notifications,
    ): int {
        $alertes = [];

        try {
            $echecs = DB::table('failed_jobs')->count();
        } catch (\Throwable) {
            $echecs = 0;
        }

        $seuilEchecs = (int) $parametres->get('supervision.failed_jobs_seuil', 5);
        if ($echecs >= $seuilEchecs) {
            $alertes[] = "File : {$echecs} job(s) en échec (seuil {$seuilEchecs}).";
        }

        $erreursApi = $supervision->erreursApiHeure();
        $seuilApi = (int) $parametres->get('supervision.api_erreurs_heure_seuil', 20);
        if ($erreursApi >= $seuilApi) {
            $alertes[] = "API : {$erreursApi} erreur(s) sur 1 h (seuil {$seuilApi}).";
        }

        $libre = @disk_free_space(storage_path());
        $total = @disk_total_space(storage_path());
        $pct = ($libre !== false && $total) ? (int) round((1 - $libre / $total) * 100) : 0;
        $seuilDisque = (int) $parametres->get('supervision.disque_pct_seuil', 85);
        if ($pct >= $seuilDisque) {
            $alertes[] = "Disque : {$pct} % utilisé (seuil {$seuilDisque} %).";
        }

        foreach ($alertes as $alerte) {
            $cle = 'supervision:alerte:'.sha1($alerte);

            if (Cache::has($cle)) {
                continue;
            }

            Cache::put($cle, true, now()->addHour());

            Log::warning('supervision.alerte', ['alerte' => $alerte]);

            $destinataires = User::where('role', 'super_admin')->where('actif', true)->pluck('id');

            if ($destinataires->isNotEmpty()) {
                $notifications->notifierPlusieursUsers(
                    $destinataires, 'supervision.alerte', 'Alerte supervision', $alerte,
                    route('admin.maintenance.index'), [], 3,
                );
            }
        }

        $this->info(count($alertes).' alerte(s), dont '.count(array_filter($alertes, fn ($a) => ! Cache::has('supervision:alerte:'.sha1($a)))).' déjà notifiée(s) en cooldown.');

        return self::SUCCESS;
    }
}
