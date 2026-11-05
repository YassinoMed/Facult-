<?php

namespace App\Console\Commands;

use App\Services\Acquis\SupervisionRedoublementService;
use Illuminate\Console\Command;

/**
 * Commande artisan de supervision proactive et d'observabilité des acquis et redoublements.
 * Exécute un audit d'intégrité sans jamais divulguer de données personnelles dans les sorties standards.
 */
class SuperviserAcquisRedoublementCommand extends Command
{
    protected $signature = 'acquis:superviser
                            {--annee= : Identifiant de l\'année universitaire à auditer}
                            {--details : Afficher le détail complet des anomalies}
                            {--json : Sortie au format JSON pour intégration CI / supervision}';

    protected $description = 'Supervise l\'intégrité des acquis pédagogiques, plans de redoublement et cohérence des crédits LMD.';

    public function handle(SupervisionRedoublementService $supervisionService): int
    {
        $anneeId = $this->option('annee') !== null ? (int) $this->option('annee') : null;
        $audit = $supervisionService->auditerCohorte($anneeId);

        if ($this->option('json')) {
            $this->output->writeln(json_encode($audit, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $audit['conforme'] ? self::SUCCESS : self::FAILURE;
        }

        $this->info('===============================================================');
        $this->info(' UniRegistre — Audit d\'intégrité des Acquis et Redoublement');
        $this->info('===============================================================');
        $this->line(sprintf('Année universitaire ciblée : %s', $audit['annee_universitaire_id'] ?? 'Toutes'));
        $this->line(sprintf('Plans audités : %d', $audit['statistiques']['total_plans'] ?? 0));
        $this->line(sprintf('Plans validés : %d (%s%%)', $audit['statistiques']['plans_valides'] ?? 0, $audit['statistiques']['taux_validation_pourcent'] ?? 0));
        $this->line(sprintf('Plans en attente/proposés : %d', $audit['statistiques']['plans_proposes'] ?? 0));
        $this->line(sprintf('Plans bloqués : %d', $audit['statistiques']['plans_bloques'] ?? 0));
        $this->newLine();

        if ($audit['anomalies_critiques_count'] > 0) {
            $this->error(sprintf('❌ %d anomalie(s) critique(s) détectée(s) !', $audit['anomalies_critiques_count']));
        } else {
            $this->info('✅ Aucune anomalie critique détectée.');
        }

        if ($audit['avertissements_count'] > 0) {
            $this->warn(sprintf('⚠️  %d avertissement(s) nécessitant attention.', $audit['avertissements_count']));
        }

        if ($this->option('details')) {
            if (! empty($audit['anomalies_critiques'])) {
                $this->newLine();
                $this->error('--- DÉTAIL DES ANOMALIES CRITIQUES ---');
                foreach ($audit['anomalies_critiques'] as $anom) {
                    $this->line(sprintf('  [%s] Plan #%s (Étudiant #%s) : %s', $anom['code'], $anom['plan_id'] ?? '—', $anom['etudiant_id'] ?? '—', $anom['details']));
                }
            }

            if (! empty($audit['avertissements'])) {
                $this->newLine();
                $this->warn('--- DÉTAIL DES AVERTISSEMENTS ---');
                foreach ($audit['avertissements'] as $avert) {
                    $this->line(sprintf('  [%s] Étudiant #%s : %s', $avert['code'], $avert['etudiant_id'] ?? '—', $avert['details']));
                }
            }
        }

        $this->newLine();

        return $audit['conforme'] ? self::SUCCESS : self::FAILURE;
    }
}
