<?php

namespace App\Modules\Sentinel\Services\Checks;

use App\Contracts\SentinelCheckInterface;
use App\Modules\Schedule\Models\Group;

/**
 * GROUPE_SURCAPACITE (erreur) : groupes actifs dont l'effectif dépasse
 * la capacité (dépassement non régularisé). Les groupes simplement
 * complets sont un état normal, jamais un incident.
 */
class GroupeSurcapaciteCheck implements SentinelCheckInterface
{
    public function code(): string
    {
        return 'GROUPE_SURCAPACITE';
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
        $groupes = Group::where('actif', true)
            ->whereNotNull('capacite')
            ->withCount(['affectations' => fn ($q) => $q->where('statut', 'active')])
            ->get()
            ->filter(fn (Group $g) => $g->affectations_count > (int) $g->capacite)
            ->values();

        if ($groupes->isEmpty()) {
            return [];
        }

        $details = $groupes->take(5)
            ->map(fn (Group $g) => "{$g->code} ({$g->affectations_count}/{$g->capacite})")
            ->implode(', ');

        return [[
            'titre' => "{$groupes->count()} groupe(s) en surcapacité",
            'diagnostic' => "Effectif supérieur à la capacité : {$details}.",
            'recommandation' => 'Rééquilibrer (transferts) ou ajuster la capacité avec motif tracé.',
        ]];
    }
}
