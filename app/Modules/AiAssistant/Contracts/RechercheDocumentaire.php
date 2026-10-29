<?php

namespace App\Modules\AiAssistant\Contracts;

use App\Modules\AiAssistant\Data\ChunkDocumentaire;

/**
 * Contrat du service de recherche documentaire (Lots 1 + 6).
 *
 * Implémentations garanties :
 * - indexation d'une liste EXPLICITE de sources (aucune découverte) ;
 * - exclusions par défaut (.env, secrets, vendor, storage, données
 *   personnelles) ;
 * - contrôle d'accès du demandeur AVANT restitution des résultats.
 */
interface RechercheDocumentaire
{
    /**
     * Recherche par mots-clés bornée ; respect de la permission du
     * demandeur pour filtrer les sources restreintes.
     *
     * @param  int  $limite  plafond demandé (borné par config `chunks_rag_max`)
     * @return array<int, ChunkDocumentaire> chunks filtrés et classés
     */
    public function rechercher(string $question, int $limite, bool $inclureRestreints): array;

    /**
     * Reconstruit l'index ; retourne le nombre de chunks indexés.
     */
    public function reindexer(): int;
}
