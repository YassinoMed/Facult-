<?php

namespace App\Services\DataExchange\Definitions;

use App\Modules\Announcements\Models\Annonce;
use App\Services\DataExchange\Contracts\ExportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportDefinitionInterface;
use App\Support\Recherche;
use Illuminate\Http\Request;

/**
 * Annonces (§39) : import/export via le centre unifié. Le statut
 * reste serveur (jamais publié par import) — import = brouillons.
 */
class AnnonceDefinition implements ExportDefinitionInterface, ImportDefinitionInterface
{
    public function entite(): string
    {
        return 'annonces';
    }

    public function libelle(): string
    {
        return 'Annonces';
    }

    public function colonnes(): array
    {
        return ['titre', 'contenu', 'type', 'audience'];
    }

    public function colonnesRequises(): array
    {
        return ['titre', 'contenu'];
    }

    public function regles(): array
    {
        return [
            'titre' => 'required|string|max:150',
            'contenu' => 'required|string|max:5000',
            'type' => 'nullable|string|max:30',
            'audience' => 'nullable|string|max:20',
        ];
    }

    public function cleDoublon(): array
    {
        return ['titre'];
    }

    public function trouverExistant(array $ligne): ?object
    {
        return Annonce::where('titre', $ligne['titre'])->first();
    }

    public function persister(array $ligne, string $mode, ?int $auteurId): string
    {
        $existant = $this->trouverExistant($ligne);

        $donnees = [
            'contenu' => $ligne['contenu'],
            'type' => in_array($ligne['type'] ?? '', Annonce::TYPES, true) ? $ligne['type'] : 'information',
            'audience' => in_array($ligne['audience'] ?? '', Annonce::AUDIENCES, true) ? $ligne['audience'] : 'tous',
        ];

        if ($existant !== null) {
            if ($mode === 'creer') {
                return "doublon ignoré : {$ligne['titre']}";
            }
            $existant->update($donnees);

            return "mise à jour : {$ligne['titre']}";
        }

        Annonce::create(['titre' => $ligne['titre'], 'statut' => 'draft', 'created_by' => $auteurId] + $donnees);

        return "créée : {$ligne['titre']}";
    }

    public function modes(): array
    {
        return ['creer', 'upsert'];
    }

    public function vider(?int $auteurId): void {}

    public function formats(): array
    {
        return ['csv', 'xlsx', 'json', 'pdf'];
    }

    public function requete(Request $request)
    {
        return Annonce::orderByDesc('id');
    }

    public function filtrer($query, Request $request)
    {
        if ($search = trim($request->input('search', ''))) {
            $motif = Recherche::contient($search);
            $query->where(fn ($w) => $w->where('titre', 'like', $motif)->orWhere('contenu', 'like', $motif));
        }

        return $query;
    }

    public function ligne($modele): array
    {
        return [
            'titre' => $modele->titre,
            'contenu' => mb_substr($modele->contenu, 0, 200),
            'type' => $modele->type,
            'audience' => $modele->audience,
        ];
    }
}
