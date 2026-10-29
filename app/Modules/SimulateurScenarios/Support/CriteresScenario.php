<?php

namespace App\Modules\SimulateurScenarios\Support;

use InvalidArgumentException;

/**
 * Lot 14 — Critères d'entrée d'un scénario institutionnel.
 *
 * Objet IMMUAUBLE et sécurisé : chaque hypothèse est bornée et une valeur
 * hors bornes est REFUSÉE avec un message explicite (jamais clampée
 * silencieusement — critère d'acceptation n° 8 du cahier).
 *
 * Aucune hypothèse ne s'applique aux données officielles : le moteur du
 * service lit, ne modifie jamais (voir ServiceScenarios).
 */
final class CriteresScenario
{
    /** Delta relatif des effectifs : -50 % à +200 % (audit LOOP-00, bornes 01). */
    public const BORNE_DELTA_EFFECTIFS_MIN = -0.5;

    public const BORNE_DELTA_EFFECTIFS_MAX = 2.0;

    /** Delta absolu des salles actives : ajout ou retrait, plafonné. */
    public const BORNE_DELTA_SALLES_MIN = -20;

    public const BORNE_DELTA_SALLES_MAX = 20;

    /** Delta relatif du volume horaire : -50 % à +100 %. */
    public const BORNE_DELTA_CHARGES_MIN = -0.5;

    public const BORNE_DELTA_CHARGES_MAX = 1.0;

    /**
     * @param  ?int  $faculteId  null = périmètre transversal (résolveur
     *                           côté serveur : le navigateur ne fait pas foi)
     * @param  ?int  $anneeId  null = toutes les années de la source
     */
    private function __construct(
        public readonly ?int $faculteId,
        public readonly ?int $anneeId,
        public readonly float $deltaEffectifs,
        public readonly int $deltaSalles,
        public readonly float $deltaCharges,
    ) {}

    /**
     * Entrée brute (requête HTTP ou tableau de test) → DTO validé.
     *
     * @param  array<string, mixed>  $entree
     *
     * @throws InvalidArgumentException hypothèse hors bornes ou type incohérent
     */
    public static function depuisTableau(array $entree): self
    {
        $faculte = isset($entree['faculte_id']) ? (int) $entree['faculte_id'] : null;
        $annee = isset($entree['annee_id']) ? (int) $entree['annee_id'] : null;
        $deltaEffectifs = self::flottant($entree['delta_effectifs'] ?? null, 'delta_effectifs');
        $deltaSalles = isset($entree['delta_salles']) ? (int) $entree['delta_salles'] : 0;
        $deltaCharges = self::flottant($entree['delta_charges'] ?? null, 'delta_charges');

        if ($deltaEffectifs < self::BORNE_DELTA_EFFECTIFS_MIN || $deltaEffectifs > self::BORNE_DELTA_EFFECTIFS_MAX) {
            throw new InvalidArgumentException(sprintf(
                "L'hypothèse delta_effectifs (%s) doit rester entre %s et %s.",
                $deltaEffectifs,
                self::BORNE_DELTA_EFFECTIFS_MIN,
                self::BORNE_DELTA_EFFECTIFS_MAX,
            ));
        }

        if ($deltaSalles < self::BORNE_DELTA_SALLES_MIN || $deltaSalles > self::BORNE_DELTA_SALLES_MAX) {
            throw new InvalidArgumentException(sprintf(
                "L'hypothèse delta_salles (%d) doit rester entre %d et %d.",
                $deltaSalles,
                self::BORNE_DELTA_SALLES_MIN,
                self::BORNE_DELTA_SALLES_MAX,
            ));
        }

        if ($deltaCharges < self::BORNE_DELTA_CHARGES_MIN || $deltaCharges > self::BORNE_DELTA_CHARGES_MAX) {
            throw new InvalidArgumentException(sprintf(
                "L'hypothèse delta_charges (%s) doit rester entre %s et %s.",
                $deltaCharges,
                self::BORNE_DELTA_CHARGES_MIN,
                self::BORNE_DELTA_CHARGES_MAX,
            ));
        }

        return new self($faculte, $annee, $deltaEffectifs, $deltaSalles, $deltaCharges);
    }

    /**
     * Flottant strict sans cast silencieux : une chaîne non numérique est
     * refusée (le navigateur ne fait pas foi).
     *
     * @throws InvalidArgumentException
     */
    private static function flottant(mixed $valeur, string $champ): float
    {
        if ($valeur === null || $valeur === '') {
            return 0.0;
        }

        if (! is_numeric($valeur)) {
            throw new InvalidArgumentException("Le champ {$champ} doit être une valeur numérique.");
        }

        return (float) $valeur;
    }
}
