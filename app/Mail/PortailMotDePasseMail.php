<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Lien de réinitialisation d'un portail étudiant/enseignant.
 * Le token n'est jamais journalisé ni stocké en clair côté serveur.
 */
class PortailMotDePasseMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $url,
        public readonly string $espace,
        public readonly int $expirationMinutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Réinitialisation de votre mot de passe — UniRegistre',
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'mail.portail-mot-de-passe',
            with: [
                'url' => $this->url,
                'espace' => $this->espace,
                'expirationMinutes' => $this->expirationMinutes,
            ],
        );
    }
}
