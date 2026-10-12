<?php

namespace App\Services\DataExchange\Definitions;

use App\Models\User;
use App\Modules\Referentiel\Models\Mention;
use App\Modules\Referentiel\Models\Specialite;
use App\Services\DataExchange\Contracts\ExportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportValidationAvancee;
use App\Services\Io\IoException;
use App\Support\Recherche;
use Illuminate\Http\Request;

/**
 * Import / Export des spécialités (Lot 4, §12).
 *
 * Dépendance : Mention.
 * Clé métier : `mention` + `code`.
 */
class SpecialiteDefinition implements ExportDefinitionInterface, ImportDefinitionInterface, ImportValidationAvancee
{
    public function entite(): string
    {
        return 'specialites';
    }

    public function libelle(): string
    {
        return 'Spécialités';
    }

    public function colonnes(): array
    {
        return ['code', 'nom', 'mention', 'departement', 'description', 'actif'];
    }

    public function colonnesRequises(): array
    {
        return ['code', 'nom', 'mention'];
    }

    public function aliasesColonnes(): array
    {
        return [
            'code' => ['code_specialite', 'identifiant'],
            'nom' => ['libelle', 'designation', 'intitule'],
            'mention' => ['code_mention', 'mention_code'],
            'departement' => ['code_departement', 'departement_code'],
        ];
    }

    public function regles(): array
    {
        return [
            'code' => ['required', 'string', 'max:30'],
            'nom' => ['required', 'string', 'max:150'],
            'mention' => ['required', 'string', 'max:30'],
            'departement' => ['nullable', 'string', 'max:30'],
            'description' => ['nullable', 'string', 'max:1000'],
            'actif' => ['nullable'],
        ];
    }

    public function resoudreMention(string $valeur, ?int $auteurId = null): ?Mention
    {
        $code = mb_strtoupper(trim($valeur));
        $auteur = $auteurId !== null ? User::find($auteurId) : null;
        $restreinteId = $auteur?->faculteRestreinteId();

        $query = Mention::where('code', $code);
        if ($restreinteId !== null) {
            $query->whereHas('domaine', fn ($q) => $q->where('faculte_id', $restreinteId));
        }

        return $query->first();
    }

    public function validerLigneMetier(array $ligne, ?int $auteurId): array
    {
        $erreurs = [];
        $mention = $this->resoudreMention($ligne['mention'] ?? '', $auteurId);

        if ($mention === null) {
            $erreurs[] = [
                'champ' => 'mention',
                'message' => "La mention « {$ligne['mention']} » est introuvable ou hors périmètre.",
                'code' => 'MENTION_NOT_FOUND',
            ];
        }

        return $erreurs;
    }

    public function cleMetier(array $ligne): ?string
    {
        $code = mb_strtoupper(trim((string) ($ligne['code'] ?? '')));
        $men = mb_strtoupper(trim((string) ($ligne['mention'] ?? '')));

        return "{$men}|{$code}";
    }

    public function cleDoublon(): array
    {
        return ['code', 'mention'];
    }

    public function trouverExistant(array $ligne): ?object
    {
        $mention = $this->resoudreMention($ligne['mention'] ?? '');
        if ($mention === null) {
            return null;
        }

        return Specialite::where('code', mb_strtoupper(trim((string) $ligne['code'])))
            ->where('mention_id', $mention->id)
            ->first();
    }

    public function persister(array $ligne, string $mode, ?int $auteurId): string
    {
        $mention = $this->resoudreMention($ligne['mention'] ?? '', $auteurId);
        if ($mention === null) {
            throw IoException::malforme("Mention « {$ligne['mention']} » introuvable (ligne {$ligne['ligne']}).");
        }

        $code = mb_strtoupper(trim((string) $ligne['code']));
        $actif = true;
        if (isset($ligne['actif'])) {
            $val = mb_strtolower((string) $ligne['actif']);
            $actif = ! in_array($val, ['0', 'false', 'faux', 'non'], true);
        }

        $donnees = [
            'nom' => trim((string) $ligne['nom']),
            'description' => $ligne['description'] ?? null,
            'actif' => $actif,
        ];

        $existant = $this->trouverExistant($ligne);

        if ($existant !== null) {
            $existant->update($donnees);

            return "mise à jour : {$code} ({$mention->code})";
        }

        Specialite::create([
            'mention_id' => $mention->id,
            'code' => $code,
        ] + $donnees);

        return "créée : {$code} ({$mention->code})";
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
        return Specialite::with('mention.domaine.faculte')->orderBy('code');
    }

    public function filtrer($query, Request $request)
    {
        if ($auteur = $request->user()) {
            if ($restreinteId = $auteur->faculteRestreinteId()) {
                $query->whereHas('mention.domaine', fn ($q) => $q->where('faculte_id', $restreinteId));
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
            'mention' => $modele->mention?->code ?? '',
            'description' => $modele->description,
            'actif' => $modele->actif ? '1' : '0',
        ];
    }
}
