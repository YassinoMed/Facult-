<?php

namespace App\Modules\Sentinel\Services;

use App\Contracts\SentinelCheckInterface;
use App\Models\User;
use App\Modules\Sentinel\Models\SentinelIncident;
use App\Modules\Sentinel\Models\SentinelRegle;
use App\Services\NotificationService;
use App\Services\ParametreService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Platform Sentinel — moteur d'exécution : chaque contrôle est isolé
 * (try/catch : un contrôle en panne n'arrête pas la ronde et ne crée
 * pas d'incident — boucle d'alerte interdite), les constats sont
 * dédupliqués par `cle_unique` (idempotence) et persistés en base
 * (jamais de cache seul pour un incident).
 */
class SentinelService
{
    public function __construct(
        private readonly SentinelRegistry $registre,
        private readonly NotificationService $notifications,
        private readonly ParametreService $parametres,
    ) {}

    /**
     * @return array{executes: int, incidents: int, erreurs: array<int, string>, duree_ms: int}
     */
    public function executer(?string $code = null): array
    {
        $debut = microtime(true);
        $executes = 0;
        $incidents = 0;
        $erreurs = [];
        $sansConstat = [];

        foreach ($this->registre->controles() as $controle) {
            if ($code !== null && $controle->code() !== $code) {
                continue;
            }

            $surcharge = SentinelRegle::where('code', $controle->code())->first();

            if ($surcharge !== null && ! $surcharge->actif) {
                continue;
            }

            $executes++;

            try {
                $constats = $controle->controler();

                foreach ($constats as $constat) {
                    $this->enregistrer($controle, $constat, $surcharge);
                    $incidents++;
                }

                // Contrôle exécuté sans constat : les incidents ouverts
                // du même code sont résolus (balayage exhaustif par
                // construction : chaque contrôle couvre tout son périmètre).
                if ($constats === []) {
                    $sansConstat[] = $controle->code();
                }
            } catch (\Throwable $e) {
                // Panne du contrôle lui-même : journalisée, jamais
                // transformée en incident (anti-boucle), jamais bloquante.
                Log::warning('sentinel.controle_echec', [
                    'code' => $controle->code(),
                    'message' => mb_substr($e->getMessage(), 0, 200),
                ]);
                $erreurs[] = $controle->code();
            }
        }

        foreach ($sansConstat as $codeNettoye) {
            SentinelIncident::where('code', $codeNettoye)
                ->whereIn('statut', [SentinelIncident::STATUT_OUVERT, SentinelIncident::STATUT_ACQUITTE])
                ->update(['statut' => SentinelIncident::STATUT_RESOLU, 'resolu_le' => now()]);
        }

        // Indicateur de dernière exécution (best-effort : la source de
        // vérité reste `derniere_detection_le` en base).
        try {
            Cache::put('sentinel:derniere-execution', now()->toIso8601String(), 3600);
        } catch (\Throwable) {
        }

        return [
            'executes' => $executes,
            'incidents' => $incidents,
            'erreurs' => $erreurs,
            'duree_ms' => (int) ((microtime(true) - $debut) * 1000),
        ];
    }

