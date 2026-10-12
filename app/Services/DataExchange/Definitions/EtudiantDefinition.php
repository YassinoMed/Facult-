<?php

namespace App\Services\DataExchange\Definitions;

use App\Models\DossierAcademique;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\MensReference;
use App\Models\User;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use App\Services\DataExchange\Contracts\ExportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportSimilitude;
use App\Services\DataExchange\Contracts\ImportValidationAvancee;
use App\Services\Io\IoException;
use App\Services\NormalisationService;
use App\Support\Recherche;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Import / Export unifié des étudiants (Lot 11, §19).
 *
 * Règles :
 * - Clé naturelle stable : CIN (si présent) ou email unique.
 * - Réconciliation MENS (§19) : si un enregistrement MENS existe, pas d'écrasement silencieux.
 * - Résolution stricte des relations (faculté, programme, niveau, groupe).
 * - Isolation par périmètre faculté (Faculty Scope).
 */
class EtudiantDefinition implements ExportDefinitionInterface, ImportDefinitionInterface, ImportSimilitude, ImportValidationAvancee
{
    public function entite(): string
    {
        return 'etudiants';
    }

    public function libelle(): string
    {
        return 'Étudiants';
    }

    public function colonnes(): array
    {
        return [
            'matricule', 'cin', 'nom', 'prenom', 'email', 'sexe', 'faculte', 'niveau',
            'annee_academique', 'date_naissance', 'telephone', 'adresse',
            'ville', 'code_postal', 'annee_bac', 'mention_bac', 'programme',
            'niveau_ref', 'groupe',
        ];
    }

    public function colonnesRequises(): array
    {
        return ['nom', 'prenom'];
    }

    public function aliasesColonnes(): array
    {
        return [
            'matricule' => ['code_etudiant', 'numero_etudiant', 'num_etudiant', 'identifiant'],
            'cin' => ['c.i.n', 'n°cin', 'num_cin', 'numero_cin', 'cin_passeport'],
            'nom' => ['nom_famille', 'last_name'],
            'prenom' => ['prenoms', 'first_name'],
            'email' => ['courriel', 'mail', 'adresse_email'],
            'sexe' => ['genre', 'gender'],
            'faculte' => ['code_faculte', 'etablissement', 'faculte_code'],
            'annee_academique' => ['annee', 'annee_universitaire', 'annee_code'],
            'date_naissance' => ['dob', 'naissance', 'date_de_naissance'],
            'telephone' => ['tel', 'mobile', 'portable'],
            'programme' => ['parcours', 'filiere', 'formation', 'parcours_code'],
            'niveau_ref' => ['code_niveau', 'niveau_lmd', 'niveau_code'],
            'groupe' => ['code_groupe', 'classe', 'groupe_code'],
        ];
    }

    public function regles(): array
    {
        return [
            'matricule' => ['nullable', 'string', 'max:30'],
            'cin' => ['nullable', 'string', 'max:30', NormalisationService::REGLE_CIN],
            'nom' => ['required', 'string', 'max:100'],
            'prenom' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'sexe' => ['nullable', 'in:M,F,m,f'],
            'faculte' => ['nullable', 'string', 'max:20'],
            'niveau' => ['nullable', 'string', 'max:20'],
            'annee_academique' => ['nullable', 'string', 'max:20'],
            'date_naissance' => ['nullable', 'date'],
            'telephone' => ['nullable', 'string', 'max:30'],
            'adresse' => ['nullable', 'string', 'max:500'],
            'ville' => ['nullable', 'string', 'max:100'],
            'code_postal' => ['nullable', 'string', 'max:20'],
            'annee_bac' => ['nullable'],
            'mention_bac' => ['nullable'],
            'programme' => ['nullable', 'string', 'max:30'],
            'niveau_ref' => ['nullable', 'string', 'max:10'],
            'groupe' => ['nullable', 'string', 'max:20'],
        ];
    }

    public function resoudreFaculte(?string $valeur, ?int $auteurId = null): ?Faculte
    {
        $code = mb_strtoupper(trim((string) $valeur));
        if ($code !== '') {
            return Faculte::where('code', $code)->first()
                ?? Faculte::where('nom', trim((string) $valeur))->first();
        }

        if ($auteurId !== null) {
            $auteur = User::find($auteurId);
            if ($auteur?->faculteRestreinteId() !== null) {
                return Faculte::find($auteur->faculteRestreinteId());
            }
        }

        return Faculte::first();
    }

