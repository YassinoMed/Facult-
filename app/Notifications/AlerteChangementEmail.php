<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Alerte de sécurité envoyée à l'ANCIENNE adresse après un changement
 * d'email effectif (politique §4 : notifier l'ancienne adresse si elle
 * reste accessible). Contenu volontairement partiel : nouvelle adresse
 * MASQUÉE uniquement, jamais en clair — un accès compromis à l'ancienne
 * boîte ne doit pas révéler la nouvelle adresse complète.
 *
 * Mise en file (retries automatiques), fail-soft : un échec d'envoi ne
 * remet jamais en cause le changement déjà enregistré.
 */
class AlerteChangementEmail extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $nouvelEmailMasque,
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

        $message = (new MailMessage)
            ->subject('Sécurité : votre adresse email a été modifiée')
            ->greeting('Bonjour,')
            ->line('L\'adresse email associée à votre dossier d\'inscription étudiant vient d\'être modifiée.')
            ->line('Nouvelle adresse (partiellement masquée) : **'.$this->nouvelEmailMasque.'**')
            ->line('Les prochaines communications de la scolarité seront envoyées à cette nouvelle adresse.');

        if (is_string($emailScolarite) && $emailScolarite !== '') {
            $message->line('Si vous n\'êtes pas à l\'origine de cette demande, contactez immédiatement le service de la scolarité : '.$emailScolarite);
        } else {
            $message->line('Si vous n\'êtes pas à l\'origine de cette demande, contactez immédiatement le service de la scolarité.');
        }

        return $message->salutation('Service de la scolarité');
    }
}
