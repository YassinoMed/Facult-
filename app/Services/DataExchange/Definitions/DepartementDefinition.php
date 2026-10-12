<?php

namespace App\Services\DataExchange\Definitions;

use App\Models\Faculte;
use App\Models\User;
use App\Modules\Referentiel\Models\Departement;
use App\Services\DataExchange\Contracts\ExportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportValidationAvancee;
use App\Services\Io\IoException;
use App\Support\Recherche;
use Illuminate\Http\Request;

/**
 * Définition départements (§21-22) : identifiant = code + faculté.
 */
class DepartementDefinition implements ExportDefinitionInterface, ImportDefinitionInterface, ImportValidationAvancee
{
    public function entite(): string
    {
        return 'departements';
    }

    public function libelle(): string
    {
        return 'Départements';
    }

    public function colonnes(): array
    {
        return ['code', 'faculte', 'nom', 'responsable'];
    }

    public function colonnesRequises(): array
    {
        return ['code', 'faculte'];
    }

    public function aliasesColonnes(): array
    {
        return [
            'faculte' => ['faculte_code', 'code_faculte', 'etablissement', 'code_etablissement', 'faculte_id'],
            'nom' => ['libelle', 'designation', 'intitule'],
            'responsable' => ['directeur', 'chef_departement'],
        ];
    }

    public function regles(): array
    {
        return [
            'code' => 'required|string|max:20',
            'faculte' => 'required|string|max:20',
            'nom' => 'nullable|string|max:150',
            'responsable' => 'nullable|string|max:150',
        ];
    }

    public function cleDoublon(): array
    {
        return ['code', 'faculte'];
    }

    public function validerLigneMetier(array $ligne, ?int $auteurId): array
    {
        $erreurs = [];
        $faculte = $this->resoudreFaculte($ligne['faculte'] ?? '', $auteurId);

        if ($faculte === null) {
            $erreurs[] = [
                'champ' => 'faculte',
                'message' => 'La faculté « '.($ligne['faculte'] ?? '').' » est introuvable.',
                'code' => 'FACULTY_NOT_FOUND',
            ];
        } elseif ($auteurId !== null) {
            $auteur = User::find($auteurId);
            $restreinteId = $auteur?->faculteRestreinteId();
            if ($restreinteId !== null && (int) $faculte->id !== $restreinteId) {
                $erreurs[] = [
                    'champ' => 'faculte',
                    'message' => 'La faculté « '.($ligne['faculte'] ?? '').' » est hors de votre périmètre autorisé.',
                    'code' => 'FACULTY_SCOPE_VIOLATION',
                ];
            }
        }

        return $erreurs;
    }

    public function cleMetier(array $ligne): ?string
    {
        $code = mb_strtoupper(trim((string) ($ligne['code'] ?? '')));
        $fac = mb_strtoupper(trim((string) ($ligne['faculte'] ?? '')));

        return "{$fac}|{$code}";
    }

    public function resoudreFaculte(?string $valeur, ?int $auteurId = null): ?Faculte
    {
        $valeur = trim((string) $valeur);
        if ($valeur === '') {
            if ($auteurId !== null) {
                $auteur = User::find($auteurId);
                if ($auteur?->faculteRestreinteId() !== null) {
                    return Faculte::find($auteur->faculteRestreinteId());
                }
            }

            return Faculte::count() === 1 ? Faculte::first() : null;
        }

        return Faculte::where('code', $valeur)->first()
            ?? Faculte::where('nom', $valeur)->first();
    }

    public function trouverExistant(array $ligne): ?object
    {
        $faculte = $this->resoudreFaculte($ligne['faculte']);

        if ($faculte === null) {
            return null;
        }

        return Departement::where('code', $ligne['code'])
            ->where('faculte_id', $faculte->id)->first();
    }

    public function persister(array $ligne, string $mode, ?int $auteurId): string
    {
        $faculte = $this->resoudreFaculte($ligne['faculte']);

        if ($faculte === null) {
            throw IoException::malforme("Faculté « {$ligne['faculte']} » introuvable (ligne {$ligne['ligne']}).");
        }

        $auteur = $auteurId !== null ? User::find($auteurId) : null;

        if ($auteur !== null && ! $auteur->peutAccederFaculte($faculte->id)) {
            throw IoException::malforme("Faculté « {$ligne['faculte']} » hors de votre périmètre (ligne {$ligne['ligne']}).");
        }

        $existant = $this->trouverExistant($ligne);

        $donnees = [
            'nom' => $ligne['nom'] ?? $ligne['code'],
            'responsable' => $ligne['responsable'],
        ];

        if ($existant !== null) {
            $existant->update($donnees);

            return "mis à jour : {$ligne['code']}";
        }

        Departement::create(['code' => $ligne['code'], 'faculte_id' => $faculte->id, 'actif' => true] + $donnees);

        return "créé : {$ligne['code']}";
    }

    public function modes(): array
    {
        return ['creer', 'upsert'];
    }

    public function vider(?int $auteurId): void
    {
        // Jamais de vidage : programmes rattachés.
    }

    public function formats(): array
    {
        return ['csv', 'xlsx', 'json', 'pdf'];
    }

    public function requete(Request $request)
    {
        $query = Departement::with('faculte')->orderBy('code');

        if ($request->user()->faculteRestreinteId() !== null) {
            $query->where('faculte_id', $request->user()->faculteRestreinteId());
        }

        return $query;
    }

    public function filtrer($query, Request $request)
    {
        if ($ids = $request->input('ids')) {
            $query->whereIn('departements.id', array_map('intval', (array) $ids));
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
            'faculte' => $modele->faculte?->code,
            'nom' => $modele->nom,
            'responsable' => $modele->responsable,
        ];
    }
}
