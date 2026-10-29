<?php

namespace App\Support;

use App\Models\CampagneCourriel;
use App\Models\GroupeDestinataires;
use App\Models\User;

/**
 * Périmètre faculté des campagnes de courriels (LOOP-SECURITY-REMEDIATION-01).
 *
 * Un `admin_faculte` (faculté restreinte) ne peut cibler, consulter ou
 * modifier que les campagnes et groupes ENREGISTRÉS dans SA faculté. Les
 * comptes globaux (super admin, scolarité) conservent l'accès complet.
 *
 * Le marqueur de périmètre est `filtres['faculte_id']` (campagnes) et
 * `criteres['faculte_id']` (groupes), posé côté serveur à la création :
 * une campagne sans marqueur est globale et reste hors du périmètre des
 * administrateurs de faculté.
 */
final class CampagnePerimetre
{
    /**
     * Faculté de périmètre de l'utilisateur courant (null = accès global).
     */
    public static function faculteAutorisee(?User $utilisateur = null): ?int
    {
        $utilisateur ??= auth()->user();

        return $utilisateur?->faculteRestreinteId();
    }

    /**
     * Force le marqueur de faculté sur un tableau de filtres/critères.
     *
     * @param  array<string, mixed>  $filtres
     * @return array<string, mixed>
     */
    public static function marquerFaculte(array $filtres, ?int $faculte): array
    {
        if ($faculte !== null) {
            $filtres['faculte_id'] = $faculte;
        }

        return $filtres;
    }

    /**
     * La campagne appartient-elle au périmètre ? (true si accès global)
     *
     * Une campagne est rattachée à une faculté si son filtre la porte
     * explicitement, ou — à défaut — si le groupe enregistré qu'elle
     * cible appartient à cette faculté. Sans marqueur : campagne
     * globale, invisible pour un administrateur de faculté.
     */
    public static function campagneAutorisee(CampagneCourriel $campagne, ?int $faculte): bool
    {
        if ($faculte === null) {
            return true;
        }

        $filtres = (array) ($campagne->filtres ?? []);

        if (isset($filtres['faculte_id']) && (int) $filtres['faculte_id'] === $faculte) {
            return true;
        }

        if (isset($filtres['faculte_id'])) {
            return false;
        }

        if ($campagne->groupe_enregistre_id !== null) {
            $groupe = $campagne->groupeEnregistre;

            return $groupe !== null && self::groupeAutorise($groupe, $faculte);
        }

        return false;
    }

    /**
     * Le groupe appartient-il au périmètre ? (true si accès global)
     */
    public static function groupeAutorise(GroupeDestinataires $groupe, ?int $faculte): bool
    {
        if ($faculte === null) {
            return true;
        }

        $criteres = (array) ($groupe->criteres ?? []);

        return isset($criteres['faculte_id']) && (int) $criteres['faculte_id'] === $faculte;
    }
}
