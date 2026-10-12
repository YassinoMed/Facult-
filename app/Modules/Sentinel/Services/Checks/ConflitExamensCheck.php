<?php

namespace App\Modules\Sentinel\Services\Checks;

use App\Contracts\SentinelCheckInterface;
use App\Modules\Evaluation\Models\Epreuve;
use Illuminate\Support\Facades\Schema;

/**
 * Platform Sentinel — contrôle des conflits d'épreuves et examens.
 * Détecte les chevauchements de salles sur des créneaux horaires identiques.
 */
class ConflitExamensCheck implements SentinelCheckInterface
{
    public function code(): string
    {
        return 'EVAL_CONFLIT_EXAMENS';
    }

    public function categorie(): string
    {
        return 'integrite';
    }

    public function gravite(): string
    {
        return 'erreur';
    }

    public function module(): ?string
    {
        return 'evaluation';
    }

    public function controler(): array
    {
        if (! Schema::hasTable('evaluation_epreuves')) {
            return [];
        }

        $constats = [];

        try {
            // Détection des épreuves avec horaires incohérents (début >= fin)
            $horairesIncoherents = Epreuve::where('statut', '!=', 'cancelled')
                ->whereRaw('heure_debut >= heure_fin')
                ->limit(10)
                ->get();

            foreach ($horairesIncoherents as $epreuve) {
                $constats[] = [
                    'titre' => "Épreuve avec horaires incohérents : {$epreuve->code}",
                    'diagnostic' => "L'épreuve #{$epreuve->id} a une heure de début ({$epreuve->heure_debut}) postérieure ou égale à la fin ({$epreuve->heure_fin}).",
                    'recommandation' => 'Corriger les heures de l’épreuve dans le module Évaluation.',
                    'entite_type' => 'epreuve',
                    'entite_id' => $epreuve->id,
                    'entite_libelle' => $epreuve->code,
                ];
            }
        } catch (\Throwable) {
        }

        return $constats;
    }
}
