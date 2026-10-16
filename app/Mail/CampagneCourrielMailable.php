<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * Mailable d'envoi individuel pour une campagne de courriels (LOOP-MAIL-CAMPAIGNS-01).
 * Corps responsive HTML et version miroir texte brut.
 * En-têtes de corrélation techniques sans divulgation de PII.
 */
class CampagneCourrielMailable extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly int $campagneId,
        public readonly int $destinataireId,
        public readonly string $sujetMessage,
        public readonly string $corpsHtml,
        public readonly string $corpsTexte,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->sujetMessage);
    }

    public function content(): Content
    {
        return new Content(
            html: 'emails.campagne',
            text: 'emails.campagne-texte',
            with: [
                'corpsHtml' => $this->corpsHtml,
                'corpsTexte' => $this->corpsTexte,
            ],
        );
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            'X-Campagne-Id' => (string) $this->campagneId,
            'X-Campagne-Destinataire-Id' => (string) $this->destinataireId,
        ]);
    }
}
