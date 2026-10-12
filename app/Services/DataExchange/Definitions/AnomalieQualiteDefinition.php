<?php

namespace App\Services\DataExchange\Definitions;

use App\Models\AnomalieQualite;
use App\Services\DataExchange\Contracts\ExportDefinitionInterface;
use App\Support\Recherche;
use Illuminate\Http\Request;

/**
 * Export des anomalies qualité (§24.16) : pilotage et reporting,
 * sans PII au-delà du libellé d'entité déjà scopé en lecture.
 */
class AnomalieQualiteDefinition implements ExportDefinitionInterface
{
    public function entite(): string
    {
        return 'anomalies_qualite';
    }

    public function libelle(): string
    {
        return 'Anomalies qualité';
    }

    public function colonnes(): array
    {
        return ['source', 'gravite', 'entite', 'message', 'statut', 'derniere_detection'];
    }

    public function formats(): array
    {
        return ['csv', 'xlsx', 'json', 'pdf'];
    }

    public function requete(Request $request)
    {
        return AnomalieQualite::orderByDesc('derniere_detection_a');
    }

    public function filtrer($query, Request $request)
    {
        if ($search = trim($request->input('search', ''))) {
            $motif = Recherche::contient($search);
            $query->where(fn ($w) => $w->where('message', 'like', $motif)->orWhere('entite_libelle', 'like', $motif));
        }

        return $query;
    }

    public function ligne($modele): array
    {
        return [
            'source' => $modele->source,
            'gravite' => $modele->gravite,
            'entite' => $modele->entite_libelle,
            'message' => $modele->message,
            'statut' => $modele->statut,
            'derniere_detection' => $modele->derniere_detection_a?->format('Y-m-d H:i'),
        ];
    }
}
