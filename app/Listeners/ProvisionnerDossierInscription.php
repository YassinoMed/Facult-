<?php

namespace App\Listeners;

use App\Events\StudentRegistered;
use App\Modules\StudentDossiers\Services\StudentDossierService;
use App\Services\AuditService;
use Illuminate\Support\Facades\Log;

/**
 * Provisionne le dossier documentaire GED dès l'inscription publique
 * (Cas A/B/E : nouveau dossier détecté → créé). Idempotent via
 * `StudentDossierService::obtenirOuCreer` (UNIQUE etudiant_id).
 * Jamais bloquant : l'inscription est déjà commise à ce stade.
 */
class ProvisionnerDossierInscription
{
    public function handle(StudentRegistered $event): void
    {
        try {
            $dossier = app(StudentDossierService::class)->obtenirOuCreer($event->etudiant);

            app(AuditService::class)->tracer(
                $event->etudiant,
                'inscription.dossier_cree',
                'student_dossier',
                $dossier->id,
                null,
                ['reference' => $dossier->reference],
                'systeme',
            );
        } catch (\Throwable $e) {
            report($e);
            Log::warning('inscription.dossier_provision_echec', ['etudiant_id' => $event->etudiant->id]);
        }
    }
}
