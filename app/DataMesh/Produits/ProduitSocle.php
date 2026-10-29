<?php

namespace App\DataMesh\Produits;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Socle commun de gouvernance pour tous les produits de données.
 * Centralise :
 * - L'authentification et les contrôles de rôles.
 * - L'isolation stricte du périmètre facultaire (cloisonnement).
 * - La minimisation et le masquage des PII.
 * - Le calcul de fraîcheur normalisé (ISO-8601).
 * - L'audit des consultations sensibles.
 */
abstract class ProduitSocle
{
    protected function consommateur(array $contexte): User
    {
        $utilisateur = $contexte['utilisateur'] ?? null;

        if (! $utilisateur instanceof User) {
            throw new AuthorizationException('Produit de données : consommateur non authentifié.');
        }

        return $utilisateur;
    }

    /**
     * Faculté effective :
     * 1. Si une faculté est demandée explicitement, contrôle strict de permission (refus sinon).
     * 2. Si le consommateur a une faculté restreinte (admin_faculte), ramené automatiquement à sa faculté.
     * 3. Sinon, retourne la faculté demandée ou null (accès global pour super_admin).
     */
    protected function perimetreFaculte(User $utilisateur, ?int $demandee): ?int
    {
        if ($demandee !== null && ! $utilisateur->peutAccederFaculte($demandee)) {
            throw new AuthorizationException('Produit de données : faculté hors périmètre.');
        }

        $restreinte = $utilisateur->faculteRestreinteId();

        if ($restreinte !== null) {
            return $restreinte;
        }

        return $demandee;
    }

    protected function roleBackOfficeRequis(User $utilisateur): void
    {
        if ($utilisateur->role === null) {
            throw new AuthorizationException('Produit de données : rôle back-office requis.');
        }
    }

    /**
     * Masquage d'un numéro de CIN (ex. 12345678 -> 12***678).
     */
    protected function masquerCin(?string $cin): ?string
    {
        if ($cin === null || mb_strlen($cin) < 4) {
            return $cin;
        }

        $longueur = mb_strlen($cin);
        $debut = mb_substr($cin, 0, 2);
        $fin = mb_substr($cin, -3);

        return $debut.str_repeat('*', max(1, $longueur - 5)).$fin;
    }

    /**
     * Masquage d'une adresse email (ex. jean.dupont@test.tn -> j***t@test.tn).
     */
    protected function masquerEmail(?string $email): ?string
    {
        if ($email === null || ! str_contains($email, '@')) {
            return $email;
        }

        [$local, $domaine] = explode('@', $email, 2);
        if (mb_strlen($local) <= 2) {
            return mb_substr($local, 0, 1).'*@'.$domaine;
        }

        return mb_substr($local, 0, 1).str_repeat('*', mb_strlen($local) - 2).mb_substr($local, -1).'@'.$domaine;
    }

    /**
     * Plus récente des dates max (chaînes ou Carbon) au format ISO, null si aucune.
     */
    protected function derniereMaj(array $dates): ?string
    {
        $dates = array_filter($dates);

        if ($dates === []) {
            return null;
        }

        return Carbon::parse(max($dates))->format(DATE_ATOM);
    }
}
