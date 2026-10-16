<?php

namespace App\Notifications;

use App\Models\Etudiant;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

/**
 * Code de vérification à usage unique du parcours public « dossier
 * existant » : envoyé UNIQUEMENT à l'adresse email déjà enregistrée du
 * dossier (jamais à une adresse librement saisie).
 *
 * Contenu volontairement minimal : jamais de CIN complet, de date de
 * naissance, de statut du dossier ni de donnée académique. Envoi
 * SYNCHRONE (non mis en file) : l'étudiant attend ce code pour
 * poursuivre.
 */
class CodeVerificationDossierExistant extends Notification
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

    public function toMail(Etudiant $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Votre code de vérification — mise à jour de dossier étudiant')
            ->greeting('Bonjour,')
            ->line('Vous avez demandé à consulter ou mettre à jour votre dossier d\'inscription en ligne.')
            ->line('Voici votre code de vérification à usage unique :')
            ->line(new HtmlString('<strong style="font-size:1.6rem;letter-spacing:.3em;">'.$this->code.'</strong>'))
            ->line('Ce code est valable '.$this->minutesValidite.' minutes et ne peut être utilisé qu\'une seule fois.')
            ->line('Si vous n\'êtes pas à l\'origine de cette demande, ignorez ce message : aucune modification ne sera effectuée sans ce code.')
            ->salutation('Service de la scolarité');
    }
}
