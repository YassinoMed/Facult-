<?php

namespace App\Services;

use App\Models\CampagneCourriel;
use App\Models\CampagneCourrielDestinataire;
use App\Models\Etudiant;
use App\Models\GroupeDestinataires;
use App\Modules\Schedule\Models\Teacher;
use App\Support\CampagnePerimetre;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Service de résolution et de déduplication des destinataires (LOOP-MAIL-CAMPAIGNS-01).
 * Filtres en allowlist stricte, validation des adresses, déduplication
 * par condensat SHA-256 et séparation des populations (étudiants, enseignants, externe).
 *
 * LOOP-SECURITY-REMEDIATION-01 : toutes les entrées de résolution
 * acceptent un périmètre faculté (`$faculteForcee`) qui écrase les
 * filtres reçus — un administrateur de faculté ne peut jamais cibler
 * d'autres facultés, même en forgeant les filtres côté client.
 */
class ResolutionDestinatairesService
{
    public const FILTRES_ETUDIANTS_AUTORISES = [
        'faculte_id',
        'programme_id',
        'groupe_id',
        'niveau',
        'annee_academique',
        'statut',
        'recherche',
    ];

    public const FILTRES_ENSEIGNANTS_AUTORISES = [
        'departement_id',
        'grade_id',
        'specialite_id',
        'statut',
        'actif',
        'recherche',
    ];

    /**
     * Valide et nettoie les filtres fournis pour les étudiants.
     *
     * @param  array<string, mixed>  $filtres
     * @return array<string, mixed>
     */
    public function validerFiltresEtudiants(array $filtres, ?int $faculteForcee = null): array
    {
        $valides = [];

        foreach ($filtres as $cle => $valeur) {
            if (! in_array($cle, self::FILTRES_ETUDIANTS_AUTORISES, true)) {
                continue;
            }

            if ($valeur === null || $valeur === '') {
                continue;
            }

            if (in_array($cle, ['faculte_id', 'programme_id', 'groupe_id'], true)) {
                $valides[$cle] = (int) $valeur;
            } elseif ($cle === 'recherche') {
                $valides[$cle] = mb_substr(trim((string) $valeur), 0, 100);
            } else {
                $valides[$cle] = trim((string) $valeur);
            }
        }

        // Périmètre serveur : la faculté forcée écrase toute valeur client.
        if ($faculteForcee !== null) {
            $valides['faculte_id'] = $faculteForcee;
        }

        return $valides;
    }

    /**
     * Valide et nettoie les filtres fournis pour les enseignants.
     *
     * @param  array<string, mixed>  $filtres
     * @return array<string, mixed>
     */
    public function validerFiltresEnseignants(array $filtres): array
    {
        $valides = [];

        foreach ($filtres as $cle => $valeur) {
            if (! in_array($cle, self::FILTRES_ENSEIGNANTS_AUTORISES, true)) {
                continue;
            }

            if ($valeur === null || $valeur === '') {
                continue;
            }

            if (in_array($cle, ['departement_id', 'grade_id', 'specialite_id'], true)) {
                $valides[$cle] = (int) $valeur;
            } elseif ($cle === 'actif') {
                $valides[$cle] = filter_var($valeur, FILTER_VALIDATE_BOOLEAN);
            } elseif ($cle === 'recherche') {
                $valides[$cle] = mb_substr(trim((string) $valeur), 0, 100);
            } else {
                $valides[$cle] = trim((string) $valeur);
            }
        }

        return $valides;
    }

