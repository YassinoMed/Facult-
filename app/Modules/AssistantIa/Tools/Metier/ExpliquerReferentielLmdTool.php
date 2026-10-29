<?php

namespace App\Modules\AssistantIa\Tools\Metier;

use App\Models\User;
use App\Modules\AssistantIa\Contracts\OutilIaInterface;
use App\Modules\AssistantIa\DTO\ResultatOutil;
use App\Modules\Referentiel\Models\Domaine;
use App\Modules\Referentiel\Models\Mention;
use App\Modules\Referentiel\Models\Niveau;

/**
 * Outil métier : Explication et exploration de la structure du référentiel LMD
 * (Domaines, Mentions, Spécialités, Semestres, UE, ECUE, Crédits ECTS et Coefficients).
 */
class ExpliquerReferentielLmdTool implements OutilIaInterface
{
    public function nom(): string
    {
        return 'expliquer_referentiel_lmd';
    }

    public function description(): string
    {
        return 'Explique l\'architecture et les règles du système LMD d\'UniRegistre (Domaines, Mentions, Semestres S1-S6, Crédits ECTS, UE et coefficients).';
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
                'sujet' => [
                    'type' => 'string',
                    'description' => 'Sujet ou terme à expliquer (ex: "credits_ects", "structure_semestre", "domaines", "mentions", "ue")',
                ],
                'mention_code' => [
                    'type' => 'string',
                    'description' => 'Code ou libellé d\'une mention spécifique à inspecter (optionnel)',
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
        $sujet = mb_strtolower(trim((string) ($parametres['sujet'] ?? 'general')));
        $mentionCode = trim((string) ($parametres['mention_code'] ?? ''));

        // Données du référentiel
        $explications = [
            'credits_ects' => [
                'titre' => 'Système de Crédits ECTS',
                'description' => 'Chaque semestre valide 30 crédits ECTS (60 crédits par année universitaire). Une Licence LMD correspond à 180 crédits (L1=60, L2=60, L3=60) et un Master à 120 crédits additionnels (M1=60, M2=60).',
                'regle_capitalisation' => 'Les UE dont la moyenne est >= 10/20 sont définitivement capitalisées et transférables.',
            ],
            'structure_semestre' => [
                'titre' => 'Organisation Semestrielle LMD',
                'description' => 'L\'année académique est divisée en deux semestres indépendants (Semestre 1 / Semestre 2). Chaque semestre comprend des Unités d\'Enseignement (UE) Fondamentales, Optionnelles et Transversales.',
                'regle_compensation' => 'La compensation semestrielle s\'applique lorsque la moyenne générale du semestre est >= 10/20 et qu\'aucune note éliminatoire n\'est constatée.',
            ],
            'ue' => [
                'titre' => 'Unités d\'Enseignement (UE) et ECUE',
                'description' => 'Une UE est un ensemble cohérent d\'enseignements constitué d\'un ou plusieurs Éléments Constitutifs (ECUE). Chaque ECUE possède un coefficient et un nombre d\'heures (cours, TD, TP).',
            ],
        ];

        // Vérification en base si disponible
        $statistiques = [
            'domaines_enregistres' => class_exists(Domaine::class) ? Domaine::count() : 0,
            'mentions_enregistrees' => class_exists(Mention::class) ? Mention::count() : 0,
            'niveaux_enregistres' => class_exists(Niveau::class) ? Niveau::count() : 0,
        ];

        $reponseDetail = $explications[$sujet] ?? [
            'titre' => 'Architecture Générale LMD UniRegistre',
            'description' => 'Le référentiel LMD UniRegistre s\'articule autour de la pyramide : Domaine -> Mention -> Spécialité/Parcours -> Niveaux (L1, L2, L3, M1, M2) -> Semestres (30 ECTS) -> Unités d\'Enseignement (UE) -> ECUE.',
            'piliers' => [
                '1. Semestrialisation des parcours et examens',
                '2. Capitalisation et transférabilité des crédits ECTS',
                '3. Compensation intra-semestre et annuelle selon maquette validée',
            ],
        ];

        return ResultatOutil::succes(
            message: "Explication du référentiel LMD ({$reponseDetail['titre']}) formulée avec succès.",
            donnees: array_merge($reponseDetail, ['statistiques_base' => $statistiques]),
            sources: [
                [
                    'source' => 'business_procedure',
                    'titre' => 'Règlement des Études LMD UniRegistre',
                    'chemin' => 'docs/regles-metier/referentiel-lmd.md',
                ],
            ]
        );
    }
}
