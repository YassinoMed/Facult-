<?php

namespace App\Services\DataExchange\Definitions;

use App\Models\GradeEnseignant;
use App\Models\User;
use App\Modules\Referentiel\Models\Departement;
use App\Modules\Schedule\Models\Teacher;
use App\Services\DataExchange\Contracts\ExportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportValidationAvancee;
use App\Services\Io\IoException;
use App\Support\Recherche;
use Illuminate\Http\Request;

/**
 * Définition enseignants (§5/§26) : identifiant = email, repli nom.
 */
class TeacherDefinition implements ExportDefinitionInterface, ImportDefinitionInterface, ImportValidationAvancee
{
    /**
     * Statuts administratifs (Phase B, option A : `actif` fait foi).
     * Normalisés insensible à la casse (SALIMA écrit ACTIF).
     */
    private const STATUTS_VALIDES = ['actif', 'inactif', 'suspendu', 'retraite'];

    public function entite(): string
    {
        return 'enseignants';
    }

    public function libelle(): string
    {
        return 'Enseignants';
    }

    public function colonnes(): array
    {
        return ['nom', 'prenom', 'email', 'matricule', 'grade', 'departement', 'bureau', 'faculte_code', 'statut'];
    }

    public function colonnesRequises(): array
    {
        return ['nom', 'prenom'];
    }

    public function aliasesColonnes(): array
    {
        return [
            'departement' => ['code_departement', 'departement_code', 'dep'],
            'faculte_code' => ['code_faculte', 'faculte', 'etablissement'],
            'grade' => ['grade_code', 'titre'],
            'matricule' => ['code_enseignant', 'code'],
        ];
    }

    public function regles(): array
    {
        return [
            'nom' => 'required|string|max:100',
            'prenom' => 'required|string|max:100',
            'email' => 'nullable|email|max:255',
            'matricule' => 'nullable|string|max:30',
            'grade' => 'nullable|string|max:50',
            'departement' => 'nullable|string|max:30',
            'bureau' => 'nullable|string|max:50',
            'faculte_code' => 'nullable|string|max:50',
            'statut' => 'nullable|string|max:50',
        ];
    }

    public function validerLigneMetier(array $ligne, ?int $auteurId): array
    {
        // Sans email, aucun rapprochement : deux homonymes ne doivent
        // jamais être fusionnés sur nom+prénom (fail-closed).
        if (empty($ligne['email']) && ! empty($ligne['nom']) && ! empty($ligne['prenom'])
            && Teacher::where('nom', $ligne['nom'])->where('prenom', $ligne['prenom'])->exists()) {
            return ['email requis : homonyme existant, rapprochement interdit sans email (ligne '.($ligne['ligne'] ?? '?').').'];
        }

        // Statut : normalisé insensible à la casse, refusé s'il est inconnu
        // (jamais ignoré silencieusement — Phase C).
        if (! empty($ligne['statut']) && $this->normaliserStatut($ligne['statut']) === null) {
            return ["statut « {$ligne['statut']} » inconnu (attendus : ".implode(', ', self::STATUTS_VALIDES).', ligne '.($ligne['ligne'] ?? '?').').'];
        }

        // Grade : format strict (la création reste possible mais explicite,
        // jamais silencieuse — voir persister()).
        if (! empty($ligne['grade']) && ! preg_match('/^[A-Za-z0-9_]{2,50}$/', trim((string) $ligne['grade']))) {
            return ["grade « {$ligne['grade']} » invalide (2 à 50 lettres/chiffres/_, ligne ".($ligne['ligne'] ?? '?').').'];
        }

        // Département : erreur dès la prévisualisation (parité preview/confirm).
        if (! empty($ligne['departement']) && $this->resoudreDepartement($ligne['departement']) === null) {
            return ["departement « {$ligne['departement']} » introuvable (ligne ".($ligne['ligne'] ?? '?').').'];
        }

        // Cohérence département × faculté quand les deux sont fournis.
        $incoherence = $this->verifierCoherenceFaculte($ligne);
        if ($incoherence !== null) {
            return [$incoherence];
        }

        // Conflit de matricule : même matricule, autre enseignant → refus
        // (jamais d'écrasement croisé — Phase C, M5).
        $conflit = $this->verifierConflitMatricule($ligne);
        if ($conflit !== null) {
            return [$conflit];
        }

        // Périmètre faculté de l'auteur (Phase B, option 2).
        $horsPerimetre = $this->verifierPerimetre($ligne, $auteurId);
        if ($horsPerimetre !== null) {
            return [$horsPerimetre];
        }

        return [];
    }

