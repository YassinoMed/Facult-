<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Confirmation du changement d'adresse email, envoyée à la NOUVELLE
 * adresse UNIQUEMENT après vérification de celle-ci (validation du code
 * de confirmation, §4). La nouvelle adresse n'est donc plus considérée
 * comme non vérifiée à la réception de ce message.
 *
 * Mise en file (retries automatiques), fail-soft : un échec d'envoi ne
 * remet jamais en cause le changement déjà enregistré. Contenu non
 * sensible : jamais de CIN, de date de naissance, de mot de passe, de
 * code ni d'ancienne adresse complète.
 */
class ConfirmationChangementEmail extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $reference,
    ) {
        $this->onQueue('notifications');
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $contact = config('inscriptions.contact_scolarite');
        $emailScolarite = is_array($contact) ? ($contact['email'] ?? null) : null;
        $horaires = is_array($contact) ? ($contact['horaires'] ?? null) : null;

        $message = (new MailMessage)
            ->subject('Confirmation de changement de votre adresse email')
            ->greeting('Bonjour,')
            ->line('Votre adresse email a bien été mise à jour sur votre dossier d\'inscription étudiant.')
            ->line('Référence de votre dossier : **'.$this->reference.'**')
            ->line('Cette adresse est désormais utilisée pour les communications de la scolarité et, le cas échéant, pour la connexion à votre espace étudiant.')
            ->line('Votre dossier reste en attente de traitement administratif : vous serez informé(e) de la décision.');

        if (is_string($emailScolarite) && $emailScolarite !== '') {
            $message->line('Pour toute question, contactez le service de la scolarité : '.$emailScolarite
                .(is_string($horaires) && $horaires !== '' ? ' ('.$horaires.')' : ''));
        }

        return $message
            ->line('Si vous n\'êtes pas à l\'origine de cette demande, contactez immédiatement le service de la scolarité.')
            ->salutation('Service de la scolarité');
    }
}
