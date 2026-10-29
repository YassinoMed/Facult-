<?php

namespace App\Notifications;

use App\Models\Etudiant;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Accusé de réception du dépôt d'une NOUVELLE inscription (formulaire
 * public `POST /inscription`), déclenché par l'événement
 * `StudentRegistered` après commit (voir `NotifierDepotRecu`).
 *
 * Envoyé de façon synchrone et fail-soft : la file `notifications` n'est
 * pas servie en permanence (aucun worker garanti) — un email « en
 * attente » y serait un email perdu pour l'étudiant. Un incident d'envoi
 * ne remet jamais en cause l'inscription déjà commise.
 *
 * Contenu non sensible : jamais de CIN, de date de naissance, de mot de
 * passe, d'adresse ni de document — la référence de dossier suffit.
 */
class ConfirmationDepotInscription extends Notification
{
    public function __construct(
        public readonly string $reference,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(Etudiant $notifiable): MailMessage
    {
        $contact = config('inscriptions.contact_scolarite');
        $emailScolarite = is_array($contact) ? ($contact['email'] ?? null) : null;
        $horaires = is_array($contact) ? ($contact['horaires'] ?? null) : null;

        $message = (new MailMessage)
            ->subject('Votre dossier d\'inscription a bien été reçu')
            ->greeting('Bonjour,')
            ->line('Nous confirmons la bonne réception de votre dossier d\'inscription déposé en ligne.')
            ->line('Référence de votre dossier : **'.$this->reference.'**')
            ->line('Conservez cette référence : elle vous permet de suivre votre dossier et de vous identifier auprès de la scolarité.')
            ->line('Votre dossier est en attente d\'examen par le service de la scolarité ; vous serez informé(e) de la décision par email.')
            ->line('Prochaines étapes :')
            ->line('— Vérification de vos informations par le service de la scolarité ;')
            ->line('— Décision administrative notifiée sur votre espace ou par email ;')
            ->line('— Le cas échéant, demande de complément ou de pièce justificative.');

        if (is_string($emailScolarite) && $emailScolarite !== '') {
            $message->line('Pour toute question, contactez le service de la scolarité : '.$emailScolarite
                .(is_string($horaires) && $horaires !== '' ? ' ('.$horaires.')' : ''));
        }

        return $message
            ->line('Si vous n\'êtes pas à l\'origine de ce dépôt, contactez immédiatement le service de la scolarité.')
            ->salutation('Service de la scolarité');
    }
}
