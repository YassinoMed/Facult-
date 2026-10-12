<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SupervisionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * Maintenance et santé système (§37-38, super_admin uniquement) :
 * indicateurs lecture seule + purge du cache applicatif. Aucune
 * donnée métier exposée ou modifiée ici.
 */
class MaintenanceController extends Controller
{
    public function index(): View
    {
        return view('admin.maintenance.index', [
            'sante' => $this->controles(),
            'supervision' => app(SupervisionService::class),
        ]);
    }

    public function purgerCache(): RedirectResponse
    {
        Artisan::call('optimize:clear');

        return redirect()->route('admin.maintenance.index')
            ->with('success', 'Caches applicatifs purgés.');
    }

    /**
     * @return array<int, array{nom: string, statut: bool, detail: string}>
     */
    private function controles(): array
    {
        try {
            DB::select('select 1');
            $base = ['nom' => 'Base de données', 'statut' => true, 'detail' => DB::getDatabaseName()];
        } catch (\Throwable) {
            $base = ['nom' => 'Base de données', 'statut' => false, 'detail' => 'Injoignable'];
        }

        try {
            $priveOk = Storage::disk('private')->exists('.') || is_writable(storage_path('app/private'));
            $stockage = ['nom' => 'Stockage privé', 'statut' => (bool) $priveOk, 'detail' => 'storage/app/private'];
        } catch (\Throwable) {
            $stockage = ['nom' => 'Stockage privé', 'statut' => false, 'detail' => 'Erreur'];
        }

        try {
            $echecs = DB::table('failed_jobs')->count();
            $file = ['nom' => 'File d’attente', 'statut' => true, 'detail' => config('queue.default')." ({$echecs} échec(s))"];
        } catch (\Throwable) {
            $file = ['nom' => 'File d’attente', 'statut' => false, 'detail' => 'Table failed_jobs absente'];
        }

        $cacheOk = true;
        try {
            cache()->put('sante:ping', 'pong', 10);
            $cacheOk = cache()->get('sante:ping') === 'pong';
        } catch (\Throwable) {
            $cacheOk = false;
        }

        return [
            $base,
            $stockage,
            $file,
            ['nom' => 'Cache', 'statut' => $cacheOk, 'detail' => (string) config('cache.default')],
            ['nom' => 'Planificateur', 'statut' => $this->schedulerOk(), 'detail' => $this->schedulerDetail()],
            ['nom' => 'Sessions', 'statut' => Schema::hasTable('sessions'), 'detail' => (string) config('session.driver')],
        ];
    }

    private function schedulerOk(): bool
    {
        $dernier = app(SupervisionService::class)->dernierScheduler();

        return $dernier !== null && now()->parse($dernier)->greaterThan(now()->subMinutes(15));
    }

    private function schedulerDetail(): string
    {
        $dernier = app(SupervisionService::class)->dernierScheduler();

        return $dernier === null
            ? 'heartbeat absent — dev : `php artisan schedule:work` · prod : cron `schedule:run` (GUIDE-DEPLOY §7.3)'
            : "dernier battement {$dernier}";
    }
}
