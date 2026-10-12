<?php

namespace App\Services\DataExchange\Definitions;

use App\Modules\Attendance\Models\Presence;
use App\Services\DataExchange\Contracts\ExportDefinitionInterface;
use App\Support\Recherche;
use Illuminate\Http\Request;

/**
 * Export des relevés de présence (§36) : lecture seule, périmètre
 * faculté appliqué par le contrôleur DataExchange.
 */
class PresenceDefinition implements ExportDefinitionInterface
{
    public function entite(): string
    {
        return 'presences';
    }

    public function libelle(): string
    {
        return 'Présences';
    }

    public function colonnes(): array
    {
        return ['etudiant', 'date', 'seance', 'statut'];
    }

    public function formats(): array
    {
        return ['csv', 'xlsx', 'json', 'pdf'];
    }

    public function requete(Request $request)
    {
        return Presence::with('etudiant')->orderByDesc('date');
    }

    public function filtrer($query, Request $request)
    {
        if ($search = trim($request->input('search', ''))) {
            $motif = Recherche::contient($search);
            $query->whereHas('etudiant', fn ($q) => $q->where('nom', 'like', $motif)->orWhere('prenom', 'like', $motif));
        }

        return $query;
    }

    public function ligne($modele): array
    {
        return [
            'etudiant' => $modele->etudiant?->nom_complet,
            'date' => $modele->date?->format('Y-m-d'),
            'seance' => $modele->seance,
            'statut' => $modele->statut,
        ];
    }
}
