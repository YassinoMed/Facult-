<?php

namespace App\Modules\StudentDossiers\Events;

use App\Modules\StudentDossiers\Models\GeneratedDocument;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Impression demandée par l'utilisateur (clic « Imprimer ») : trace
 * d'intention côté serveur — l'impression physique reste une action
 * navigateur explicite, jamais automatique.
 */
class StudentDossierPdfPrinted
{
    use Dispatchable;

    public function __construct(public readonly GeneratedDocument $document) {}
}
