<?php

namespace App\Services\Exports;

use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Models\User;
use App\Modules\Enseignement\Models\Enseignement;
use App\Services\Io\Exports\ExportColumnPolicy;
use App\Support\Recherche;
use Illuminate\Database\Eloquent\Builder;

/**
 * Moteur central de listes étudiants (§38) : UNE requête scopée +
 * filtres combinés (intersection stricte), UNE population pour la
 * prévisualisation ET tous les formats (PDF/XLSX/CSV/print).
 * Colonnes via ExportColumnPolicy (jamais de sensible sans permission).
 */
class ListeEtudiantsService
{
    public const FILTRES = [
        'annee_universitaire_id', 'programme_id', 'niveau', 'semestre',
        'groupe_id', 'sous_groupe_id', 'ue_id', 'matiere_id', 'enseignant_id', 'statut',
        'sans_groupe', 'search', 'depose_depuis',
    ];

    public const TRIS = ['nom', 'prenom', 'num_inscription', 'groupe', 'niveau', 'programme', 'anciennete', 'created_at'];

    /**
     * Regroupements PDF/listes (§2.1-2.2) : clés génériques résolues sur les
     * relations chargées — jamais de `if group/if program` dans les exports.
     * `parcours` = alias de `programme` ; `module`/`ecue` = alias de `matiere`.
     */
    public const REGROUPEMENTS = [
        'programme', 'niveau', 'groupe', 'sous_groupe', 'statut',
        'faculte', 'annee', 'semestre', 'matiere',
    ];

    public const ALIAS_REGROUPEMENTS = [
        'parcours' => 'programme',
        'module' => 'matiere',
        'ecue' => 'matiere',
    ];

