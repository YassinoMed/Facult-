<?php

namespace App\Services;

use App\Models\CampagneCourriel;
use App\Models\CampagneCourrielDestinataire;
use App\Models\Etudiant;
use App\Models\GroupeDestinataires;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Service de résolution et de déduplication des destinataires (LOOP-MAIL-CAMPAIGNS-01).
 * Filtres en allowlist stricte, validation des adresses, déduplication
 * par condensat SHA-256 et séparation des populations (étudiants, enseignants, externe).
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
    public function validerFiltresEtudiants(array $filtres): array
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
     */
    public function requeteEtudiants(array $filtres = []): Builder
    {
        $filtres = $this->validerFiltresEtudiants($filtres);

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
     */
    public function requeteEnseignants(array $filtres = []): Builder
    {
        $filtres = $this->validerFiltresEnseignants($filtres);

        $q = Teacher::query()
            ->whereNotNull('email')
            ->where('email', '!=', '');

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
    public function resoudre(string $typeCiblage, array $donnees = []): array
    {
        $bruts = collect();

        switch ($typeCiblage) {
            case CampagneCourriel::CIBLAGE_INDIVIDUEL:
                $bruts = $this->extraireIndividuel($donnees);
                break;

            case CampagneCourriel::CIBLAGE_LISTE_FIXE:
                $bruts = $this->extraireListeFixe($donnees);
                break;

            case CampagneCourriel::CIBLAGE_DYNAMIQUE_ETUDIANTS:
                $filtres = (array) ($donnees['filtres'] ?? []);
                $bruts = $this->requeteEtudiants($filtres)
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
                $bruts = $this->requeteEnseignants($filtres)
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

                return $this->resoudreGroupe($groupe);

            default:
                throw new InvalidArgumentException("Type de ciblage non reconnu : {$typeCiblage}.");
        }

        return $this->filtrerEtDedupliquer($bruts);
    }

    /**
     * Résout les destinataires d'un groupe enregistré.
     */
    public function resoudreGroupe(GroupeDestinataires $groupe): array
    {
        if ($groupe->type === GroupeDestinataires::TYPE_DYNAMIQUE_ETUDIANTS) {
            return $this->resoudre(CampagneCourriel::CIBLAGE_DYNAMIQUE_ETUDIANTS, ['filtres' => $groupe->criteres ?? []]);
        }

        if ($groupe->type === GroupeDestinataires::TYPE_DYNAMIQUE_ENSEIGNANTS) {
            return $this->resoudre(CampagneCourriel::CIBLAGE_DYNAMIQUE_ENSEIGNANTS, ['filtres' => $groupe->criteres ?? []]);
        }

        // Mode liste fixe
        $membres = collect((array) ($groupe->membres_fixes ?? []));

        return $this->filtrerEtDedupliquer($membres);
    }

    /**
     * Extraction pour un destinataire unique.
     */
    private function extraireIndividuel(array $donnees): Collection
    {
        $type = $donnees['type_destinataire'] ?? CampagneCourrielDestinataire::TYPE_EXTERNE;
        $refId = isset($donnees['reference_id']) ? (int) $donnees['reference_id'] : null;
        $email = trim((string) ($donnees['email'] ?? ''));
        $nom = trim((string) ($donnees['nom_complet'] ?? ''));

        if ($refId !== null) {
            if ($type === CampagneCourrielDestinataire::TYPE_ETUDIANT) {
                $e = Etudiant::find($refId);
                if ($e !== null) {
                    $email = (string) $e->email;
                    $nom = trim($e->prenom.' '.$e->nom);
                }
            } elseif ($type === CampagneCourrielDestinataire::TYPE_ENSEIGNANT) {
                $t = Teacher::find($refId);
                if ($t !== null) {
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
     */
    private function extraireListeFixe(array $donnees): Collection
    {
        $items = collect();

        $etudiantIds = array_filter(array_map('intval', (array) ($donnees['etudiant_ids'] ?? [])));
        if (! empty($etudiantIds)) {
            Etudiant::whereIn('id', $etudiantIds)
                ->whereNotNull('email')
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
            Teacher::whereIn('id', $teacherIds)
                ->whereNotNull('email')
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
