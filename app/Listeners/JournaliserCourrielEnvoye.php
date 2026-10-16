<?php

namespace App\Listeners;

use App\Models\Courriel;
use App\Services\CourrielService;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Address;
use Throwable;

/**
 * Journalisation passive des courriels réellement émis (LOOP-MAIL-ENTERPRISE-01,
 * LOOP 04) : AUCUNE modification du comportement d'envoi, AUCUN secret.
 *
 * - Enregistre uniquement : destinataire masqué + condensat, sujet,
 *   catégorie/modèle (via l'en-tête `X-Courriel` posé par `CourrielService`),
 *   statut technique et identifiant du message SMTP ;
 * - ne persiste JAMAIS le corps, un OTP, un jeton ou une adresse en clair ;
 * - ne lève jamais (fail-soft : un journal en panne ne doit pas empêcher
 *   l'envoi d'un code de sécurité).
 */
class JournaliserCourrielEnvoye
{
    private const ENTETE_ID = 'X-Courriel-Id';

    private const ENTETE_MODELE = 'X-Courriel-Modele';

    public function surEnvoi(MessageSending $evenement): void
    {
        try {
            $courriel = $this->retrouverParEntete($evenement);
            $destinataire = $this->premierDestinataire($evenement);

            if ($courriel !== null) {
                $courriel->update(['statut' => Courriel::STATUT_EN_COURS]);

                return;
            }

            Courriel::create([
                'categorie' => 'passif',
                'modele' => $this->modeleParEntete($evenement) ?? 'hors_catalogue',
                'version_modele' => (string) config('courriels.version_catalogue', '1'),
                'statut' => Courriel::STATUT_EN_COURS,
                'tentatives' => 1,
                'destinataire_masque' => Courriel::masquerDestinataire($destinataire),
                'destinataire_hash' => Courriel::condensatDestinataire($destinataire),
                'sujet' => mb_substr((string) $evenement->message->getSubject(), 0, 255),
            ]);
        } catch (Throwable $e) {
            Log::warning('courriel.journal_echec', ['diagnostic' => CourrielService::expurgerMessage($e)]);
        }
    }

    public function surEnvoye(MessageSent $evenement): void
    {
        try {
            $courriel = $this->retrouverParEntete($evenement);

            if ($courriel !== null) {
                $courriel->update([
                    'statut' => Courriel::STATUT_ACCEPTE_SMTP,
                    'envoye_le' => now(),
                    'donnees' => array_merge($courriel->donnees ?? [], [
                        'message_id' => mb_substr((string) $evenement->message->getHeaders()->get('Message-ID'), 0, 120),
                    ]),
                ]);

                return;
            }

            Courriel::where('statut', Courriel::STATUT_EN_COURS)
                ->where('destinataire_hash', Courriel::condensatDestinataire($this->premierDestinataire($evenement)))
                ->latest('id')
                ->first()
                ?->update([
                    'statut' => Courriel::STATUT_ACCEPTE_SMTP,
                    'envoye_le' => now(),
                ]);
        } catch (Throwable $e) {
            Log::warning('courriel.journal_echec', ['diagnostic' => CourrielService::expurgerMessage($e)]);
        }
    }

    private function retrouverParEntete(MessageSending|MessageSent $evenement): ?Courriel
    {
        $entetes = $evenement->message->getHeaders();

        if (! $entetes->has(self::ENTETE_ID)) {
            return null;
        }

        $identifiant = (int) $entetes->get(self::ENTETE_ID)->getBodyAsString();

        return $identifiant > 0 ? Courriel::find($identifiant) : null;
    }

    private function modeleParEntete(MessageSending|MessageSent $evenement): ?string
    {
        $entetes = $evenement->message->getHeaders();

        if (! $entetes->has(self::ENTETE_MODELE)) {
            return null;
        }

        $modele = mb_substr($entetes->get(self::ENTETE_MODELE)->getBodyAsString(), 0, 80);

        return is_string(config('courriels.modeles.'.$modele)) || is_array(config('courriels.modeles.'.$modele))
            ? $modele
            : null;
    }

    private function premierDestinataire(MessageSending|MessageSent $evenement): string
    {
        $destinataires = $evenement->message->getTo();

        foreach ($destinataires as $destinataire) {
            if ($destinataire instanceof Address) {
                return $destinataire->getAddress();
            }
        }

        return 'inconnu@local';
    }
}
