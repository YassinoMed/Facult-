<?php

namespace App\Services\DataExchange\Definitions;

use App\Models\Faculte;
use App\Models\User;
use App\Services\DataExchange\Contracts\ExportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportValidationAvancee;
use App\Services\Io\IoException;
use App\Support\Recherche;
use Illuminate\Http\Request;

/**
 * Import / Export des Facultés et Établissements (Lot 2, §10).
 *
 * Établissement = Faculté dans le modèle unifié.
 * Clé métier : `code` (ex. FST, FSB, FMT).
 */
class FaculteDefinition implements ExportDefinitionInterface, ImportDefinitionInterface, ImportValidationAvancee
{
    public function entite(): string
    {
        return 'facultes';
    }

    public function libelle(): string
    {
        return 'Facultés / Établissements';
    }

    public function colonnes(): array
    {
        return ['code', 'nom', 'sigle', 'description', 'adresse', 'telephone', 'email', 'site_web', 'active', 'type', 'etablissement_code'];
    }

    public function colonnesRequises(): array
    {
        return ['code', 'nom'];
    }

    public function aliasesColonnes(): array
    {
        return [
            'code' => ['code_faculte', 'code_etablissement', 'id_faculte', 'identifiant'],
            'nom' => ['libelle', 'designation', 'nom_faculte', 'nom_etablissement', 'intitule'],
            'sigle' => ['abbreviation', 'court', 'acronyme'],
            'telephone' => ['tel', 'phone', 'contact'],
            'site_web' => ['site', 'url', 'web'],
            'active' => ['statut', 'actif'],
            'etablissement_code' => ['etablissement', 'code_etablissement_parent'],
        ];
    }

    public function regles(): array
    {
        return [
            'code' => ['required', 'string', 'max:20'],
            'nom' => ['required', 'string', 'max:255'],
            'sigle' => ['nullable', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:1000'],
            'adresse' => ['nullable', 'string', 'max:500'],
            'telephone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'site_web' => ['nullable', 'string', 'max:255'],
            'active' => ['nullable'],
            'type' => ['nullable', 'string', 'max:50'],
            'etablissement_code' => ['nullable', 'string', 'max:50'],
        ];
    }

    public function validerLigneMetier(array $ligne, ?int $auteurId): array
    {
        $erreurs = [];

        // Contrôle du Faculty Scope : un admin restreint ne peut modifier que sa propre faculté
        if ($auteurId !== null) {
            $auteur = User::find($auteurId);
            $restreinteId = $auteur?->faculteRestreinteId();
            if ($restreinteId !== null) {
                $existant = $this->trouverExistant($ligne);
                if ($existant === null || (int) $existant->id !== $restreinteId) {
                    $erreurs[] = [
                        'champ' => 'code',
                        'message' => "Vous n'êtes pas autorisé à créer ou modifier une faculté en dehors de votre périmètre.",
                        'code' => 'FACULTY_SCOPE_VIOLATION',
                    ];
                }
            }
        }

        return $erreurs;
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
        return Faculte::where('code', mb_strtoupper(trim((string) $ligne['code'])))->first();
    }

    public function persister(array $ligne, string $mode, ?int $auteurId): string
    {
        $code = mb_strtoupper(trim((string) $ligne['code']));

        if ($auteurId !== null) {
            $auteur = User::find($auteurId);
            $restreinteId = $auteur?->faculteRestreinteId();
            if ($restreinteId !== null) {
                $faculteAutorisee = Faculte::find($restreinteId);
                if ($faculteAutorisee === null || $faculteAutorisee->code !== $code) {
                    throw IoException::malforme("Action refusée : périmètre restreint à la faculté {$faculteAutorisee?->code}.");
                }
            }
        }

        $active = true;
        if (isset($ligne['active'])) {
            $val = mb_strtolower((string) $ligne['active']);
            $active = ! in_array($val, ['0', 'false', 'faux', 'non', 'no'], true);
        }

        $donnees = [
            'nom' => trim((string) $ligne['nom']),
            'sigle' => ! empty($ligne['sigle']) ? trim((string) $ligne['sigle']) : null,
            'description' => $ligne['description'] ?? null,
            'adresse' => $ligne['adresse'] ?? null,
            'telephone' => $ligne['telephone'] ?? null,
            'email' => ! empty($ligne['email']) ? mb_strtolower(trim((string) $ligne['email'])) : null,
            'site_web' => $ligne['site_web'] ?? null,
            'active' => $active,
        ];

        $existant = $this->trouverExistant($ligne);

        if ($existant !== null) {
            $existant->update($donnees);

            return "mise à jour : {$code}";
        }

        Faculte::create(['code' => $code] + $donnees);

        return "créée : {$code}";
    }

    public function modes(): array
    {
        return ['creer', 'upsert'];
    }

    public function vider(?int $auteurId): void
    {
        // Jamais de vidage : entité centrale avec clés étrangères restrictives.
    }

    public function formats(): array
    {
        return ['csv', 'xlsx', 'json', 'pdf'];
    }

    public function requete(Request $request)
    {
        return Faculte::orderBy('code');
    }

    public function filtrer($query, Request $request)
    {
        if ($search = trim((string) $request->input('search', ''))) {
            $motif = Recherche::contient($search);
            $query->where('code', 'like', $motif)->orWhere('nom', 'like', $motif);
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
            'sigle' => $modele->sigle,
            'description' => $modele->description,
            'adresse' => $modele->adresse,
            'telephone' => $modele->telephone,
            'email' => $modele->email,
            'site_web' => $modele->site_web,
            'active' => $modele->active ? '1' : '0',
        ];
    }
}
