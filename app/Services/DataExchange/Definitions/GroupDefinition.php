<?php

namespace App\Services\DataExchange\Definitions;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use App\Services\DataExchange\Contracts\ExportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportValidationAvancee;
use App\Services\Io\IoException;
use App\Support\Recherche;
use Illuminate\Http\Request;

/**
 * Définition groupes (§5/§26) : identifiant = code ; faculté résolue
 * par code (repli nom) ; périmètre faculté appliqué.
 */
class GroupDefinition implements ExportDefinitionInterface, ImportDefinitionInterface, ImportValidationAvancee
{
    public function entite(): string
    {
        return 'groupes';
    }

    public function libelle(): string
    {
        return 'Groupes';
    }

    public function colonnes(): array
    {
        return ['code', 'nom', 'faculte', 'niveau', 'filiere', 'programme', 'niveau_ref', 'annee', 'capacite'];
    }

    public function colonnesRequises(): array
    {
        return ['code'];
    }

    public function aliasesColonnes(): array
    {
        return [
            'nom' => ['libelle', 'designation', 'intitule'],
            'programme' => ['parcours_code', 'code_parcours', 'parcours', 'code_programme'],
            'niveau' => ['niveau_code', 'code_niveau'],
            'annee' => ['annee_code', 'code_annee', 'annee_universitaire'],
            'faculte' => ['faculte_code', 'code_faculte'],
        ];
    }

    public function regles(): array
    {
        return [
            'code' => 'required|string|max:20',
            'nom' => 'nullable|string|max:100',
            'faculte' => 'nullable|string|max:20',
            'niveau' => 'nullable|string|max:20',
            'filiere' => 'nullable|string|max:150',
            'programme' => 'nullable|string|max:30',
            'niveau_ref' => 'nullable|string|max:10',
            'annee' => 'nullable|string|max:20',
            'capacite' => 'nullable|integer|min:1|max:10000',
        ];
    }

    public function cleDoublon(): array
    {
        return ['code'];
    }

    public function validerLigneMetier(array $ligne, ?int $auteurId): array
    {
        return [];
    }

    public function cleMetier(array $ligne): ?string
    {
        return mb_strtoupper(trim((string) ($ligne['code'] ?? '')));
    }

    public function trouverExistant(array $ligne): ?object
    {
        return Group::where('code', $ligne['code'])->first();
    }

    public function resoudreFaculte(?string $valeur, ?string $programmeCode = null, ?int $auteurId = null): ?int
    {
        $code = mb_strtoupper(trim((string) $valeur));
        if ($code !== '') {
            return Faculte::where('code', $code)->first()?->id
                ?? Faculte::where('nom', trim((string) $valeur))->first()?->id;
        }

        if (! empty($programmeCode)) {
            $prog = Programme::where('code', mb_strtoupper(trim($programmeCode)))->first();
            if ($prog !== null) {
                return (int) $prog->faculte_id;
            }
        }

        if ($auteurId !== null) {
            $auteur = User::find($auteurId);
            if ($auteur?->faculteRestreinteId() !== null) {
                return $auteur->faculteRestreinteId();
            }
        }

        return Faculte::first()?->id;
    }

    /**
     * @param  array<string, mixed>  $ligne
     *
     * @throws IoException
     */
    private function resoudreProgramme(array $ligne, int $faculteId): ?int
    {
        if (($ligne['programme'] ?? null) === null || trim((string) $ligne['programme']) === '') {
            return null;
        }

        $programme = Programme::where('code', mb_strtoupper(trim((string) $ligne['programme'])))->first();

        if ($programme === null || (int) $programme->faculte_id !== $faculteId) {
            throw IoException::malforme("Programme « {$ligne['programme']} » introuvable pour cette faculté (ligne {$ligne['ligne']}).");
        }

        return $programme->id;
    }

