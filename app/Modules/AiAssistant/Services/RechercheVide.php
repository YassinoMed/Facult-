<?php

namespace App\Modules\AiAssistant\Services;

use App\Modules\AiAssistant\Contracts\RechercheDocumentaire;

/**
 * Implémentation VERTE du contrat RechercheDocumentaire (Lot 4/5) :
 * aucun index disponible encore. La vraie base documentaire (Lot 6)
 * remplace CETTE LIAISON par simple-binding du conteneur — aucun
 * changement de code du service de chat.
 */
final class RechercheVide implements RechercheDocumentaire
{
    public function rechercher(string $question, int $limite, bool $inclureRestreints): array
    {
        return [];
    }

    public function reindexer(): int
    {
        return 0;
    }

    public static function chunksVides(): array
    {
        return [];
    }
}
