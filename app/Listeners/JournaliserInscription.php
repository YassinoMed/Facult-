<?php

namespace App\Listeners;

use App\Events\StudentRegistered;
use Illuminate\Support\Facades\Log;

/**
 * Exemple de réaction découplée (§7) : journal technique SANS aucune
 * donnée personnelle (ni CIN, ni email, ni nom — cf. règle d'audit).
 * Les futurs modules (email, SMS, stats) s'ajoutent ici sans toucher
 * au parcours d'inscription.
 */
class JournaliserInscription
{
    public function handle(StudentRegistered $event): void
    {
        Log::info('inscription.enregistree', [
            'statut' => $event->etudiant->statut,
        ]);
    }
}
