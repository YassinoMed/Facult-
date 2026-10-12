<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Planning administrateur des sauvegardes : fréquence, jours/heures,
 * rétention en jours, destination, alertes, chiffrement planifié.
 * Idempotente (updateOrInsert par clé), réversible. Défaut : planification
 * ACTIVE, horaire (1/h) — cf. BackupSchedulerService.
 */
return new class extends Migration
{
    private const PARAMETRES = [
        [
            'cle' => 'sauvegarde.plan.actif', 'valeur' => '1', 'type' => 'booleen',
            'categorie' => 'sauvegardes',
            'description' => 'Planification automatique active (évaluée toutes les 15 min).',
        ],
        [
            'cle' => 'sauvegarde.plan.frequence', 'valeur' => 'horaire', 'type' => 'texte',
            'categorie' => 'sauvegardes',
            'description' => 'horaire | toutes_2h | toutes_6h | toutes_12h | quotidienne | personnalise.',
        ],
        [
            'cle' => 'sauvegarde.plan.jours', 'valeur' => 'lundi, mardi, mercredi, jeudi, vendredi, samedi, dimanche', 'type' => 'liste',
            'categorie' => 'sauvegardes',
            'description' => 'Jours d’exécution (mode personnalise).',
        ],
        [
            'cle' => 'sauvegarde.plan.heure', 'valeur' => '01:00', 'type' => 'texte',
            'categorie' => 'sauvegardes',
            'description' => 'Heure d’exécution HH:MM (modes quotidienne et personnalise).',
        ],
        [
            'cle' => 'sauvegarde.plan.retention_jours', 'valeur' => '14', 'type' => 'entier',
            'categorie' => 'sauvegardes',
            'description' => 'Âge maximal d’un lot conservé (jours) — complète sauvegarde.conserver (nombre).',
        ],
        [
            'cle' => 'sauvegarde.plan.destination', 'valeur' => 'local_private', 'type' => 'texte',
            'categorie' => 'sauvegardes',
            'description' => 'Clé de destination parmi sauvegardes.destinations.',
        ],
        [
            'cle' => 'sauvegarde.plan.chiffrer', 'valeur' => '0', 'type' => 'booleen',
            'categorie' => 'sauvegardes',
            'description' => 'Chiffrer les lots planifiés (SAUVEGARDE_CLE requise, échec explicite sinon).',
        ],
        [
            'cle' => 'sauvegarde.plan.alerter_echec', 'valeur' => '1', 'type' => 'booleen',
            'categorie' => 'sauvegardes',
            'description' => 'Alerter (Sentinel + auteur) à chaque échec.',
        ],
        [
            'cle' => 'sauvegarde.plan.alerter_retard', 'valeur' => '1', 'type' => 'booleen',
            'categorie' => 'sauvegardes',
            'description' => 'Alerter si aucun succès dans la fenêtre attendue.',
        ],
        [
            'cle' => 'sauvegarde.plan.alerter_succes', 'valeur' => '0', 'type' => 'booleen',
            'categorie' => 'sauvegardes',
            'description' => 'Notifier chaque succès (désactivé par défaut : anti-spam).',
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