    /**
     * @param  array<string, mixed>  $ligne
     *
     * @throws IoException
     */
    private function resoudreNiveau(array $ligne, ?int $programmeId): ?int
    {
        if (($ligne['niveau_ref'] ?? null) === null || trim((string) $ligne['niveau_ref']) === '') {
            return null;
        }

        $query = Niveau::where('code', mb_strtoupper(trim((string) $ligne['niveau_ref'])));

        if ($programmeId !== null) {
            $query->where('programme_id', $programmeId);
        }

        $niveau = $query->first();

        if ($niveau === null) {
            throw IoException::malforme("Niveau « {$ligne['niveau_ref']} » introuvable (ligne {$ligne['ligne']}).");
        }

        return $niveau->id;
    }

    /**
     * @param  array<string, mixed>  $ligne
     *
     * @throws IoException
     */
    private function resoudreAnnee(array $ligne): ?int
    {
        if (($ligne['annee'] ?? null) === null || trim((string) $ligne['annee']) === '') {
            return null;
        }

        $annee = AnneeUniversitaire::where('libelle', trim((string) $ligne['annee']))->first();

        if ($annee === null) {
            throw IoException::malforme("Année « {$ligne['annee']} » introuvable (ligne {$ligne['ligne']}).");
        }

        return $annee->id;
    }

    public function persister(array $ligne, string $mode, ?int $auteurId): string
    {
        $faculteId = $this->resoudreFaculte($ligne['faculte'] ?? null, $ligne['programme'] ?? null, $auteurId);

        if ($faculteId === null) {
            throw IoException::malforme("Faculté introuvable pour le groupe « {$ligne['code']} » (ligne {$ligne['ligne']}).");
        }

        $auteur = $auteurId !== null ? User::find($auteurId) : null;

        if ($auteur !== null && ! $auteur->peutAccederFaculte($faculteId)) {
            throw IoException::malforme("Groupe « {$ligne['code']} » hors de votre périmètre (ligne {$ligne['ligne']}).");
        }

        $existant = $this->trouverExistant($ligne);

        // Rattachement LMD + année + capacité (optionnels, résolus serveur).
        $programmeId = $this->resoudreProgramme($ligne, $faculteId);
        $niveauId = $this->resoudreNiveau($ligne, $programmeId);
        $anneeId = $this->resoudreAnnee($ligne);

        $donnees = [
            'nom' => $ligne['nom'] ?? $ligne['code'],
            'faculte_id' => $faculteId,
            'niveau' => $ligne['niveau'] ?? 'L1',
            'filiere' => $ligne['filiere'] ?? null,
            'programme_id' => $programmeId,
            'niveau_id' => $niveauId,
            'annee_universitaire_id' => $anneeId,
            'capacite' => ! empty($ligne['capacite']) ? (int) $ligne['capacite'] : 35,
        ];

        if ($existant !== null) {
            $existant->update($donnees);

            return "mis à jour : {$ligne['code']}";
        }

        Group::create(['code' => $ligne['code'], 'actif' => true] + $donnees);

        return "créé : {$ligne['code']}";
    }

    public function modes(): array
    {
        return ['creer', 'upsert'];
    }

    public function vider(?int $auteurId): void
    {
        // Jamais de vidage : étudiants et plannings rattachés.
    }

    public function formats(): array
    {
        return ['csv', 'xlsx', 'json', 'pdf'];
    }

    public function requete(Request $request)
    {
        $query = Group::with(['faculte', 'programme', 'niveauRef', 'anneeUniversitaire'])->orderBy('code');

        if ($request->user()->faculteRestreinteId() !== null) {
            $query->where('faculte_id', $request->user()->faculteRestreinteId());
        }

        return $query;
    }

    public function filtrer($query, Request $request)
    {
        if ($ids = $request->input('ids')) {
            $query->whereIn('groups.id', array_map('intval', (array) $ids));
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
            'faculte' => $modele->faculte?->code,
            'niveau' => $modele->niveau,
            'filiere' => $modele->filiere,
            'programme' => $modele->programme?->code,
            'niveau_ref' => $modele->niveauRef?->code,
            'annee' => $modele->anneeUniversitaire?->libelle,
            'capacite' => $modele->capacite,
        ];
    }
}
