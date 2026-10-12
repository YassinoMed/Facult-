<?php

namespace App\Services\Io\Exports;

/**
 * Filtres multicritères d'export (§70, §84) :
 *
 * - TOUS les critères sont en liste blanche (jamais de colonne SQL issue
 *   directement de l'entrée utilisateur) ;
 * - combinables librement (AND logique) ;
 * - validés côté serveur par les règles centralisées ci-dessous,
 *   réutilisées par l'UI web ET l'API (§72 : la validation n'est jamais
 *   seulement côté client).
 */
final class ExportFilters
{
    /** Types d'export supportés par le moteur unique (§71, §90). */
    public const TYPES = ['students', 'grades', 'subject', 'group', 'averages', 'filiere', 'stats'];

    public const GRANULARITES = ['matiere', 'semestre', 'annee', 'generale'];

    /**
     * Liste blanche stricte des filtres (§70) — la correspondance vers les
     * colonnes SQL est arbitrée côté builders, jamais depuis l'entrée.
     */
    public const CLES_AUTORISEES = [
        // périmètre académique
        'faculte', 'filiere', 'niveau', 'groupe', 'annee', 'semestre', 'matiere', 'session',
        // statuts & décisions
        'statut', 'statut_academique', 'decision',
        // intervalles numériques
        'moyenne_min', 'moyenne_max', 'note_min', 'note_max', 'credits_min', 'credits_max',
        // identification individuelle
        'cin', 'nom', 'prenom', 'num_inscription', 'matricule', 'search',
    ];

    /**
     * Règles de validation Laravel — réutilisées telles quelles par la
     * FormRequest web et le contrôleur API.
     *
     * @return array<string, string|array<int, string>>
     */
    public static function regles(): array
    {
        return [
            'filters.faculte' => ['nullable', 'string', 'max:20'],
            'filters.filiere' => ['nullable', 'string', 'max:150'],
            'filters.niveau' => ['nullable', 'in:L1,L2,L3,M1,M2,D1,D2,D3'],
            'filters.groupe' => ['nullable', 'string', 'max:20'],
            'filters.annee' => ['nullable', 'regex:/^\d{4}-\d{4}$/'],
            'filters.semestre' => ['nullable', 'integer', 'in:1,2'],
            'filters.matiere' => ['nullable', 'string', 'max:20'],
            'filters.session' => ['nullable', 'in:normale,rattrapage,toutes'],
            'filters.statut' => ['nullable', 'in:en_attente,valide,rejete'],
            'filters.statut_academique' => ['nullable', 'in:en_cours,valide,ajourne,abandon'],
            'filters.decision' => ['nullable', 'in:admis,ajourne,rattrapage'],
            'filters.moyenne_min' => ['nullable', 'numeric', 'min:0', 'max:20'],
            'filters.moyenne_max' => ['nullable', 'numeric', 'min:0', 'max:20'],
            'filters.note_min' => ['nullable', 'numeric', 'min:0', 'max:20'],
            'filters.note_max' => ['nullable', 'numeric', 'min:0', 'max:20'],
            'filters.credits_min' => ['nullable', 'integer', 'min:0', 'max:999'],
            'filters.credits_max' => ['nullable', 'integer', 'min:0', 'max:999'],
            'filters.cin' => ['nullable', 'string', 'max:30'],
            'filters.nom' => ['nullable', 'string', 'max:100'],
            'filters.prenom' => ['nullable', 'string', 'max:100'],
            'filters.num_inscription' => ['nullable', 'string', 'max:30'],
            'filters.matricule' => ['nullable', 'string', 'max:30'],
            'filters.search' => ['nullable', 'string', 'max:100'],
        ];
    }

    public static function messages(): array
    {
        return [
            'filters.niveau.in' => 'Niveau invalide.',
            'filters.semestre.in' => 'Le semestre doit être 1 ou 2.',
            'filters.session.in' => 'La session doit être normale, rattrapage ou toutes.',
            'filters.statut.in' => 'Statut étudiant invalide.',
            'filters.statut_academique.in' => 'Statut académique invalide.',
            'filters.decision.in' => 'Décision invalide.',
            'filters.moyenne_min.max' => 'La moyenne minimale ne peut excéder 20.',
            'filters.moyenne_max.max' => 'La moyenne maximale ne peut excéder 20.',
            'filters.note_min.max' => 'La note minimale ne peut excéder 20.',
            'filters.note_max.max' => 'La note maximale ne peut excéder 20.',
        ];
    }

    /**
     * Extrait UNIQUEMENT les clés de la liste blanche (§84) — toute autre
     * clé est ignorée avant même la validation métier.
     *
     * @return array<string, mixed>
     */
    public static function epurer(array $filtres): array
    {
        $epures = [];
        foreach (self::CLES_AUTORISEES as $cle) {
            if (array_key_exists($cle, $filtres) && $filtres[$cle] !== null && $filtres[$cle] !== '') {
                $epures[$cle] = $filtres[$cle];
            }
        }

        return $epures;
    }

    /**
     * Rejette explicitement toute clé hors liste blanche (422) — utilisé
     * par l'API (§42/§59/§84).
     *
     * @return array<int, string>
     */
    public static function clesInconnues(array $filtres): array
    {
        return array_values(array_diff(array_keys($filtres), self::CLES_AUTORISEES));
    }
}
