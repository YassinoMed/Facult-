<?php

namespace App\Services\DataExchange\Definitions;

use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\TypeDiplome;
use App\Services\DataExchange\Contracts\ExportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportValidationAvancee;
use App\Services\Io\IoException;
use App\Support\Recherche;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Définition niveaux (§14) : identifiant = code + programme/diplôme.
 * Jamais de vidage : semestres, groupes et inscriptions rattachés.
 */
class NiveauDefinition implements ExportDefinitionInterface, ImportDefinitionInterface, ImportValidationAvancee
{
    public function entite(): string
    {
        return 'niveaux';
    }

    public function libelle(): string
    {
        return 'Niveaux';
    }

    public function colonnes(): array
    {
        return ['code', 'nom', 'programme', 'diplome', 'ordre'];
    }

    public function colonnesRequises(): array
    {
        return ['code'];
    }

    public function aliasesColonnes(): array
    {
        return [
            'nom' => ['libelle', 'designation', 'intitule'],
            'programme' => ['programme_code', 'parcours_code', 'parcours', 'code_programme'],
            'diplome' => ['diplome_code', 'code_diplome', 'type_diplome'],
        ];
    }

    public function regles(): array
    {
        return [
            'code' => 'required|string|max:10',
            'nom' => 'nullable|string|max:100',
            'programme' => 'nullable|string|max:30',
            'diplome' => 'nullable|string|max:30',
            'ordre' => 'nullable|integer|min:1|max:20',
        ];
    }

    public function cleDoublon(): array
    {
        return ['code', 'programme'];
    }

    public function validerLigneMetier(array $ligne, ?int $auteurId): array
    {
        $erreurs = [];
        $progCode = $ligne['programme'] ?? null;
        $dipCode = $ligne['diplome'] ?? null;

        if (empty($progCode) && empty($dipCode)) {
            $erreurs[] = [
                'champ' => 'programme',
                'message' => 'Le programme ou le diplôme associé au niveau est obligatoire.',
                'code' => 'PROGRAMME_OR_DIPLOME_REQUIRED',
            ];

            return $erreurs;
        }

        // Audit 08/10/2026 : fin du repli silencieux « Programme::all() » —
        // les cibles sont validées en prévisualisation, fan-out compris.
        $programmes = $this->programmesCibles($progCode, $dipCode);

        if ($programmes === null) {
            $erreurs[] = empty($progCode) ? [
                'champ' => 'diplome',
                'message' => "Diplôme « {$dipCode} » introuvable.",
                'code' => 'DIPLOME_NOT_FOUND',
            ] : [
                'champ' => 'programme',
                'message' => "Programme « {$progCode} » introuvable.",
                'code' => 'PROGRAMME_NOT_FOUND',
            ];
        } elseif ($programmes->isEmpty()) {
            $erreurs[] = [
                'champ' => 'diplome',
                'message' => "Aucun programme rattaché au diplôme « {$dipCode} ».",
                'code' => 'DIPLOME_SANS_PROGRAMMES',
            ];
        } else {
            foreach ($programmes as $programme) {
                if (($message = $this->gardeSaturation($programme, $ligne['code'] ?? '')) !== null) {
                    $erreurs[] = [
                        'champ' => 'programme',
                        'message' => "Programme {$programme->code} : {$message}",
                        'code' => 'NIVEAU_PROGRAMME_SATURE',
                    ];
                }
            }
        }

        return $erreurs;
    }

    /**
     * INV-10 : le nombre de niveaux d'un programme ne dépasse pas la
     * durée du type de diplôme (données configurables, zéro code en dur).
     * Un programme existant échappe à la garde (idempotence d'import).
     */
    private function gardeSaturation(Programme $programme, string $codeNiveau): ?string
    {
        if (Niveau::where('programme_id', $programme->id)->where('code', $codeNiveau)->exists()) {
            return null;
        }

        $duree = $programme->typeDiplome?->duree_annees;

        if ($duree !== null && Niveau::where('programme_id', $programme->id)->count() >= $duree) {
            return "le diplôme prévoit {$duree} niveaux et la limite est atteinte.";
        }

        return null;
    }

