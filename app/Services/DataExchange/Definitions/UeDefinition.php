<?php

namespace App\Services\DataExchange\Definitions;

use App\Models\User;
use App\Modules\Referentiel\Models\Maquette;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Services\DataExchange\Contracts\ExportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportValidationAvancee;
use App\Services\Io\IoException;
use App\Support\Recherche;
use Illuminate\Http\Request;

/**
 * Définition UE (§21-22) : identifiant = code + semestre (du programme).
 */
class UeDefinition implements ExportDefinitionInterface, ImportDefinitionInterface, ImportValidationAvancee
{
    public function entite(): string
    {
        return 'ues';
    }

    public function libelle(): string
    {
        return 'Unités d’enseignement';
    }

    public function colonnes(): array
    {
        return ['code', 'semestre', 'programme', 'faculte', 'nom', 'credits', 'coefficient', 'type', 'niveau'];
    }

    public function colonnesRequises(): array
    {
        return ['code', 'semestre', 'programme'];
    }

    public function aliasesColonnes(): array
    {
        return [
            'nom' => ['libelle', 'designation', 'intitule'],
            'programme' => ['parcours_code', 'code_parcours', 'parcours', 'code_programme'],
            'faculte' => ['code_faculte', 'faculte_code', 'etablissement'],
            'semestre' => ['semestre_code', 'code_semestre'],
            'niveau' => ['niveau_code', 'code_niveau'],
        ];
    }

    public function regles(): array
    {
        return [
            'code' => 'required|string|max:30',
            'semestre' => 'required|string|max:10',
            'programme' => 'required|string|max:30',
            'faculte' => 'nullable|string|max:20',
            'nom' => 'nullable|string|max:200',
            'credits' => 'nullable|integer|min:0|max:60',
            'coefficient' => 'nullable|numeric|min:0|max:99',
            'type' => 'nullable|string|max:50',
            'niveau' => 'nullable|string|max:20',
        ];
    }

    public function cleDoublon(): array
    {
        return ['code', 'semestre', 'programme'];
    }

    public function validerLigneMetier(array $ligne, ?int $auteurId): array
    {
        $erreurs = [];
        // LOOP A4 : résolution canonique (faculté explicite = levée
        // d'ambiguïté inter-facultés, sinon repli historique).
        $prog = Programme::resoudreParCode($ligne['programme'] ?? null, $ligne['faculte'] ?? null);
        if ($prog === null) {
            $erreurs[] = [
                'champ' => 'programme',
                'message' => 'Le parcours/programme « '.($ligne['programme'] ?? '').' » est introuvable'
                    .(! empty($ligne['faculte']) ? ' pour la faculté « '.($ligne['faculte'] ?? '').' »' : '').'.',
                'code' => 'PROGRAMME_NOT_FOUND',
            ];
        } elseif ($prog->statut === Programme::STATUT_ARCHIVE) {
            $erreurs[] = [
                'champ' => 'programme',
                'message' => 'Le parcours/programme « '.($ligne['programme'] ?? '').' » est archivé.',
                'code' => 'PROGRAMME_ARCHIVE',
            ];
        }

        // INV-8 (audit 08/10/2026) : une UE utilisée par une maquette
        // non-brouillon est verrouillée — miroir du CRUD web
        // (ReferentielController::ueVerrouillee) : l'import ne
        // contourne plus le verrou de plan d'études.
        $existante = $this->trouverExistant($ligne);

        if ($existante !== null && Maquette::whereHas('unitesEnseignement',
            fn ($q) => $q->where('unites_enseignement.id', $existante->id))
            ->whereNotIn('statut', ['draft'])
            ->exists()) {
            $erreurs[] = [
                'champ' => 'code',
                'message' => "UE « {$existante->code} » utilisée par une maquette validée : créez une nouvelle version de maquette.",
                'code' => 'UE_VERROUILLEE_MAQUETTE',
            ];
        }

        return $erreurs;
    }

    public function cleMetier(array $ligne): ?string
    {
        $code = mb_strtoupper(trim((string) ($ligne['code'] ?? '')));
        $prog = mb_strtoupper(trim((string) ($ligne['programme'] ?? '')));
        $sem = mb_strtoupper(trim((string) ($ligne['semestre'] ?? '')));
        $fac = mb_strtoupper(trim((string) ($ligne['faculte'] ?? '')));

        return "{$prog}|{$sem}|{$code}|{$fac}";
    }

