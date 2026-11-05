<?php

namespace App\Modules\AssistantIa\Tools\Metier;

use App\Models\Etudiant;
use App\Models\User;
use App\Modules\AssistantIa\Contracts\OutilIaInterface;
use App\Modules\AssistantIa\DTO\ResultatOutil;
use App\Services\Acquis\MoteurDecisionAcademiqueService;
use App\Services\Acquis\PlanRedoublementService;

/**
 * Outil métier Assistant IA : Explication pédagogique et réglementaire des acquis et du redoublement.
 *
 * RÈGLE D'OR : Strictement explicatif et consultatif. Ne prend aucune décision souveraine
 * et ne mute aucun résultat en base (la décision officielle appartient exclusivement au jury).
 */
class ExpliquerAcquisRedoublementTool implements OutilIaInterface
{
    public function __construct(
        protected ?MoteurDecisionAcademiqueService $moteurDecision = null,
        protected ?PlanRedoublementService $planRedoublementService = null
    ) {
        $this->moteurDecision = $moteurDecision ?? app(MoteurDecisionAcademiqueService::class);
        $this->planRedoublementService = $planRedoublementService ?? app(PlanRedoublementService::class);
    }

    public function nom(): string
    {
        return 'expliquer_acquis_redoublement';
    }

    public function description(): string
    {
        return 'Explique pédagogiquement les règles de capitalisation, de compensation et le statut des acquis pour un étudiant en redoublement (Décret présidentiel n° 2022-631 JORT n° 80, Arrêté du 7 juillet 2023 JORT n° 70, Arrêté du 10 mai 2023 JORT n° 51, et Décret n° 95-2602 JORT n° 03). Outil strictement explicatif et non décisionnel.';
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
                'etudiant_id' => [
                    'type' => 'integer',
                    'description' => 'Identifiant unique de l\'étudiant (optionnel si question générale sur les règles).',
                ],
                'num_inscription' => [
                    'type' => 'string',
                    'description' => 'Numéro d\'inscription ou matricule de l\'étudiant (alternative à etudiant_id).',
                ],
                'sujet' => [
                    'type' => 'string',
                    'description' => 'Sujet ciblé : "general", "capitalisation", "compensation", "seuil_eliminatoire", "prerequis".',
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
        $etudiantId = isset($parametres['etudiant_id']) ? (int) $parametres['etudiant_id'] : null;
        $numInscription = isset($parametres['num_inscription']) ? trim((string) $parametres['num_inscription']) : null;
        $sujet = mb_strtolower(trim((string) ($parametres['sujet'] ?? 'general')));

        $sources = [
            [
                'titre' => 'Décret présidentiel n° 2022-631 du 14 juillet 2022',
                'url_ou_chemin' => 'reglementation/decret_2022_631.pdf',
                'extrait' => 'Fixant le cadre général du régime des études et les conditions d\'obtention de la Licence LMD (JORT n° 80 du 19 juillet 2022, p. 2267-2274).',
            ],
            [
                'titre' => 'Arrêté ministériel du 7 juillet 2023',
                'url_ou_chemin' => 'reglementation/arrete_7_juillet_2023.pdf',
                'extrait' => 'Guide unifié des crédits, évaluation, délibération, capitalisation et réinscription ciblée des redoublants (JORT n° 70 du 11 juillet 2023, p. 2167-2178).',
            ],
        ];

        if (in_array($sujet, ['seuil_eliminatoire', 'regimes_specifiques', 'all'], true) || $etudiantId !== null || ! empty($numInscription)) {
            $sources[] = [
                'titre' => 'Arrêté ministériel du 10 mai 2023',
                'url_ou_chemin' => 'reglementation/arrete_10_mai_2023.pdf',
                'extrait' => 'Fixant les diplômes nationaux de licence concernés par le seuil de compétence (حد الكفاءة à 6.0/20 pour Santé et Éducation - JORT n° 51 du 19 mai 2023).',
            ];
            $sources[] = [
                'titre' => 'Décret n° 95-2602 du 25 décembre 1995',
                'url_ou_chemin' => 'reglementation/decret_95_2602.pdf',
                'extrait' => 'Régime des études d\'ingénieur (JORT n° 03 du 09 janvier 1996, p. 43-48, modifié par Décret 2009-643), seuil éliminatoire de module 8.0/20 (Art. 14).',
            ];
        }

        // 1. Cas d'une question générale sur les règles LMD
        if ($etudiantId === null && empty($numInscription)) {
            $explications = [
                'capitalisation' => [
                    'titre' => 'Principe de Capitalisation (Article 8 de l\'Arrêté du 7 juillet 2023)',
                    'regle' => 'Toute Unité d\'Enseignement (UE) ayant obtenu une moyenne >= 10/20 est définitivement acquise et capitalisée. Les crédits ECTS associés sont acquis à vie. Les ECUEs ayant une note >= 10/20 sont également capitalisés individuellement s\'ils disposent de crédits propres dans la maquette.',
                    'effet_redoublement' => 'L\'étudiant redoublant ne peut et ne doit pas repasser les UE capitalisées.',
                ],
                'compensation' => [
                    'titre' => 'Principe de Compensation (Article 9 de l\'Arrêté du 7 juillet 2023)',
                    'regle' => 'La compensation opère au sein d\'un même semestre lorsque la moyenne pondérée du semestre est >= 10/20. Si la moyenne annuelle est >= 10/20, la compensation annuelle s\'applique selon les dispositions de la maquette validée.',
                    'effet_redoublement' => 'En cas de redoublement, les UE non capitalisées individuellement (< 10/20) qui n\'ont pas été validées par une délibération officielle d\'admission compensée doivent être réinscrites.',
                ],
                'seuil_eliminatoire' => [
                    'titre' => 'Seuils Éliminatoires de Compétence (Art. 13 Arrêté 7 juillet 2023 & Arrêté 10 mai 2023)',
                    'regle' => 'Le seuil éliminatoire dépend strictement de la formation : 06/20 en Licences de Santé et Éducation (Arrêté du 10 mai 2023, seuil CONFIRME) ; 08/20 en cycle ingénieur selon Décret 95-2602 Art. 14 et règlements d\'écoles (ENIT/ENSI) ; absence de seuil éliminatoire général par défaut en Licence Informatique standard et Mastère LMD (ABSENCE_CONFIRMEE). Une note inférieure au seuil applicable bloque toute compensation.',
                ],
                'regimes_specifiques' => [
                    'titre' => 'Formations relevant d\'un régime d\'exception',
                    'formations' => [
                        'Études médicales, pharmaceutiques et odontologiques : régimes spécifiques régis par décrets propres (pas de semestrialisation LMD standard).',
                        'Écoles nationales d\'ingénieurs et cycles préparatoires : régime modulaire sous Décret 95-2602 avec seuil d\'élimination spécifique (8.00/20) et crédits annuels.',
                        'Mastères de recherche et professionnels : sous Décrets 2008-3123 et 2012-1227, compensation semestrielle soumise aux maquettes d\'habilitation.',
                    ],
                ],
                'general' => [
                    'titre' => 'Régime Général du Redoublement et Réinscription Ciblée',
                    'points_cles' => [
                        '1. L\'étudiant ne se réinscrit qu\'aux UE et ECUE non validés.',
                        '2. Le total des crédits à réinscrire est égal à 60 moins les crédits capitalisés.',
                        '3. Les décisions souveraines appartiennent exclusivement au jury de délibération.',
                        '4. L\'Assistant IA a un rôle purement explicatif et informatif.',
                    ],
                ],
            ];

            return ResultatOutil::succes([
                'mode' => 'explication_reglementaire_generale',
                'sujet' => $sujet,
                'contenu' => $explications[$sujet] ?? $explications['general'],
                'avertissement_legal' => 'Ces explications sont données à titre indicatif et pédagogique. Seul le procès-verbal officiel du jury de délibération fait foi.',
            ], 'Explication réglementaire formulée avec succès.', $sources);
        }

        // 2. Cas de l'analyse d'un étudiant particulier
        $etudiant = null;
        if ($etudiantId !== null) {
            $etudiant = Etudiant::find($etudiantId);
        } elseif (! empty($numInscription)) {
            $etudiant = Etudiant::where('num_inscription', $numInscription)->first();
        }

        if ($etudiant === null) {
            return ResultatOutil::echec('Dossier étudiant introuvable pour les identifiants fournis.');
        }

        // Vérification de périmètre faculté si l'utilisateur est restreint
        $faculteRestreinteId = $utilisateur->faculteRestreinteId();
        if ($faculteRestreinteId !== null && (int) $etudiant->faculte_id !== $faculteRestreinteId) {
            return ResultatOutil::echec('Accès non autorisé : l\'étudiant n\'appartient pas à votre périmètre de faculté.');
        }

        // Exécution de l'analyse académique
        $analyse = $this->moteurDecision->determinerDecision($etudiant);
        $simulation = $this->planRedoublementService->simulerPlan($etudiant);

        return ResultatOutil::succes([
            'mode' => 'analyse_dossier_etudiant',
            'etudiant' => [
                'id' => $etudiant->id,
                'matricule' => $etudiant->num_inscription,
                'nom_complet' => $etudiant->nom.' '.$etudiant->prenom,
                'statut_etudiant' => $etudiant->statut,
            ],
            'synthese_academique' => [
                'decision_globale' => $analyse['decision_globale'] ?? 'NON_DETERMINEE',
                'credits_capitalises' => $analyse['credits_capitalises'] ?? 0,
                'credits_a_reprendre' => $analyse['credits_non_acquis'] ?? 0,
                'anomalies_detectees' => $analyse['anomalies'] ?? [],
            ],
            'explication_pedagogique' => [
                'elements_conserves' => $simulation['elements_conserves'] ?? [],
                'elements_a_reprendre' => $simulation['elements_a_reprendre'] ?? [],
                'alertes_prerequis' => $simulation['alertes_prerequis'] ?? [],
                'regles_appliquees' => $simulation['regles_appliquees'] ?? [],
            ],
            'clause_de_non_responsabilite' => 'Cette analyse est délivrée à titre d\'aide à la compréhension des droits acquis. Elle ne modifie aucunement les notes ni le statut légal, qui restent régis par les procès-verbaux de délibération.',
        ], 'Analyse pédagogique des acquis de l\'étudiant établie avec succès.', $sources);
    }
}
