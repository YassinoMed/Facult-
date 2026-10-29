<?php

namespace App\Notifications;

use App\Models\Etudiant;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Email final du parcours public « dossier existant » : confirmation de
 * la prise en compte de la demande de mise à jour. Envoyé UNIQUEMENT
 * après le commit de la transaction d'enregistrement (jamais avant), et
 * UNIQUEMENT si au moins un champ a réellement changé (voir le
 * contrôleur : une demande vide n'engendre aucun email).
 *
 * Envoyé de façon synchrone et fail-soft : la file `notifications` n'est
 * pas servie en permanence (aucun worker garanti) — l'écran final ne
 * doit jamais annoncer un email qu'un worker manquant laisserait en
 * attente. Un incident d'envoi ne remet jamais en cause l'enregistrement
 * déjà validé (voir `DossierExistantService::notifierConfirmation`).
 *
 * Contenu non sensible : jamais de CIN complet, de date de naissance, de
 * code de vérification, de mot de passe ni de document.
 */
class ConfirmationMiseAJourDossierExistant extends Notification
{
    public function __construct(
        public readonly string $reference,
        public readonly int $nbChampsModifies,
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
            ->subject('Confirmation de mise à jour de votre dossier étudiant')
            ->greeting('Bonjour,')
            ->line('Nous avons bien reçu votre demande de mise à jour de votre dossier d\'inscription.')
            ->line('Référence de votre dossier : **'.$this->reference.'**')
            ->line($this->nbChampsModifies > 0
                ? 'Vos modifications ('.$this->nbChampsModifies.' champ(s)) ont été enregistrées et seront vérifiées par le service de la scolarité.'
                : 'Votre demande a été enregistrée. Aucune donnée n\'a été modifiée.')
            ->line('Votre dossier reste en attente de traitement administratif : vous serez informé(e) de la décision.')
            ->line('Prochaines étapes :')
            ->line('— Vérification de vos informations par le service de la scolarité ;')
            ->line('— Décision administrative notifiée sur votre espace ou par email ;')
            ->line('— Le cas échéant, demande de complément ou de pièce justificative.');

        if (is_string($emailScolarite) && $emailScolarite !== '') {
            $message->line('Pour toute question, contactez le service de la scolarité : '.$emailScolarite
                .(is_string($horaires) && $horaires !== '' ? ' ('.$horaires.')' : ''));
        }

        return $message
            ->line('Si vous n\'êtes pas à l\'origine de cette demande, contactez immédiatement le service de la scolarité.')
            ->salutation('Service de la scolarité');
    }
}
