<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AnomalieQualite;
use App\Models\AppNotification;
use App\Models\ImportReport;
use App\Modules\Tasks\Models\Task;
use App\Services\SupervisionService;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Centre d'exploitation (§31) : façade SANS table — réutilise
 * supervision, qualité, tâches, audit et notifications. Chaque
 * anomalie propose voir / tâche / audit (aucun incident parallèle).
 */
class SystemeController extends Controller
{
    public function index(SupervisionService $supervision): View
    {
        $tachesOuvertes = Task::whereIn('statut', ['pending', 'in_progress', 'waiting', 'expired'])->count();
        $anomaliesOuvertes = AnomalieQualite::whereIn('statut', ['ouverte', 'acquittee', 'en_cours'])->count();
        $notifs24h = AppNotification::where('created_at', '>=', now()->subDay())->count();
        $imports24h = ImportReport::where('created_at', '>=', now()->subDay())->count();
        $utilisateursActifs = (int) DB::table('sessions')->distinct('user_id')->count('user_id');

        try {
            $jobsAttente = DB::table('jobs')->count();
            $jobsEchecs = DB::table('failed_jobs')->count();
        } catch (\Throwable) {
            $jobsAttente = 0;
            $jobsEchecs = 0;
        }

        return view('admin.systeme.index', [
            'sondes' => $supervision->sondes(),
            'echecs' => $supervision->echecsJobs(5),
            'erreurs_api' => $supervision->erreursApiHeure(),
            'dernier_scheduler' => $supervision->dernierScheduler(),
            'activite' => [
                'Utilisateurs actifs (sessions)' => $utilisateursActifs,
                'Tâches ouvertes' => $tachesOuvertes,
                'Notifications (24 h)' => $notifs24h,
                'Imports/exports (24 h)' => $imports24h,
                'Jobs en attente' => $jobsAttente,
                'Jobs en échec' => $jobsEchecs,
            ],
            'donnees' => ['Anomalies qualité ouvertes' => $anomaliesOuvertes],
            'release' => [
                'Environnement' => config('app.env'),
                'Laravel' => app()->version(),
                'PHP' => PHP_VERSION,
                'Maintenance' => app()->maintenanceMode()->active() ? 'ACTIF' : 'inactif',
            ],
        ]);
    }
}
