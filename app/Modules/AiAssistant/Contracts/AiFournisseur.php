<?php

namespace App\Modules\AiAssistant\Contracts;

use App\Modules\AiAssistant\Data\AiDemande;
use App\Modules\AiAssistant\Data\AiReponse;

/**
 * Contrat d'un fournisseur IA (Lot 1) — distant, local ou factice.
 *
 * Le code métier ne dépend JAMAIS d'une marque : uniquement de ce
 * contrat. Passerelle Lot 2 : sélection par config('ai-assistant').
 * Aucun fallback automatique vers une destination externe.
 */
interface AiFournisseur
{
    public function nom(): string;

    public function modele(): string;

    public function generer(AiDemande $demande): AiReponse;
}