    /**
     * @param  array<string, mixed>  $filtres
     */
    public function requete(array $filtres, ?User $utilisateur): Builder
    {
        $query = Etudiant::with(['faculte', 'groupe', 'programmeRef', 'affectations.sousGroupe']);

        // Regroupement semestre/matière : relations pédagogiques chargées
        // en une fois (pas de N+1 dans cleRegroupement).
        $regroupement = self::normaliserRegroupement($filtres['regroupement'] ?? null);

        if ($regroupement === 'semestre') {
            $query->with(['inscriptionsAcademiques.semestres']);
        }

        if ($regroupement === 'matiere') {
            $query->with(['inscriptionsAcademiques.pedagogiques.matiere']);
        }

        // Scope serveur : périmètre faculté, jamais un paramètre client.
        $faculteId = $utilisateur?->faculteRestreinteId()
            ?? (isset($filtres['faculte_id']) ? (int) $filtres['faculte_id'] : null);

        // Le filtre faculté s'applique à tous les profils : pour un compte
        // restreint la valeur est forcée côté contrôleur, pour un compte
        // central elle reste un simple filtre (sous-ensemble de son accès).
        if ($faculteId !== null) {
            $query->where('faculte_id', $faculteId);
        }

        if (! empty($filtres['annee_universitaire_id'])) {
            $anneeId = (int) $filtres['annee_universitaire_id'];
            $query->whereHas('inscriptionsAcademiques', fn ($q) => $q->where('annee_universitaire_id', $anneeId));
        }

        if (! empty($filtres['programme_id'])) {
            $query->where('programme_id', (int) $filtres['programme_id']);
        }

        if (! empty($filtres['niveau'])) {
            $query->where('niveau', $filtres['niveau']);
        }

        if (! empty($filtres['groupe_id'])) {
            $query->where('groupe_id', (int) $filtres['groupe_id']);
        }

        if (! empty($filtres['sous_groupe_id'])) {
            $sousGroupeId = (int) $filtres['sous_groupe_id'];
            $query->whereHas('affectations', fn ($q) => $q->where('statut', 'active')->where('sous_groupe_id', $sousGroupeId));
        }

        if (! empty($filtres['sans_groupe'])) {
            $query->whereNull('groupe_id');
        }

        // Ancienneté de dépôt (LOOP 18) : dossiers déposés depuis au moins
        // N jours (entier positif) — file de traitement, dossiers qui
        // « traînent ». Additif : ignoré par les exports sauf si transmis.
        if (! empty($filtres['depose_depuis'])) {
            $jours = max(1, (int) $filtres['depose_depuis']);
            $query->where('created_at', '<=', now()->subDays($jours));
        }

        if (! empty($filtres['statut'])) {
            $query->where('statut', $filtres['statut']);
        }

        if (! empty($filtres['ue_id'])) {
            $ueId = (int) $filtres['ue_id'];
            $query->whereHas('inscriptionsAcademiques.pedagogiques', fn ($q) => $q->where('ue_id', $ueId));
        }

        if (! empty($filtres['matiere_id'])) {
            $matiereId = (int) $filtres['matiere_id'];
            $query->whereHas('inscriptionsAcademiques.pedagogiques', fn ($q) => $q->where('matiere_id', $matiereId));
        }

        if (! empty($filtres['semestre'])) {
            $query->whereHas('inscriptionsAcademiques.semestres', fn ($q) => $q->where('numero', (int) $filtres['semestre']));
        }

        if (! empty($filtres['enseignant_id'])) {
            $matieres = Enseignement::whereHas(
                'enseignants', fn ($q) => $q->where('teachers.id', (int) $filtres['enseignant_id'])
            )->pluck('matiere_id')->all();
            $query->whereHas('inscriptionsAcademiques.pedagogiques', fn ($q) => $q->whereIn('matiere_id', $matieres));
        }

        if (! empty($filtres['search'])) {
            $motif = Recherche::contient(trim((string) $filtres['search']));
            $query->where(fn ($w) => $w
                ->where('nom', 'like', $motif)->orWhere('prenom', 'like', $motif)
                ->orWhere('cin', 'like', $motif)->orWhere('num_inscription', 'like', $motif)
                ->orWhere('email', 'like', $motif)->orWhere('telephone', 'like', $motif));
        }

        $tri = in_array($filtres['tri'] ?? '', self::TRIS, true) ? $filtres['tri'] : 'nom';
        $sens = ($filtres['sens'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        if ($tri === 'groupe') {
            $query->leftJoin('groups', 'groups.id', '=', 'etudiants.groupe_id')
                ->orderBy('groups.code', $sens)->select('etudiants.*');
        } elseif ($tri === 'programme') {
            $query->leftJoin('programmes', 'programmes.id', '=', 'etudiants.programme_id')
                ->orderBy('programmes.code', $sens)->select('etudiants.*');
        } elseif ($tri === 'anciennete' || $tri === 'created_at') {
            $query->orderBy('etudiants.created_at', $sens);
        } else {
            $query->orderBy($tri === 'num_inscription' ? 'num_inscription' : $tri, $sens);
        }

        if ($tri === 'nom') {
            $query->orderBy('prenom', $sens);
        }

        return $query;
    }

    /**
     * @return array{entetes: array<int, string>, lignes: array<int, array<string, mixed>>}
     */
    public function jeuDonnees(Builder $query, ?User $utilisateur, ?array $colonnes = null): array
    {
        $catalogue = array_merge(['numero'], ExportColumnPolicy::catalogue('students'));
        $personnelAutorise = $utilisateur !== null && $utilisateur->hasPermission('exports.personal');

        $retenues = $colonnes === null
            ? $catalogue
            : array_values(array_intersect($catalogue, $colonnes));

        if ($retenues === []) {
            $retenues = $catalogue;
        }

        if (! $personnelAutorise) {
            // CIN conservée mais masquée (masquerCin) ; email/téléphone retirés.
            $retenues = array_values(array_diff($retenues, ['email', 'telephone']));
        }

        // Permission calculée UNE fois (jamais de hasPermission() par ligne).
        $utilisateurCin = $personnelAutorise ? $utilisateur : null;

        $lignes = [];
        $index = 0;

        // chunk() et non cursor() : cursor() ignore les with() (lazy N+1),
        // chunk() applique l'eager loading par lot de 1000.
        $query->with(['inscriptionsAcademiques.anneeUniversitaire'])->chunk(1000, function ($etudiants) use ($retenues, $utilisateurCin, &$lignes, &$index) {
            foreach ($etudiants as $etudiant) {
                $index++;
                $lignes[] = $this->lignePour($etudiant, $retenues, $index, $utilisateurCin);
            }
        });

        return ['entetes' => $retenues, 'lignes' => $lignes];
    }

    /**
     * Sections regroupées (§2.1-2.2) : UN seul curseur sur la MÊME query,
     * lignes via lignePour (même politique de colonnes), sections triées
     * par clé. Numérotation continue + effectif par section.
     *
     * @return array{entetes: array<int, string>, total: int, sections: array<int, array{titre: string, effectif: int, lignes: array<int, array<string, mixed>>}>}
     */
    public function sectionsRegroupees(Builder $query, ?User $utilisateur, ?array $colonnes, string $regroupement): array
    {
        $cle = self::normaliserRegroupement($regroupement) ?? 'groupe';

        $catalogue = array_merge(['numero'], ExportColumnPolicy::catalogue('students'));
        $retenues = $colonnes === null ? $catalogue : array_values(array_intersect($catalogue, $colonnes));

        if ($retenues === []) {
            $retenues = $catalogue;
        }

        $personnelAutorise = $utilisateur !== null && $utilisateur->hasPermission('exports.personal');

        if (! $personnelAutorise) {
            $retenues = array_values(array_diff($retenues, ['email', 'telephone']));
        }

        $utilisateurCin = $personnelAutorise ? $utilisateur : null;

        $paquets = [];
        $index = 0;

        // chunk() : eager loading réel (cursor() = lazy N+1).
        $query->with(['inscriptionsAcademiques.anneeUniversitaire'])->chunk(1000, function ($etudiants) use ($cle, $retenues, $utilisateurCin, &$paquets, &$index) {
            foreach ($etudiants as $etudiant) {
                $index++;
                $titre = $this->cleRegroupement($etudiant, $cle);
                $paquets[$titre][] = $this->lignePour($etudiant, $retenues, $index, $utilisateurCin);
            }
        });

        ksort($paquets, SORT_NATURAL | SORT_FLAG_CASE);

        $sections = [];

        foreach ($paquets as $titre => $lignes) {
            $sections[] = ['titre' => $titre, 'effectif' => count($lignes), 'lignes' => $lignes];
        }

        return ['entetes' => $retenues, 'total' => $index, 'sections' => $sections];
    }

    public static function normaliserRegroupement(mixed $valeur): ?string
    {
        if (! is_string($valeur) || trim($valeur) === '') {
            return null;
        }

        $cle = mb_strtolower(trim($valeur));
        $cle = self::ALIAS_REGROUPEMENTS[$cle] ?? $cle;

        return in_array($cle, self::REGROUPEMENTS, true) ? $cle : null;
    }

    public static function libelleRegroupement(string $regroupement): string
    {
        return match (self::normaliserRegroupement($regroupement) ?? 'groupe') {
            'programme' => 'Programme',
            'niveau' => 'Niveau',
            'groupe' => 'Groupe',
            'sous_groupe' => 'Sous-groupe',
            'statut' => 'Statut',
            'faculte' => 'Faculté',
            'annee' => 'Année',
            'semestre' => 'Semestre',
            'matiere' => 'ECUE / Module',
        };
    }

    public function cleRegroupement(Etudiant $etudiant, string $regroupement): string
    {
        $cle = self::normaliserRegroupement($regroupement) ?? 'groupe';

        return match ($cle) {
            'programme' => $etudiant->programmeRef?->code ?? $etudiant->programmeRef?->nom ?? 'Sans programme',
            'niveau' => $etudiant->niveau ?? 'Sans niveau',
            'groupe' => $etudiant->groupe?->code ?? 'Sans groupe',
            'sous_groupe' => ($etudiant->relationLoaded('affectations')
                ? $etudiant->affectations->firstWhere('statut', 'active')
                : $etudiant->affectations()->where('statut', 'active')->first())?->sousGroupe?->code ?? 'Sans sous-groupe',
            'statut' => $etudiant->statut ?? 'Sans statut',
            'faculte' => $etudiant->faculte?->code ?? 'Sans faculté',
            'annee' => $etudiant->annee_academique ?? 'Sans année',
            'semestre' => $this->cleSemestre($etudiant),
            'matiere' => $this->cleMatiere($etudiant),
        };
    }

    private function cleSemestre(Etudiant $etudiant): string
    {
        $numeros = $etudiant->relationLoaded('inscriptionsAcademiques')
            ? $etudiant->inscriptionsAcademiques->flatMap(fn ($i) => $i->relationLoaded('semestres') ? $i->semestres->pluck('numero') : [])
            : $etudiant->inscriptionsAcademiques()->with('semestres')->get()->flatMap(fn ($i) => $i->semestres->pluck('numero'));

        $numero = $numeros->filter()->sort()->first();

        return $numero !== null ? "Semestre {$numero}" : 'Sans semestre';
    }

    private function cleMatiere(Etudiant $etudiant): string
    {
        $codes = $etudiant->relationLoaded('inscriptionsAcademiques')
            ? $etudiant->inscriptionsAcademiques->flatMap(fn ($i) => $i->relationLoaded('pedagogiques') ? $i->pedagogiques->map(fn ($p) => $p->matiere?->code) : [])
            : $etudiant->inscriptionsAcademiques()->with('pedagogiques.matiere')->get()->flatMap(fn ($i) => $i->pedagogiques->map(fn ($p) => $p->matiere?->code));

        $codes = $codes->filter()->unique()->sort()->values();

        return $codes->isNotEmpty() ? $codes->implode(', ') : 'Sans ECUE';
    }

    /**
     * PHASE 7 — inscription source des résultats affichés : celle de
     * l'année du dossier, sinon la plus récente. Lecture seule sur la
     * source officielle (`inscriptions_academiques`, écrite par les
     * délibérations) ; sans inscription : colonnes vides, jamais inventées.
     */
    private function inscriptionAffichee(Etudiant $etudiant): ?InscriptionAcademique
    {
        $inscriptions = $etudiant->relationLoaded('inscriptionsAcademiques')
            ? $etudiant->inscriptionsAcademiques
            : $etudiant->inscriptionsAcademiques()->with('anneeUniversitaire')->orderByDesc('annee_universitaire_id')->get();

        if ($inscriptions->isEmpty()) {
            return null;
        }

        foreach ($inscriptions as $inscription) {
            if ($etudiant->annee_academique !== null
                && $inscription->anneeUniversitaire?->libelle === $etudiant->annee_academique) {
                return $inscription;
            }
        }

        return $inscriptions->sortByDesc('annee_universitaire_id')->first();
    }

    /** @param array<int, string> $entetes */
    private function lignePour(Etudiant $etudiant, array $entetes, int $index, ?User $utilisateur): array
    {
        $affectationActive = $etudiant->relationLoaded('affectations')
            ? $etudiant->affectations->firstWhere('statut', 'active')
            : $etudiant->affectations()->where('statut', 'active')->with('sousGroupe')->first();

        // PHASE 7 — résultats officiels LUS sur l'inscription de l'année
        // affichée (repli : la plus récente), jamais calculés ici.
        $inscription = $this->inscriptionAffichee($etudiant);

        $toutes = [
            'numero' => $index,
            'matricule' => $etudiant->num_inscription,
            'cin' => ExportColumnPolicy::masquerCin((string) $etudiant->cin, $utilisateur),
            'nom' => $etudiant->nom,
            'prenom' => $etudiant->prenom,
            'email' => $etudiant->email,
            'telephone' => $etudiant->telephone,
            'groupe' => $etudiant->groupe?->code,
            'sous_groupe' => $affectationActive?->sousGroupe?->code ?? '',
            'programme' => $etudiant->programmeRef?->code ?? $etudiant->programmeRef?->nom ?? '',
            'filiere' => $etudiant->groupe?->filiere ?? '',
            'niveau' => $etudiant->niveau,
            'faculte' => $etudiant->faculte?->code ?? '',
            'annee' => $etudiant->annee_academique,
            'inscription' => $etudiant->annee_academique,
            'moyenne' => $inscription?->moyenne_annuelle ?? '',
            'credits' => $inscription?->credits_obtenus ?? '',
            'decision' => $inscription?->decision ?? '',
            'statut' => $etudiant->statut,
        ];

        $ligne = [];
        foreach ($entetes as $entete) {
            $ligne[$entete] = $toutes[$entete] ?? '';
        }

        return $ligne;
    }
}