    public function cleMetier(array $ligne): ?string
    {
        return ! empty($ligne['email']) ? mb_strtolower(trim((string) $ligne['email'])) : null;
    }

    public function cleDoublon(): array
    {
        return ['email'];
    }

    public function trouverExistant(array $ligne): ?object
    {
        if (($ligne['email'] ?? null) !== null) {
            return Teacher::where('email', $ligne['email'])->first();
        }

        return Teacher::where('nom', $ligne['nom'])->where('prenom', $ligne['prenom'])->first();
    }

    public function persister(array $ligne, string $mode, ?int $auteurId): string
    {
        $existant = $this->trouverExistant($ligne);

        // Ceinture (défense en profondeur si la validation preview est
        // contournée) : sans email, l'existant ne peut provenir que du
        // repli nom+prénom → fusion interdite.
        if ($existant !== null && empty($ligne['email'])) {
            throw IoException::malforme(
                'email requis : homonyme existant, rapprochement interdit sans email (ligne '.($ligne['ligne'] ?? '?').').'
            );
        }

        // Ceintures Phase C : statut, conflit matricule, périmètre.
        $statut = $this->normaliserStatut($ligne['statut'] ?? null);
        if (! empty($ligne['statut']) && $statut === null) {
            throw IoException::malforme(
                "statut « {$ligne['statut']} » inconnu (ligne ".($ligne['ligne'] ?? '?').').'
            );
        }
        $conflit = $this->verifierConflitMatricule($ligne, $existant?->id);
        if ($conflit !== null) {
            throw IoException::malforme($conflit);
        }
        $horsPerimetre = $this->verifierPerimetre($ligne, $auteurId, $existant);
        if ($horsPerimetre !== null) {
            throw IoException::malforme($horsPerimetre);
        }

        // Références LMD résolues serveur (grade, département) — erreur
        // bloquante si inconnues (jamais d'approximation).
        [$gradeId, $gradeCree] = $this->resoudreGrade(
            $ligne['grade'] ?? null, $ligne['ligne'] ?? '?'
        );
        $departementId = $this->resoudreReference(
            Departement::class, $ligne['departement'] ?? null, 'departement', $ligne['ligne'] ?? '?'
        );

        // Cohérence département × faculté (redondante avec la validation :
        // anti-TOCTOU à la confirmation).
        $incoherence = $this->verifierCoherenceFaculte($ligne);
        if ($incoherence !== null) {
            throw IoException::malforme($incoherence);
        }

        $donnees = [
            'nom' => $ligne['nom'],
            'prenom' => $ligne['prenom'],
            'email' => $ligne['email'],
            'matricule' => $ligne['matricule'] ?? null,
            'grade_id' => $gradeId,
            'departement_id' => $departementId,
            'bureau' => $ligne['bureau'] ?? null,
        ];

        // Statut persisté + `actif` synchronisé (Phase B, option A).
        if ($statut !== null) {
            $donnees['statut'] = $statut;
            $donnees['actif'] = $statut === 'actif';
        } elseif ($existant === null) {
            $donnees['statut'] = 'actif';
            $donnees['actif'] = true;
        }

        $precisionGrade = $gradeCree !== null ? " (grade {$gradeCree} créé)" : '';

        if ($existant !== null) {
            $existant->update($donnees);

            return "mis à jour : {$ligne['nom']} {$ligne['prenom']}{$precisionGrade}";
        }

        Teacher::create($donnees + ['actif' => true]);

        return "créé : {$ligne['nom']} {$ligne['prenom']}{$precisionGrade}";
    }

