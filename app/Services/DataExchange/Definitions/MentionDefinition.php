<?php

namespace App\Services\DataExchange\Definitions;

use App\Models\User;
use App\Modules\Referentiel\Models\Domaine;
use App\Modules\Referentiel\Models\Mention;
use App\Services\DataExchange\Contracts\ExportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportValidationAvancee;
use App\Services\Io\IoException;
use App\Support\Recherche;
use Illuminate\Http\Request;

/**
 * Import / Export des mentions (Lot 4, §12).
 *
 * Dépendance : Domaine de formation.
 * Clé métier : `domaine` + `code`.
 */
class MentionDefinition implements ExportDefinitionInterface, ImportDefinitionInterface, ImportValidationAvancee
{
    public function entite(): string
    {
        return 'mentions';
    }

    public function libelle(): string
    {
        return 'Mentions';
    }

    public function colonnes(): array
    {
        return ['code', 'nom', 'domaine', 'sigle', 'description', 'actif'];
    }

    public function colonnesRequises(): array
    {
        return ['code', 'nom', 'domaine'];
    }

    public function aliasesColonnes(): array
    {
        return [
            'code' => ['code_mention', 'identifiant'],
            'nom' => ['libelle', 'designation', 'intitule'],
            'domaine' => ['code_domaine', 'domaine_code'],
            'sigle' => ['acronyme', 'court'],
        ];
    }

    public function regles(): array
    {
        return [
            'code' => ['required', 'string', 'max:30'],
            'nom' => ['required', 'string', 'max:150'],
            'domaine' => ['required', 'string', 'max:20'],
            'sigle' => ['nullable', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:1000'],
            'actif' => ['nullable'],
        ];
    }

    public function resoudreDomaine(string $valeur, ?int $auteurId = null): ?Domaine
    {
        $code = mb_strtoupper(trim($valeur));
        $auteur = $auteurId !== null ? User::find($auteurId) : null;
        $restreinteId = $auteur?->faculteRestreinteId();

        $query = Domaine::where('code', $code);
        if ($restreinteId !== null) {
            $query->where('faculte_id', $restreinteId);
        }

        return $query->first();
    }

    public function validerLigneMetier(array $ligne, ?int $auteurId): array
    {
        $erreurs = [];
        $domaine = $this->resoudreDomaine($ligne['domaine'] ?? '', $auteurId);

        if ($domaine === null) {
            $erreurs[] = [
                'champ' => 'domaine',
                'message' => "Le domaine « {$ligne['domaine']} » est introuvable ou hors périmètre.",
                'code' => 'DOMAIN_NOT_FOUND',
            ];
        }

        return $erreurs;
    }

    public function cleMetier(array $ligne): ?string
    {
        $code = mb_strtoupper(trim((string) ($ligne['code'] ?? '')));
        $dom = mb_strtoupper(trim((string) ($ligne['domaine'] ?? '')));

        return "{$dom}|{$code}";
    }

    public function cleDoublon(): array
    {
        return ['code', 'domaine'];
    }

    public function trouverExistant(array $ligne): ?object
    {
        $domaine = $this->resoudreDomaine($ligne['domaine'] ?? '');
        if ($domaine === null) {
            return null;
        }

        return Mention::where('code', mb_strtoupper(trim((string) $ligne['code'])))
            ->where('domaine_id', $domaine->id)
            ->first();
    }

    public function persister(array $ligne, string $mode, ?int $auteurId): string
    {
        $domaine = $this->resoudreDomaine($ligne['domaine'] ?? '', $auteurId);
        if ($domaine === null) {
            throw IoException::malforme("Domaine « {$ligne['domaine']} » introuvable (ligne {$ligne['ligne']}).");
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

            return "mise à jour : {$code} ({$domaine->code})";
        }

        Mention::create([
            'domaine_id' => $domaine->id,
            'code' => $code,
        ] + $donnees);

        return "créée : {$code} ({$domaine->code})";
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
        return Mention::with('domaine.faculte')->orderBy('code');
    }

    public function filtrer($query, Request $request)
    {
        if ($auteur = $request->user()) {
            if ($restreinteId = $auteur->faculteRestreinteId()) {
                $query->whereHas('domaine', fn ($q) => $q->where('faculte_id', $restreinteId));
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
            'domaine' => $modele->domaine?->code ?? '',
            'sigle' => $modele->sigle,
            'description' => $modele->description,
            'actif' => $modele->actif ? '1' : '0',
        ];
    }
}
