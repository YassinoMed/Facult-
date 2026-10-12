<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * LOOP BACKUP-01 dette 5 — paramètres sauvegardes dans le registre
 * central `parametres` (jamais de 2e système de configuration).
 * Idempotente : firstOrCreate par clé (rejouable, dovetail import).
 */
return new class extends Migration
{
    private const PARAMETRES = [
        [
            'cle' => 'sauvegarde.conserver', 'valeur' => '14', 'type' => 'entier',
            'categorie' => 'sauvegardes',
            'description' => 'Lots conservés par la rétention (scheduler + jobs).',
        ],
        [
            'cle' => 'sauvegarde.alerte_age_heures', 'valeur' => '30', 'type' => 'entier',
            'categorie' => 'sauvegardes',
            'description' => 'Âge maximal du dernier succès avant alerte dashboard (heures).',
        ],
        [
            'cle' => 'sauvegarde.notifier_echec', 'valeur' => '1', 'type' => 'booleen',
            'categorie' => 'sauvegardes',
            'description' => 'Notifier l’auteur en cas d’échec (sauvegarde, restore test, restauration).',
        ],
    ];

    public function up(): void
    {
        foreach (self::PARAMETRES as $parametre) {
            DB::table('parametres')->updateOrInsert(
                ['cle' => $parametre['cle']],
                $parametre + ['created_at' => now(), 'updated_at' => now()]
            );
        }
    }

    public function down(): void
    {
        DB::table('parametres')
            ->whereIn('cle', array_column(self::PARAMETRES, 'cle'))
            ->delete();
    }
};