    /**
     * Normalise un statut administratif (insensible à la casse).
     * NULL si absent ou inconnu (l'appelant distingue les deux cas).
     */
    private function normaliserStatut(mixed $valeur): ?string
    {
        if ($valeur === null || trim((string) $valeur) === '') {
            return null;
        }

        $code = mb_strtolower(trim((string) $valeur));

        return in_array($code, self::STATUTS_VALIDES, true) ? $code : null;
    }

    /**
     * Résout un département par code (insensible à la casse).
     */
    private function resoudreDepartement(mixed $valeur): ?Departement
    {
        if ($valeur === null || trim((string) $valeur) === '') {
            return null;
        }

        $code = mb_strtoupper(trim((string) $valeur));

        return Departement::whereRaw('UPPER(code) = ?', [$code])->first();
    }

    /**
     * Cohérence département × faculte_code quand les deux sont fournis.
     * NULL si cohérent ou non vérifiable.
     */
    private function verifierCoherenceFaculte(array $ligne): ?string
    {
        if (empty($ligne['departement']) || empty($ligne['faculte_code'])) {
            return null;
        }

        $departement = $this->resoudreDepartement($ligne['departement']);

        if ($departement === null) {
            return null;
        }

        $attendu = mb_strtoupper(trim((string) $ligne['faculte_code']));
        $reel = mb_strtoupper((string) ($departement->faculte?->code ?? ''));

        if ($reel === '' || $reel === $attendu) {
            return null;
        }

        return "faculte_code « {$ligne['faculte_code']} » incohérent avec le département « {$ligne['departement']} » (faculté {$reel}, ligne ".($ligne['ligne'] ?? '?').').';
    }

    /**
     * Conflit de matricule : même matricule détenu par un autre enseignant.
     * NULL si aucun conflit. $ignorerId = mise à jour légitime en cours.
     */
    private function verifierConflitMatricule(array $ligne, ?int $ignorerId = null): ?string
    {
        if (empty($ligne['matricule'])) {
            return null;
        }

        $conflit = Teacher::where('matricule', trim((string) $ligne['matricule']))
            ->when($ignorerId !== null, fn ($q) => $q->where('id', '!=', $ignorerId))
            ->exists();

        if (! $conflit) {
            return null;
        }

        // Mise à jour légitime : même email → même enseignant.
        if (! empty($ligne['email'])) {
            $trouve = Teacher::where('email', $ligne['email'])->first();
            if ($trouve !== null && $ignorerId !== null && $trouve->id === $ignorerId) {
                return null;
            }
            if ($trouve !== null && $ignorerId === null) {
                // En validation (sans existant résolu) : si l'email matche,
                // c'est une mise à jour — le persister tranche avec l'ID.
                return null;
            }
        }

        return "matricule « {$ligne['matricule']} » déjà utilisé par un autre enseignant (ligne ".($ligne['ligne'] ?? '?').').';
    }

    /**
     * Périmètre faculté de l'auteur (Phase B, option 2) : mise à jour
     * d'un enseignant hors périmètre ou création vers un département
     * étranger → refus. NULL si autorisé.
     */
    private function verifierPerimetre(array $ligne, ?int $auteurId, ?object $existant = null): ?string
    {
        if ($auteurId === null) {
            return null;
        }

        $auteur = User::find($auteurId);
        $restreinte = $auteur?->faculteRestreinteId();

        if ($restreinte === null) {
            return null;
        }

        $cible = $existant ?? $this->trouverExistant($ligne);

        if ($cible instanceof Teacher) {
            $faculteId = $cible->departement?->faculte_id;
            if ($faculteId !== null && (int) $faculteId !== $restreinte) {
                return 'enseignant hors de votre périmètre faculté (ligne '.($ligne['ligne'] ?? '?').').';
            }

            return null;
        }

        if (! empty($ligne['departement'])) {
            $departement = $this->resoudreDepartement($ligne['departement']);
            if ($departement !== null && (int) $departement->faculte_id !== $restreinte) {
                return "département « {$ligne['departement']} » hors de votre périmètre faculté (ligne ".($ligne['ligne'] ?? '?').').';
            }
        }

        return null;
    }