    /**
     * Construit la requête Eloquent pour cibler des étudiants.
     * `$faculteForcee` (périmètre serveur) écrase toute valeur de faculte_id
     * fournie dans les filtres.
     */
    public function requeteEtudiants(array $filtres = [], ?int $faculteForcee = null): Builder
    {
        $filtres = $this->validerFiltresEtudiants($filtres, $faculteForcee);

        $q = Etudiant::query()
            ->whereNotNull('email')
            ->where('email', '!=', '');

        if (isset($filtres['faculte_id'])) {
            $q->where('faculte_id', $filtres['faculte_id']);
        }

        if (isset($filtres['programme_id'])) {
            $q->where('programme_id', $filtres['programme_id']);
        }

        if (isset($filtres['groupe_id'])) {
            $q->where('groupe_id', $filtres['groupe_id']);
        }

        if (isset($filtres['niveau'])) {
            $q->where('niveau', $filtres['niveau']);
        }

        if (isset($filtres['annee_academique'])) {
            $q->where('annee_academique', $filtres['annee_academique']);
        }

        if (isset($filtres['statut'])) {
            $q->where('statut', $filtres['statut']);
        }

        if (isset($filtres['recherche']) && $filtres['recherche'] !== '') {
            $terme = '%'.$filtres['recherche'].'%';
            $q->where(function ($sub) use ($terme) {
                $sub->where('nom', 'like', $terme)
                    ->orWhere('prenom', 'like', $terme)
                    ->orWhere('email', 'like', $terme)
                    ->orWhere('cin', 'like', $terme)
                    ->orWhere('identifiant_national', 'like', $terme);
            });
        }

        return $q;
    }

    /**
     * Construit la requête Eloquent pour cibler des enseignants.
     * `$faculteForcee` restreint aux enseignants dont le département
     * appartient à la faculté (périmètre serveur, non contournable).
     */
    public function requeteEnseignants(array $filtres = [], ?int $faculteForcee = null): Builder
    {
        $filtres = $this->validerFiltresEnseignants($filtres);

        $q = Teacher::query()
            ->whereNotNull('email')
            ->where('email', '!=', '');

        if ($faculteForcee !== null) {
            $q->whereHas('departement', fn ($sub) => $sub->where('faculte_id', $faculteForcee));
        }

        if (isset($filtres['departement_id'])) {
            $q->where('departement_id', $filtres['departement_id']);
        }

        if (isset($filtres['grade_id'])) {
            $q->where('grade_id', $filtres['grade_id']);
        }

        if (isset($filtres['specialite_id'])) {
            $q->where('specialite_id', $filtres['specialite_id']);
        }

        if (isset($filtres['statut'])) {
            $q->where('statut', $filtres['statut']);
        }

        if (isset($filtres['actif'])) {
            $q->where('actif', $filtres['actif']);
        }

        if (isset($filtres['recherche']) && $filtres['recherche'] !== '') {
            $terme = '%'.$filtres['recherche'].'%';
            $q->where(function ($sub) use ($terme) {
                $sub->where('nom', 'like', $terme)
                    ->orWhere('prenom', 'like', $terme)
                    ->orWhere('email', 'like', $terme)
                    ->orWhere('matricule', 'like', $terme);
            });
        }

        return $q;
    }

