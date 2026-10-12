<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ExecuterSauvegardeJob;
use App\Jobs\TesterRestaurationJob;
use App\Models\DatabaseBackup;
use App\Services\BackupService;
use App\Services\ParametreService;
use App\Services\Sauvegardes\BackupSchedulerService;
use App\Services\Sauvegardes\RegistreSauvegardesService;
use App\Services\Sauvegardes\RestaurationSauvegardeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sauvegardes database (LOOP BACKUP-01) : tableau de bord en lecture
 * seule. Toute la donnée vient du registre (jamais du filesystem
 * brut ni d'entrées utilisateur).
 */
class SauvegardesController extends Controller
{
    public function __construct(
        private readonly RegistreSauvegardesService $registre,
        private readonly BackupService $backupService,
    ) {}

    public function index(): View
    {
        $dernier = $this->registre->dernierReussi();

        return view('admin.system.sauvegardes.index', [
            'dernier' => $dernier,
            'statistiques' => $this->backupService->statistiques(),
            'sauvegardes' => $this->registre->historique(),
            'dossier' => $this->backupService->dossierBackup(),
        ]);
    }

    /**
     * Déclenche la création d'une sauvegarde database (LOOP BACKUP-01).
     * Double garde anti-concurrence : ligne RUNNING existante ou verrou
     * actif → 409 Conflict.
     */
    public function store(Request $request): RedirectResponse
    {
        if (DatabaseBackup::where('status', 'RUNNING')->exists()
            || ! Cache::lock('sauvegarde:sonde', 5)->acquire()) {
            abort(Response::HTTP_CONFLICT, 'BACKUP_ALREADY_RUNNING : une sauvegarde est déjà en cours.');
        }

        // Si la file de messages est interceptée (tests unitaires de jobs)
        // ou en production : création asynchrone par défaut (le job porte
        // verrous, vérification et notifications) ; repli synchrone tracé
        // si la file est indisponible.
        $dossier = $this->backupService->dossierBackup();
        $this->backupService->verifierRepertoire($dossier);

        $backup = DatabaseBackup::create([
            'uuid' => (string) Str::uuid(),
            'filename' => $this->backupService->genererNomFichier($dossier),
            'disk' => 'local',
            'path' => $dossier.'/en-cours-'.now()->format('Ymd-His').'.sql',
            'database_name' => config('database.connections.'.config('database.default').'.database'),
            'connexion' => config('database.default'),
            'type' => 'MANUAL',
            'status' => 'RUNNING',
            'started_at' => now(),
            'created_by' => auth()->id(),
        ]);

        try {
            ExecuterSauvegardeJob::dispatch($backup->id);
        } catch (\Throwable $e) {
            Log::warning('sauvegarde.dispatch_echec_repli_sync', ['backup_id' => $backup->id]);
            $backup->delete();

            try {
                $sync = $this->backupService->create($request->user()?->id);

                return redirect()->route('admin.system.backups.index')
                    ->with('success', "Sauvegarde {$sync->filename} créée avec succès (taille : ".number_format($sync->size_bytes / 1024, 1, ',', ' ').' Ko, SHA-256 : '.substr((string) $sync->checksum, 0, 16).'…).');
            } catch (\Throwable $e2) {
                report($e2);

                return redirect()->route('admin.system.backups.index')
                    ->withErrors(['backup' => "Échec de création de sauvegarde : {$e2->getMessage()}"]);
            }
        }

        return redirect()->route('admin.system.backups.index')
            ->with('success', "Sauvegarde {$backup->uuid} lancée.");
    }

    /**
     * Vérifie l'intégrité SHA-256 du fichier de sauvegarde sur disque.
     */
    public function verifier(DatabaseBackup $backup): RedirectResponse
    {
        $resultat = $this->backupService->verify($backup);

        if ($resultat['statut'] === 'VALID') {
            return redirect()->route('admin.system.backups.index')
                ->with('success', "Sauvegarde {$backup->filename} vérifiée : empreinte SHA-256 valide et intègre.");
        }

        return redirect()->route('admin.system.backups.index')
            ->withErrors(['backup' => "Échec de vérification pour {$backup->filename} : ".($resultat['message'] ?? 'empreinte invalide')]);
    }

    /**
     * Lance un test de restauration (dette 3) : jamais sur la base
     * live, base temporaire supprimée après vérification.
     */
    public function testerRestauration(DatabaseBackup $backup): RedirectResponse
    {
        TesterRestaurationJob::dispatch($backup->id);

        return redirect()->route('admin.system.backups.index')
            ->with('success', "Test de restauration lancé pour {$backup->filename} (base temporaire, sans toucher la production).");
    }

    /**
     * Restauration production (dette 4) : permission dédiée +
     * confirmation exacte + pré-restore obligatoire + maintenance.
     * Les refus métier reviennent en erreurs de champ, jamais en 500.
     */
    public function restaurer(Request $request, DatabaseBackup $backup): RedirectResponse
    {
        $valides = $request->validate([
            'confirmation' => ['required', 'string', 'max:100'],
        ]);

        try {
            app(RestaurationSauvegardeService::class)->restaurer(
                $backup, $valides['confirmation'], $request->user()->id
            );
        } catch (\RuntimeException $e) {
            $champ = $e->getMessage() === 'CONFIRMATION_INVALIDE' ? 'confirmation' : 'backup';

            return back()->withErrors([$champ => $this->messageRestauration($e->getMessage())]);
        }

        return redirect()->route('admin.system.backups.index')
            ->with('success', "Base {$backup->database_name} restaurée depuis {$backup->filename} (pré-restore conservé).");
    }

    /**
     * Planification administrateur (permission dédiée `settings`,
     * super-admin) : fréquences, jours/heures, rétention, destination,
     * alertes. Écrit via `ParametreService` (audité).
     */
    public function planification(BackupSchedulerService $planificateur, ParametreService $parametres): View
    {
        return view('admin.system.sauvegardes.planification', [
            'plan' => $planificateur->plan(),
            'frequences' => BackupSchedulerService::FREQUENCES,
            'destinations' => collect((array) config('sauvegardes.destinations', []))
                ->mapWithKeys(fn ($d, $cle) => [$cle => $d['nom'] ?? $cle])->all(),
            'jours' => ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'],
            'prochaine' => $planificateur->prochaineExecution(),
            'conserver' => (int) $parametres->get('sauvegarde.conserver', 14),
        ]);
    }

    public function mettreAJourPlanification(Request $request, ParametreService $parametres): RedirectResponse
    {
        $valides = $request->validate([
            'actif' => ['required', 'boolean'],
            'frequence' => ['required', 'in:'.implode(',', array_keys(BackupSchedulerService::FREQUENCES))],
            'jours' => ['sometimes', 'array'],
            'jours.*' => ['in:lundi,mardi,mercredi,jeudi,vendredi,samedi,dimanche'],
            'heure' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'retention_jours' => ['required', 'integer', 'min:1', 'max:365'],
            'conserver' => ['required', 'integer', 'min:1', 'max:365'],
            'destination' => ['required', 'string', 'in:'.implode(',', array_keys((array) config('sauvegardes.destinations', ['local' => ''])))],
            'chiffrer' => ['required', 'boolean'],
            'alerter_echec' => ['required', 'boolean'],
            'alerter_retard' => ['required', 'boolean'],
            'alerter_succes' => ['required', 'boolean'],
        ]);

        $auteur = (int) $request->user()->id;
        $parametres->set('sauvegarde.plan.actif', (bool) $valides['actif'], $auteur);
        $parametres->set('sauvegarde.plan.frequence', $valides['frequence'], $auteur);
        $parametres->set('sauvegarde.plan.jours', implode(', ', $valides['jours'] ?? []), $auteur);
        $parametres->set('sauvegarde.plan.heure', $valides['heure'], $auteur);
        $parametres->set('sauvegarde.plan.retention_jours', (int) $valides['retention_jours'], $auteur);
        $parametres->set('sauvegarde.conserver', (int) $valides['conserver'], $auteur);
        $parametres->set('sauvegarde.plan.destination', $valides['destination'], $auteur);
        $parametres->set('sauvegarde.plan.chiffrer', (bool) $valides['chiffrer'], $auteur);
        $parametres->set('sauvegarde.plan.alerter_echec', (bool) $valides['alerter_echec'], $auteur);
        $parametres->set('sauvegarde.plan.alerter_retard', (bool) $valides['alerter_retard'], $auteur);
        $parametres->set('sauvegarde.plan.alerter_succes', (bool) $valides['alerter_succes'], $auteur);

        return redirect()->route('admin.system.backups.planification')
            ->with('success', 'Planning de sauvegarde mis à jour (évalué toutes les 15 minutes).');
    }

    private function messageRestauration(string $code): string
    {
        return match (true) {
            str_starts_with($code, 'CONFIRMATION') => 'Confirmation incorrecte : saisissez exactement la valeur demandée.',
            str_contains($code, 'CHECKSUM') => 'Sauvegarde invalide (checksum) : restauration interdite.',
            str_contains($code, 'RESTORE_TEST') => 'Test de restauration manquant ou non concluant : testez d’abord.',
            str_contains($code, 'PRE_RESTORE') => 'Pré-restore impossible : restauration annulée, production intacte.',
            default => 'Restauration impossible sur cet environnement.',
        };
    }
}
