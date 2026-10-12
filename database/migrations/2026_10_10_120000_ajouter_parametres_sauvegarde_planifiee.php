<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Platform Sentinel & Système — Paramètres de sauvegarde SQL automatique
 * et configurable (fréquence horaire par défaut, rétention, destinations).
 */
return new class extends Migration
{
    private const NOUVEAUX_PARAMETRES = [
        [
            'cle' => 'sauvegarde.active',
            'valeur' => '1',
            'type' => 'booleen',
            'categorie' => 'sauvegardes',
            'description' => 'Activation de la sauvegarde SQL automatique planifiée.',
        ],
        [
            'cle' => 'sauvegarde.frequence',
            'valeur' => 'hourly',
            'type' => 'chaine',
            'categorie' => 'sauvegardes',
            'description' => 'Fréquence automatique : hourly (toutes les heures), 2h, 6h, 12h, daily (24h), custom.',
        ],
        [
            'cle' => 'sauvegarde.heure',
            'valeur' => '01:00',
            'type' => 'chaine',
            'categorie' => 'sauvegardes',
            'description' => 'Heure d\'exécution pour les fréquences journalières ou personnalisées (HH:mm).',
        ],
        [
            'cle' => 'sauvegarde.jours',
            'valeur' => '*',
            'type' => 'chaine',
            'categorie' => 'sauvegardes',
            'description' => 'Jours autorisés pour l\'exécution (* pour tous, ou liste 0-6 séparée par des virgules).',
        ],
        [
            'cle' => 'sauvegarde.retention_jours',
            'valeur' => '14',
            'type' => 'entier',
            'categorie' => 'sauvegardes',
            'description' => 'Durée de rétention des sauvegardes en jours.',
        ],
        [
            'cle' => 'sauvegarde.retention_max_backups',
            'valeur' => '48',
            'type' => 'entier',
            'categorie' => 'sauvegardes',
            'description' => 'Nombre maximal de sauvegardes conservées (au-delà, purge des plus anciennes).',
        ],
        [
            'cle' => 'sauvegarde.stockage_destination',
            'valeur' => 'local_private',
            'type' => 'chaine',
            'categorie' => 'sauvegardes',
            'description' => 'Destination de stockage autorisée (local_private, externe).',
        ],
        [
            'cle' => 'sauvegarde.alerte_succes',
            'valeur' => '0',
            'type' => 'booleen',
            'categorie' => 'sauvegardes',
            'description' => 'Envoyer une notification en cas de sauvegarde réussie.',
        ],
        [
            'cle' => 'sauvegarde.alerte_echec',
            'valeur' => '1',
            'type' => 'booleen',
            'categorie' => 'sauvegardes',
            'description' => 'Envoyer une notification en cas d\'échec de sauvegarde.',
        ],
        [
            'cle' => 'sauvegarde.compression',
            'valeur' => 'gzip',
            'type' => 'chaine',
            'categorie' => 'sauvegardes',
            'description' => 'Format de compression : gzip (.sql.gz) ou none (.sql).',
        ],
    ];

    public function up(): void
    {
        foreach (self::NOUVEAUX_PARAMETRES as $parametre) {
            DB::table('parametres')->updateOrInsert(
                ['cle' => $parametre['cle']],
                $parametre + ['created_at' => now(), 'updated_at' => now()]
            );
        }
    }

    public function down(): void
    {
        DB::table('parametres')
            ->whereIn('cle', array_column(self::NOUVEAUX_PARAMETRES, 'cle'))
            ->delete();
    }
};