    /**
     * Résout l'ensemble des destinataires pour un type de ciblage et des données données,
     * en appliquant la déduplication et l'élimination des adresses invalides.
     *
     * @param  array<string, mixed>  $donnees
     * @return array{
     *     total_brut: int,
     *     total_valides: int,
     *     total_doublons: int,
     *     total_invalides: int,
     *     destinataires: list<array{
     *         type_destinataire: string,
     *         reference_id: int|null,
     *         nom_complet: string,
     *         email: string,
     *         email_hash: string
     *     }>
     * }
     */
    public function resoudre(string $typeCiblage, array $donnees = [], ?int $faculteForcee = null): array
    {
        $bruts = collect();

        switch ($typeCiblage) {
            case CampagneCourriel::CIBLAGE_INDIVIDUEL:
                $bruts = $this->extraireIndividuel($donnees, $faculteForcee);
                break;

            case CampagneCourriel::CIBLAGE_LISTE_FIXE:
                $bruts = $this->extraireListeFixe($donnees, $faculteForcee);
                break;

            case CampagneCourriel::CIBLAGE_DYNAMIQUE_ETUDIANTS:
                $filtres = (array) ($donnees['filtres'] ?? []);
                $bruts = $this->requeteEtudiants($filtres, $faculteForcee)
                    ->select(['id', 'nom', 'prenom', 'email'])
                    ->cursor()
                    ->map(fn ($e) => [
                        'type_destinataire' => CampagneCourrielDestinataire::TYPE_ETUDIANT,
                        'reference_id' => $e->id,
                        'nom_complet' => trim($e->prenom.' '.$e->nom),
                        'email' => (string) $e->email,
                    ]);
                break;

            case CampagneCourriel::CIBLAGE_DYNAMIQUE_ENSEIGNANTS:
                $filtres = (array) ($donnees['filtres'] ?? []);
                $bruts = $this->requeteEnseignants($filtres, $faculteForcee)
                    ->select(['id', 'nom', 'prenom', 'email'])
                    ->cursor()
                    ->map(fn ($t) => [
                        'type_destinataire' => CampagneCourrielDestinataire::TYPE_ENSEIGNANT,
                        'reference_id' => $t->id,
                        'nom_complet' => trim($t->prenom.' '.$t->nom),
                        'email' => (string) $t->email,
                    ]);
                break;

            case CampagneCourriel::CIBLAGE_GROUPE_ENREGISTRE:
                $groupeId = $donnees['groupe_enregistre_id'] ?? null;
                $groupe = GroupeDestinataires::find($groupeId);
                if ($groupe === null || $groupe->est_archive) {
                    throw new InvalidArgumentException('Groupe enregistré introuvable ou archivé.');
                }

                return $this->resoudreGroupe($groupe, $faculteForcee);

            default:
                throw new InvalidArgumentException("Type de ciblage non reconnu : {$typeCiblage}.");
        }

        return $this->filtrerEtDedupliquer($bruts);
    }

    /**
     * Résout les destinataires d'un groupe enregistré.
     * `$faculteForcee` : groupe hors périmètre → refus ; listes fixes
     * filtrées aux entités de la faculté.
     */
    public function resoudreGroupe(GroupeDestinataires $groupe, ?int $faculteForcee = null): array
    {
        if (! CampagnePerimetre::groupeAutorise($groupe, $faculteForcee)) {
            throw new InvalidArgumentException('Groupe de destinataires hors de votre périmètre.');
        }

        if ($groupe->type === GroupeDestinataires::TYPE_DYNAMIQUE_ETUDIANTS) {
            return $this->resoudre(CampagneCourriel::CIBLAGE_DYNAMIQUE_ETUDIANTS, ['filtres' => $groupe->criteres ?? []], $faculteForcee);
        }

        if ($groupe->type === GroupeDestinataires::TYPE_DYNAMIQUE_ENSEIGNANTS) {
            return $this->resoudre(CampagneCourriel::CIBLAGE_DYNAMIQUE_ENSEIGNANTS, ['filtres' => $groupe->criteres ?? []], $faculteForcee);
        }

        // Mode liste fixe : les membres référencés hors faculté sont écartés.
        $membres = $this->membresDansPerimetre(collect((array) ($groupe->membres_fixes ?? [])), $faculteForcee);

        return $this->filtrerEtDedupliquer($membres);
    }

