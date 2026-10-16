<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

/**
 * Code de confirmation du changement d'adresse email : envoyé UNIQUEMENT
 * à l'adresse DÉJÀ VÉRIFIÉE du dossier (l'adresse actuellement
 * enregistrée, via `$etudiant->notify()` — jamais à une adresse reprise
 * d'une requête). C'est la preuve supplémentaire exigée avant de valider
 * le changement (mission §3) : le titulaire légitime du dossier doit
 * démontrer qu'il contrôle encore l'adresse en cours — un tiers qui
 * recevrait un code suite à une faute de frappe ne peut rien valider.
 *
 * La NOUVELLE adresse n'est_effective qu'après validation de ce code et
 * ne reçoit une confirmation qu'après le changement effectif. Contenu
 * minimal : jamais de CIN, de date de naissance, de statut, de mot de
 * passe ni d'adresse complète. Envoi SYNCHRONE (non mis en file) :
 * l'étudiant attend ce code pour poursuivre.
 */
class CodeConfirmationChangementEmail extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $code,
        public readonly int $minutesValidite,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Votre code de confirmation — changement d\'adresse email')
            ->greeting('Bonjour,')
            ->line('Une demande de changement d\'adresse email a été effectuée pour votre dossier d\'inscription étudiant.')
            ->line('Par sécurité, le code de confirmation est envoyé à votre adresse actuelle (celle enregistrée sur le dossier).')
            ->line('Voici votre code de confirmation à usage unique :')
            ->line(new HtmlString('<strong style="font-size:1.6rem;letter-spacing:.3em;">'.$this->code.'</strong>'))
            ->line('Ce code est valable '.$this->minutesValidite.' minutes et ne peut être utilisé qu\'une seule fois.')
            ->line('Si vous n\'êtes pas à l\'origine de cette demande, ignorez ce message : aucune modification ne sera effectuée sans ce code.')
            ->salutation('Service de la scolarité');
    }
}
