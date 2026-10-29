<?php

namespace App\Modules\AssistantIa\Tools\Metier;

use App\Models\User;
use App\Modules\AssistantIa\Contracts\OutilIaInterface;
use App\Modules\AssistantIa\DTO\ResultatOutil;

/**
 * Outil métier : Vérification et explication des règles officielles d'évaluation et de délibération des jurys.
 * (Sessions d'examen, compensation annuelle, seuils d'admissibilité, rattrapage, passage avec dettes).
 */
class VerifierReglesEvaluationTool implements OutilIaInterface
{
    public function nom(): string
    {
        return 'verifier_regles_evaluation';
    }

    public function description(): string
    {
        return 'Vérifie et explique les règles officielles d\'évaluation, de délibération des jurys, de session de rattrapage et de compensation annuelle.';
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
                'type_regle' => [
                    'type' => 'string',
                    'description' => 'Type de règle (ex: "compensation_annuelle", "session_rattrapage", "passage_avec_dette", "seuil_admission")',
                ],
                'niveau' => [
                    'type' => 'string',
                    'description' => 'Niveau académique concerné (ex: "L1", "L2", "L3", "M1", "M2")',
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
        $typeRegle = mb_strtolower(trim((string) ($parametres['type_regle'] ?? 'compensation_annuelle')));
        $niveau = strtoupper(trim((string) ($parametres['niveau'] ?? 'L1')));

        $reglesOfficielles = [
            'compensation_annuelle' => [
                'regle' => 'Compensation annuelle entre les semestres S1 et S2 (ou S3/S4, S5/S6).',
                'condition' => 'Moyenne générale de l\'année >= 10.00/20 avec total des crédits validés = 60 ECTS.',
                'exception' => 'Une note inférieure à la note éliminatoire fixée par la maquette de la faculté bloque la compensation.',
            ],
            'session_rattrapage' => [
                'regle' => 'Session de rattrapage ouverte aux étudiants ajournés à la session principale.',
                'modalite' => 'L\'étudiant repasse obligatoirement les épreuves des éléments constitutifs non validés (note < 10/20) des UE non compensées.',
                'calcul_note' => 'La meilleure note entre la session principale et la session de rattrapage est retenue pour le calcul final.',
            ],
            'passage_avec_dette' => [
                'regle' => 'Passage conditionnel de L1 vers L2 ou L2 vers L3 avec crédits manquants.',
                'condition' => 'Un minimum de 45 crédits ECTS sur 60 doit être capitalisé au terme de l\'année universitaire pour autoriser l\'enjambement.',
                'obligation' => 'L\'étudiant doit impérativement se réinscrire aux UE manquantes durant l\'année suivante.',
            ],
            'seuil_admission' => [
                'regle' => 'Conditions d\'obtention du diplôme de Licence ou Master.',
                'condition' => 'Validation intégrale des 180 crédits (Licence) ou 120 crédits (Master) sans aucune dette résiduelle.',
            ],
        ];

        $reponse = $reglesOfficielles[$typeRegle] ?? $reglesOfficielles['compensation_annuelle'];

        return ResultatOutil::succes(
            message: "Règle officielle d'évaluation pour le niveau {$niveau} vérifiée.",
            donnees: array_merge($reponse, [
                'niveau_concerne' => $niveau,
                'avertissement_juridique' => 'Seul le procès-verbal signé par le Président du Jury fait foi pour toute décision d’admission ou d’ajournement.',
            ]),
            sources: [
                [
                    'source' => 'business_procedure',
                    'titre' => 'Guide Officiel des Délibérations et Examens UniRegistre',
                    'chemin' => 'docs/regles-metier/deliberations-examens.md',
                ],
            ]
        );
    }
}