    /**
     * Ne conserve que les membres (listes fixes) rattachés aux entités de
     * la faculté de périmètre. Les adresses externes saisies manuellement
     * ne portent aucune référence : elles sont conservées.
     *
     * @param  Collection<int, mixed>  $membres
     * @return Collection<int, mixed>
     */
    private function membresDansPerimetre(Collection $membres, ?int $faculteForcee): Collection
    {
        if ($faculteForcee === null || $membres->isEmpty()) {
            return $membres;
        }

        $etudiantIds = $membres
            ->filter(fn ($m) => is_array($m)
                && ($m['type_destinataire'] ?? null) === CampagneCourrielDestinataire::TYPE_ETUDIANT
                && ! empty($m['reference_id']))
            ->pluck('reference_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $teacherIds = $membres
            ->filter(fn ($m) => is_array($m)
                && ($m['type_destinataire'] ?? null) === CampagneCourrielDestinataire::TYPE_ENSEIGNANT
                && ! empty($m['reference_id']))
            ->pluck('reference_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $etudiantsAutorises = $etudiantIds->isEmpty()
            ? collect()
            : Etudiant::whereIn('id', $etudiantIds)->where('faculte_id', $faculteForcee)->pluck('id')->map(fn ($id) => (int) $id);

        $enseignantsAutorises = $teacherIds->isEmpty()
            ? collect()
            : Teacher::whereIn('id', $teacherIds)
                ->whereHas('departement', fn ($sub) => $sub->where('faculte_id', $faculteForcee))
                ->pluck('id')
                ->map(fn ($id) => (int) $id);

        return $membres->filter(function ($m) use ($etudiantsAutorises, $enseignantsAutorises) {
            if (! is_array($m)) {
                return false;
            }

            $type = $m['type_destinataire'] ?? null;
            $refId = isset($m['reference_id']) ? (int) $m['reference_id'] : null;

            if ($refId === null || $refId === 0) {
                return true; // Adresse externe sans référence : conservée.
            }

            return match ($type) {
                CampagneCourrielDestinataire::TYPE_ETUDIANT => $etudiantsAutorises->contains($refId),
                CampagneCourrielDestinataire::TYPE_ENSEIGNANT => $enseignantsAutorises->contains($refId),
                default => false,
            };
        })->values();
    }

    /**
     * Extraction pour un destinataire unique.
     * Hors périmètre faculté → aucun destinataire (silencieux : pas de
     * fuite d'existence de la référence).
     */
    private function extraireIndividuel(array $donnees, ?int $faculteForcee = null): Collection
    {
        $type = $donnees['type_destinataire'] ?? CampagneCourrielDestinataire::TYPE_EXTERNE;
        $refId = isset($donnees['reference_id']) ? (int) $donnees['reference_id'] : null;
        $email = trim((string) ($donnees['email'] ?? ''));
        $nom = trim((string) ($donnees['nom_complet'] ?? ''));

        if ($refId !== null) {
            if ($type === CampagneCourrielDestinataire::TYPE_ETUDIANT) {
                $e = Etudiant::find($refId);
                if ($e !== null) {
                    if ($faculteForcee !== null && (int) $e->faculte_id !== $faculteForcee) {
                        return collect();
                    }
                    $email = (string) $e->email;
                    $nom = trim($e->prenom.' '.$e->nom);
                }
            } elseif ($type === CampagneCourrielDestinataire::TYPE_ENSEIGNANT) {
                $t = Teacher::find($refId);
                if ($t !== null) {
                    if ($faculteForcee !== null && (int) $t->faculteId() !== $faculteForcee) {
                        return collect();
                    }
                    $email = (string) $t->email;
                    $nom = trim($t->prenom.' '.$t->nom);
                }
            }
        }

        if ($email === '') {
            return collect();
        }

        return collect([[
            'type_destinataire' => $type,
            'reference_id' => $refId,
            'nom_complet' => $nom,
            'email' => $email,
        ]]);
    }

    /**
     * Extraction d'une liste sélectionnée d'étudiants / enseignants.
     * `$faculteForcee` : les références hors faculté sont écartées avant
     * toute déduplication (anti-IDOR de masse).
     */
    private function extraireListeFixe(array $donnees, ?int $faculteForcee = null): Collection
    {
        $items = collect();

        $etudiantIds = array_filter(array_map('intval', (array) ($donnees['etudiant_ids'] ?? [])));
        if (! empty($etudiantIds)) {
            $requeteEtudiants = Etudiant::whereIn('id', $etudiantIds)
                ->whereNotNull('email');

            if ($faculteForcee !== null) {
                $requeteEtudiants->where('faculte_id', $faculteForcee);
            }

            $requeteEtudiants
                ->select(['id', 'nom', 'prenom', 'email'])
                ->get()
                ->each(function ($e) use ($items) {
                    $items->push([
                        'type_destinataire' => CampagneCourrielDestinataire::TYPE_ETUDIANT,
                        'reference_id' => $e->id,
                        'nom_complet' => trim($e->prenom.' '.$e->nom),
                        'email' => (string) $e->email,
                    ]);
                });
        }

        $teacherIds = array_filter(array_map('intval', (array) ($donnees['teacher_ids'] ?? [])));
        if (! empty($teacherIds)) {
            $requeteEnseignants = Teacher::whereIn('id', $teacherIds)
                ->whereNotNull('email');

            if ($faculteForcee !== null) {
                $requeteEnseignants->whereHas('departement', fn ($sub) => $sub->where('faculte_id', $faculteForcee));
            }

            $requeteEnseignants
                ->select(['id', 'nom', 'prenom', 'email'])
                ->get()
                ->each(function ($t) use ($items) {
                    $items->push([
                        'type_destinataire' => CampagneCourrielDestinataire::TYPE_ENSEIGNANT,
                        'reference_id' => $t->id,
                        'nom_complet' => trim($t->prenom.' '.$t->nom),
                        'email' => (string) $t->email,
                    ]);
                });
        }

        $emailsManuels = (array) ($donnees['emails_libres'] ?? []);
        foreach ($emailsManuels as $em) {
            $items->push([
                'type_destinataire' => CampagneCourrielDestinataire::TYPE_EXTERNE,
                'reference_id' => null,
                'nom_complet' => trim((string) ($em['nom_complet'] ?? '')),
                'email' => trim((string) ($em['email'] ?? '')),
            ]);
        }

        return $items;
    }

    /**
     * Normalisation, validation et déduplication rigoureuse.
     *
     * @param  iterable<array<string, mixed>>  $elements
     * @return array{
     *     total_brut: int,
     *     total_valides: int,
     *     total_doublons: int,
     *     total_invalides: int,
     *     destinataires: list<array{
     *         type_destinataire: string,
     *         reference_id: int|null,
     *         nom_complet: string,
     *         email: string,
     *         email_hash: string
     *     }>
     * }
     */
    private function filtrerEtDedupliquer(iterable $elements): array
    {
        $totalBrut = 0;
        $totalDoublons = 0;
        $totalInvalides = 0;
        $vus = [];
        $destinataires = [];

        foreach ($elements as $item) {
            $totalBrut++;
            $email = trim((string) ($item['email'] ?? ''));

            // Validation de sécurité : pas de retour à la ligne, format e-mail RFC valide
            if ($email === '' || str_contains($email, "\r") || str_contains($email, "\n") || mb_strlen($email) > 254) {
                $totalInvalides++;

                continue;
            }

            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $totalInvalides++;

                continue;
            }

            $emailNormalise = mb_strtolower($email);
            $hash = CampagneCourrielDestinataire::hacherEmail($emailNormalise);

            // Déduplication stricte
            if (isset($vus[$hash])) {
                $totalDoublons++;

                continue;
            }

            $vus[$hash] = true;
            $destinataires[] = [
                'type_destinataire' => (string) ($item['type_destinataire'] ?? CampagneCourrielDestinataire::TYPE_EXTERNE),
                'reference_id' => isset($item['reference_id']) ? (int) $item['reference_id'] : null,
                'nom_complet' => mb_substr(trim((string) ($item['nom_complet'] ?? '')), 0, 150),
                'email' => $emailNormalise,
                'email_hash' => $hash,
            ];
        }

        return [
            'total_brut' => $totalBrut,
            'total_valides' => count($destinataires),
            'total_doublons' => $totalDoublons,
            'total_invalides' => $totalInvalides,
            'destinataires' => $destinataires,
        ];
    }
}