    /**
     * @return Collection<int, Programme>|null — null si la RÉFÉRENCE
     *                                         (programme ou diplôme) est introuvable ; collection
     *                                         (éventuellement vide pour un diplôme sans programme)
     *                                         si la référence existe.
     */
    private function programmesCibles(?string $progCode, ?string $dipCode)
    {
        if (! empty($progCode)) {
            $programmes = Programme::where('code', mb_strtoupper(trim($progCode)))->get();

            return $programmes->isEmpty() ? null : $programmes;
        }

        if (! empty($dipCode)) {
            $dip = TypeDiplome::where('code', mb_strtoupper(trim($dipCode)))->first();

            return $dip !== null ? Programme::where('type_diplome_id', $dip->id)->get() : null;
        }

        return null;
    }

    public function cleMetier(array $ligne): ?string
    {
        $code = mb_strtoupper(trim((string) ($ligne['code'] ?? '')));
        $ref = mb_strtoupper(trim((string) ($ligne['programme'] ?? $ligne['diplome'] ?? '')));

        return "{$ref}|{$code}";
    }

    public function trouverExistant(array $ligne): ?object
    {
        $programmeCode = $ligne['programme'] ?? null;

        // Sans programme, `niveaux.code` n'identifie rien : retourner le
        // premier niveau venu ferait écraser celui d'un autre programme.
        if (empty($programmeCode)) {
            return null;
        }

        $programme = Programme::where('code', mb_strtoupper(trim((string) $programmeCode)))->first();

        if ($programme === null) {
            return null;
        }

        return Niveau::resoudreDansProgramme($ligne['code'] ?? null, $programme->id);
    }

    public function persister(array $ligne, string $mode, ?int $auteurId): string
    {
        $programmeCode = $ligne['programme'] ?? null;
        $diplomeCode = $ligne['diplome'] ?? null;

        // Audit 08/10/2026 : PLUS de repli « Programme::all() » — sans
        // cible explicite résolue, la ligne est refusée proprement.
        $programmes = $this->programmesCibles($programmeCode, $diplomeCode) ?? collect();

        if ($programmes->isEmpty()) {
            throw IoException::malforme("Aucun parcours/programme résolu pour rattacher le niveau « {$ligne['code']} » (ligne {$ligne['ligne']}) : programme ou diplôme introuvable.");
        }

        $donnees = [
            'nom' => $ligne['nom'] ?? $ligne['code'],
            'ordre' => ! empty($ligne['ordre']) ? (int) $ligne['ordre'] : 1,
        ];

        $totalImpactes = 0;
        foreach ($programmes as $p) {
            Niveau::updateOrCreate(
                ['code' => $ligne['code'], 'programme_id' => $p->id],
                ['nom' => $donnees['nom'], 'ordre' => $donnees['ordre'], 'actif' => true]
            );
            $totalImpactes++;
        }

        return "niveau {$ligne['code']} appliqué ({$totalImpactes} parcours)";
    }

    public function modes(): array
    {
        return ['creer', 'upsert'];
    }

    public function vider(?int $auteurId): void
    {
        // Jamais de vidage : semestres, groupes et inscriptions rattachés.
    }

    public function formats(): array
    {
        return ['csv', 'xlsx', 'json', 'pdf'];
    }

    public function requete(Request $request)
    {
        $query = Niveau::with('programme')->orderBy('ordre');

        if ($request->user()->faculteRestreinteId() !== null) {
            $query->whereHas('programme', fn ($q) => $q->where('faculte_id', $request->user()->faculteRestreinteId()));
        }

        return $query;
    }

    public function filtrer($query, Request $request)
    {
        if ($ids = $request->input('ids')) {
            $query->whereIn('niveaux.id', array_map('intval', (array) $ids));
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
            'nom' => $modele->nom,
            'programme' => $modele->programme?->code,
            'ordre' => $modele->ordre,
        ];
    }
}
