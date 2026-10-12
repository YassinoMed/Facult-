<?php

namespace App\Services\DataExchange\Definitions;

use App\Models\Batiment;
use App\Models\Faculte;
use App\Models\User;
use App\Services\DataExchange\Contracts\ExportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportValidationAvancee;
use App\Support\Recherche;
use Illuminate\Http\Request;

class BatimentDefinition implements ExportDefinitionInterface, ImportDefinitionInterface, ImportValidationAvancee
{
    public function entite(): string
    {
        return 'batiments';
    }

    public function libelle(): string
    {
        return 'Bâtiments';
    }

    public function colonnes(): array
    {
        return ['code', 'nom', 'faculte'];
    }

    public function colonnesRequises(): array
    {
        return ['code', 'nom'];
    }

    public function aliasesColonnes(): array
    {
        return [
            'nom' => ['libelle', 'designation'],
            'faculte' => ['faculte_code', 'code_faculte', 'campus'],
        ];
    }

    public function regles(): array
    {
        return [
            'code' => ['required', 'string', 'max:50'],
            'nom' => ['required', 'string', 'max:255'],
            'faculte' => ['nullable', 'string', 'max:50'],
        ];
    }

    public function resoudreFaculte(?string $code, ?int $auteurId = null): ?Faculte
    {
        if ($auteurId !== null) {
            $auteur = User::find($auteurId);
            $restreinteId = $auteur?->faculteRestreinteId();
            if ($restreinteId !== null) {
                return Faculte::find($restreinteId);
            }
        }

        if (! empty($code)) {
            $faculte = Faculte::where('code', mb_strtoupper(trim($code)))->first();
            if ($faculte !== null) {
                return $faculte;
            }
        }

        return Faculte::count() === 1 ? Faculte::first() : null;
    }

    public function validerLigneMetier(array $ligne, ?int $auteurId): array
    {
        return [];
    }

    public function cleMetier(array $ligne): ?string
    {
        return mb_strtoupper(trim((string) ($ligne['code'] ?? '')));
    }

    public function cleDoublon(): array
    {
        return ['code'];
    }

    public function trouverExistant(array $ligne): ?object
    {
        return Batiment::where('code', mb_strtoupper(trim((string) $ligne['code'])))->first();
    }

    public function persister(array $ligne, string $mode, ?int $auteurId): string
    {
        $code = mb_strtoupper(trim((string) $ligne['code']));
        $nom = trim((string) $ligne['nom']);
        $faculte = $this->resoudreFaculte($ligne['faculte'] ?? null, $auteurId);

        $existant = $this->trouverExistant($ligne);

        if ($existant !== null) {
            $existant->update([
                'nom' => $nom,
                'faculte_id' => $faculte?->id ?? $existant->faculte_id,
            ]);

            return "mise à jour : {$code}";
        }

        Batiment::create([
            'code' => $code,
            'nom' => $nom,
            'faculte_id' => $faculte?->id,
        ]);

        return "création : {$code}";
    }

    public function formats(): array
    {
        return ['csv', 'xlsx', 'json', 'pdf'];
    }

    public function modes(): array
    {
        return ['creer', 'upsert', 'remplacer'];
    }

    public function vider(?int $auteurId): void {}

    public function requete(Request $request)
    {
        return Batiment::with('faculte')->orderBy('code');
    }

    public function filtrer($query, Request $request)
    {
        if ($search = trim($request->input('search', ''))) {
            $motif = Recherche::contient($search);
            $query->where(fn ($q) => $q->where('code', 'like', $motif)->orWhere('nom', 'like', $motif));
        }

        return $query;
    }

    public function ligne($modele): array
    {
        return [
            'code' => $modele->code,
            'nom' => $modele->nom,
            'faculte' => $modele->faculte?->code,
        ];
    }
}
