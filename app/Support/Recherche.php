<?php

namespace App\Support;

/**
 * Échappement des recherches LIKE (audit §P3-22) : les caractères
 * génériques `%`, `_` et l'échappement `\` saisis par l'utilisateur sont
 * neutralisés afin qu'une recherche reste une recherche littérale
 * (aucune injection de motif, aucun déni de service par motif coûteux).
 *
 * Note driver : l'échappement antislash est natif en MySQL (production) ;
 * sous SQLite (tests) il est conservateur (le motif exige un antislash
 * littéral) — dans les deux cas, aucun joker injecté ne s'exécute.
 */
final class Recherche
{
    /**
     * Neutralise les jokers LIKE dans une saisie utilisateur.
     */
    public static function echapperLike(string $recherche): string
    {
        return addcslashes($recherche, '\\%_');
    }

    /**
     * Motif LIKE « contient » sûr à partir d'une saisie utilisateur.
     */
    public static function contient(string $recherche): string
    {
        return '%'.self::echapperLike($recherche).'%';
    }
}
