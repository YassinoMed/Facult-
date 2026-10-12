<?php

namespace App\Services\DataExchange\Definitions;

use App\Models\Faculte;
use App\Models\User;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\Specialite;
use App\Modules\Referentiel\Models\TypeDiplome;
use App\Services\DataExchange\Contracts\ExportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportValidationAvancee;
use App\Services\Io\IoException;
use App\Support\Recherche;
use Illuminate\Http\Request;

/**
 * Définition programmes (§14) : identifiant = code (contexte faculté).
 * Jamais de vidage : niveaux, maquettes et inscriptions rattachés.
 */
class ProgrammeDefinition implements ExportDefinitionInterface, ImportDefinitionInterface, ImportValidationAvancee
{
    public function entite(): string
    {
        return 'programmes';
    }

    public function libelle(): string
    {
        return 'Programmes / Parcours';
    }

    public function colonnes(): array
    {
        return ['code', 'nom', 'faculte', 'specialite', 'diplome', 'duree_annees', 'semestres_nombre', 'credits_requis', 'statut', 'capacite'];
    }

    public function colonnesRequises(): array
    {
        return ['code', 'nom'];
    }

    public function aliasesColonnes(): array
    {
        return [
            'nom' => ['libelle', 'designation', 'intitule'],
            'faculte' => ['code_faculte', 'faculte_code', 'etablissement'],
            'specialite' => ['specialite_code', 'code_specialite'],
            'diplome' => ['diplome_code', 'code_diplome', 'type_diplome'],
            'duree_annees' => ['duree'],
            'semestres_nombre' => ['semestres', 'nb_semestres'],
            'credits_requis' => ['credits', 'credits_total'],
        ];
    }

    public function regles(): array
    {
        return [
            'code' => 'required|string|max:30',
            'nom' => 'required|string|max:200',
            'faculte' => 'nullable|string|max:20',
            'specialite' => 'nullable|string|max:30',
            'diplome' => 'nullable|string|max:30',
            'duree_annees' => 'nullable|integer|min:1|max:12',
            'semestres_nombre' => 'nullable|integer|min:1|max:24',
            'credits_requis' => 'nullable|integer|min:0|max:1000',
            'statut' => 'nullable|string|max:20',
            'capacite' => 'nullable|integer|min:0|max:65535',
        ];
    }

    public function cleDoublon(): array
    {
        return ['code'];
    }

    public function resoudreFaculte(?string $valeur, ?string $specialiteCode, ?int $auteurId = null): ?Faculte
    {
        $code = mb_strtoupper(trim((string) $valeur));
        if ($code !== '') {
            return Faculte::where('code', $code)->first()
                ?? Faculte::where('nom', trim((string) $valeur))->first();
        }

        if (! empty($specialiteCode)) {
            $spec = Specialite::with('mention.domaine')->where('code', mb_strtoupper(trim((string) $specialiteCode)))->first();
            if ($spec?->mention?->domaine?->faculte !== null) {
                return $spec->mention->domaine->faculte;
            }
        }

        if ($auteurId !== null) {
            $auteur = User::find($auteurId);
            if ($auteur?->faculteRestreinteId() !== null) {
                return Faculte::find($auteur->faculteRestreinteId());
            }
        }

        return Faculte::count() === 1 ? Faculte::first() : Faculte::first();
    }

    public function validerLigneMetier(array $ligne, ?int $auteurId): array
    {
        $erreurs = [];
        $faculte = $this->resoudreFaculte($ligne['faculte'] ?? null, $ligne['specialite'] ?? null, $auteurId);

        if ($faculte === null) {
            $erreurs[] = [
                'champ' => 'faculte',
                'message' => 'Faculté introuvable pour le parcours « '.($ligne['code'] ?? '').' ».',
                'code' => 'FACULTY_NOT_FOUND',
            ];
        }

        $statut = mb_strtolower(trim((string) ($ligne['statut'] ?? '')));

        if ($statut !== '' && ! in_array($statut, Programme::STATUTS, true)) {
            $erreurs[] = [
                'champ' => 'statut',
                'message' => 'Statut « '.($ligne['statut'] ?? '').' » inconnu (attendu : '.implode(', ', Programme::STATUTS).').',
                'code' => 'PROGRAMME_STATUT_INVALIDE',
            ];
        }

        // LOOP A2 : référence explicite introuvable = erreur bloquante
        // (fail-closed) — jamais d'ignorance silencieuse d'une faute de
        // frappe ; colonne absente = repli null historique, inchangé.
        if (! empty($ligne['specialite'])
            && Specialite::where('code', mb_strtoupper(trim((string) $ligne['specialite'])))->doesntExist()) {
            $erreurs[] = [
                'champ' => 'specialite',
                'message' => 'Spécialité « '.($ligne['specialite'] ?? '').' » introuvable.',
                'code' => 'SPECIALITE_INTROUVABLE',
            ];
        }

        if (! empty($ligne['diplome'])
            && TypeDiplome::where('code', mb_strtoupper(trim((string) $ligne['diplome'])))->doesntExist()) {
            $erreurs[] = [
                'champ' => 'diplome',
                'message' => 'Type de diplôme « '.($ligne['diplome'] ?? '').' » introuvable.',
                'code' => 'DIPLOME_INTROUVABLE',
            ];
        }

        return $erreurs;
    }

    public function cleMetier(array $ligne): ?string
    {
        // Clé intra-lot : code + faculté explicite (deux lignes au même
        // code dans deux facultés distinctes sont deux programmes, pas
        // un doublon — unicité réelle = faculte+code).
        return mb_strtoupper(trim((string) ($ligne['code'] ?? '')))
            .'|'.mb_strtoupper(trim((string) ($ligne['faculte'] ?? '')));
    }

