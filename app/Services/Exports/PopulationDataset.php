<?php

namespace App\Services\Exports;

/**
 * Registre des datasets population (§18-19) : simples définitions
 * (code/label/filtres/colonnes/regroupements/permissions) SANS logique
 * métier. Toute la requête vit dans ListeEtudiantsService (source unique).
 *
 * Un dataset = un nom + un contexte scalaire (ids) → filtres centraux.
 * Les contextes objets (Epreuve, Deliberation…) sont traduits côté module
 * en scalaires AVANT appel — le registre ne dépend d'aucun module.
 */
final class PopulationDataset
{
    /**
     * @var array<string, array{label: string, filtres: array<int, string>, colonnes: array<int, string>, regroupements: array<int, string>, permission: string}>
     */
    public const DATASETS = [
        'students' => [
            'label' => 'Tous les étudiants',
            'filtres' => ['annee_universitaire_id', 'programme_id', 'niveau', 'semestre', 'groupe_id', 'statut', 'search'],
            'colonnes' => ['numero', 'matricule', 'nom', 'prenom', 'groupe', 'niveau', 'programme', 'statut'],
            'regroupements' => ['programme', 'niveau', 'groupe', 'statut', 'faculte'],
            'permission' => 'archives.view',
        ],
        'students_by_group' => [
            'label' => 'Étudiants par groupe',
            'filtres' => ['groupe_id', 'sous_groupe_id', 'statut'],
            'colonnes' => ['numero', 'matricule', 'nom', 'prenom', 'sous_groupe', 'statut'],
            'regroupements' => ['sous_groupe', 'statut'],
            'permission' => 'groupes.view',
        ],
        'students_by_program' => [
            'label' => 'Étudiants par programme',
            'filtres' => ['programme_id', 'niveau', 'annee_universitaire_id', 'statut'],
            'colonnes' => ['numero', 'matricule', 'nom', 'prenom', 'groupe', 'niveau', 'statut'],
            'regroupements' => ['niveau', 'groupe'],
            'permission' => 'archives.view',
        ],
        'students_by_level' => [
            'label' => 'Étudiants par niveau',
            'filtres' => ['niveau', 'programme_id', 'annee_universitaire_id', 'statut'],
            'colonnes' => ['numero', 'matricule', 'nom', 'prenom', 'groupe', 'programme', 'statut'],
            'regroupements' => ['programme', 'groupe'],
            'permission' => 'archives.view',
        ],
        'students_by_ue' => [
            'label' => 'Étudiants par UE',
            'filtres' => ['ue_id', 'annee_universitaire_id', 'statut'],
            'colonnes' => ['numero', 'matricule', 'nom', 'prenom', 'groupe', 'niveau', 'statut'],
            'regroupements' => ['groupe', 'niveau'],
            'permission' => 'archives.view',
        ],
        'students_by_ecue' => [
            'label' => 'Étudiants par ECUE',
            'filtres' => ['matiere_id', 'annee_universitaire_id', 'statut'],
            'colonnes' => ['numero', 'matricule', 'nom', 'prenom', 'groupe', 'sous_groupe', 'niveau', 'statut'],
            'regroupements' => ['groupe', 'sous_groupe', 'niveau'],
            'permission' => 'archives.view',
        ],
        'students_by_teacher' => [
            'label' => 'Étudiants par enseignant',
            'filtres' => ['enseignant_id', 'annee_universitaire_id', 'statut'],
            'colonnes' => ['numero', 'matricule', 'nom', 'prenom', 'groupe', 'niveau', 'statut'],
            'regroupements' => ['groupe', 'niveau'],
            'permission' => 'enseignants.view',
        ],
        'exam_candidates' => [
            'label' => 'Candidats d examen',
            'filtres' => ['groupe_id', 'matiere_id', 'annee_universitaire_id', 'statut'],
            'colonnes' => ['numero', 'matricule', 'nom', 'prenom', 'groupe', 'statut'],
            'regroupements' => ['groupe'],
            'permission' => 'evaluations.view',
        ],
        'attendance_list' => [
            'label' => 'Feuille de présence',
            'filtres' => ['groupe_id', 'matiere_id', 'annee_universitaire_id', 'statut'],
            'colonnes' => ['numero', 'matricule', 'nom', 'prenom', 'groupe', 'sous_groupe'],
            'regroupements' => ['groupe', 'sous_groupe'],
            'permission' => 'attendance.view',
        ],
        'grade_list' => [
            'label' => 'Liste de notes',
            'filtres' => ['matiere_id', 'groupe_id', 'annee_universitaire_id', 'statut'],
            'colonnes' => ['numero', 'matricule', 'nom', 'prenom', 'groupe', 'statut'],
            'regroupements' => ['groupe'],
            'permission' => 'notes_eval.view',
        ],
    ];

    /** @return array<int, string> */
    public static function codes(): array
    {
        return array_keys(self::DATASETS);
    }

    /** @return array{label: string, filtres: array<int, string>, colonnes: array<int, string>, regroupements: array<int, string>, permission: string} */
    public static function definition(string $code): array
    {
        abort_unless(isset(self::DATASETS[$code]), 404, "Dataset « {$code} » inconnu.");

        return self::DATASETS[$code];
    }

    /**
     * Traduit un contexte scalaire en filtres centraux whitelistés par dataset.
     * Clés inconnues du dataset = ignorées (filtres contextuels §21).
     *
     * @param  array<string, mixed>  $contexte
     * @return array<string, mixed>
     */
    public static function filtresPour(string $code, array $contexte): array
    {
        $definition = self::definition($code);
        $autorises = array_merge($definition['filtres'], ['tri', 'sens', 'titre']);

        $filtres = [];

        foreach ($autorises as $cle) {
            if (array_key_exists($cle, $contexte) && $contexte[$cle] !== null && $contexte[$cle] !== '') {
                $filtres[$cle] = $contexte[$cle];
            }
        }

        return $filtres;
    }

    /**
     * @param  array<string, mixed>  $contexte
     * @return array<int, string>|null
     */
    public static function colonnesPour(string $code, array $contexte): ?array
    {
        if (isset($contexte['colonnes']) && is_array($contexte['colonnes'])) {
            return array_values(array_map('strval', $contexte['colonnes']));
        }

        return self::definition($code)['colonnes'];
    }

    public static function regroupementPour(string $code, array $contexte): ?string
    {
        $demande = ListeEtudiantsService::normaliserRegroupement($contexte['regroupement'] ?? null);

        if ($demande !== null && in_array($demande, self::definition($code)['regroupements'], true)) {
            return $demande;
        }

        return null;
    }
}
