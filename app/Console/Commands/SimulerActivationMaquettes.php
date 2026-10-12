<?php

namespace App\Console\Commands;

use App\Modules\Referentiel\Services\MaquetteActivationSimulationService;
use Illuminate\Console\Command;

/**
 * Simulation d'activation des maquettes (MASTER LOOP §1.3) : rapport de
 * prévalidation LECTURE SEULE — aucune écriture (ni activation, ni
 * migration legacy). L'activation reste un geste manuel autorisé
 * (permissions referentiel.manage + maquettes.approve, périmètre
 * faculté) après décision pédagogique explicite.
 */
class SimulerActivationMaquettes extends Command
{
    protected $signature = 'referentiel:simuler-activation
                            {--programme= : Restreindre à un programme (code)}
                            {--faculte= : Restreindre à une faculté (id)}
                            {--json : Sortie JSON (artefact machine)}';

    protected $description = 'Simule l’activation des maquettes LMD (validations, blocages, avertissements, recommandation) — sans aucune écriture.';

    public function handle(MaquetteActivationSimulationService $simulation): int
    {
        $rapport = $simulation->simuler(
            $this->option('faculte') !== null ? (int) $this->option('faculte') : null,
            $this->option('programme'),
        );

        if ((bool) $this->option('json')) {
            $this->line(json_encode($rapport, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        foreach ($rapport['programmes'] as $programme) {
            $pastille = match ($programme['recommandation']) {
                'ACTIVE_DEJA' => '🟢',
                'ACTIVABLE' => '🟡',
                default => '🔴',
            };
            $this->info("{$pastille} {$programme['programme']} — {$programme['programme_nom']} : {$programme['recommandation']}");

            foreach ($programme['validations'] as $validation) {
                $this->line("    ✔ [{$validation['regle']}] {$validation['detail']}");
            }
            foreach ($programme['blocages'] as $blocage) {
                $this->line("    ✘ [{$blocage['regle']}/{$blocage['code']}] {$blocage['detail']}");
            }
            foreach ($programme['avertissements'] as $avertissement) {
                $this->line("    ⚠ [{$avertissement['regle']}/{$avertissement['code']}] {$avertissement['detail']}");
            }
            foreach ($programme['corrections'] as $correction) {
                $this->line("    → {$correction}");
            }
            foreach ($programme['consommateurs'] as $consommateur) {
                $this->line("    · {$consommateur}");
            }
            $this->newLine();
        }

        $resume = $rapport['resume'];
        $this->info(sprintf(
            'Résumé : %d programme(s) — %d activable(s), %d déjà active(s), %d en attente, %d blocage(s), %d avertissement(s). '
            .'Matières : %d rattachées / %d standalone. Inscriptions sans programme : %d. Aucune écriture effectuée.',
            $resume['programmes'], $resume['activables'], $resume['actives_deja'], $resume['en_attente'],
            $resume['blocages'], $resume['avertissements'],
            $resume['matieres_rattachees'], $resume['matieres_standalone'], $resume['inscriptions_sans_programme'],
        ));

        return self::SUCCESS;
    }
}
