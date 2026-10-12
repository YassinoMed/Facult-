<?php

namespace App\Services\DataExchange\Definitions;

use App\Modules\Referentiel\Models\TypeDiplome;
use App\Services\DataExchange\Contracts\ExportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportValidationAvancee;
use App\Support\Recherche;
use Illuminate\Http\Request;

/**
 * Import / Export des types de diplôme (Lot 4, §12).
 *
 * Ex. Licence, Mastère, Ingénieur, Doctorat.
 * Clé métier : `code`.
 */
class TypeDiplomeDefinition implements ExportDefinitionInterface, ImportDefinitionInterface, ImportValidationAvancee
{
    public function entite(): string
    {
        return 'type-diplomes';
    }

    public function libelle(): string
    {
        return 'Types de diplôme';
    }

    public function colonnes(): array
    {
        return ['code', 'nom', 'ordre', 'actif', 'cycle', 'credits_requis', 'duree_annees'];
    }

    public function colonnesRequises(): array
    {
        return ['code', 'nom'];
    }

    public function aliasesColonnes(): array
    {
        return [
            'code' => ['code_diplome', 'identifiant'],
            'nom' => ['libelle', 'designation', 'intitule'],
            'ordre' => ['sequence', 'rang'],
            'cycle' => ['niveau_diplome', 'niveau', 'type'],
            'credits_requis' => ['credits_total', 'credits', 'ects_total', 'total_credits'],
            'duree_annees' => ['duree', 'annees'],
        ];
    }

    public function regles(): array
    {
        return [
            'code' => ['required', 'string', 'max:20'],
            'nom' => ['required', 'string', 'max:100'],
            'ordre' => ['nullable', 'integer', 'min:1', 'max:255'],
            'actif' => ['nullable'],
            'cycle' => ['nullable', 'string', 'max:30'],
            'credits_requis' => ['nullable', 'integer', 'min:0', 'max:999'],
            'duree_annees' => ['nullable', 'integer', 'min:1', 'max:12'],
        ];
    }

    public function validerLigneMetier(array $ligne, ?int $auteurId): array
    {
        // Cycle insensible à la casse (fichiers réels : LICENCE…),
        // normalisé en minuscules à la persistance.
        $cycle = mb_strtolower(trim((string) ($ligne['cycle'] ?? '')));

        if ($cycle !== '' && ! in_array($cycle, TypeDiplome::CYCLES, true)) {
            return [[
                'champ' => 'cycle',
                'message' => 'Cycle « '.($ligne['cycle'] ?? '').' » inconnu (attendus : '.implode(', ', TypeDiplome::CYCLES).').',
                'code' => 'TYPE_CYCLE_INCONNU',
            ]];
        }

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
        return TypeDiplome::where('code', mb_strtoupper(trim((string) $ligne['code'])))->first();
    }

    public function persister(array $ligne, string $mode, ?int $auteurId): string
    {
        $code = mb_strtoupper(trim((string) $ligne['code']));
        $actif = true;
        if (isset($ligne['actif'])) {
            $val = mb_strtolower((string) $ligne['actif']);
            $actif = ! in_array($val, ['0', 'false', 'faux', 'non'], true);
        }

        $donnees = [
            'nom' => trim((string) $ligne['nom']),
            'ordre' => ! empty($ligne['ordre']) ? (int) $ligne['ordre'] : 1,
            'actif' => $actif,
            'cycle' => ! empty($ligne['cycle']) ? mb_strtolower(trim((string) $ligne['cycle'])) : null,
            'credits_requis' => ! empty($ligne['credits_requis']) ? (int) $ligne['credits_requis'] : null,
            'duree_annees' => ! empty($ligne['duree_annees']) ? (int) $ligne['duree_annees'] : null,
        ];

        $existant = $this->trouverExistant($ligne);

        if ($existant !== null) {
            $existant->update($donnees);

            return "mise à jour : {$code}";
        }

        TypeDiplome::create(['code' => $code] + $donnees);

        return "créé : {$code}";
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
        return TypeDiplome::orderBy('ordre')->orderBy('code');
    }

    public function filtrer($query, Request $request)
    {
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
            'ordre' => (string) $modele->ordre,
            'actif' => $modele->actif ? '1' : '0',
            'cycle' => $modele->cycle,
            'credits_requis' => $modele->credits_requis,
            'duree_annees' => $modele->duree_annees,
        ];
    }
}
