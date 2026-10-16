<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

/**
 * Code de confirmation de la NOUVELLE adresse email : envoyé UNIQUEMENT
 * à l'adresse candidate (via `Notification::route('mail', $nouvelEmail)`),
 * APRÈS validation du code envoyé à l'adresse actuellement enregistrée.
 *
 * L'email servant d'identifiant de connexion à l'espace étudiant
 * (`Etudiant\ConnexionController` : email ou CIN + mot de passe), la
 * nouvelle adresse n'est JAMAIS appliquée avant validation de ce code :
 * l'ancienne adresse reste en vigueur jusqu'à la confirmation, et la
 * candidate vit uniquement dans un état temporaire côté serveur (cache,
 * jamais en base). Contenu minimal : jamais de CIN, de date de
 * naissance, de statut, de mot de passe ni d'adresse complète. Envoi
 * SYNCHRONE (non mis en file) : l'étudiant attend ce code pour conclure.
 */
class CodeConfirmationNouvelleAdresse extends Notification
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
            ->subject('Confirmez votre nouvelle adresse email — dossier étudiant')
            ->greeting('Bonjour,')
            ->line('Une demande d\'utilisation de cette adresse email a été effectuée pour un dossier d\'inscription étudiant.')
            ->line('Si vous êtes à l\'origine de cette demande, saisissez le code ci-dessous sur la page de votre dossier pour valider définitivement le changement :')
            ->line(new HtmlString('<strong style="font-size:1.6rem;letter-spacing:.3em;">'.$this->code.'</strong>'))
            ->line('Ce code est valable '.$this->minutesValidite.' minutes et ne peut être utilisé qu\'une seule fois.')
            ->line('Si vous n\'êtes pas à l\'origine de cette demande, ignorez ce message : aucune modification ne sera effectuée sans ce code.')
            ->salutation('Service de la scolarité');
    }
}
