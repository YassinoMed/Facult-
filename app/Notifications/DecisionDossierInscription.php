<?php

namespace App\Notifications;

use App\Models\Etudiant;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Email de décision administrative (validation / rejet) d'un dossier
 * d'inscription, déclenché par l'événement `DossierDecisionPrise` après
 * commit (voir `NotifierDecisionDossier`).
 *
 * Envoyé de façon synchrone et fail-soft : la file `notifications` n'est
 * pas servie en permanence (aucun worker garanti) — la décision doit
 * atteindre l'étudiant sans dépendre d'un processus externe. Un incident
 * d'envoi ne remet jamais en cause la transition déjà commise.
 *
 * Contenu non sensible : référence de dossier et, le cas échéant, le
 * motif saisi par la scolarité — jamais de CIN, de date de naissance ni
 * de mot de passe.
 */
class DecisionDossierInscription extends Notification
{
    private const STATUT_VALIDE = 'valide';

    public function __construct(
        public readonly string $reference,
        public readonly string $statut,
        public readonly ?string $motif = null,
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

        $estValide = $this->statut === self::STATUT_VALIDE;

        $message = (new MailMessage)
            ->subject($estValide
                ? 'Votre inscription universitaire est validée'
                : 'Décision concernant votre dossier d\'inscription')
            ->greeting('Bonjour,')
            ->line($estValide
                ? 'Félicitations : après examen, votre dossier d\'inscription a été approuvé par le service de la scolarité.'
                : 'Après examen, votre dossier d\'inscription n\'a pas été retenu par le service de la scolarité.')
            ->line('Référence de votre dossier : **'.$this->reference.'**');

        if (! $estValide && is_string($this->motif) && trim($this->motif) !== '') {
            $message->line('Motif communiqué : '.trim($this->motif));
        }

        $message->line($estValide
            ? 'Votre dossier est désormais validé. Vous pouvez consulter votre suivi et télécharger votre récépissé depuis votre espace étudiant.'
            : 'Vous pouvez contacter le service de la scolarité pour connaître les suites possibles (recours, réorientation, nouvelle candidature).');

        if (is_string($emailScolarite) && $emailScolarite !== '') {
            $message->line('Pour toute question, contactez le service de la scolarité : '.$emailScolarite
                .(is_string($horaires) && $horaires !== '' ? ' ('.$horaires.')' : ''));
        }

        return $message->salutation('Service de la scolarité');
    }
}
