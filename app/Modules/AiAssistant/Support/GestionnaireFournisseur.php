<?php

namespace App\Modules\AiAssistant\Support;

use App\Modules\AiAssistant\Contracts\AiFournisseur;
use App\Modules\AiAssistant\Exceptions\AiAssistantException;
use App\Modules\AiAssistant\Providers\ClientOpenAiCompatible;
use App\Modules\AiAssistant\Providers\FournisseurFacticeDeterministe;
use App\Modules\AiAssistant\Providers\FournisseurOllama;

/**
 * Sélection du fournisseur actif (Lot 2) STRICTEMENT par
 * config('ai-assistant'). POLITIQUE DE CONFIDENTIALITÉ : aucun
 * basculement automatique vers une autre destination — si le
 * fournisseur actif est indisponible, l'erreur remonte (fail-soft côté
 * utilisateur, pas de re-routage des données vers un tiers).
 */
final class GestionnaireFournisseur
{
    public function actif(): AiFournisseur
    {
        $nom = (string) config('ai-assistant.defaut', 'factice');
        $declaration = config("ai-assistant.fournisseurs.{$nom}");

        if (! is_array($declaration)) {
            throw new AiAssistantException("Fournisseur IA non déclaré : {$nom}.");
        }

        return match ($declaration['driver']) {
            'factice' => new FournisseurFacticeDeterministe,
            'compatible_openai' => new ClientOpenAiCompatible($nom, $declaration),
            'ollama' => new FournisseurOllama($nom, $declaration),
            default => throw new AiAssistantException(
                "Driver fournisseur IA inconnu : {$declaration['driver']}."
            ),
        };
    }
}
