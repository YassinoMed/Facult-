<?php

namespace App\Modules\Sentinel\Services\Checks;

use App\Contracts\SentinelCheckInterface;
use App\Models\DatabaseBackup;
use App\Services\ParametreService;
use App\Services\Sauvegardes\BackupSchedulerService;
use Carbon\CarbonImmutable;

/**
 * SAUVEGARDE_FRAICHEUR (critique) : aucune sauvegarde réussie dans la
 * fenêtre attendue (intervalle du planning ou `sauvegarde.alerte_age_heures`,
 * au plus contraignant des deux). Incident AGRÉGÉ unique (pas d'alerte à
 * chaque contrôle : déduplication + cooldown Sentinel). Sans PII.
 */
class SauvegardeFraicheurCheck implements SentinelCheckInterface
{
    public function __construct(
        private readonly BackupSchedulerService $planificateur,
        private readonly ParametreService $parametres,
    ) {}

    public function code(): string
    {
        return 'SAUVEGARDE_FRAICHEUR';
    }

    public function categorie(): string
    {
        return 'sauvegarde';
    }

    public function gravite(): string
    {
        return 'critique';
    }

    public function module(): ?string
    {
        return 'systeme';
    }

    public function controler(): array
    {
        $plan = $this->planificateur->plan();

        if (! $plan['actif']) {
            return [];
        }

        $fenetreHeures = max(
            $plan['intervalle_heures'],
            max(1, (int) $this->parametres->get('sauvegarde.alerte_age_heures', 30))
        );

        $dernier = DatabaseBackup::where('status', 'SUCCESS')->latest('completed_at')->first();

        if ($dernier !== null
            && CarbonImmutable::instance($dernier->completed_at)->greaterThan(CarbonImmutable::now()->subHours($fenetreHeures))) {
            return [];
        }

        return [[
            'titre' => $dernier === null
                ? 'Aucune sauvegarde réussie enregistrée'
                : "Dernière sauvegarde réussie il y a plus de {$fenetreHeures} h",
            'diagnostic' => $dernier === null
                ? 'Le registre ne contient aucune sauvegarde SUCCESS.'
                : "Dernier succès le {$dernier->completed_at} ({$dernier->filename}).",
            'recommandation' => 'Vérifier le scheduler (`schedule:run`), les workers et les échecs récents, puis lancer une sauvegarde manuelle.',
        ]];
    }
}
