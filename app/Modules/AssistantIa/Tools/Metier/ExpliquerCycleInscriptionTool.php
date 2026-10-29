<?php

namespace App\Modules\AssistantIa\Tools\Metier;

use App\Models\User;
use App\Modules\AssistantIa\Contracts\OutilIaInterface;
use App\Modules\AssistantIa\DTO\ResultatOutil;

/**
 * Outil métier : Explication officielle du cycle d'inscription universitaire UniRegistre.
 */
class ExpliquerCycleInscriptionTool implements OutilIaInterface
{
    public function nom(): string
    {
        return 'expliquer_cycle_inscription';
    }

    public function description(): string
    {
        return 'Fournit les étapes officielles, prérequis et statuts du workflow d\'inscription académique.';
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
                'type_parcours' => [
                    'type' => 'string',
                    'description' => 'Type de parcours (ex: "licence", "master", "re-inscription")',
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
        $etapes = [
            [
                'ordre' => 1,
                'etape' => 'Création du compte et téléversement des pièces',
                'description' => 'L\'étudiant soumet son identité et téléverse ses justificatifs (CIN, photo, relevé de notes, baccalauréat).',
            ],
            [
                'ordre' => 2,
                'etape' => 'Paiement des droits d\'inscription',
                'description' => 'Dépôt du reçu ou paiement en ligne. Génération du dossier de paiement canonique.',
            ],
            [
                'ordre' => 3,
                'etape' => 'Vérification automatique et contrôle de scolarité',
                'description' => 'Le moteur de vérification (11 règles de cohérence) analyse l\'éligibilité, les doublons et les prérequis LMD.',
            ],
            [
                'ordre' => 4,
                'etape' => 'Décision administrative (Validation ou Rejet motivé)',
                'description' => 'L\'agent de scolarité valide le dossier. Si une anomalie est détectée, le dossier passe en statut d\'attente ou bloqué.',
            ],
            [
                'ordre' => 5,
                'etape' => 'Affectation pédagogique et délivrance des attestations',
                'description' => 'L\'étudiant est affecté à un groupe de TD/TP et peut télécharger son certificat d\'inscription avec QR code officiel.',
            ],
        ];

        return ResultatOutil::succes([
            'etapes_cycle' => $etapes,
            'source_reglementaire' => 'Règlement Intérieur et Directives LMD UniRegistre',
        ], "Cycle d'inscription standard en 5 étapes.");
    }
}