    public function validerLigneMetier(array $ligne, ?int $auteurId): array
    {
        $erreurs = [];
        $faculte = $this->resoudreFaculte($ligne['faculte'] ?? null, $auteurId);

        if ($faculte === null) {
            $erreurs[] = [
                'champ' => 'faculte',
                'message' => 'Aucune faculté disponible pour rattacher les étudiants.',
                'code' => 'FACULTY_NOT_FOUND',
            ];

            return $erreurs;
        }

        if ($auteurId !== null) {
            $auteur = User::find($auteurId);
            $restreinteId = $auteur?->faculteRestreinteId();
            if ($restreinteId !== null && (int) $faculte->id !== $restreinteId) {
                $erreurs[] = [
                    'champ' => 'faculte',
                    'message' => 'La faculté « '.($ligne['faculte'] ?? '').' » est hors de votre périmètre autorisé.',
                    'code' => 'FACULTY_SCOPE_VIOLATION',
                ];
            }
        }

        // Vérification cohérence programme
        if (! empty($ligne['programme'])) {
            $programme = Programme::where('code', mb_strtoupper(trim((string) $ligne['programme'])))->first();
            if ($programme === null || (int) $programme->faculte_id !== (int) $faculte->id) {
                $erreurs[] = [
                    'champ' => 'programme',
                    'message' => "Le programme « {$ligne['programme']} » est introuvable pour la faculté {$faculte->code}.",
                    'code' => 'PROGRAMME_NOT_FOUND',
                ];
            }
        }

        // Vérification cohérence groupe
        if (! empty($ligne['groupe'])) {
            $groupe = Group::where('code', mb_strtoupper(trim((string) $ligne['groupe'])))->first();
            if ($groupe === null || (int) $groupe->faculte_id !== (int) $faculte->id) {
                $erreurs[] = [
                    'champ' => 'groupe',
                    'message' => "Le groupe « {$ligne['groupe']} » est introuvable pour la faculté {$faculte->code}.",
                    'code' => 'GROUP_NOT_FOUND',
                ];
            }
        }

        return $erreurs;
    }

    public function cleMetier(array $ligne): ?string
    {
        if (! empty($ligne['matricule'])) {
            return 'mat:'.mb_strtoupper(trim((string) $ligne['matricule']));
        }

        if (! empty($ligne['cin'])) {
            return 'cin:'.mb_strtoupper(trim((string) $ligne['cin']));
        }

        return 'email:'.mb_strtolower(trim((string) ($ligne['email'] ?? '')));
    }

    public function cleDoublon(): array
    {
        return ['cin'];
    }

    /**
     * PHASE 5 — candidats à la similarité nominative (revue seule,
     * jamais de fusion) : même naissance OU même nom (insensible
     * casse), requête BORNÉE à 20. Les homonymes vrais (CIN/naissance
     * distinctes) sont écartés par le détecteur, jamais fusionnés.
     *
     * @param  array<string, mixed>  $ligne
     * @return array<int, array{id: int, nom: string, prenom: string, naissance: ?string}>
     */
    public function candidatsSimilaires(array $ligne, int $limite = 20): array
    {
        $nom = trim((string) ($ligne['nom'] ?? ''));
        $naissance = trim((string) ($ligne['date_naissance'] ?? ''));

        if ($nom === '' && $naissance === '') {
            return [];
        }

        return Etudiant::query()
            ->when($naissance !== '', fn ($q) => $q->orWhereDate('date_naissance', $naissance))
            ->when($nom !== '', fn ($q) => $q->orWhereRaw('LOWER(nom) = ?', [mb_strtolower($nom)]))
            ->limit(max(1, $limite))
            ->get(['id', 'nom', 'prenom', 'date_naissance'])
            ->map(fn (Etudiant $e) => [
                'id' => $e->id,
                'nom' => $e->nom,
                'prenom' => $e->prenom,
                'naissance' => $e->date_naissance instanceof \DateTimeInterface
                    ? $e->date_naissance->format('Y-m-d')
                    : ($e->date_naissance !== null ? (string) $e->date_naissance : null),
            ])
            ->all();
    }

