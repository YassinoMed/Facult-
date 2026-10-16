<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * Enveloppe des courriels émis via `CourrielService` (LOOP-MAIL-ENTERPRISE-01) :
 * sujet fixe du catalogue, lignes de texte sûres (déjà validées et
 * neutralisées côté service), versions HTML responsive + texte brut, et
 * en-têtes de corrélation `X-Courriel-*` pour le journal passif.
 * Aucune donnée sensible ne transite ici (ni OTP, ni jeton).
 */
class CourrielMessage extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  list<string>  $lignes
     */
    public function __construct(
        public readonly int $courrielId,
        public readonly string $modele,
        public readonly string $sujetMessage,
        public readonly array $lignes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->sujetMessage);
    }

    public function content(): Content
    {
        return new Content(
            html: 'emails.courriel',
            text: 'emails.courriel-texte',
            with: ['lignes' => $this->lignes],
        );
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            'X-Courriel-Id' => (string) $this->courrielId,
            'X-Courriel-Modele' => $this->modele,
        ]);
    }
}
