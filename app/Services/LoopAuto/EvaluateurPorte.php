<?php

namespace App\Services\LoopAuto;

/**
 * Moteur de transition (LOOP K) : fonction pure, sans E/S.
 * Évalue un verdict + contexte vers une transition décidable et testable.
 */
final class EvaluateurPorte
{
    public const MAX_TENTATIVES = 3;

    public const MAX_LOOPS = 10;

    /**
     * @param  array{verdict: string, reserves_bloquantes: bool, preuves: bool, poursuite_autorisee: bool, suivant_connu: bool, tentative: int, loops_consecutifs: int}  $contexte
     * @return array{transition: string, motif: string}
     */
    public static function evaluer(array $contexte): array
    {
        $tentative = (int) ($contexte['tentative'] ?? 0);
        $loops = (int) ($contexte['loops_consecutifs'] ?? 0);

        if ($loops >= self::MAX_LOOPS) {
            return ['transition' => 'STOPPED', 'motif' => 'Limite de 10 LOOP consécutifs atteinte.'];
        }

        if ($tentative >= self::MAX_TENTATIVES) {
            return ['transition' => 'FAILED', 'motif' => 'Trois tentatives épuisées.'];
        }

        if (($contexte['verdict'] ?? '') === 'blocked') {
            return ['transition' => 'BLOCKED', 'motif' => 'Verdict bloqué : décision ou preuve manquante.'];
        }

        if (! ($contexte['preuves'] ?? false)) {
            return ['transition' => 'BLOCKED', 'motif' => 'Preuves insuffisantes.'];
        }

        if (($contexte['verdict'] ?? '') !== 'pass') {
            return ['transition' => 'RETRY', 'motif' => 'Critères non satisfaits, nouvelle tentative autorisée.'];
        }

        if (($contexte['reserves_bloquantes'] ?? false)) {
            return ['transition' => 'BLOCKED', 'motif' => 'Réserve bloquante ouverte.'];
        }

        if (! ($contexte['suivant_connu'] ?? false)) {
            return ['transition' => 'STOPPED', 'motif' => 'LOOP suivant absent du registre.'];
        }

        if (! ($contexte['poursuite_autorisee'] ?? false)) {
            return ['transition' => 'BLOCKED', 'motif' => 'Poursuite avec réserves non autorisée.'];
        }

        return ['transition' => 'NEXT', 'motif' => 'Porte validée, passage au LOOP suivant.'];
    }
}
