<?php

namespace App\Modules\StudentDossiers\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Document supprimé : le modèle n'existe plus — seuls des identifiants
 * et le titre (métadonnées) transitent, jamais de contenu.
 */
class DocumentDeleted
{
    use Dispatchable;

    public function __construct(
        public readonly int $etudiantId,
        public readonly int $dossierId,
        public readonly string $titre,
        public readonly ?string $categorie = null,
    ) {}
}
