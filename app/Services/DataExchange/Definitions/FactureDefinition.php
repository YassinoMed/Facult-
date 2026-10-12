<?php

namespace App\Services\DataExchange\Definitions;

use App\Modules\Finances\Models\Facture;
use App\Services\DataExchange\Contracts\ExportDefinitionInterface;
use App\Support\Recherche;
use Illuminate\Http\Request;

/**
 * Export des factures (§23.16) : montants formattés, périmètre
 * faculté appliqué par le contrôleur DataExchange. Réservé aux
 * rôles financiers (finances.view).
 */
class FactureDefinition implements ExportDefinitionInterface
{
    public function entite(): string
    {
        return 'factures';
    }

    public function libelle(): string
    {
        return 'Factures';
    }

    public function colonnes(): array
    {
        return ['reference', 'etudiant', 'net', 'paye', 'reste', 'statut'];
    }

    public function formats(): array
    {
        return ['csv', 'xlsx', 'json', 'pdf'];
    }

    public function requete(Request $request)
    {
        return Facture::with(['etudiant', 'paiements'])->orderByDesc('id');
    }

    public function filtrer($query, Request $request)
    {
        if ($search = trim($request->input('search', ''))) {
            $motif = Recherche::contient($search);
            $query->where(fn ($w) => $w->where('reference', 'like', $motif)
                ->orWhereHas('etudiant', fn ($q) => $q->where('nom', 'like', $motif)->orWhere('prenom', 'like', $motif)));
        }

        return $query;
    }

    public function ligne($modele): array
    {
        return [
            'reference' => $modele->reference,
            'etudiant' => $modele->etudiant?->nom_complet,
            'net' => Facture::formater($modele->netCentimes()),
            'paye' => Facture::formater($modele->payeCentimes()),
            'reste' => Facture::formater($modele->resteCentimes()),
            'statut' => $modele->statut,
        ];
    }
}
