<?php

namespace App\Services\DataExchange\Definitions;

use App\Models\AnneeUniversitaire;
use App\Models\Matiere;
use App\Modules\Enseignement\Models\Enseignement;
use App\Modules\Enseignement\Models\TypeEnseignement;
use App\Services\DataExchange\Contracts\ExportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportValidationAvancee;
use App\Services\Io\IoException;
use App\Support\Recherche;
use Illuminate\Http\Request;

/**
 * Enseignements (import/export CSV/XLSX/JSON, §40-42) : identification
 * par code ECUE + code type + année. Références résolues serveur.
 */
class EnseignementDefinition implements ExportDefinitionInterface, ImportDefinitionInterface, ImportValidationAvancee
{
    public function entite(): string
    {
        return 'enseignements';
    }

    public function libelle(): string
    {
        return 'Enseignements';
    }

    public function colonnes(): array
    {
        return ['code', 'ecue', 'type', 'annee', 'programme', 'semestre', 'volume_cm', 'volume_td', 'volume_tp', 'volume_projet', 'volume_autre', 'statut'];
    }

    public function colonnesRequises(): array
    {
        return ['ecue', 'annee'];
    }

    public function aliasesColonnes(): array
    {
        return [
            'ecue' => ['ecue_code', 'code_ecue', 'matiere'],
            'annee' => ['annee_code', 'code_annee', 'annee_universitaire'],
            'volume_cm' => ['volume_horaire', 'volume'],
            'programme' => ['parcours_code', 'code_parcours', 'parcours'],
            'semestre' => ['semestre_code', 'code_semestre'],
        ];
    }

    public function regles(): array
    {
        return [
            'code' => 'nullable|string|max:30',
            'ecue' => 'required|string|max:20',
            'type' => 'nullable|string|max:20',
            'annee' => 'required|string|max:20',
            'programme' => 'nullable|string|max:30',
            'semestre' => 'nullable|string|max:20',
            'volume_cm' => 'nullable|integer|min:0|max:999',
            'volume_td' => 'nullable|integer|min:0|max:999',
            'volume_tp' => 'nullable|integer|min:0|max:999',
            'volume_projet' => 'nullable|integer|min:0|max:999',
            'volume_autre' => 'nullable|integer|min:0|max:999',
            'statut' => 'nullable|string|max:50',
        ];
    }

    public function cleDoublon(): array
    {
        return ['code', 'ecue', 'annee'];
    }

    public function validerLigneMetier(array $ligne, ?int $auteurId): array
    {
        return [];
    }

    public function cleMetier(array $ligne): ?string
    {
        if (! empty($ligne['code'])) {
            return mb_strtoupper(trim((string) $ligne['code']));
        }

        $ecue = mb_strtoupper(trim((string) ($ligne['ecue'] ?? '')));
        $annee = mb_strtoupper(trim((string) ($ligne['annee'] ?? '')));

        return "{$annee}|{$ecue}";
    }

    public function trouverExistant(array $ligne): ?object
    {
        if (! empty($ligne['code'])) {
            $existant = Enseignement::where('code', trim((string) $ligne['code']))->first();
            if ($existant !== null) {
                return $existant;
            }
        }

        $ecue = Matiere::where('code', $ligne['ecue'] ?? '')->first();
        $typeCode = ! empty($ligne['type']) ? mb_strtoupper(trim((string) $ligne['type'])) : 'CM';
        $type = TypeEnseignement::where('code', $typeCode)->first();
        $annee = AnneeUniversitaire::where('libelle', $ligne['annee'] ?? '')->first();

        if ($ecue === null || $type === null || $annee === null) {
            return null;
        }

        return Enseignement::where('matiere_id', $ecue->id)
            ->where('type_id', $type->id)
            ->where('annee_universitaire_id', $annee->id)->first();
    }