    public function trouverExistant(array $ligne): ?object
    {
        // LOOP 10 §10.4 : recherche scopée quand la ligne affirme sa
        // faculté — jamais de rattachement au programme homonyme d'une
        // autre faculté (repli global historique sinon, inchangé).
        if (! empty($ligne['faculte'])) {
            $faculte = Faculte::where('code', mb_strtoupper(trim((string) $ligne['faculte'])))->first()
                ?? Faculte::where('nom', trim((string) $ligne['faculte']))->first();

            if ($faculte !== null) {
                return Programme::where('code', $ligne['code'])
                    ->where('faculte_id', $faculte->id)
                    ->first();
            }
        }

        return Programme::where('code', $ligne['code'])->first();
    }

    public function persister(array $ligne, string $mode, ?int $auteurId): string
    {
        $specialiteCode = $ligne['specialite'] ?? null;
        $diplomeCode = $ligne['diplome'] ?? null;

        $faculte = $this->resoudreFaculte($ligne['faculte'] ?? null, $specialiteCode, $auteurId);

        if ($faculte === null) {
            throw IoException::malforme("Faculté introuvable pour le parcours « {$ligne['code']} » (ligne {$ligne['ligne']}).");
        }

        $auteur = $auteurId !== null ? User::find($auteurId) : null;
        if ($auteur !== null && ! $auteur->peutAccederFaculte($faculte->id)) {
            throw IoException::malforme("Faculté « {$faculte->code} » hors de votre périmètre (ligne {$ligne['ligne']}).");
        }

        $specialiteId = null;
        if (! empty($specialiteCode)) {
            $spec = Specialite::with('mention.domaine')->where('code', mb_strtoupper(trim((string) $specialiteCode)))->first();

            // LOOP A2 : miroir anti-TOCTOU de validerLigneMetier (une
            // spécialité supprimée entre aperçu et confirmation refuse
            // au lieu d'être ignorée silencieusement).
            if ($spec === null) {
                throw IoException::malforme("Spécialité « {$specialiteCode} » introuvable (ligne {$ligne['ligne']}).");
            }

            $specialiteId = $spec->id;

            // Invariant §9 : spécialité de la même faculté que le programme —
            // contrôlé uniquement quand la ligne affirme EXPLICITEMENT les
            // deux (sinon la faculté dérive de la spécialité elle-même,
            // cohérente par construction).
            if ($spec !== null && ! empty($ligne['faculte'])
                && (int) $spec->mention->domaine->faculte_id !== (int) $faculte->id) {
                throw IoException::malforme("Spécialité « {$specialiteCode} » d'une autre faculté que « {$faculte->code} » (ligne {$ligne['ligne']}).");
            }
        }

        $diplomeId = null;
        if (! empty($diplomeCode)) {
            $dip = TypeDiplome::where('code', mb_strtoupper(trim((string) $diplomeCode)))->first();

            if ($dip === null) {
                throw IoException::malforme("Type de diplôme « {$diplomeCode} » introuvable (ligne {$ligne['ligne']}).");
            }

            $diplomeId = $dip->id;
        }

        $existant = Programme::where('code', $ligne['code'])
            ->where('faculte_id', $faculte->id)
            ->first();

        $donnees = [
            'nom' => $ligne['nom'],
            'faculte_id' => $faculte->id,
            'specialite_id' => $specialiteId,
            'type_diplome_id' => $diplomeId,
            'duree_annees' => ! empty($ligne['duree_annees']) ? (int) $ligne['duree_annees'] : 3,
            'semestres_nombre' => ! empty($ligne['semestres_nombre']) ? (int) $ligne['semestres_nombre'] : 6,
            'credits_requis' => ! empty($ligne['credits_requis']) ? (int) $ligne['credits_requis'] : 180,
        ];

        // Statut/capacité : appliqués uniquement si la colonne est
        // fournie (jamais d'écrasement par réimport sans la colonne).
        // `actif` est re-synchronisé depuis `statut` par le modèle.
        if (! empty($ligne['statut'])) {
            $donnees['statut'] = mb_strtolower(trim((string) $ligne['statut']));
        }

        if (! empty($ligne['capacite'])) {
            $donnees['capacite'] = (int) $ligne['capacite'];
        }

        if ($existant !== null) {
            $existant->update($donnees);

            return "mis à jour : {$ligne['code']}";
        }

        Programme::create(['code' => $ligne['code'], 'actif' => true] + $donnees);

        return "créé : {$ligne['code']}";
    }

    public function modes(): array
    {
        return ['creer', 'upsert'];
    }

    public function vider(?int $auteurId): void
    {
        // Jamais de vidage : niveaux, maquettes et inscriptions rattachés.
    }

    public function formats(): array
    {
        return ['csv', 'xlsx', 'json', 'pdf'];
    }

    public function requete(Request $request)
    {
        $query = Programme::with('faculte')->orderBy('code');

        if ($request->user()->faculteRestreinteId() !== null) {
            $query->where('faculte_id', $request->user()->faculteRestreinteId());
        }

        return $query;
    }

    public function filtrer($query, Request $request)
    {
        if ($ids = $request->input('ids')) {
            $query->whereIn('programmes.id', array_map('intval', (array) $ids));
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
            'duree_annees' => $modele->duree_annees,
            'semestres_nombre' => $modele->semestres_nombre,
            'credits_requis' => $modele->credits_requis,
            'statut' => $modele->statut,
            'capacite' => $modele->capacite,
        ];
    }
}
