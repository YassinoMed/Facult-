<?php

namespace App\Services\DataExchange\Definitions;

use App\Models\AnneeUniversitaire;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Services\DataExchange\Contracts\ExportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportValidationAvancee;
use App\Services\Io\IoException;
use App\Support\Recherche;
use Illuminate\Http\Request;

/**
 * Import / Export des sessions d'examen (Lot 18, §26).
 *
 * Dépendance : Année universitaire.
 * Clé métier : `code`.
 */
class SessionExamenDefinition implements ExportDefinitionInterface, ImportDefinitionInterface, ImportValidationAvancee
{
    public function entite(): string
    {
        return 'sessions-examen';
    }

    public function libelle(): string
    {
        return 'Sessions d’examen';
    }

    public function colonnes(): array
    {
        return ['code', 'nom', 'annee', 'type', 'date_debut', 'date_fin', 'statut', 'description'];
    }

    public function colonnesRequises(): array
    {
        return ['code', 'nom', 'annee'];
    }

    public function aliasesColonnes(): array
    {
        return [
            'code' => ['code_session', 'identifiant'],
            'nom' => ['libelle', 'designation', 'intitule'],
            'annee' => ['annee_universitaire', 'session_annee'],
            'type' => ['type_session', 'nature'],
            'date_debut' => ['debut', 'start_date'],
            'date_fin' => ['fin', 'end_date'],
        ];
    }

    public function regles(): array
    {
        return [
            'code' => ['required', 'string', 'max:30'],
            'nom' => ['required', 'string', 'max:150'],
            'annee' => ['required', 'regex:/^\d{4}-\d{4}$/'],
            'type' => ['nullable', 'in:principale,rattrapage,exceptionnelle'],
            'date_debut' => ['nullable', 'date'],
            'date_fin' => ['nullable', 'date', 'after_or_equal:date_debut'],
            'statut' => ['nullable', 'in:draft,open,ongoing,closed,locked,archived'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function resoudreAnnee(string $valeur): ?AnneeUniversitaire
    {
        return AnneeUniversitaire::where('libelle', trim($valeur))->first();
    }

    public function validerLigneMetier(array $ligne, ?int $auteurId): array
    {
        $erreurs = [];
        $annee = $this->resoudreAnnee($ligne['annee'] ?? '');

        if ($annee === null) {
            $erreurs[] = [
                'champ' => 'annee',
                'message' => "L'année universitaire « {$ligne['annee']} » est introuvable.",
                'code' => 'YEAR_NOT_FOUND',
            ];
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
        return SessionExamen::where('code', mb_strtoupper(trim((string) $ligne['code'])))->first();
    }

    public function persister(array $ligne, string $mode, ?int $auteurId): string
    {
        $annee = $this->resoudreAnnee($ligne['annee'] ?? '');
        if ($annee === null) {
            throw IoException::malforme("Année « {$ligne['annee']} » introuvable (ligne {$ligne['ligne']}).");
        }

        $code = mb_strtoupper(trim((string) $ligne['code']));
        $donnees = [
            'annee_universitaire_id' => $annee->id,
            'nom' => trim((string) $ligne['nom']),
            'type' => $ligne['type'] ?? 'principale',
            'date_debut' => ! empty($ligne['date_debut']) ? $ligne['date_debut'] : null,
            'date_fin' => ! empty($ligne['date_fin']) ? $ligne['date_fin'] : null,
            'statut' => $ligne['statut'] ?? 'draft',
            'description' => $ligne['description'] ?? null,
            'created_by' => $auteurId,
        ];

        $existant = $this->trouverExistant($ligne);

        if ($existant !== null) {
            $existant->update($donnees);

            return "mise à jour : {$code}";
        }

        SessionExamen::create(['code' => $code] + $donnees);

        return "créée : {$code}";
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
        return SessionExamen::with('anneeUniversitaire')->orderByDesc('id');
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
            'annee' => $modele->anneeUniversitaire?->libelle ?? '',
            'type' => $modele->type,
            'date_debut' => $modele->date_debut?->format('Y-m-d'),
            'date_fin' => $modele->date_fin?->format('Y-m-d'),
            'statut' => $modele->statut,
            'description' => $modele->description ?? '',
        ];
    }
}
