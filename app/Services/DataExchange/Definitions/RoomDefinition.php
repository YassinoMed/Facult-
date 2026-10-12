<?php

namespace App\Services\DataExchange\Definitions;

use App\Modules\Schedule\Models\Room;
use App\Services\DataExchange\Contracts\ExportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportValidationAvancee;
use App\Support\Recherche;
use Illuminate\Http\Request;

/**
 * Définition salles (§5/§26) : identifiant = code unique.
 */
class RoomDefinition implements ExportDefinitionInterface, ImportDefinitionInterface, ImportValidationAvancee
{
    public function entite(): string
    {
        return 'salles';
    }

    public function libelle(): string
    {
        return 'Salles';
    }

    public function colonnes(): array
    {
        return ['code', 'nom', 'capacite', 'campus', 'batiment', 'etage', 'type', 'statut'];
    }

    public function colonnesRequises(): array
    {
        return ['code'];
    }

    public function aliasesColonnes(): array
    {
        return [
            'nom' => ['libelle', 'designation'],
            'batiment' => ['batiment_code', 'code_batiment'],
            'campus' => ['faculte_code', 'code_faculte', 'faculte'],
            'capacite' => ['places', 'nb_places'],
        ];
    }

    public function regles(): array
    {
        return [
            'code' => 'required|string|max:20',
            'nom' => 'nullable|string|max:100',
            'capacite' => 'nullable|integer|min:1|max:10000',
            'campus' => 'nullable|string|max:100',
            'batiment' => 'nullable|string|max:100',
            'etage' => 'nullable|string|max:20',
            'type' => 'nullable|string|max:50',
            'statut' => 'nullable|string|max:50',
        ];
    }

    public function cleDoublon(): array
    {
        return ['code'];
    }

    public function validerLigneMetier(array $ligne, ?int $auteurId): array
    {
        return [];
    }

    public function cleMetier(array $ligne): ?string
    {
        return mb_strtoupper(trim((string) ($ligne['code'] ?? '')));
    }

    public function trouverExistant(array $ligne): ?object
    {
        return Room::where('code', $ligne['code'])->first();
    }

    public function persister(array $ligne, string $mode, ?int $auteurId): string
    {
        $existant = $this->trouverExistant($ligne);

        $donnees = [
            'nom' => $ligne['nom'],
            'capacite' => $ligne['capacite'] !== null ? (int) $ligne['capacite'] : null,
            'campus' => $ligne['campus'] ?? null,
            'batiment' => $ligne['batiment'] ?? null,
            'etage' => $ligne['etage'] ?? null,
        ];

        if ($existant !== null) {
            $existant->update($donnees);

            return "mise à jour : {$ligne['code']}";
        }

        Room::create(['code' => $ligne['code'], 'actif' => true] + $donnees);

        return "créée : {$ligne['code']}";
    }

    public function modes(): array
    {
        return ['creer', 'upsert'];
    }

    public function vider(?int $auteurId): void
    {
        // Jamais de vidage : référentiel partagé.
    }

    public function formats(): array
    {
        return ['csv', 'xlsx', 'json', 'pdf'];
    }

    public function requete(Request $request)
    {
        return Room::orderBy('code');
    }

    public function filtrer($query, Request $request)
    {
        if ($ids = $request->input('ids')) {
            $query->whereIn('id', array_map('intval', (array) $ids));
        }

        if ($search = trim($request->input('search', ''))) {
            $motif = Recherche::contient($search);
            $query->where(fn ($w) => $w
                ->where('code', 'like', $motif)
                ->orWhere('nom', 'like', $motif));
        }

        return $query;
    }

    public function ligne($modele): array
    {
        return [
            'code' => $modele->code,
            'nom' => $modele->nom,
            'capacite' => $modele->capacite,
            'campus' => $modele->campus,
            'batiment' => $modele->batiment,
            'etage' => $modele->etage,
        ];
    }
}