    public function trouverExistant(array $ligne): ?object
    {
        $matricule = ! empty($ligne['matricule']) ? trim((string) $ligne['matricule']) : null;
        $cin = ! empty($ligne['cin']) ? mb_strtoupper(trim((string) $ligne['cin'])) : null;
        $email = ! empty($ligne['email']) ? mb_strtolower(trim((string) $ligne['email'])) : null;

        // Fail-closed (PHASE 5) : sans aucun identifiant, AUCUNE fiche ne
        // correspond — sinon la requête sans contrainte retournait le
        // premier étudiant de la table et `persister()` l'écrasait.
        if ($matricule === null && $cin === null && $email === null) {
            return null;
        }

        return Etudiant::query()
            ->when($matricule !== null, fn ($q) => $q->where('num_inscription', $matricule))
            ->when($cin !== null, fn ($q) => $q->orWhere('cin', $cin))
            ->when($email !== null, fn ($q) => $q->orWhere('email', $email))
            ->first();
    }

    public function persister(array $ligne, string $mode, ?int $auteurId): string
    {
        $faculte = $this->resoudreFaculte($ligne['faculte'] ?? null, $auteurId);
        if ($faculte === null) {
            throw IoException::malforme("Aucune faculté disponible (ligne {$ligne['ligne']}).");
        }

        if ($auteurId !== null) {
            $auteur = User::find($auteurId);
            $restreinteId = $auteur?->faculteRestreinteId();
            if ($restreinteId !== null && (int) $faculte->id !== $restreinteId) {
                throw IoException::malforme('Faculté « '.($ligne['faculte'] ?? '')." » hors périmètre (ligne {$ligne['ligne']}).");
            }
        }

        // Résolution programme, niveau, groupe
        $programmeId = null;
        if (! empty($ligne['programme'])) {
            $p = Programme::where('code', mb_strtoupper(trim((string) $ligne['programme'])))->first();
            $programmeId = $p?->id;
        }

        $niveauId = null;
        if (! empty($ligne['niveau_ref'])) {
            $n = Niveau::where('code', mb_strtoupper(trim((string) $ligne['niveau_ref'])))
                ->when($programmeId !== null, fn ($q) => $q->where('programme_id', $programmeId))
                ->first();
            $niveauId = $n?->id;
        }

        $groupeId = null;
        if (! empty($ligne['groupe'])) {
            $g = Group::where('code', mb_strtoupper(trim((string) $ligne['groupe'])))->first();
            $groupeId = $g?->id;
        }

        $cin = ! empty($ligne['cin']) ? mb_strtoupper(trim((string) $ligne['cin'])) : null;
        $matricule = ! empty($ligne['matricule']) ? trim((string) $ligne['matricule']) : null;
        $norm = app(NormalisationService::class);
        $nom = $norm->nomPropre((string) $ligne['nom']) ?? trim((string) $ligne['nom']);
        $prenom = $norm->nomPropre((string) $ligne['prenom']) ?? trim((string) $ligne['prenom']);

        $existant = $this->trouverExistant($ligne);

        $email = ! empty($ligne['email']) ? mb_strtolower(trim((string) $ligne['email'])) : null;
        if ($email === null || $email === '') {
            if ($existant !== null) {
                // LOOP saisies : ré-import — l'email EXISTANT de
                // l'étudiant est PRÉSERVÉ (jamais réécrit au hasard par
                // une fabrication non déterministe, jamais effacé).
                $email = $existant->email;
            } else {
                // Création : email universitaire fabriqué de façon
                // DÉTERMINISTE (le dataset Salima n'a pas de colonne
                // email — comportement historique conservé, le
                // non-déterminisme rand() est supprimé : un même
                // fichier rejoué produit le même email).
                $slugPrenom = Str::slug($prenom);
                $slugNom = Str::slug($nom);
                $suffix = $cin !== null
                    ? substr($cin, -4)
                    : substr((string) ($ligne['matricule'] ?? $ligne['ligne'] ?? ''), -4);
                $email = "{$slugPrenom}.{$slugNom}{$suffix}@etudiant.univ.tn";
            }
        }

        $donnees = [
            'cin' => $cin,
            'nom' => $nom,
            'prenom' => $prenom,
            'email' => $email,
            'sexe' => ! empty($ligne['sexe']) ? mb_strtoupper(trim((string) $ligne['sexe'])) : 'M',
            'faculte_id' => $faculte->id,
            'programme_id' => $programmeId,
            'niveau_id' => $niveauId,
            'groupe_id' => $groupeId,
            'niveau' => $ligne['niveau'] ?? 'L1',
            'annee_academique' => $ligne['annee_academique'] ?? '2026-2027',
            'date_naissance' => ! empty($ligne['date_naissance']) ? $ligne['date_naissance'] : null,
            'telephone' => $ligne['telephone'] ?? null,
            'adresse' => $ligne['adresse'] ?? 'Non renseignée',
            'ville' => $ligne['ville'] ?? 'Non renseignée',
            'code_postal' => $ligne['code_postal'] ?? '0000',
            'annee_bac' => ! empty($ligne['annee_bac']) ? (int) $ligne['annee_bac'] : 2025,
            'mention_bac' => $ligne['mention_bac'] ?? null,
        ];

        if ($matricule !== null) {
            $donnees['num_inscription'] = $matricule;
        }

        // MENS réconciliation (§19) : si un enregistrement MENS existe avec des données divergentes, on audite
        if ($cin !== null) {
            $mens = MensReference::where('cin_normalise', MensReference::normaliserCin($cin))->orWhere('cin_original', $cin)->first();
            if ($mens !== null && $mens->matricule_national) {
                $donnees['identifiant_national'] = $mens->matricule_national;
            }
        }

        $etudiant = $existant ?? new Etudiant;
        $etudiant->fill(['cin' => $cin] + $donnees);
        if ($matricule !== null) {
            $etudiant->num_inscription = $matricule;
        }
        $etudiant->save();

        if ($matricule !== null) {
            DossierAcademique::updateOrCreate(
                ['etudiant_id' => $etudiant->id],
                ['matricule' => $matricule, 'date_archivage' => now(), 'statut' => 'actif']
            );
        }

        if ($existant !== null) {
            return "mise à jour : {$nom} {$prenom} (".($cin ?? $email).')';
        }

        return "créé : {$nom} {$prenom} (".($cin ?? $email).')';
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
        return Etudiant::with(['faculte', 'programme', 'groupe'])->orderBy('nom')->orderBy('prenom');
    }

