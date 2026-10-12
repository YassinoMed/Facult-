<?php

namespace App\Services;

use App\Models\IntegrationLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Supervision technique (§21) : sondes sans PII (base, cache, file,
 * stockage, disque, erreurs API). Consommé par /ready, la page
 * maintenance et la commande supervision:controler.
 */
class SupervisionService
{
    /**
     * @return array<int, array{nom: string, ok: bool, detail: string}>
     */
    public function sondes(): array
    {
        try {
            DB::select('select 1');
            $base = ['nom' => 'Base de données', 'ok' => true, 'detail' => (string) DB::getDatabaseName()];
        } catch (\Throwable) {
            $base = ['nom' => 'Base de données', 'ok' => false, 'detail' => 'Injoignable'];
        }

        try {
            Cache::put('supervision:ping', 'pong', 60);
            $cache = ['nom' => 'Cache', 'ok' => Cache::get('supervision:ping') === 'pong', 'detail' => (string) config('cache.default')];
        } catch (\Throwable) {
            $cache = ['nom' => 'Cache', 'ok' => false, 'detail' => 'Erreur'];
        }

        try {
            $attente = DB::table('jobs')->count();
            $echecs = DB::table('failed_jobs')->count();
            $file = ['nom' => 'File d’attente', 'ok' => true, 'detail' => "{$attente} en attente, {$echecs} échec(s)"];
        } catch (\Throwable) {
            $file = ['nom' => 'File d’attente', 'ok' => false, 'detail' => 'Tables jobs absentes'];
            $attente = 0;
            $echecs = 0;
        }

        try {
            $priveOk = Storage::disk('private')->exists('.') || is_writable(storage_path('app/private'));
            $stockage = ['nom' => 'Stockage privé', 'ok' => (bool) $priveOk, 'detail' => 'storage/app/private'];
        } catch (\Throwable) {
            $stockage = ['nom' => 'Stockage privé', 'ok' => false, 'detail' => 'Erreur'];
        }

        $libre = @disk_free_space(storage_path());
        $total = @disk_total_space(storage_path());
        $pct = ($libre !== false && $total) ? (int) round((1 - $libre / $total) * 100) : 0;
        $disque = ['nom' => 'Disque', 'ok' => $pct < 90, 'detail' => "{$pct} % utilisé"];

        return [$base, $cache, $file, $stockage, $disque];
    }

    public function echecsJobs(int $limite = 10): array
    {
        try {
            return DB::table('failed_jobs')
                ->select(['id', 'queue', 'failed_at', 'exception'])
                ->orderByDesc('failed_at')->limit($limite)->get()
                ->map(fn ($job) => [
                    'id' => $job->id,
                    'queue' => $job->queue,
                    'failed_at' => $job->failed_at,
                    'erreur' => mb_substr(strtok((string) $job->exception, "\n"), 0, 200),
                ])->all();
        } catch (\Throwable) {
            return [];
        }
    }

    public function erreursApiHeure(): int
    {
        try {
            return IntegrationLog::where('direction', 'api')
                ->where('status', 'failed')
                ->where('created_at', '>=', now()->subHour())
                ->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    public function dernierScheduler(): ?string
    {
        try {
            return Cache::get('supervision:scheduler-dernier');
        } catch (\Throwable) {
            return null;
        }
    }
}
