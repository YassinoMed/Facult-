<?php

namespace App\Services\Notifications;

use App\DataMesh\Outbox\OutboxService;
use App\Models\AppNotification;
use App\Models\Etudiant;
use App\Models\User;
use App\Modules\Schedule\Models\Teacher;
use App\Services\Notifications\Contrats\NotificationDispatcherInterface;
use App\Services\Notifications\DTO\DemandeNotification;
use App\Services\Notifications\DTO\ResultatNotification;
use App\Services\Notifications\Support\MasqueurDonneesSensibles;
use App\Services\NotificationService;
use App\Services\PreferenceNotificationService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Centre de notifications unifié (§4-6, LOT 02).
 *
 * Responsabilités majeures :
 * - Point d'entrée unique pour la diffusion multicanale (in-app, email, outbox, externe).
 * - Respect des préférences d'opt-out tout en protégeant les alertes institutionnelles obligatoires.
 * - Idempotence stricte par clé de déduplication (aucun doublon en cas d'événements répétés).
 * - Masquage rigoureux et systématique des PII et secrets dans les données et journaux.
 * - Traçabilité de l'état de livraison ('en_attente', 'envoye', 'echec', 'echec_definitif') et reprise sur erreur.
 */
class CentreNotificationsUnifieService implements NotificationDispatcherInterface
{
    public function __construct(
        protected NotificationService $notificationService,
        protected PreferenceNotificationService $preferenceService,
        protected OutboxService $outboxService,
    ) {}

    /**
     * Traite et distribue une notification multicanale unifiée.
     */
    public function distribuer(DemandeNotification $demande): ResultatNotification
    {
        $destinataire = $demande->destinataire;
        $etudiantId = $destinataire instanceof Etudiant ? (int) $destinataire->id : null;
        $userId = $destinataire instanceof User ? (int) $destinataire->id : null;

        // 1. Contrôle des préférences d'opt-out de l'utilisateur
        if (! $this->preferenceService->notifiable($etudiantId, $userId, $demande->categorie)) {
            Log::info('notifications.ignoree_par_preference', [
                'destinataire_type' => get_class($destinataire),
                'destinataire_id' => $destinataire->id,
                'categorie' => $demande->categorie,
                'evenement' => $demande->evenement,
            ]);

            return new ResultatNotification(
                succes: true,
                statut: 'ignore_preference',
                details: ['raison' => 'opt_out_utilisateur']
            );
        }

        // 2. Prévention des doublons & Idempotence
        if ($demande->cleDeduplication !== null) {
            $existante = $this->rechercherDoublon($destinataire, $demande->evenement, $demande->cleDeduplication);
            if ($existante !== null) {
                return new ResultatNotification(
                    succes: true,
                    statut: 'deduplique',
                    notification: $existante,
                    details: ['cle_dedup' => $demande->cleDeduplication]
                );
            }
        }

        // 3. Masquage et assainissement des données sensibles (PII, secrets, CIN, cartes)
        $titrePropre = MasqueurDonneesSensibles::masquerTexte($demande->titre);
        $messagePropre = MasqueurDonneesSensibles::masquerTexte($demande->message);
        $donneesPropres = MasqueurDonneesSensibles::masquerTableau($demande->donnees);

        if ($demande->cleDeduplication !== null) {
            $donneesPropres['cle_dedup'] = $demande->cleDeduplication;
        }

        if (! isset($donneesPropres['statut_envoi'])) {
            $donneesPropres['statut_envoi'] = 'en_attente';
        }

        $notification = null;
        $outboxMessage = null;
        $detailsCanaux = [];

        // 4. Acheminement In-App (table app_notifications)
        if (in_array('in_app', $demande->canaux, true)) {
            try {
                $notification = $this->creerNotificationInApp(
                    $destinataire,
                    $demande->evenement,
                    $titrePropre,
                    $messagePropre,
                    $demande->lien,
                    $donneesPropres,
                    $demande->priorite
                );
                $detailsCanaux['in_app'] = 'creee';
            } catch (Throwable $e) {
                Log::error('notifications.echec_in_app', [
                    'erreur' => MasqueurDonneesSensibles::masquerTexte($e->getMessage()),
                ]);

                return new ResultatNotification(
                    succes: false,
                    statut: 'echec',
                    erreur: MasqueurDonneesSensibles::masquerTexte($e->getMessage()),
                    details: ['echec_canal' => 'in_app']
                );
            }
        }

        // 5. Acheminement Transactional Outbox
        if (in_array('outbox', $demande->canaux, true)) {
            try {
                $agregatId = $notification?->id ?? (int) $destinataire->id;
                $outboxMessage = $this->outboxService->enregistrer(
                    $demande->evenement,
                    'notification',
                    $agregatId,
                    [
                        'titre' => $titrePropre,
                        'message' => $messagePropre,
                        'lien' => $demande->lien,
                        'destinataire_type' => get_class($destinataire),
                        'destinataire_id' => $destinataire->id,
                        'donnees' => $donneesPropres,
                    ],
                    $demande->cleDeduplication
                );
                $detailsCanaux['outbox'] = 'enregistre';
            } catch (Throwable $e) {
                Log::warning('notifications.echec_outbox', [
                    'erreur' => MasqueurDonneesSensibles::masquerTexte($e->getMessage()),
                ]);
            }
        }

        // 6. Déclenchement de l'acheminement externe si canal demandé
        if ($notification !== null && (in_array('externe', $demande->canaux, true) || in_array('email', $demande->canaux, true))) {
            $this->notificationService->acheminerExterne($notification);
            $detailsCanaux['externe'] = 'achemine';
        }

        return new ResultatNotification(
            succes: true,
            statut: 'envoye',
            notification: $notification,
            outboxMessage: $outboxMessage,
            details: $detailsCanaux
        );
    }

    /**
     * Retente la distribution d'une notification en échec.
     */
    public function retenter(AppNotification $notification): bool
    {
        try {
            $this->notificationService->retenterEnvoiExterne($notification);

            return true;
        } catch (Throwable $e) {
            Log::error('notifications.echec_retente', [
                'notification_id' => $notification->id,
                'erreur' => MasqueurDonneesSensibles::masquerTexte($e->getMessage()),
            ]);

            return false;
        }
    }

    /**
     * Consulte l'état de livraison et le suivi d'une notification.
     */
    public function statut(AppNotification $notification): array
    {
        $data = $notification->data ?? [];

        return [
            'id' => (int) $notification->id,
            'statut_envoi' => (string) ($data['statut_envoi'] ?? 'inconnu'),
            'tentatives' => (int) ($data['tentatives'] ?? 0),
            'dernier_essai_le' => isset($data['dernier_essai_le']) ? (string) $data['dernier_essai_le'] : null,
            'erreur' => isset($data['erreur']) ? (string) $data['erreur'] : null,
            'read_at' => $notification->read_at?->toISOString(),
        ];
    }

    protected function rechercherDoublon(Etudiant|User|Teacher $destinataire, string $evenement, string $cleDedup): ?AppNotification
    {
        $query = AppNotification::query()->where('type', $evenement);

        if ($destinataire instanceof Etudiant) {
            $query->where('etudiant_id', $destinataire->id);
        } elseif ($destinataire instanceof User) {
            $query->where('user_id', $destinataire->id);
        } elseif ($destinataire instanceof Teacher) {
            $query->where('teacher_id', $destinataire->id);
        }

        return $query->where('data->cle_dedup', $cleDedup)->first();
    }

    protected function creerNotificationInApp(
        Etudiant|User|Teacher $destinataire,
        string $evenement,
        string $titre,
        string $message,
        ?string $lien,
        array $donnees,
        int $priorite
    ): AppNotification {
        if ($destinataire instanceof Etudiant) {
            return $this->notificationService->notifierEtudiant(
                $destinataire,
                $evenement,
                $titre,
                $message,
                $lien,
                $donnees,
                $priorite
            );
        }

        if ($destinataire instanceof User) {
            return $this->notificationService->notifierUser(
                $destinataire,
                $evenement,
                $titre,
                $message,
                $lien,
                $donnees,
                $priorite
            );
        }

        return $this->notificationService->notifierEnseignant(
            $destinataire,
            $evenement,
            $titre,
            $message,
            $lien,
            $donnees,
            $priorite
        );
    }
}
