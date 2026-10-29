<?php

namespace App\Support;

use RuntimeException;

/**
 * Verrou des données de démonstration (LOOP-SECURITY-REMEDIATION-01).
 *
 * Les seeders qui créent des comptes à mots de passe CONNUS (étudiants de
 * démo, personnel de scolarité, comptes super-admin de secours…) ne sont
 * autorisés que :
 *   - en environnement local/testing, ou
 *   - sur opt-in EXPLICITE `SEED_DEMO_DATA=true` (staging de démonstration
 *     uniquement — jamais une production réelle).
 *
 * Hors de ces cas, `verrou()` interrompt le seeder au lieu de créer des
 * comptes à mots de passe publics.
 */
final class DemoData
{
    public static function autorisee(): bool
    {
        return app()->environment('local', 'testing')
            || (bool) config('security.seed_demo_data', false);
    }

    /**
     * Interrompt un seeder de démonstration hors contexte autorisé.
     */
    public static function verrou(string $seeder, string $detail = ''): void
    {
        $message = "{$seeder} est interdit dans cet environnement : il crée des comptes "
            .'de démonstration à mots de passe connus. '
            .'Utilisez une procédure dédiée (mots de passe forts et uniques), '
            .'ou posez SEED_DEMO_DATA=true pour un staging de démonstration.';

        if ($detail !== '') {
            $message .= ' '.$detail;
        }

        throw new RuntimeException($message);
    }
}
