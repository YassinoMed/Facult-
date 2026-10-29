<?php

namespace App\Events;

use App\Models\InscriptionAcademique;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * LOOP-UNIREGISTRE-DOCUMENTS-REUTILISABLES-01 : une inscription
 * académique annuelle vient d'être créée. Les modules documentaires
 * peuvent alors associer automatiquement les pièces permanentes de
 * l'étudiant à ce nouveau dossier annuel, sans nouveau téléversement.
 *
 * Événement émis DANS la transaction de création : une erreur du
 * listener est interceptée (fail-soft) et ne bloque jamais l'inscription.
 */
class InscriptionAcademiqueCreee
{
    use Dispatchable;

    public function __construct(
        public readonly InscriptionAcademique $inscription,
        public readonly ?int $auteurId = null,
    ) {}
}
