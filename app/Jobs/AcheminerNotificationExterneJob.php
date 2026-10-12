<?php

namespace App\Jobs;

use App\Contracts\CanalNotificationExterneInterface;
use App\Models\AppNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Job de distribution externe asynchrone des notifications (LOOP 12) :
 * - Observable : statut d'envoi ('en_attente', 'envoye', 'echec', 'echec_definitif')
 *   et nombre de tentatives tracés dans notification->data.
 * - Tolérant aux pannes : 3 tentatives avant classement en échec définitif.
 * - Résilient : ne lève jamais d'exception non gérée vers les flux métiers.
 */
class AcheminerNotificationExterneJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [5, 30, 120];

    public function __construct(
        public readonly int $notificationId,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(CanalNotificationExterneInterface $canal): void
    {
        /** @var AppNotification|null $notification */
        $notification = AppNotification::find($this->notificationId);
        if ($notification === null) {
            return;
        }

        $data = $notification->data ?? [];
        $tentatives = (int) ($data['tentatives'] ?? 0) + 1;
        $data['tentatives'] = $tentatives;
        $data['dernier_essai_le'] = now()->toISOString();

        try {
            $canal->envoyer($notification);

            $data['statut_envoi'] = 'envoye';
            $data['erreur'] = null;
            $notification->update(['data' => $data]);
        } catch (Throwable $e) {
            $data['statut_envoi'] = 'echec';
            $data['erreur'] = mb_substr($e->getMessage(), 0, 255);
            $notification->update(['data' => $data]);

            // Re-lève l'exception pour permettre à la queue Laravel de retenter le job
            if ($this->attempts() < $this->tries) {
                throw $e;
            }
        }
    }

    /**
     * Gestion après épuisement de toutes les tentatives.
     */
    public function failed(?Throwable $exception): void
    {
        /** @var AppNotification|null $notification */
        $notification = AppNotification::find($this->notificationId);
        if ($notification !== null) {
            $data = $notification->data ?? [];
            $data['statut_envoi'] = 'echec_definitif';
            $data['erreur'] = $exception !== null ? mb_substr($exception->getMessage(), 0, 255) : 'Échec définitif d\'envoi.';
            $notification->update(['data' => $data]);
        }
    }
}
