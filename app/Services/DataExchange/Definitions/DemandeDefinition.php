<?php

namespace App\Services\DataExchange\Definitions;

use App\Modules\OnlineRequests\Models\OnlineRequest;
use App\Services\DataExchange\Contracts\ExportDefinitionInterface;
use App\Support\Recherche;
use Illuminate\Http\Request;

/**
 * Export des demandes (Phase 9 §76) : MÉTADONNÉES uniquement
 * (référence, service, statut, dates) — jamais le contenu des pièces
 * ni les messages. Import interdit : contournerait le workflow.
 */
class DemandeDefinition implements ExportDefinitionInterface
{
    public function entite(): string
    {
        return 'demandes';
    }

    public function libelle(): string
    {
        return 'Demandes de services (métadonnées)';
    }

    public function colonnes(): array
    {
        return ['reference', 'service', 'etudiant', 'statut', 'soumise_le', 'cloturee_le'];
    }

    public function formats(): array
    {
        return ['csv', 'xlsx', 'json', 'pdf'];
    }

    public function requete(Request $request)
    {
        $query = OnlineRequest::with(['etudiant', 'type'])->orderByDesc('id');

        if ($request->user()->faculteRestreinteId() !== null) {
            $query->where('faculte_id', $request->user()->faculteRestreinteId());
        }

        return $query;
    }

    public function filtrer($query, Request $request)
    {
        if ($ids = $request->input('ids')) {
            $query->whereIn('online_requests.id', array_map('intval', (array) $ids));
        }

        if ($statut = trim($request->input('statut', ''))) {
            $query->where('status', $statut);
        }

        if ($search = trim($request->input('search', ''))) {
            $motif = Recherche::contient($search);
            $query->where(fn ($w) => $w
                ->where('reference', 'like', $motif)
                ->orWhereHas('etudiant', fn ($q) => $q->where('nom', 'like', $motif)->orWhere('prenom', 'like', $motif)));
        }

        return $query;
    }

    public function ligne($modele): array
    {
        return [
            'reference' => $modele->reference,
            'service' => $modele->type?->code,
            'etudiant' => $modele->etudiant?->num_inscription,
            'statut' => $modele->status,
            'soumise_le' => $modele->submitted_at?->format('Y-m-d'),
            'cloturee_le' => $modele->completed_at?->format('Y-m-d'),
        ];
    }
}
