<?php

namespace App\Modules\Sentinel\Services\Checks;

use App\Contracts\SentinelCheckInterface;
use App\Models\AffectationGroupe;

/**
 * AFFECT_NIVEAU (erreur) : affectations actives dont le niveau du
 * groupe diffère du niveau officiel de l'étudiant (dérive, transfert
 * manuel ou exception non régularisée). Incident agrégé, borné à 50.
 */
class AffectationNiveauCheck implements SentinelCheckInterface
{
    public function code(): string
    {
        return 'AFFECT_NIVEAU';
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
        return 'affectations';
    }

    public function controler(): array
    {
        $lignes = AffectationGroupe::query()
            ->where('affectations_groupe.statut', 'active')
            ->join('etudiants', 'etudiants.id', '=', 'affectations_groupe.etudiant_id')
            ->join('groups', 'groups.id', '=', 'affectations_groupe.groupe_id')
            ->whereNotNull('etudiants.niveau')
            ->whereNotNull('groups.niveau')
            ->whereColumn('etudiants.niveau', '!=', 'groups.niveau')
            ->limit(51)
            ->get(['etudiants.nom', 'etudiants.niveau as niveau_etudiant', 'groups.code', 'groups.niveau as niveau_groupe']);

        if ($lignes->isEmpty()) {
            return [];
        }

        $premier = $lignes->first();

        return [[
            'titre' => ($lignes->count() > 50 ? 'Plus de 50' : (string) $lignes->count()).' affectation(s) à niveau incompatible',
            'diagnostic' => "Ex. {$premier->nom} (niveau {$premier->niveau_etudiant}) affecté au groupe {$premier->code} (niveau {$premier->niveau_groupe}). La règle LEVEL_MISMATCH interdit ces affectations sauf exception `groupes.manage`.",
            'recommandation' => 'Régulariser (transfert vers un groupe compatible ou exception tracée) depuis la fiche étudiant.',
        ]];
    }
}
