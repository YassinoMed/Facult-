<?php

namespace App\Console\Commands;

use App\Modules\Attendance\Services\PresenceEmploiDuTempsService;
use App\Services\ParametreService;
use Illuminate\Console\Command;

/**
 * Commande planifiée idempotente de rapprochement des présences des enseignants
 * avec l'emploi du temps.
 *
 * La tolérance de retard par défaut vient du registre central des paramètres
 * (`presences.tolerance_retard_minutes`, administrable) — l'option --tolerance
 * explicite reste prioritaire pour un lanceur manuel.
 */
class RapprocherPresencesEnseignants extends Command
{
    protected $signature = 'presences:rapprocher-enseignants
                            {--date= : Date au format YYYY-MM-DD (défaut : aujourd\'hui)}
                            {--faculte= : Identifiant de la faculté}
                            {--tolerance= : Tolérance en minutes pour les retards (défaut : paramètre presences.tolerance_retard_minutes)}
                            {--fenetre= : Demi-fenêtre en minutes pour l\'appariement des pointages (défaut : paramètre presences.fenetre_pointage_minutes)}';

    protected $description = 'Rapproche de manière idempotente les séances d\'emploi du temps et les pointages des enseignants.';

    public function handle(PresenceEmploiDuTempsService $service, ParametreService $parametres): int
    {
        $date = (string) ($this->option('date') ?: now()->toDateString());
        $faculteId = $this->option('faculte') ? (int) $this->option('faculte') : null;
        $tolerance = $this->option('tolerance') !== null
            ? (int) $this->option('tolerance')
            : (int) $parametres->get('presences.tolerance_retard_minutes', PresenceEmploiDuTempsService::TOLERANCE_PAR_DEFAUT);
        $fenetre = $this->option('fenetre') !== null
            ? (int) $this->option('fenetre')
            : (int) $parametres->get('presences.fenetre_pointage_minutes', PresenceEmploiDuTempsService::FENETRE_PAR_DEFAUT);

        $this->info("Rapprochement des présences enseignants pour le {$date} (tolérance: {$tolerance} min, fenêtre: {$fenetre} min)...");

        $kpis = $service->reconcilierEtPersister($date, $faculteId, null, $tolerance, $fenetre);

        $this->table(
            ['Total', 'Confirmées', 'Retards pot.', 'Pointages manq.', 'À vérifier', 'Absences validées', 'Annulées', 'Dispenses', 'Retards justifiés', 'Rejetées', 'À venir', 'En cours'],
            [[
                $kpis['total_seances'],
                $kpis['presences_confirmees'],
                $kpis['retards_potentiels'],
                $kpis['pointages_manquants'],
                $kpis['absences_a_verifier'],
                $kpis['absences_validees'],
                $kpis['seances_annulees'],
                $kpis['dispenses_missions'],
                $kpis['retards_justifies'],
                $kpis['absences_rejetees'],
                $kpis['seances_a_venir'],
                $kpis['seances_en_cours'],
            ]]
        );

        $this->info('Rapprochement terminé avec succès.');

        return self::SUCCESS;
    }
}