    public function filtrer($query, Request $request)
    {
        if ($auteur = $request->user()) {
            if ($restreinteId = $auteur->faculteRestreinteId()) {
                $query->where('faculte_id', $restreinteId);
            }
        }

        if ($search = trim((string) $request->input('search', ''))) {
            $motif = Recherche::contient($search);
            $query->where(fn ($q) => $q->where('nom', 'like', $motif)
                ->orWhere('prenom', 'like', $motif)
                ->orWhere('cin', 'like', $motif)
                ->orWhere('email', 'like', $motif)
            );
        }

        return $query;
    }

    public function colonnesAutorisees(array $demandees, bool $estAdmin = false): array
    {
        $sensibles = ['cin', 'telephone', 'date_naissance', 'adresse'];
        if (! $estAdmin) {
            $demandees = array_diff($demandees, $sensibles);
        }

        return array_values(array_intersect($this->colonnes(), $demandees));
    }

    public function ligne($modele): array
    {
        return [
            'cin' => $modele->cin,
            'nom' => $modele->nom,
            'prenom' => $modele->prenom,
            'email' => $modele->email,
            'sexe' => $modele->sexe,
            'faculte' => $modele->faculte?->code ?? '',
            'niveau' => $modele->niveau,
            'annee_academique' => $modele->annee_academique,
            'date_naissance' => $modele->date_naissance?->format('Y-m-d'),
            'telephone' => $modele->telephone,
            'adresse' => $modele->adresse,
            'ville' => $modele->ville,
            'code_postal' => $modele->code_postal,
            'annee_bac' => (string) $modele->annee_bac,
            'mention_bac' => $modele->mention_bac,
            'programme' => $modele->programme?->code ?? '',
            'niveau_ref' => $modele->niveauRelation?->code ?? '',
            'groupe' => $modele->groupe?->code ?? '',
        ];
    }
}