    public function resoudreSemestre(string $programmeCode, string $semestreCode, ?string $faculteCode = null): ?SemestreLmd
    {
        $programme = Programme::resoudreParCode($programmeCode, $faculteCode);

        if ($programme === null) {
            return null;
        }

        $query = SemestreLmd::where('code', $semestreCode)
            ->whereHas('niveau', fn ($q) => $q->where('programme_id', $programme->id));

        return $query->first();
    }

    public function trouverExistant(array $ligne): ?object
    {
        $semestre = $this->resoudreSemestre($ligne['programme'] ?? '', $ligne['semestre'] ?? '', $ligne['faculte'] ?? null);

        if ($semestre === null) {
            return null;
        }

        return UniteEnseignement::where('code', $ligne['code'])
            ->where('semestre_id', $semestre->id)->first();
    }

    public function persister(array $ligne, string $mode, ?int $auteurId): string
    {
        $programme = Programme::resoudreParCode($ligne['programme'] ?? null, $ligne['faculte'] ?? null);

        if ($programme === null) {
            throw IoException::malforme("Programme « {$ligne['programme']} » introuvable (ligne {$ligne['ligne']}).");
        }

        $auteur = $auteurId !== null ? User::find($auteurId) : null;

        if ($auteur !== null && ! $auteur->peutAccederFaculte((int) $programme->faculte_id)) {
            throw IoException::malforme("Programme « {$ligne['programme']} » hors de votre périmètre (ligne {$ligne['ligne']}).");
        }

        $semestre = $this->resoudreSemestre($ligne['programme'], $ligne['semestre'], $ligne['faculte'] ?? null);

        if ($semestre === null) {
            throw IoException::malforme("Semestre « {$ligne['semestre']} » introuvable ou ambigu pour « {$ligne['programme']} » (ligne {$ligne['ligne']}).");
        }

        if ($programme->statut === Programme::STATUT_ARCHIVE) {
            throw IoException::malforme("Programme « {$ligne['programme']} » archivé (ligne {$ligne['ligne']}).");
        }

        $type = mb_strtolower(trim((string) ($ligne['type'] ?? 'fondamentale')));
        if (in_array($type, ['obligatoire', 'fondamentale', 'majeure'], true)) {
            $type = 'fondamentale';
        } elseif (in_array($type, ['optionnelle', 'mineure', 'libre'], true)) {
            $type = 'optionnelle';
        } elseif (in_array($type, ['transversale'], true)) {
            $type = 'transversale';
        } elseif (in_array($type, ['professionnelle', 'stage'], true)) {
            $type = 'professionnelle';
        } else {
            $type = 'fondamentale';
        }

        $existant = $this->trouverExistant($ligne);

        $donnees = [
            'nom' => $ligne['nom'] ?? $ligne['code'],
            'credits' => $ligne['credits'] !== null ? (int) $ligne['credits'] : 0,
            'coefficient' => $ligne['coefficient'] !== null ? (float) $ligne['coefficient'] : 1,
            'type' => $type,
        ];

        if ($existant !== null) {
            $existant->update($donnees);

            return "mise à jour : {$ligne['code']}";
        }

        UniteEnseignement::create([
            'code' => $ligne['code'],
            'semestre_id' => $semestre->id,
            'programme_id' => $programme->id,
            'actif' => true,
        ] + $donnees);

        return "créée : {$ligne['code']}";
    }

    public function modes(): array
    {
        return ['creer', 'upsert'];
    }

    public function vider(?int $auteurId): void
    {
        // Jamais de vidage : ECUE et maquettes rattachées.
    }

    public function formats(): array
    {
        return ['csv', 'xlsx', 'json', 'pdf'];
    }

    public function requete(Request $request)
    {
        $query = UniteEnseignement::with(['semestre', 'programme'])->orderBy('code');

        if ($request->user()->faculteRestreinteId() !== null) {
            $query->whereHas('programme', fn ($q) => $q->where('faculte_id', $request->user()->faculteRestreinteId()));
        }

        return $query;
    }

    public function filtrer($query, Request $request)
    {
        if ($ids = $request->input('ids')) {
            $query->whereIn('unites_enseignement.id', array_map('intval', (array) $ids));
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
            'semestre' => $modele->semestre?->code,
            'programme' => $modele->programme?->code,
            // LOOP A4 : la faculté rend l'export réimportable sans
            // ambiguïté inter-facultés (colonne importable nullable).
            'faculte' => $modele->programme?->faculte?->code,
            'nom' => $modele->nom,
            'credits' => $modele->credits,
            'coefficient' => $modele->coefficient,
            'type' => $modele->type,
        ];
    }
}
