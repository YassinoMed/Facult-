<?php

namespace App\Modules\Referentiel\Support;

use App\Models\Admission;
use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\InscriptionPedagogique;
use App\Modules\Enseignement\Models\Enseignement;
use App\Modules\Evaluation\Models\Deliberation;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\RegleEvaluation;
use App\Modules\Referentiel\Models\Departement;
use App\Modules\Referentiel\Models\Domaine;
use App\Modules\Referentiel\Models\Mention;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\Specialite;
use App\Modules\Referentiel\Models\TypeDiplome;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Teacher;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Carte des entités du référentiel LMD (§2-13) : UNE seule source pour
 * le CRUD générique (labels, champs, règles, recherche, périmètre,
 * gardes de suppression, enfants). Ajouter une entité = une entrée ici.
 */
final class EntitesReferentiel
{
    /**
     * @return array<int, string>
     */
    public static function cles(): array
    {
        return array_keys(self::carte());
    }

    /**
     * @return array<string, mixed>
     */
    public static function pour(string $cle): array
    {
        return self::carte()[$cle] ?? abort(404);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function carte(): array
    {
        return [
            'departements' => [
                'model' => Departement::class,
                'label' => 'Département', 'labels' => 'Départements',
                'champs' => [
                    ['nom' => 'faculte_id', 'label' => 'Établissement', 'type' => 'select', 'options' => 'facultes', 'requis' => true],
                    ['nom' => 'code', 'label' => 'Code', 'type' => 'text', 'requis' => true],
                    ['nom' => 'nom', 'label' => 'Nom', 'type' => 'text', 'requis' => true],
                    ['nom' => 'description', 'label' => 'Description', 'type' => 'textarea'],
                    ['nom' => 'responsable', 'label' => 'Responsable', 'type' => 'text'],
                    ['nom' => 'actif', 'label' => 'Actif', 'type' => 'checkbox'],
                ],
                'regles' => fn (?int $id) => [
                    'faculte_id' => 'required|exists:facultes,id',
                    'code' => ['required', 'string', 'max:20', Rule::unique('departements', 'code')->ignore($id)->where(fn ($q) => $q->where('faculte_id', request('faculte_id')))],
                    'nom' => 'required|string|max:150',
                    'description' => 'nullable|string|max:2000',
                    'responsable' => 'nullable|string|max:150',
                    'actif' => 'sometimes|boolean',
                ],
                'recherche' => ['code', 'nom'],
                'perimetre' => fn (Builder $q, ?int $faculteId) => $faculteId !== null ? $q->where('faculte_id', $faculteId) : $q,
                'garde' => fn (Departement $m) => $m->programmes()->exists() || Teacher::where('departement_id', $m->id)->exists()
                    ? 'Ce département est utilisé (programmes et/ou enseignants rattachés).' : null,
                'enfants' => [['programmes', 'Programmes', 'admin.referentiel.programmes.index']],
                'avec' => ['faculte'],
            ],
            'domaines' => [
                'model' => Domaine::class,
                'label' => 'Domaine', 'labels' => 'Domaines',
                'champs' => [
                    ['nom' => 'faculte_id', 'label' => 'Établissement', 'type' => 'select', 'options' => 'facultes', 'requis' => true],
                    ['nom' => 'code', 'label' => 'Code', 'type' => 'text', 'requis' => true],
                    ['nom' => 'sigle', 'label' => 'Sigle', 'type' => 'text'],
                    ['nom' => 'nom', 'label' => 'Nom', 'type' => 'text', 'requis' => true],
                    ['nom' => 'description', 'label' => 'Description', 'type' => 'textarea'],
                    ['nom' => 'actif', 'label' => 'Actif', 'type' => 'checkbox'],
                ],
                'regles' => fn (?int $id) => [
                    'faculte_id' => 'required|exists:facultes,id',
                    'code' => ['required', 'string', 'max:20', Rule::unique('domaines', 'code')->ignore($id)->where(fn ($q) => $q->where('faculte_id', request('faculte_id')))],
                    'sigle' => 'nullable|string|max:20',
                    'nom' => 'required|string|max:150',
                    'description' => 'nullable|string|max:2000',
                    'actif' => 'sometimes|boolean',
                ],
                'recherche' => ['code', 'sigle', 'nom'],
                'perimetre' => fn (Builder $q, ?int $faculteId) => $faculteId !== null ? $q->where('faculte_id', $faculteId) : $q,
                'garde' => fn (Domaine $m) => $m->mentions()->exists() ? 'Ce domaine a des mentions rattachées.' : null,
                'enfants' => [['mentions', 'Mentions', null]],
                'avec' => ['faculte'],
            ],
            'mentions' => [
                'model' => Mention::class,
                'label' => 'Mention', 'labels' => 'Mentions',
                'champs' => [
                    ['nom' => 'domaine_id', 'label' => 'Domaine', 'type' => 'select', 'options' => 'domaines', 'requis' => true],
                    ['nom' => 'code', 'label' => 'Code', 'type' => 'text', 'requis' => true],
                    ['nom' => 'sigle', 'label' => 'Sigle', 'type' => 'text'],
                    ['nom' => 'nom', 'label' => 'Nom', 'type' => 'text', 'requis' => true],
                    ['nom' => 'description', 'label' => 'Description', 'type' => 'textarea'],
                    ['nom' => 'actif', 'label' => 'Actif', 'type' => 'checkbox'],
                ],
                'regles' => fn (?int $id) => [
                    'domaine_id' => 'required|exists:domaines,id',
                    'code' => ['required', 'string', 'max:30', Rule::unique('mentions', 'code')->ignore($id)->where(fn ($q) => $q->where('domaine_id', request('domaine_id')))],
                    'sigle' => 'nullable|string|max:20',
                    'nom' => 'required|string|max:150',
                    'description' => 'nullable|string|max:2000',
                    'actif' => 'sometimes|boolean',
                ],
                'recherche' => ['code', 'sigle', 'nom'],
                'perimetre' => fn (Builder $q, ?int $faculteId) => $faculteId !== null
                    ? $q->whereHas('domaine', fn ($d) => $d->where('faculte_id', $faculteId)) : $q,
                'garde' => fn (Mention $m) => $m->specialites()->exists() ? 'Cette mention a des spécialités rattachées.' : null,
                'enfants' => [['specialites', 'Spécialités', null]],
                'avec' => ['domaine.faculte'],
            ],
            'specialites' => [
                'model' => Specialite::class,
                'label' => 'Spécialité', 'labels' => 'Spécialités',
                'champs' => [
                    ['nom' => 'mention_id', 'label' => 'Mention', 'type' => 'select', 'options' => 'mentions', 'requis' => true],
                    ['nom' => 'code', 'label' => 'Code', 'type' => 'text', 'requis' => true],
                    ['nom' => 'nom', 'label' => 'Nom', 'type' => 'text', 'requis' => true],
                    ['nom' => 'description', 'label' => 'Description', 'type' => 'textarea'],
                    ['nom' => 'actif', 'label' => 'Actif', 'type' => 'checkbox'],
                ],
                'regles' => fn (?int $id) => [
                    'mention_id' => 'required|exists:mentions,id',
                    'code' => ['required', 'string', 'max:30', Rule::unique('specialites', 'code')->ignore($id)->where(fn ($q) => $q->where('mention_id', request('mention_id')))],
                    'nom' => 'required|string|max:150',
                    'description' => 'nullable|string|max:2000',
                    'actif' => 'sometimes|boolean',
                ],
                'recherche' => ['code', 'nom'],
                'perimetre' => fn (Builder $q, ?int $faculteId) => $faculteId !== null
                    ? $q->whereHas('mention.domaine', fn ($d) => $d->where('faculte_id', $faculteId)) : $q,
                'garde' => fn (Specialite $m) => $m->programmes()->exists() ? 'Cette spécialité a des programmes rattachés.' : null,
                'enfants' => [['programmes', 'Programmes', null]],
                'avec' => ['mention.domaine'],
            ],
            'types-diplomes' => [
                'model' => TypeDiplome::class,
                'label' => 'Type de diplôme', 'labels' => 'Types de diplômes',
                'champs' => [
                    ['nom' => 'code', 'label' => 'Code', 'type' => 'text', 'requis' => true],
                    ['nom' => 'nom', 'label' => 'Nom', 'type' => 'text', 'requis' => true],
                    ['nom' => 'ordre', 'label' => 'Ordre', 'type' => 'number'],
                    ['nom' => 'cycle', 'label' => 'Cycle LMD', 'type' => 'select', 'options' => 'cycles-diplome'],
                    ['nom' => 'credits_requis', 'label' => 'Crédits requis (indicatif)', 'type' => 'number'],
                    ['nom' => 'duree_annees', 'label' => 'Durée (années, indicatif)', 'type' => 'number'],
                    ['nom' => 'actif', 'label' => 'Actif', 'type' => 'checkbox'],
                ],
                'regles' => fn (?int $id) => [
                    'code' => ['required', 'string', 'max:20', Rule::unique('type_diplomes', 'code')->ignore($id)],
                    'nom' => 'required|string|max:100',
                    'ordre' => 'nullable|integer|min:0|max:999',
                    'cycle' => ['nullable', Rule::in(TypeDiplome::CYCLES)],
                    'credits_requis' => 'nullable|integer|min:0|max:999',
                    'duree_annees' => 'nullable|integer|min:1|max:12',
                    'actif' => 'sometimes|boolean',
                ],
                'recherche' => ['code', 'nom'],
                'perimetre' => fn (Builder $q, ?int $faculteId) => $q,
                'garde' => fn (TypeDiplome $m) => $m->programmes()->exists() ? 'Ce type est utilisé par des programmes.' : null,
                'enfants' => [['programmes', 'Programmes', null]],
                'avec' => [],
            ],
            'programmes' => [
                'model' => Programme::class,
                'label' => 'Programme', 'labels' => 'Programmes / Parcours',
                'champs' => [
                    ['nom' => 'faculte_id', 'label' => 'Établissement', 'type' => 'select', 'options' => 'facultes', 'requis' => true],
                    ['nom' => 'departement_id', 'label' => 'Département', 'type' => 'select', 'options' => 'departements'],
                    ['nom' => 'specialite_id', 'label' => 'Spécialité', 'type' => 'select', 'options' => 'specialites'],
                    ['nom' => 'type_diplome_id', 'label' => 'Type de diplôme', 'type' => 'select', 'options' => 'types-diplomes'],
                    ['nom' => 'code', 'label' => 'Code', 'type' => 'text', 'requis' => true],
                    ['nom' => 'nom', 'label' => 'Nom', 'type' => 'text', 'requis' => true],
                    ['nom' => 'intitule_officiel', 'label' => 'Intitulé officiel', 'type' => 'text'],
                    ['nom' => 'sigle', 'label' => 'Sigle', 'type' => 'text'],
                    ['nom' => 'description', 'label' => 'Description', 'type' => 'textarea'],
                    ['nom' => 'duree_annees', 'label' => 'Durée (années)', 'type' => 'number'],
                    ['nom' => 'semestres_nombre', 'label' => 'Nombre de semestres', 'type' => 'number'],
                    ['nom' => 'credits_requis', 'label' => 'Crédits requis', 'type' => 'number'],
                    ['nom' => 'responsable', 'label' => 'Responsable', 'type' => 'text'],
                    ['nom' => 'mode', 'label' => 'Mode', 'type' => 'select', 'options' => 'modes'],
                    ['nom' => 'date_debut', 'label' => 'Début', 'type' => 'date'],
                    ['nom' => 'date_fin', 'label' => 'Fin éventuelle', 'type' => 'date'],
                    ['nom' => 'statut', 'label' => 'Statut', 'type' => 'select', 'options' => 'statuts-programme', 'requis' => true],
                    ['nom' => 'capacite', 'label' => 'Capacité indicative (groupes seuls font foi à l’affectation)', 'type' => 'number'],
                ],
                'regles' => fn (?int $id) => [
                    'faculte_id' => 'required|exists:facultes,id',
                    // INV-2 (audit 08/10/2026) : jamais un département
                    // d'une autre faculté (« FST programme + FSEGS
                    // département » refusé côté serveur, pas seulement UI).
                    'departement_id' => ['nullable', 'exists:departements,id',
                        function (string $attribute, mixed $value, Closure $fail) {
                            if ($value === null) {
                                return;
                            }
                            $departement = Departement::find($value);
                            if ($departement !== null && (int) $departement->faculte_id !== (int) request('faculte_id')) {
                                $fail('Le département choisi n\'appartient pas à la faculté du programme.');
                            }
                        }],
                    // INV-3 : la chaîne spécialité→mention→domaine doit
                    // rester dans la faculté du programme.
                    'specialite_id' => ['nullable', 'exists:specialites,id',
                        function (string $attribute, mixed $value, Closure $fail) {
                            if ($value === null) {
                                return;
                            }
                            $domaine = Specialite::with('mention.domaine')->find($value)?->mention?->domaine;
                            if ($domaine !== null && (int) $domaine->faculte_id !== (int) request('faculte_id')) {
                                $fail('La spécialité choisie n\'appartient pas à la faculté du programme.');
                            }
                        }],
                    'type_diplome_id' => 'nullable|exists:type_diplomes,id',
                    // INV-4 : code normalisé + format contrôlé (les codes
                    // « P6ac5fe947520a » deviennent impossibles).
                    'code' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9\-_]{2,30}$/',
                        Rule::unique('programmes', 'code')->ignore($id)->where(fn ($q) => $q->where('faculte_id', request('faculte_id')))],
                    'nom' => 'required|string|min:3|max:200',
                    'intitule_officiel' => 'nullable|string|max:255',
                    'sigle' => 'nullable|string|max:20',
                    'description' => 'nullable|string|max:2000',
                    'duree_annees' => 'nullable|integer|min:1|max:12',
                    'semestres_nombre' => 'nullable|integer|min:1|max:24',
                    'credits_requis' => 'nullable|integer|min:0|max:999',
                    'responsable' => 'nullable|string|max:150',
                    'mode' => 'nullable|in:initiale,continue,alternance',
                    'date_debut' => 'nullable|date',
                    'date_fin' => 'nullable|date|after_or_equal:date_debut',
                    'statut' => ['required', Rule::in(Programme::STATUTS)],
                    'capacite' => 'nullable|integer|min:0|max:65535',
                ],
                'recherche' => ['code', 'sigle', 'nom'],
                'perimetre' => fn (Builder $q, ?int $faculteId) => $faculteId !== null ? $q->where('faculte_id', $faculteId) : $q,
                'garde' => function (Programme $m) {
                    // INV-7 (audit 08/10/2026) : TOUTES les tables
                    // consommatrices sont vérifiées — une FK restrict ne
                    // doit JAMAIS remonter en 500.
                    if ($m->niveaux()->exists() || $m->maquettes()->exists()) {
                        return 'Ce programme a des niveaux ou des maquettes.';
                    }

                    foreach ([
                        [UniteEnseignement::class, 'Ce programme a des UE rattachées.'],
                        [Enseignement::class, 'Ce programme a des enseignements rattachés.'],
                        [InscriptionAcademique::class, 'Ce programme a des inscriptions académiques.'],
                        [Group::class, 'Ce programme a des groupes rattachés.'],
                        [Admission::class, 'Ce programme a des admissions.'],
                        [RegleEvaluation::class, 'Ce programme a des règles d\'évaluation.'],
                    ] as [$modele, $message]) {
                        if ($modele::where('programme_id', $m->id)->exists()) {
                            return $message;
                        }
                    }

                    if (Examen::where('programme_id', $m->id)->exists()
                        || Deliberation::where('programme_id', $m->id)->exists()) {
                        return 'Ce programme est utilisé par le module Évaluation.';
                    }

                    return null;
                },
                'enfants' => [['niveaux', 'Niveaux', null], ['maquettes', 'Maquettes', null]],
                'avec' => ['faculte', 'typeDiplome'],
            ],
            'niveaux' => [
                'model' => Niveau::class,
                'label' => 'Niveau', 'labels' => 'Niveaux',
                'champs' => [
                    ['nom' => 'programme_id', 'label' => 'Programme', 'type' => 'select', 'options' => 'programmes', 'requis' => true],
                    ['nom' => 'code', 'label' => 'Code', 'type' => 'text', 'requis' => true],
                    ['nom' => 'nom', 'label' => 'Nom', 'type' => 'text', 'requis' => true],
                    ['nom' => 'ordre', 'label' => 'Ordre pédagogique', 'type' => 'number'],
                    ['nom' => 'semestres_nombre', 'label' => 'Nombre de semestres', 'type' => 'number'],
                    ['nom' => 'credits_attendus', 'label' => 'Crédits attendus', 'type' => 'number'],
                    ['nom' => 'actif', 'label' => 'Actif', 'type' => 'checkbox'],
                ],
                'regles' => fn (?int $id) => [
                    // INV-10 (audit 08/10/2026) : le nombre de niveaux
                    // d'un programme ne dépasse pas la durée du type de
                    // diplôme (données configurables, zéro « M1 » codé).
                    'programme_id' => ['required', 'exists:programmes,id',
                        function (string $attribute, mixed $value, Closure $fail) use ($id) {
                            $programme = Programme::with('typeDiplome')->find($value);
                            $duree = $programme?->typeDiplome?->duree_annees;

                            if ($duree !== null) {
                                $existants = Niveau::where('programme_id', $value)
                                    ->when($id !== null, fn ($q) => $q->whereKeyNot((int) $id))
                                    ->count();

                                if ($existants >= $duree) {
                                    $fail("Le diplôme de ce programme prévoit {$duree} niveau(x) : la limite est atteinte.");
                                }
                            }
                        }],
                    'code' => ['required', 'string', 'max:10', Rule::unique('niveaux', 'code')->ignore($id)->where(fn ($q) => $q->where('programme_id', request('programme_id')))],
                    'nom' => 'required|string|max:100',
                    'ordre' => 'nullable|integer|min:1|max:20',
                    'semestres_nombre' => 'nullable|integer|min:1|max:12',
                    'credits_attendus' => 'nullable|integer|min:0|max:999',
                    'actif' => 'sometimes|boolean',
                ],
                'recherche' => ['code', 'nom'],
                'perimetre' => fn (Builder $q, ?int $faculteId) => $faculteId !== null
                    ? $q->whereHas('programme', fn ($p) => $p->where('faculte_id', $faculteId)) : $q,
                'garde' => function (Niveau $m) {
                    if ($m->semestres()->exists()) {
                        return 'Ce niveau a des semestres.';
                    }

                    foreach ([
                        [Group::class, 'niveau_id', 'Ce niveau a des groupes rattachés.'],
                        [Examen::class, 'niveau_id', 'Ce niveau est utilisé par le module Évaluation.'],
                        [Deliberation::class, 'niveau_id', 'Ce niveau est utilisé par le module Évaluation.'],
                        [Admission::class, 'niveau_id', 'Ce niveau a des admissions.'],
                    ] as [$modele, $colonne, $message]) {
                        if ($modele::where($colonne, $m->id)->exists()) {
                            return $message;
                        }
                    }

                    return null;
                },
                'enfants' => [['semestres', 'Semestres', null]],
                'avec' => ['programme.faculte'],
            ],
            'semestres' => [
                'model' => SemestreLmd::class,
                'label' => 'Semestre', 'labels' => 'Semestres',
                'champs' => [
                    ['nom' => 'niveau_id', 'label' => 'Niveau', 'type' => 'select', 'options' => 'niveaux', 'requis' => true],
                    ['nom' => 'code', 'label' => 'Code', 'type' => 'text', 'requis' => true],
                    ['nom' => 'nom', 'label' => 'Nom', 'type' => 'text', 'requis' => true],
                    ['nom' => 'numero', 'label' => 'Numéro', 'type' => 'number', 'requis' => true],
                    ['nom' => 'ordre', 'label' => 'Ordre', 'type' => 'number'],
                    ['nom' => 'credits_attendus', 'label' => 'Crédits attendus', 'type' => 'number'],
                    ['nom' => 'actif', 'label' => 'Actif', 'type' => 'checkbox'],
                ],
                'regles' => fn (?int $id) => [
                    'niveau_id' => 'required|exists:niveaux,id',
                    'code' => ['required', 'string', 'max:10', Rule::unique('semestres_lmd', 'code')->ignore($id)->where(fn ($q) => $q->where('niveau_id', request('niveau_id')))],
                    'nom' => 'required|string|max:100',
                    'numero' => 'required|integer|min:1|max:24',
                    'ordre' => 'nullable|integer|min:1|max:48',
                    'credits_attendus' => 'nullable|integer|min:0|max:999',
                    'actif' => 'sometimes|boolean',
                ],
                'recherche' => ['code', 'nom'],
                'perimetre' => fn (Builder $q, ?int $faculteId) => $faculteId !== null
                    ? $q->whereHas('niveau.programme', fn ($p) => $p->where('faculte_id', $faculteId)) : $q,
                'garde' => function (SemestreLmd $m) {
                    if ($m->unitesEnseignement()->exists()) {
                        return 'Ce semestre a des UE.';
                    }

                    foreach ([
                        [InscriptionPedagogique::class, 'semestre_id', 'Ce semestre a des inscriptions pédagogiques.'],
                        [Examen::class, 'semestre_lmd_id', 'Ce semestre est utilisé par le module Évaluation.'],
                        [Deliberation::class, 'semestre_lmd_id', 'Ce semestre est utilisé par le module Évaluation.'],
                    ] as [$modele, $colonne, $message]) {
                        if ($modele::where($colonne, $m->id)->exists()) {
                            return $message;
                        }
                    }

                    if (DB::table('maquette_ue')->where('semestre_id', $m->id)->exists()) {
                        return 'Ce semestre est utilisé par des maquettes.';
                    }

                    return null;
                },
                'enfants' => [['unitesEnseignement', 'Unités d’enseignement', null]],
                'avec' => ['niveau.programme'],
            ],
            'ues' => [
                'model' => UniteEnseignement::class,
                'label' => 'UE', 'labels' => 'Unités d’enseignement',
                'champs' => [
                    ['nom' => 'semestre_id', 'label' => 'Semestre', 'type' => 'select', 'options' => 'semestres', 'requis' => true],
                    ['nom' => 'code', 'label' => 'Code', 'type' => 'text', 'requis' => true],
                    ['nom' => 'sigle', 'label' => 'Sigle', 'type' => 'text'],
                    ['nom' => 'nom', 'label' => 'Nom', 'type' => 'text', 'requis' => true],
                    ['nom' => 'description', 'label' => 'Description', 'type' => 'textarea'],
                    ['nom' => 'credits', 'label' => 'Crédits', 'type' => 'number', 'requis' => true],
                    ['nom' => 'coefficient', 'label' => 'Coefficient', 'type' => 'decimal'],
                    ['nom' => 'type', 'label' => 'Type', 'type' => 'select', 'options' => 'types-ue'],
                    ['nom' => 'actif', 'label' => 'Actif', 'type' => 'checkbox'],
                ],
                'regles' => fn (?int $id) => [
                    // INV-1 (audit 08/10/2026) : `ue.programme_id ==
                    // ue.semestre.niveau.programme_id` — un transfert
                    // d'UE vers le semestre d'un AUTRE programme est
                    // refusé (recréation + analyse d'impact requises ;
                    // le périmètre faculté est vérifié en amont, 403).
                    'semestre_id' => ['required', 'exists:semestres_lmd,id',
                        function (string $attribute, mixed $value, Closure $fail) use ($id) {
                            if ($id === null) {
                                return; // création : programme_id dérivé par le contrôleur
                            }

                            $ue = UniteEnseignement::find($id);
                            $programme = SemestreLmd::with('niveau')->find($value)?->niveau?->programme_id;

                            if ($ue !== null && $programme !== null
                                && (int) $programme !== (int) $ue->programme_id) {
                                $fail('Le semestre choisi appartient à un autre programme (invariant UE/programme).');
                            }
                        }],
                    'code' => ['required', 'string', 'max:30', Rule::unique('unites_enseignement', 'code')->ignore($id)->where(fn ($q) => $q->where('semestre_id', request('semestre_id')))],
                    'sigle' => 'nullable|string|max:20',
                    'nom' => 'required|string|max:200',
                    'description' => 'nullable|string|max:2000',
                    'credits' => 'required|integer|min:0|max:60',
                    'coefficient' => 'nullable|numeric|min:0|max:99',
                    'type' => 'nullable|in:fondamentale,transversale,optionnelle,professionnelle',
                    'actif' => 'sometimes|boolean',
                ],
                'recherche' => ['code', 'sigle', 'nom'],
                'perimetre' => fn (Builder $q, ?int $faculteId) => $faculteId !== null
                    ? $q->whereHas('programme', fn ($p) => $p->where('faculte_id', $faculteId)) : $q,
                'garde' => function (UniteEnseignement $m) {
                    if ($m->ecues()->exists()) {
                        return 'Cette UE a des ECUE rattachées.';
                    }

                    if (DB::table('maquette_ue')->where('ue_id', $m->id)->exists()) {
                        return 'Cette UE est utilisée par des maquettes.';
                    }

                    foreach ([
                        [InscriptionPedagogique::class, 'ue_id', 'Cette UE a des inscriptions pédagogiques.'],
                        [Epreuve::class, 'ue_id', 'Cette UE est utilisée par des épreuves.'],
                    ] as [$modele, $colonne, $message]) {
                        if ($modele::where($colonne, $m->id)->exists()) {
                            return $message;
                        }
                    }

                    return null;
                },
                'enfants' => [['ecues', 'ECUE', null]],
                'avec' => ['semestre.niveau', 'programme'],
            ],
        ];
    }

    /**
     * Options des selects (scopées faculté si restreinte).
     *
     * @return array<int, array{id: int, label: string}>
     */
    public static function options(string $nom, ?int $faculteId): array
    {
        $etiquette = fn ($m, $champs) => implode(' — ', array_filter(array_map(
            fn ($c) => is_string($m->$c ?? null) && trim($m->$c) !== '' ? trim($m->$c) : null, $champs
        )));

        return match ($nom) {
            'facultes' => Faculte::orderBy('nom')->get()
                ->map(fn ($m) => ['id' => $m->id, 'label' => $m->nom])->all(),
            'domaines' => Domaine::with('faculte')
                ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))
                ->orderBy('code')->get()
                ->map(fn ($m) => ['id' => $m->id, 'label' => $etiquette($m, ['code', 'nom'])])->all(),
            'mentions' => Mention::with('domaine')
                ->when($faculteId !== null, fn ($q) => $q->whereHas('domaine', fn ($d) => $d->where('faculte_id', $faculteId)))
                ->orderBy('code')->get()
                ->map(fn ($m) => ['id' => $m->id, 'label' => $etiquette($m, ['code', 'nom'])])->all(),
            'specialites' => Specialite::with('mention')
                ->when($faculteId !== null, fn ($q) => $q->whereHas('mention.domaine', fn ($d) => $d->where('faculte_id', $faculteId)))
                ->orderBy('code')->get()
                ->map(fn ($m) => ['id' => $m->id, 'label' => $etiquette($m, ['code', 'nom'])])->all(),
            'departements' => Departement::orderBy('code')
                ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))
                ->get()->map(fn ($m) => ['id' => $m->id, 'label' => $etiquette($m, ['code', 'nom'])])->all(),
            'types-diplomes' => TypeDiplome::where('actif', true)->orderBy('ordre')->get()
                ->map(fn ($m) => ['id' => $m->id, 'label' => $etiquette($m, ['code', 'nom'])])->all(),
            'programmes' => Programme::orderBy('code')
                ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))
                ->get()->map(fn ($m) => ['id' => $m->id, 'label' => $etiquette($m, ['code', 'nom'])])->all(),
            'niveaux' => Niveau::with('programme')->orderBy('ordre')
                ->when($faculteId !== null, fn ($q) => $q->whereHas('programme', fn ($p) => $p->where('faculte_id', $faculteId)))
                ->get()->map(fn ($m) => ['id' => $m->id, 'label' => $m->programme->code.' · '.$m->code])->all(),
            'semestres' => SemestreLmd::with('niveau.programme')->orderBy('code')
                ->when($faculteId !== null, fn ($q) => $q->whereHas('niveau.programme', fn ($p) => $p->where('faculte_id', $faculteId)))
                ->get()->map(fn ($m) => ['id' => $m->id, 'label' => $m->niveau->programme->code.' · '.$m->code])->all(),
            'types-ue' => array_map(fn ($t) => ['id' => $t, 'label' => ucfirst($t)], UniteEnseignement::TYPES),
            'statuts-programme' => [
                ['id' => Programme::STATUT_BROUILLON, 'label' => 'Brouillon (non proposable)'],
                ['id' => Programme::STATUT_ACTIF, 'label' => 'Actif'],
                ['id' => Programme::STATUT_INACTIF, 'label' => 'Inactif (non proposable)'],
                ['id' => Programme::STATUT_ARCHIVE, 'label' => 'Archivé (terminal)'],
            ],
            'cycles-diplome' => [
                ['id' => 'licence', 'label' => 'Licence'],
                ['id' => 'master', 'label' => 'Master'],
                ['id' => 'doctorat', 'label' => 'Doctorat'],
            ],
            'modes' => [
                ['id' => 'initiale', 'label' => 'Formation initiale'],
                ['id' => 'continue', 'label' => 'Formation continue'],
                ['id' => 'alternance', 'label' => 'Alternance'],
            ],
            'annees' => AnneeUniversitaire::orderByDesc('date_debut')->get()
                ->map(fn ($m) => ['id' => $m->id, 'label' => $m->libelle])->all(),
            default => [],
        };
    }
}