    public function persister(array $ligne, string $mode, ?int $auteurId): string
    {
        $ecue = Matiere::where('code', $ligne['ecue'])->first();
        $typeCode = ! empty($ligne['type']) ? mb_strtoupper(trim((string) $ligne['type'])) : 'CM';
        $type = TypeEnseignement::firstOrCreate(['code' => $typeCode], ['nom' => $typeCode]);
        $annee = AnneeUniversitaire::where('libelle', $ligne['annee'])->first();

        foreach ([['ecue', $ecue, 'ECUE'], ['annee', $annee, 'Année']] as [$champ, $obj, $label]) {
            if ($obj === null) {
                throw IoException::malforme("{$label} « {$ligne[$champ]} » introuvable (ligne {$ligne['ligne']}).");
            }
        }

        $statutBrut = mb_strtolower(trim((string) ($ligne['statut'] ?? 'draft')));
        $statut = match (true) {
            str_contains($statutBrut, 'act') => 'active',
            str_contains($statutBrut, 'plan') => 'planned',
            str_contains($statutBrut, 'val') => 'validated',
            str_contains($statutBrut, 'term') || str_contains($statutBrut, 'comp') => 'completed',
            str_contains($statutBrut, 'annul') || str_contains($statutBrut, 'canc') => 'cancelled',
            default => 'draft',
        };

        $donnees = [
            'volume_cm' => (int) ($ligne['volume_cm'] ?? 0),
            'volume_td' => (int) ($ligne['volume_td'] ?? 0),
            'volume_tp' => (int) ($ligne['volume_tp'] ?? 0),
            'volume_projet' => (int) ($ligne['volume_projet'] ?? 0),
            'volume_autre' => (int) ($ligne['volume_autre'] ?? 0),
            'statut' => $statut,
        ];

        $existant = $this->trouverExistant($ligne);

        if ($existant !== null) {
            $existant->update($donnees);

            return "mis à jour : {$ligne['ecue']} / {$typeCode}";
        }

        Enseignement::create([
            'code' => ! empty($ligne['code']) ? trim((string) $ligne['code']) : null,
            'matiere_id' => $ecue->id,
            'type_id' => $type->id,
            'annee_universitaire_id' => $annee->id,
            'semestre_id' => $ecue->ue?->semestre_id,
            'programme_id' => $ecue->ue?->programme_id,
            'statut' => $statut,
            'cree_par' => $auteurId,
            'volume_cm' => $donnees['volume_cm'],
            'volume_td' => $donnees['volume_td'],
            'volume_tp' => $donnees['volume_tp'],
            'volume_projet' => $donnees['volume_projet'],
            'volume_autre' => $donnees['volume_autre'],
        ]);

        return "créé : {$ligne['ecue']} / {$typeCode}";
    }

    public function modes(): array
    {
        return ['creer', 'upsert'];
    }

    public function vider(?int $auteurId): void
    {
        // Jamais de vidage.
    }

    public function formats(): array
    {
        return ['csv', 'xlsx', 'json', 'pdf'];
    }

    public function requete(Request $request)
    {
        $query = Enseignement::with(['matiere', 'type'])->orderByDesc('id');

        if ($request->user()->faculteRestreinteId() !== null) {
            $query->whereHas('matiere.ue.programme',
                fn ($q) => $q->where('faculte_id', $request->user()->faculteRestreinteId()));
        }

        return $query;
    }

    public function filtrer($query, Request $request)
    {
        if ($search = trim($request->input('search', ''))) {
            $motif = Recherche::contient($search);
            $query->whereHas('matiere', fn ($w) => $w
                ->where('code', 'like', $motif)->orWhere('nom', 'like', $motif));
        }

        return $query->limit(10000);
    }

    public function ligne($modele): array
    {
        return [
            'ecue' => $modele->matiere->code,
            'type' => $modele->type?->code,
            'annee' => $modele->anneeUniversitaire->libelle,
            'volume_cm' => $modele->volume_cm,
            'volume_td' => $modele->volume_td,
            'volume_tp' => $modele->volume_tp,
            'volume_projet' => $modele->volume_projet,
            'volume_autre' => $modele->volume_autre,
            'statut' => $modele->statut,
        ];
    }
}
