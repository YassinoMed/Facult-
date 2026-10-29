<?php

namespace App\Modules\AssistantIa\Tools\Metier;

use App\Models\User;
use App\Modules\AssistantIa\Contracts\OutilIaInterface;
use App\Modules\AssistantIa\DTO\ResultatOutil;
use App\Services\DocumentsRequisService;

/**
 * Outil métier : Consultation du référentiel officiel des pièces justificatives requises.
 */
class VerifierPiecesManquantesTool implements OutilIaInterface
{
    public function nom(): string
    {
        return 'verifier_pieces_manquantes';
    }

    public function description(): string
    {
        return 'Consulte le catalogue réglementaire des pièces justificatives exigées selon le type de dossier (CIN, photo, diplômes, etc.).';
    }

    public function mode(): string
    {
        return 'metier';
    }

    public function permissionRequise(): string
    {
        return 'assistant_ia.mode_metier';
    }

    public function schemaParametres(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'categorie' => [
                    'type' => 'string',
                    'description' => 'Catégorie de pièces à filtrer (ex: "identity", "academic", ou vide pour toutes)',
                ],
            ],
        ];
    }

    public function estSensible(): bool
    {
        return false;
    }

    public function executer(array $parametres, User $utilisateur): ResultatOutil
    {
        $categorie = mb_strtolower(trim((string) ($parametres['categorie'] ?? '')));
        $catalogue = DocumentsRequisService::CATALOGUE_PIECES;

        if ($categorie !== '') {
            $catalogue = array_filter($catalogue, fn ($p) => ($p['category'] ?? '') === $categorie);
        }

        $piecesNormalisees = [];
        foreach ($catalogue as $code => $piece) {
            $piecesNormalisees[] = [
                'code' => $code,
                'libelle' => $piece['libelle'] ?? $code,
                'description' => $piece['description'] ?? '',
                'formats_autorises' => $piece['formats'] ?? [],
                'taille_max_mo' => $piece['taille_max_mo'] ?? 5,
                'categorie' => $piece['category'] ?? 'general',
            ];
        }

        return ResultatOutil::succes([
            'total' => count($piecesNormalisees),
            'pieces' => $piecesNormalisees,
        ], count($piecesNormalisees).' pièce(s) justificative(s) référencée(s) au catalogue.');
    }
}
