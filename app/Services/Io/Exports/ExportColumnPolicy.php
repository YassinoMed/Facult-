<?php

namespace App\Services\Io\Exports;

use App\Models\User;

/**
 * Politique des colonnes d'export (§78 + §85) :
 *
 * - catalogues de colonnes par type (liste blanche) ;
 * - colonnes personnalisables par l'utilisateur (sous-ensemble du catalogue) ;
 * - niveaux de protection : EXPORT_BASIC / EXPORT_ACADEMIC / EXPORT_PERSONAL
 *   mappés sur les permissions RBAC ; EXPORT_SENSITIVE (documents
 *   administratifs) n'est JAMAIS exportable ;
 * - CIN masquée (BK***456) pour les rôles sans permission personnelle.
 */
class ExportColumnPolicy
{
    /** Colonnes autorisées par type d'export (§78 — liste blanche). */
    private const CATALOGUES = [
        'students' => ['matricule', 'cin', 'nom', 'prenom', 'email', 'telephone', 'groupe', 'sous_groupe', 'programme', 'filiere', 'niveau', 'faculte', 'annee', 'moyenne', 'credits', 'decision', 'statut'],
        'grades' => ['matricule', 'cin', 'nom', 'prenom', 'groupe', 'annee', 'niveau', 'semestre', 'matiere', 'note', 'coefficient', 'credits', 'session'],
        'subject' => ['matricule', 'nom', 'prenom', 'groupe', 'note', 'coefficient', 'credits', 'session', 'resultat'],
        'group' => ['matricule', 'nom', 'prenom', 'groupe', 'moyenne', 'credits', 'decision'],
        'averages' => ['matricule', 'nom', 'prenom', 'matiere', 'note', 'coefficient', 'semestre', 'moyenne', 'credits', 'annee', 'decision', 'parcours', 'moyenne_generale', 'total_credits'],
        'filiere' => ['niveau', 'groupe', 'effectif', 'moyenne', 'credits', 'admis', 'ajournes'],
        'stats' => ['faculte', 'filiere', 'niveau', 'semestre', 'matiere', 'effectif', 'moyenne', 'taux_reussite', 'nb_notes', 'repartition'],
    ];

    /** Niveau EXPORT_PERSONAL (§85) : données de contact complètes. */
    private const COLONNES_PERSONNELLES = ['cin', 'email', 'telephone'];

    /**
     * Applique la personnalisation + les permissions sur un jeu de données.
     *
     * @param  array{entetes: array<int, string>, lignes: array<int, array<string, mixed>>}  $donnees
     * @param  array<int, string>|null  $colonnesSouhaitees
     * @return array{entetes: array<int, string>, lignes: array<int, array<string, mixed>>}
     */
    public function appliquer(array $donnees, ?User $utilisateur, string $type, ?array $colonnesSouhaitees = null): array
    {
        $catalogue = self::CATALOGUES[$type] ?? [];
        $personnelAutorise = $utilisateur !== null && $utilisateur->hasPermission('exports.personal');

        // 1. Colonnes retenues : intersection catalogue ∩ choix utilisateur
        $retenues = $colonnesSouhaitees === null
            ? $catalogue
            : array_values(array_intersect($catalogue, $colonnesSouhaitees));

        if ($retenues === []) {
            $retenues = $catalogue; // choix vide → catalogue par défaut (jamais d'export vide)
        }

        // 2. Colonnes personnelles sans permission → retirées
        if (! $personnelAutorise) {
            $retenues = array_values(array_diff($retenues, self::COLONNES_PERSONNELLES));
        }

        // 3. Reconstruction : entêtes dans l'ordre du catalogue + lignes filtrées
        $entetes = array_values(array_intersect($catalogue, $retenues));
        $lignes = [];
        foreach ($donnees['lignes'] as $ligne) {
            $normalisee = [];
            foreach ($entetes as $entete) {
                $normalisee[$entete] = $ligne[$entete] ?? '';
            }
            $lignes[] = $normalisee;
        }

        return ['entetes' => $entetes, 'lignes' => $lignes];
    }

    /**
     * Catalogue d'un type (pour l'UI §87 et la validation API).
     *
     * @return array<int, string>
     */
    public static function catalogue(string $type): array
    {
        return self::CATALOGUES[$type] ?? [];
    }

    /**
     * Une colonne donnée est-elle personnelle (données de contact) ?
     */
    public static function estPersonnelle(string $colonne): bool
    {
        return in_array($colonne, self::COLONNES_PERSONNELLES, true);
    }

    /**
     * CIN masquée pour les rôles sans permission personnelle (§21/§43/§85).
     */
    public static function masquerCin(?string $cin, ?User $utilisateur): string
    {
        if ($cin === null || $cin === '') {
            return '';
        }

        if ($utilisateur !== null && $utilisateur->hasPermission('exports.personal')) {
            return $cin;
        }

        return mb_substr($cin, 0, 2).'***'.mb_substr($cin, -3);
    }
}