    /**
     * Résout un grade par code (insensible à la casse).
     * Création explicite si inconnu (format strict, signalée dans le
     * message — jamais silencieuse) ; erreur bloquante si format invalide.
     *
     * @return array{0: ?int, 1: ?string} [grade_id, code créé ou null]
     *
     * @throws IoException
     */
    private function resoudreGrade(mixed $valeur, mixed $ligneNo): array
    {
        if ($valeur === null || trim((string) $valeur) === '') {
            return [null, null];
        }

        $code = mb_strtoupper(trim((string) $valeur));

        if (! preg_match('/^[A-Z0-9_]{2,50}$/', $code)) {
            throw IoException::malforme("grade « {$valeur} » invalide (2 à 50 lettres/chiffres/_, ligne {$ligneNo}).");
        }

        $trouve = GradeEnseignant::where('code', $code)->first();

        if ($trouve !== null) {
            return [$trouve->id, null];
        }

        $nom = ucwords(str_replace('_', ' ', strtolower($code)));
        $cree = GradeEnseignant::create(['code' => $code, 'nom' => $nom, 'actif' => true]);

        return [$cree->id, $code];
    }

    /**
     * Résout un code de référentiel (grade, département).
     *
     * @throws IoException
     */
    private function resoudreReference(string $classe, mixed $valeur, string $champ, mixed $ligneNo): ?int
    {
        if ($valeur === null || trim((string) $valeur) === '') {
            return null;
        }

        $trouve = $classe::where('code', mb_strtoupper(trim((string) $valeur)))->first();

        if ($trouve === null) {
            throw IoException::malforme("{$champ} « {$valeur} » introuvable (ligne {$ligneNo}).");
        }

        return $trouve->id;
    }

    public function modes(): array
    {
        return ['creer', 'upsert'];
    }

    public function vider(?int $auteurId): void
    {
        // Jamais de vidage : référentiel partagé (remplacement interdit).
    }

    public function formats(): array
    {
        return ['csv', 'xlsx', 'json', 'pdf'];
    }

    public function requete(Request $request)
    {
        return Teacher::with(['grade', 'departement.faculte'])->orderBy('nom');
    }

    public function filtrer($query, Request $request)
    {
        // Phase B, option 2 : les exports/vues restreints au périmètre
        // (transversaux + sa faculté) — jamais d'exfiltration inter-facultés.
        $utilisateur = $request->user();
        $restreinte = $utilisateur?->faculteRestreinteId();

        if ($restreinte !== null) {
            $query->where(
                fn ($w) => $w->whereNull('teachers.departement_id')
                    ->orWhereHas('departement', fn ($d) => $d->where('faculte_id', $restreinte))
            );
        }

        if ($ids = $request->input('ids')) {
            $query->whereIn('id', array_map('intval', (array) $ids));
        }

        if ($search = trim($request->input('search', ''))) {
            $motif = Recherche::contient($search);
            $query->where(fn ($w) => $w
                ->where('nom', 'like', $motif)
                ->orWhere('prenom', 'like', $motif)
                ->orWhere('email', 'like', $motif));
        }

        return $query;
    }

    public function ligne($modele): array
    {
        return [
            'nom' => $modele->nom,
            'prenom' => $modele->prenom,
            'email' => $modele->email,
            'matricule' => $modele->matricule,
            'grade' => $modele->grade?->code,
            'departement' => $modele->departement?->code,
            'bureau' => $modele->bureau,
            'faculte_code' => $modele->departement?->faculte?->code,
            'statut' => $modele->statut,
        ];
    }
}
