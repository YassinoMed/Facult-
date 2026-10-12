<?php

namespace App\Services\DataExchange\Definitions;

use App\Models\Faculte;
use App\Models\User;
use App\Modules\Referentiel\Models\Domaine;
use App\Services\DataExchange\Contracts\ExportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportValidationAvancee;
use App\Services\Io\IoException;
use App\Support\Recherche;
use Illuminate\Http\Request;

/**
 * Import / Export des domaines de formation (Lot 4, §12).
 *
 * Dépendance : Faculté / Établissement.
 * Clé métier : `faculte` + `code`.
 */
class DomaineDefinition implements ExportDefinitionInterface, ImportDefinitionInterface, ImportValidationAvancee
{
    public function entite(): string
    {
        return 'domaines';
    }

    public function libelle(): string
    {
        return 'Domaines de formation';
    }

    public function colonnes(): array
    {
        return ['code', 'nom', 'faculte', 'sigle', 'description', 'actif'];
    }

    public function colonnesRequises(): array
    {
        return ['code', 'nom'];
    }

    public function aliasesColonnes(): array
    {
        return [
            'code' => ['code_domaine', 'identifiant'],
            'nom' => ['libelle', 'designation', 'intitule'],
            'faculte' => ['code_faculte', 'etablissement', 'code_etablissement', 'faculte_id'],
            'sigle' => ['acronyme', 'court'],
        ];
    }

    public function regles(): array
    {
        return [
            'code' => ['required', 'string', 'max:20'],
            'nom' => ['required', 'string', 'max:150'],
            'faculte' => ['nullable', 'string', 'max:20'],
            'sigle' => ['nullable', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:1000'],
            'actif' => ['nullable'],
        ];
    }

    public function resoudreFaculte(?string $valeur, ?int $auteurId = null): ?Faculte
    {
        $code = mb_strtoupper(trim((string) $valeur));
        if ($code === '') {
            if ($auteurId !== null) {
                $auteur = User::find($auteurId);
                if ($auteur?->faculteRestreinteId() !== null) {
                    return Faculte::find($auteur->faculteRestreinteId());
                }
            }

            return Faculte::count() === 1 ? Faculte::first() : Faculte::first();
        }

        return Faculte::where('code', $code)->first()
            ?? Faculte::where('nom', trim((string) $valeur))->first();
    }

    public function validerLigneMetier(array $ligne, ?int $auteurId): array
    {
        $erreurs = [];
        $faculte = $this->resoudreFaculte($ligne['faculte'] ?? null, $auteurId);

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

    public function cleDoublon(): array
    {
        return ['code'];
    }

    public function trouverExistant(array $ligne): ?object
    {
        $faculte = $this->resoudreFaculte($ligne['faculte'] ?? null);
        if ($faculte === null) {
            return null;
        }

        return Domaine::where('code', mb_strtoupper(trim((string) $ligne['code'])))
            ->where('faculte_id', $faculte->id)
            ->first();
    }

    public function persister(array $ligne, string $mode, ?int $auteurId): string
    {
        $faculte = $this->resoudreFaculte($ligne['faculte'] ?? null, $auteurId);
        if ($faculte === null) {
            throw IoException::malforme('Faculté « '.($ligne['faculte'] ?? '')." » introuvable (ligne {$ligne['ligne']}).");
        }

        if ($auteurId !== null) {
            $auteur = User::find($auteurId);
            $restreinteId = $auteur?->faculteRestreinteId();
            if ($restreinteId !== null && (int) $faculte->id !== $restreinteId) {
                throw IoException::malforme('Faculté « '.($ligne['faculte'] ?? '')." » hors périmètre (ligne {$ligne['ligne']}).");
            }
        }

        $code = mb_strtoupper(trim((string) $ligne['code']));
        $actif = true;
        if (isset($ligne['actif'])) {
            $val = mb_strtolower((string) $ligne['actif']);
            $actif = ! in_array($val, ['0', 'false', 'faux', 'non'], true);
        }

        $donnees = [
            'nom' => trim((string) $ligne['nom']),
            'sigle' => ! empty($ligne['sigle']) ? trim((string) $ligne['sigle']) : null,
            'description' => $ligne['description'] ?? null,
            'actif' => $actif,
        ];

        $existant = $this->trouverExistant($ligne);

        if ($existant !== null) {
            $existant->update($donnees);

            return "mise à jour : {$code} ({$faculte->code})";
        }

        Domaine::create([
            'faculte_id' => $faculte->id,
            'code' => $code,
        ] + $donnees);

        return "créé : {$code} ({$faculte->code})";
    }

    public function modes(): array
    {
        return ['creer', 'upsert'];
    }

    public function vider(?int $auteurId): void
    {
        // Pas de vidage global
    }

    public function formats(): array
    {
        return ['csv', 'xlsx', 'json', 'pdf'];
    }

    public function requete(Request $request)
    {
        return Domaine::with('faculte')->orderBy('code');
    }

    public function filtrer($query, Request $request)
    {
        if ($auteur = $request->user()) {
            if ($restreinteId = $auteur->faculteRestreinteId()) {
                $query->where('faculte_id', $restreinteId);
            }
        }

        if ($search = trim((string) $request->input('search', ''))) {
            $motif = Recherche::contient($search);
            $query->where(fn ($q) => $q->where('code', 'like', $motif)->orWhere('nom', 'like', $motif));
        }

        return $query;
    }

    public function colonnesAutorisees(array $demandees, bool $estAdmin = false): array
    {
        return array_values(array_intersect($this->colonnes(), $demandees));
    }

    public function ligne($modele): array
    {
        return [
            'code' => $modele->code,
            'nom' => $modele->nom,
            'faculte' => $modele->faculte?->code ?? '',
            'sigle' => $modele->sigle,
            'description' => $modele->description,
            'actif' => $modele->actif ? '1' : '0',
        ];
    }
}
