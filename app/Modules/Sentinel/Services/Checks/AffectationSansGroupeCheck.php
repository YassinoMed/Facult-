<?php

namespace App\Modules\Sentinel\Services\Checks;

use App\Contracts\SentinelCheckInterface;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;

/**
 * AFFECT_SANS_GROUPE (avertissement) : étudiants au dossier valide,
 * inscrits valides sur l'année active, sans groupe. Incident AGRÉGÉ
 * unique (compte + échantillon), jamais un incident par étudiant.
 * 2 requêtes bornées, lecture seule.
 */
class AffectationSansGroupeCheck implements SentinelCheckInterface
{
    public function code(): string
    {
        return 'AFFECT_SANS_GROUPE';
    }

    public function categorie(): string
    {
        return 'integrite';
    }

    public function gravite(): string
    {
        return 'avertissement';
    }

    public function module(): ?string
    {
        return 'affectations';
    }

    public function controler(): array
    {
        $annee = AnneeUniversitaire::where('active', true)->first();

        if ($annee === null) {
            return [];
        }

        $base = Etudiant::where('statut', 'valide')
            ->whereNull('groupe_id')
            ->whereHas('inscriptionsAcademiques', fn ($q) => $q
                ->where('annee_universitaire_id', $annee->id)
                ->where('statut', 'valide'));

        $total = (clone $base)->count();

        if ($total === 0) {
            return [];
        }

        $exemples = (clone $base)->limit(5)->pluck('nom')->all();

        return [[
            'titre' => "{$total} étudiant(s) validé(s) sans groupe ({$annee->libelle})",
            'diagnostic' => 'Aucune affectation active alors que dossier et inscription sont validés. Exemples : '.implode(', ', $exemples).'.',
            'recommandation' => 'Lancer une simulation au cockpit de masse, ou vérifier l’éligibilité (niveau, capacité, verrous).',
        ]];
    }
}
