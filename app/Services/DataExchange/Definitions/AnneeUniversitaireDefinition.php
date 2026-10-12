<?php

namespace App\Services\DataExchange\Definitions;

use App\Models\AnneeUniversitaire;
use App\Services\DataExchange\Contracts\ExportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportValidationAvancee;
use App\Support\Recherche;
use Illuminate\Http\Request;

/**
 * Import / Export des années universitaires (Lot 1, §9).
 *
 * Entité racine du calendrier académique.
 * Clé métier stable : `code` / `libelle` (format AAAA-AAAA).
 */
class AnneeUniversitaireDefinition implements ExportDefinitionInterface, ImportDefinitionInterface, ImportValidationAvancee
{
    public function entite(): string
    {
        return 'annees-universitaires';
    }

    public function libelle(): string
    {
        return 'Années universitaires';
    }

    public function colonnes(): array
    {
        return ['libelle', 'date_debut', 'date_fin', 'active'];
    }

    public function colonnesRequises(): array
    {
        return ['libelle'];
    }

    public function aliasesColonnes(): array
    {
        return [
            'libelle' => ['code', 'annee', 'annee_universitaire', 'annee_academique', 'session'],
            'date_debut' => ['debut', 'start_date', 'date_start'],
            'date_fin' => ['fin', 'end_date', 'date_end'],
            'active' => ['courante', 'is_active', 'active_year', 'statut', 'etat'],
        ];
    }

    public function regles(): array
    {
        return [
            'libelle' => ['required', 'regex:/^\d{4}-\d{4}$/'],
            'date_debut' => ['nullable', 'date'],
            'date_fin' => ['nullable', 'date', 'after_or_equal:date_debut'],
            'active' => ['nullable'],
        ];
    }

    public function validerLigneMetier(array $ligne, ?int $auteurId): array
    {
        $erreurs = [];

        // Validation cohérence des années dans le libellé AAAA-(AAAA+1)
        if (preg_match('/^(\d{4})-(\d{4})$/', (string) $ligne['libelle'], $m)) {
            $annee1 = (int) $m[1];
            $annee2 = (int) $m[2];
            if ($annee2 !== $annee1 + 1) {
                $erreurs[] = [
                    'champ' => 'libelle',
                    'message' => "Le libellé « {$ligne['libelle']} » doit représenter deux années consécutives (ex. {$annee1}-".($annee1 + 1).').',
                    'code' => 'INVALID_YEAR_FORMAT',
                ];
            }
        }

        return $erreurs;
    }

    public function cleMetier(array $ligne): ?string
    {
        return mb_strtolower(trim((string) ($ligne['libelle'] ?? '')));
    }

    public function cleDoublon(): array
    {
        return ['libelle'];
    }

    public function trouverExistant(array $ligne): ?object
    {
        return AnneeUniversitaire::where('libelle', trim((string) $ligne['libelle']))->first();
    }

    public function persister(array $ligne, string $mode, ?int $auteurId): string
    {
        $libelle = trim((string) $ligne['libelle']);
        $debut = ! empty($ligne['date_debut']) ? $ligne['date_debut'] : null;
        $fin = ! empty($ligne['date_fin']) ? $ligne['date_fin'] : null;

        // Si dates non fournies, déduire 01/09/AAAA et 30/06/AAAA+1
        if ($debut === null && preg_match('/^(\d{4})-(\d{4})$/', $libelle, $m)) {
            $debut = $m[1].'-09-01';
            $fin = $m[2].'-06-30';
        }

        $active = false;
        if (isset($ligne['active'])) {
            $val = mb_strtolower((string) $ligne['active']);
            $active = in_array($val, ['1', 'true', 'vrai', 'oui', 'yes', 'active', 'actif'], true);
        }

        $existant = $this->trouverExistant($ligne);

        if ($existant !== null) {
            $existant->update([
                'date_debut' => $debut ?? $existant->date_debut,
                'date_fin' => $fin ?? $existant->date_fin,
            ]);

            if ($active) {
                $existant->definirCourante();
            }

            return "mise à jour : {$libelle}";
        }

        $nouvelle = AnneeUniversitaire::create([
            'libelle' => $libelle,
            'date_debut' => $debut,
            'date_fin' => $fin,
            'active' => false,
        ]);

        if ($active) {
            $nouvelle->definirCourante();
        }

        return "créée : {$libelle}";
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
        return AnneeUniversitaire::orderByDesc('libelle');
    }

    public function filtrer($query, Request $request)
    {
        if ($search = trim((string) $request->input('search', ''))) {
            $query->where('libelle', 'like', Recherche::contient($search));
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
            'libelle' => $modele->libelle,
            'date_debut' => $modele->date_debut?->format('Y-m-d'),
            'date_fin' => $modele->date_fin?->format('Y-m-d'),
            'active' => $modele->active ? '1' : '0',
        ];
    }
}