    /**
     * @param  array{code?: string, gravite?: string, module?: ?string, titre: string, diagnostic: string, recommandation?: ?string, entite_type?: ?string, entite_id?: ?int, entite_libelle?: ?string, faculte_id?: ?int}  $constat
     */
    private function enregistrer(
        SentinelCheckInterface $controle,
        array $constat,
        ?SentinelRegle $surcharge,
    ): void {
        $cle = sha1(implode('|', [
            $constat['code'] ?? $controle->code(),
            (string) ($constat['entite_type'] ?? ''),
            (string) ($constat['entite_id'] ?? ''),
        ]));

        $incident = SentinelIncident::where('cle_unique', $cle)->first();

        if ($incident !== null) {
            // Rouvert si résolu/ignoré puis redétecté ; compteur + horodatage.
            $incident->update([
                'statut' => $incident->statut === SentinelIncident::STATUT_RESOLU
                    || $incident->statut === SentinelIncident::STATUT_IGNORE
                    ? SentinelIncident::STATUT_OUVERT
                    : $incident->statut,
                'derniere_detection_le' => now(),
                'compteur' => $incident->compteur + 1,
                'gravite' => $surcharge?->gravite ?? $constat['gravite'] ?? $controle->gravite(),
            ]);

            $this->alerterSiCritique($incident->fresh());

            return;
        }

        try {
            $incident = SentinelIncident::create([
                'cle_unique' => $cle,
                'code' => $constat['code'] ?? $controle->code(),
                'categorie' => $controle->categorie(),
                'gravite' => $surcharge?->gravite ?? $constat['gravite'] ?? $controle->gravite(),
                'module' => $constat['module'] ?? $controle->module(),
                'titre' => mb_substr($constat['titre'], 0, 255),
                'diagnostic' => $constat['diagnostic'],
                'recommandation' => $constat['recommandation'] ?? null,
                'statut' => SentinelIncident::STATUT_OUVERT,
                'entite_type' => $constat['entite_type'] ?? null,
                'entite_id' => $constat['entite_id'] ?? null,
                'entite_libelle' => isset($constat['entite_libelle']) ? mb_substr($constat['entite_libelle'], 0, 255) : null,
                'faculte_id' => $constat['faculte_id'] ?? null,
                'detecte_le' => now(),
                'derniere_detection_le' => now(),
            ]);

            $this->alerterSiCritique($incident);
        } catch (QueryException) {
            // Concurrence : un autre processus a inséré l'incident simultanément.
            $incidentExistant = SentinelIncident::where('cle_unique', $cle)->first();
            if ($incidentExistant !== null) {
                $incidentExistant->update([
                    'statut' => $incidentExistant->statut === SentinelIncident::STATUT_RESOLU
                        || $incidentExistant->statut === SentinelIncident::STATUT_IGNORE
                        ? SentinelIncident::STATUT_OUVERT
                        : $incidentExistant->statut,
                    'derniere_detection_le' => now(),
                    'compteur' => $incidentExistant->compteur + 1,
                    'gravite' => $surcharge?->gravite ?? $constat['gravite'] ?? $controle->gravite(),
                ]);
                $this->alerterSiCritique($incidentExistant->fresh());
            }
        }
    }

    /**
     * Alerte des super-admins actifs sur incident CRITIQUE uniquement
     * (les autres gravités vivent au cockpit). Cooldown en BASE
     * (`derniere_notification_le`, jamais de cache seul) + journal.
     * Fail-soft : un envoi en échec ne fait jamais échouer la ronde.
     */
    private function alerterSiCritique(SentinelIncident $incident): void
    {
        if ($incident->gravite !== 'critique') {
            return;
        }

        $cooldown = max(1, (int) $this->parametres->get('sentinel.notif_cooldown_minutes', 60));

        if ($incident->derniere_notification_le !== null
            && $incident->derniere_notification_le->diffInMinutes(now()) < $cooldown) {
            return;
        }

        try {
            Log::warning('sentinel.incident_critique', [
                'code' => $incident->code, 'titre' => $incident->titre,
            ]);

            $destinataires = User::where('role', 'super_admin')
                ->where('actif', true)->pluck('id');

            if ($destinataires->isNotEmpty()) {
                $this->notifications->notifierPlusieursUsers(
                    $destinataires, 'sentinel.critique', 'Incident critique Sentinel', $incident->titre,
                    route('admin.sentinel.index'), [], 3,
                );
            }

            $incident->update(['derniere_notification_le' => now()]);
        } catch (\Throwable $e) {
            Log::warning('sentinel.notification_echec', [
                'incident' => $incident->id,
                'message' => mb_substr($e->getMessage(), 0, 200),
            ]);
        }
    }
}
