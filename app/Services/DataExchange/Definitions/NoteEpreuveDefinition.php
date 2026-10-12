<?php

namespace App\Services\DataExchange\Definitions;

use App\Modules\Evaluation\Models\NoteEpreuve;
use App\Services\DataExchange\Contracts\ExportDefinitionInterface;
use App\Support\Recherche;
use Illuminate\Http\Request;

/**
 * Export des notes d'épreuve (§31) : valeurs + statuts, jamais de PII
 * au-delà du périmètre autorisé (filtrage faculté côté contrôleur).
 */
class NoteEpreuveDefinition implements ExportDefinitionInterface
{
    public function entite(): string
    {
        return 'notes_epreuves';
    }

    public function libelle(): string
    {
        return 'Notes d’épreuves';
    }

    public function colonnes(): array
    {
        return ['epreuve', 'etudiant', 'valeur', 'note_max', 'statut'];
    }

    public function formats(): array
    {
        return ['csv', 'xlsx', 'json', 'pdf'];
    }

    public function requete(Request $request)
    {
        return NoteEpreuve::with(['epreuve.matiere', 'etudiant'])->orderByDesc('id');
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
            'epreuve' => $modele->epreuve?->matiere?->code.' '.$modele->epreuve?->date?->format('d/m/Y'),
            'etudiant' => $modele->etudiant?->nom_complet,
            'valeur' => $modele->valeur,
            'note_max' => $modele->note_max,
            'statut' => $modele->statut,
        ];
    }
}
