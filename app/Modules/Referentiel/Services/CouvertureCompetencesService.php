<?php

namespace App\Modules\Referentiel\Services;

use App\Models\Matiere;
use App\Modules\Referentiel\Models\Competence;
use App\Modules\Referentiel\Models\CompetenceObjectif;
use App\Modules\Referentiel\Models\CompetenceRapportSnapshot;
use App\Modules\Referentiel\Models\Maquette;
use App\Modules\Referentiel\Models\MaquetteUe;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\UniteEnseignement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Lot 20 — Compétences et couverture pédagogique.
 *
 * RÈGLES DU CAHIER (interdits et exigences) :
 * - INVENTER des compétences ou une taxonomie de niveaux : INTERDIT —
 *   catalogue et `niveau_attendu` sont SAISIS par l'administration ;
 * - les rapports DISTINGUENT « absence de données » (UE/ECUE jamais
 *   reliée : `non_renseigne`) de « absence de couverture » (compétence
 *   du périmètre qu'aucune UE/ECUE/programme ne couvre : `non_couverte`) ;
 * - les imports DÉTECTENT les ambiguïtés (code UE/programme matchant
 *   plusieurs entités) au lieu de choisir arbitrairement une association ;
 * - les associations portent sur les entités canoniques : une nouvelle
 *   version de maquette ne modifie jamais les associations existantes
 *   ni les résultats déjà publiés (aucune écriture rétroactive).
 *
 * Lecture seule sur toutes les tables du référentiel ; écriture
 * uniquement dans `competences` et `competence_objectifs`.
 */
final class CouvertureCompetencesService
{
    /** Modèles canoniques associables (existence vérifiée, jamais de lien mort). */
    private const MODELES_CIBLE = [
        'programme' => Programme::class,
        'ue' => UniteEnseignement::class,
        'ecue' => Matiere::class,
    ];

    /** Plafond de garde : lignes par import, éléments par rapport. */
    public const PLAFOND_LIGNES_IMPORT = 500;

    /* ── Catalogue ─────────────────────────────────────────────── */

    /**
     * Créer une compétence en brouillon (version 1).
     */
    public function creer(array $donnees, ?int $auteurId): Competence
    {
        return Competence::create([
            'code' => $donnees['code'],
            'libelle' => $donnees['libelle'],
            'description' => $donnees['description'] ?? null,
            'faculte_id' => $donnees['faculte_id'] ?? null,
            'statut' => 'brouillon',
            'version' => 1,
            'cree_par' => $auteurId,
        ]);
    }

    /**
     * Réviser un BROUILLON (libellé/description/portée) : la version est
     * incrémentée — trace des révisions. Une compétence figée
     * (approuvée/archivée) est REFUSÉE : archiver et recréer, jamais de
     * réinterprétation rétroactive.
     */
    public function reviser(Competence $competence, array $donnees): Competence
    {
        if ($competence->estFigee()) {
            throw new \DomainException('Compétence figée ('.$competence->statut.') : archivez-la et créez une nouvelle compétence.');
        }

        $competence->forceFill([
            'libelle' => $donnees['libelle'] ?? $competence->libelle,
            'description' => array_key_exists('description', $donnees) ? $donnees['description'] : $competence->description,
            'faculte_id' => array_key_exists('faculte_id', $donnees) ? $donnees['faculte_id'] : $competence->faculte_id,
            'version' => (int) $competence->version + 1,
        ])->save();

        return $competence->refresh();
    }

    /**
     * Approuver (figer) un brouillon — acte de l'autorité d'approbation
     * (contrôleur : `maquettes.approve`, la même que les maquettes).
     */
    public function approuver(Competence $competence, int $auteurId): Competence
    {
        if ($competence->statut !== 'brouillon') {
            throw new \DomainException('Seul un brouillon peut être approuvé.');
        }

        $competence->forceFill([
            'statut' => 'approuvee',
            'approuve_par' => $auteurId,
            'approuve_le' => now(),
        ])->save();

        return $competence->refresh();
    }

    /**
     * Archiver une compétence approuvée : ses associations existantes
     * restent lisibles (trace) mais elle ne peut plus être associée.
     */
    public function archiver(Competence $competence): Competence
    {
        if ($competence->statut !== 'approuvee') {
            throw new \DomainException('Seule une compétence approuvée peut être archivée.');
        }

        $competence->forceFill(['statut' => 'archivee'])->save();

        return $competence->refresh();
    }

    /* ── Associations canoniques ───────────────────────────────── */

    /**
     * Associer une compétence APPROUVÉE à une cible canonique EXISTANTE,
     * avec cohérence de portée facultaire. Idempotent : re-lier retourne
     * l'association existante (UNIQUE compétence+cible).
     *
     * @throws \DomainException si la compétence n'est pas approuvée, la
     *                          cible n'existe pas ou la portée est incohérente.
     */
    public function associer(Competence $competence, string $cibleType, int $cibleId, ?string $niveauAttendu, ?int $auteurId): CompetenceObjectif
    {
        if (! $competence->estApprouvee()) {
            throw new \DomainException('Seule une compétence APPROUVÉE peut être associée (statut : '.$competence->statut.').');
        }

        $cible = $this->trouverCible($cibleType, $cibleId);

        if ($cible === null) {
            throw new \DomainException("La cible « {$cibleType} » #{$cibleId} n'existe pas : association refusée (jamais de lien mort).");
        }

        $faculteCible = $this->faculteDeLaCible($cibleType, $cible);

        if ($competence->faculte_id !== null && $faculteCible !== null && (int) $competence->faculte_id !== $faculteCible) {
            throw new \DomainException('Compétence locale à la faculté #'.$competence->faculte_id.' : cible hors périmètre.');
        }

        return CompetenceObjectif::firstOrCreate(
            ['competence_id' => $competence->id, 'cible_type' => $cibleType, 'cible_id' => $cibleId],
            ['niveau_attendu' => $niveauAttendu, 'cree_par' => $auteurId],
        );
    }

    /**
     * Dissocier (l'association canonique disparaît — les rapports futurs
     * n'affichent plus la couverture ; aucun résultat historique stocké
     * n'est modifié, les rapports sont calculés à la demande).
     */
    public function dissocier(Competence $competence, string $cibleType, int $cibleId): bool
    {
        return (bool) CompetenceObjectif::where('competence_id', $competence->id)
            ->where('cible_type', $cibleType)
            ->where('cible_id', $cibleId)
            ->delete();
    }

    /* ── Import par codes : ambiguïtés DÉTECTÉES, jamais arbitraires ── */

    /**
     * Importer des associations par CODES (compétence + cible). Chaque
     * ligne est résolue indépendamment : valide → créée (idempotente) ;
     * code inconnu, compétence non approuvée ou AMBIGUÏTÉ (code matchant
     * plusieurs entités) → rejetée avec motif EXPLICITE. Aucune
     * association n'est créée arbitrairement pour une ligne ambiguë.
     *
     * `cible_contexte` désambiguïse un code non unique : code programme
     * pour une UE, code UE pour un ECUE, code faculté pour un programme.
     * Sans contexte, une cible matchant plusieurs entités reste rejetée.
     *
     * @param  iterable<array{competence_code: string, cible_type: string, cible_code: string, cible_contexte?: ?string, niveau_attendu?: ?string}>  $lignes
     * @return array{creees: int, ignorees: int, rejets: array<int, array{ligne: int, motif: string}>}
     */
    public function importer(iterable $lignes, ?int $auteurId): array
    {
        $resultat = ['creees' => 0, 'ignorees' => 0, 'rejets' => []];
        $numero = 0;

        foreach ($lignes as $ligne) {
            $numero++;

            if ($numero > self::PLAFOND_LIGNES_IMPORT) {
                $resultat['rejets'][] = ['ligne' => $numero, 'motif' => 'Plafond de '.self::PLAFOND_LIGNES_IMPORT.' lignes par import dépassé.'];
                break;
            }

            $competence = Competence::where('code', (string) $ligne['competence_code'])->first();

            if ($competence === null) {
                $resultat['rejets'][] = ['ligne' => $numero, 'motif' => 'Compétence « '.$ligne['competence_code'].' » inconnue.'];

                continue;
            }

            if (! $competence->estApprouvee()) {
                $resultat['rejets'][] = ['ligne' => $numero, 'motif' => 'Compétence « '.$competence->code.'» non approuvée ('.$competence->statut.').'];

                continue;
            }

            $cibleType = (string) $ligne['cible_type'];

            [$cibleId, $motif] = $this->resoudreCodeUnique(
                $cibleType,
                (string) $ligne['cible_code'],
                isset($ligne['cible_contexte']) ? (string) $ligne['cible_contexte'] : null,
            );

            if ($cibleId === null) {
                $resultat['rejets'][] = ['ligne' => $numero, 'motif' => $motif];

                continue;
            }

            try {
                $association = $this->associer($competence, $cibleType, $cibleId, $ligne['niveau_attendu'] ?? null, $auteurId);
            } catch (\DomainException $e) {
                $resultat['rejets'][] = ['ligne' => $numero, 'motif' => $e->getMessage()];

                continue;
            }

            $association->wasRecentlyCreated ? $resultat['creees']++ : $resultat['ignorees']++;
        }

        return $resultat;
    }

    /**
     * Résoudre un code cible en id UNIQUE — retourne [id, null] ou
     * [null, motif]. Les codes UE et programme ne sont uniques que par
     * semestre/faculté : un code matchant PLUSIEURS entités est une
     * AMBIGUÏTÉ signalée avec ses candidats, jamais un choix arbitraire.
     * Un `cible_contexte` explicite (faculté pour un programme, programme
     * pour une UE, UE pour un ECUE) lève l'ambiguïté sans jamais choisir
     * à la place de l'utilisateur.
     *
     * @return array{0: ?int, 1: ?string}
     */
    private function resoudreCodeUnique(string $cibleType, string $code, ?string $contexte = null): array
    {
        $modele = self::MODELES_CIBLE[$cibleType] ?? null;

        if ($modele === null) {
            return [null, "Type de cible « {$cibleType} » inconnu (programme, ue, ecue)."];
        }

        $contexte = $contexte !== null && trim($contexte) !== '' ? trim($contexte) : null;

        $requete = $modele::query()->where('code', $code);
        $this->appliquerContexte($requete, $cibleType, $contexte);
        $candidats = $requete->get(['id']);

        if ($candidats->count() === 0) {
            return [null, $contexte === null
                ? "Code « {$code} » inconnu pour une cible {$cibleType}."
                : "Code « {$code} » inconnu pour une cible {$cibleType} dans le contexte « {$contexte} »."];
        }

        if ($candidats->count() > 1) {
            $ids = $candidats->pluck('id')->implode(', ');

            return [null, "Code « {$code} » AMBIGU pour une cible {$cibleType} : {$candidats->count()} entités (ids {$ids}) — précisez `cible_contexte` (".$this->descriptionContexte($cibleType).") ou l'identifiant."];
        }

        return [(int) $candidats->first()->id, null];
    }

    /**
     * Restreindre la résolution par le contexte documenté du type de cible.
     */
    private function appliquerContexte(Builder $requete, string $cibleType, ?string $contexte): void
    {
        if ($contexte === null) {
            return;
        }

        match ($cibleType) {
            'programme' => $requete->whereHas('faculte', fn ($q) => $q->where('code', $contexte)),
            'ue' => $requete->whereHas('programme', fn ($q) => $q->where('code', $contexte)),
            'ecue' => $requete->whereHas('ue', fn ($q) => $q->where('code', $contexte)),
            default => null,
        };
    }

    /**
     * Nature du contexte attendu pour chaque type de cible (message d'erreur).
     */
    private function descriptionContexte(string $cibleType): string
    {
        return match ($cibleType) {
            'programme' => 'code faculté',
            'ue' => 'code programme',
            'ecue' => 'code UE',
            default => 'contexte',
        };
    }

    /* ── Rapport de couverture d'une maquette ───────────────────── */

    /**
     * Rapport de couverture pédagogique d'une maquette (lecture seule).
     *
     * Deux axes DISTINCTS (critère du cahier) :
     * - par UE/ECUE du plan : `renseigne` (≥ 1 compétence associée) ou
     *   `non_renseigne` = ABSENCE DE DONNÉE (l'administration n'a pas
     *   encore relié l'entité — jamais interprété comme lacune) ;
     * - par compétence du périmètre (approuvées, établissement + faculté
     *   du programme) : `couverte` (associée au programme, à une UE ou à
     *   un ECUE du plan) ou `non_couverte` = ABSENCE DE COUVERTURE
     *   (lacune réelle du plan d'études vis-à-vis du catalogue).
     *
     * @return array<string, mixed>
     */
    public function rapportCouverture(Maquette $maquette): array
    {
        $maquette->loadMissing('programme.faculte', 'anneeUniversitaire');

        $faculteId = $maquette->programme?->faculte_id !== null ? (int) $maquette->programme->faculte_id : null;

        $lignesUe = MaquetteUe::where('maquette_id', $maquette->id)
            ->with(['ue', 'ecues.matiere'])
            ->orderBy('id')
            ->get();

        // Associations du périmètre : vers le programme, les UE du plan
        // et les matières (ECUE canoniques) du plan.
        $ueIds = $lignesUe->pluck('ue_id')->filter()->unique()->values();
        $ecueIds = $lignesUe->flatMap(fn ($l) => $l->ecues->pluck('matiere_id'))->filter()->unique()->values();
        $programmeId = (int) $maquette->programme_id;

        $associations = CompetenceObjectif::query()
            ->whereIn('cible_id', $ueIds->concat($ecueIds)->push($programmeId)->unique()->all())
            ->with('competence:id,code,libelle,faculte_id,statut')
            ->get();

        $versUe = $associations->where('cible_type', 'ue')->groupBy('cible_id');
        $versEcue = $associations->where('cible_type', 'ecue')->groupBy('cible_id');

        // Axe 1 : par entité du plan (absence de DONNÉE).
        $ues = [];
        $ecues = [];
        $ueRenseignees = 0;
        $ecueRenseignes = 0;

        foreach ($lignesUe as $ligne) {
            $liees = $versUe->get($ligne->ue_id, collect())->pluck('competence')->filter()->values();
            $ues[] = [
                'ue_id' => $ligne->ue_id,
                'code' => $ligne->ue?->code,
                'etat' => $liees->isNotEmpty() ? 'renseigne' : 'non_renseigne',
                'competences' => $liees->map(fn ($c) => ['code' => $c->code, 'libelle' => $c->libelle])->all(),
            ];
            $liees->isNotEmpty() && $ueRenseignees++;

            foreach ($ligne->ecues as $contexte) {
                $lieesEcue = $versEcue->get($contexte->matiere_id, collect())->pluck('competence')->filter()->values();
                $ecues[] = [
                    'matiere_id' => $contexte->matiere_id,
                    'code' => $contexte->matiere?->code,
                    'ue' => $ligne->ue?->code,
                    'etat' => $lieesEcue->isNotEmpty() ? 'renseigne' : 'non_renseigne',
                    'competences' => $lieesEcue->map(fn ($c) => ['code' => $c->code, 'libelle' => $c->libelle])->all(),
                ];
                $lieesEcue->isNotEmpty() && $ecueRenseignes++;
            }
        }

        // Axe 2 : par compétence du périmètre (absence de COUVERTURE).
        $competencesPerimetre = Competence::query()
            ->where('statut', 'approuvee')
            ->when($faculteId !== null, fn ($q) => $q->where(function ($w) use ($faculteId) {
                $w->whereNull('faculte_id')->orWhere('faculte_id', $faculteId);
            }))
            ->orderBy('code')
            ->get(['id', 'code', 'libelle']);

        $couvertesParId = $associations->pluck('competence_id')->unique();
        $competences = [];
        $couvertes = 0;

        foreach ($competencesPerimetre as $competence) {
            $estCouverte = $couvertesParId->contains($competence->id);
            $estCouverte && $couvertes++;
            $competences[] = [
                'code' => $competence->code,
                'libelle' => $competence->libelle,
                'etat' => $estCouverte ? 'couverte' : 'non_couverte',
            ];
        }

        return [
            'maquette' => [
                'id' => $maquette->id,
                'version' => $maquette->version,
                'statut' => $maquette->statut,
                'programme' => $maquette->programme?->code,
                'annee' => $maquette->anneeUniversitaire?->libelle,
            ],
            'ues' => $ues,
            'ecues' => $ecues,
            'competences' => $competences,
            'totaux' => [
                'ues' => count($ues),
                'ues_renseignees' => $ueRenseignees,
                'ecues' => count($ecues),
                'ecues_renseignes' => $ecueRenseignes,
                'competences_perimetre' => $competencesPerimetre->count(),
                'competences_couvertes' => $couvertes,
            ],
            'legende' => [
                'non_renseigne' => 'ABSENCE DE DONNÉE : l\'UE/ECUE n\'a pas encore été reliée à une compétence.',
                'non_couverte' => 'ABSENCE DE COUVERTURE : aucune UE/ECUE/programme du périmètre ne couvre cette compétence.',
            ],
        ];
    }

    /* ── Snapshots de rapport (trace figée) ─────────────────────── */

    /**
     * Figer le rapport de couverture courant dans un snapshot immuable.
     *
     * Les associations restent canoniques et non versionnées (choix MVP) :
     * le snapshot est la SEULE trace figée d'un rapport publié. Il ne
     * modifie ni les associations ni les maquettes.
     */
    public function figerRapport(Maquette $maquette, ?int $auteurId): CompetenceRapportSnapshot
    {
        $rapport = $this->rapportCouverture($maquette);

        return CompetenceRapportSnapshot::create([
            'maquette_id' => $maquette->id,
            'empreinte' => hash('sha256', $this->canoniserJson($rapport)),
            'donnees' => $rapport,
            'cree_par' => $auteurId,
            'created_at' => now(),
        ]);
    }

    /**
     * Historique des snapshots d'une maquette (plus récent d'abord).
     *
     * @return Collection<int, CompetenceRapportSnapshot>
     */
    public function historiqueRapports(Maquette $maquette, int $limite = 10)
    {
        return CompetenceRapportSnapshot::query()
            ->where('maquette_id', $maquette->id)
            ->orderByDesc('id')
            ->limit($limite)
            ->get();
    }

    /**
     * Sérialisation canonique (clés triées) : mêmes données ⇒ même
     * empreinte, quel que soit l'ordre de construction des tableaux.
     */
    private function canoniserJson(mixed $valeur): string
    {
        return json_encode(
            $this->trierRecursivement($valeur),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    private function trierRecursivement(mixed $valeur): mixed
    {
        if (! is_array($valeur)) {
            return $valeur;
        }

        if (array_is_list($valeur)) {
            return array_map(fn ($element) => $this->trierRecursivement($element), $valeur);
        }

        ksort($valeur);

        return array_map(fn ($element) => $this->trierRecursivement($element), $valeur);
    }

    /* ── Validation de cohérence ────────────────────────────────── */

    /**
     * Contrôle de cohérence des associations : compétences approuvées,
     * cibles existantes, types valides. Retourne les anomalies trouvées
     * (lecture seule — la correction est humaine).
     *
     * @return array<int, array{competence_objectif_id: int, anomalie: string}>
     */
    public function verifierCoherence(): array
    {
        $anomalies = [];

        CompetenceObjectif::query()->with('competence:id,code,statut')
            ->chunkById(200, function ($chunk) use (&$anomalies): void {
                foreach ($chunk as $objectif) {
                    if ($objectif->competence === null) {
                        $anomalies[] = ['competence_objectif_id' => $objectif->id, 'anomalie' => 'Compétence introuvable.'];

                        continue;
                    }

                    if (! $objectif->competence->estApprouvee()) {
                        $anomalies[] = ['competence_objectif_id' => $objectif->id, 'anomalie' => 'Compétence « '.$objectif->competence->code.'» non approuvée ('.$objectif->competence->statut.').'];
                    }

                    if (! in_array($objectif->cible_type, CompetenceObjectif::TYPES_CIBLE, true)) {
                        $anomalies[] = ['competence_objectif_id' => $objectif->id, 'anomalie' => 'Type de cible inconnu : '.$objectif->cible_type.'.'];

                        continue;
                    }

                    if ($this->trouverCible($objectif->cible_type, (int) $objectif->cible_id) === null) {
                        $anomalies[] = ['competence_objectif_id' => $objectif->id, 'anomalie' => 'Cible '.$objectif->cible_type.' #'.$objectif->cible_id.' introuvable (lien mort).'];
                    }
                }
            });

        return $anomalies;
    }

    /* ── Internes ───────────────────────────────────────────────── */

    private function trouverCible(string $cibleType, int $cibleId): ?object
    {
        $modele = self::MODELES_CIBLE[$cibleType] ?? null;

        if ($modele === null) {
            return null;
        }

        return $modele::query()->find($cibleId);
    }

    /**
     * Faculté d'une cible canonique (null = sans rattachement facultaire).
     */
    private function faculteDeLaCible(string $cibleType, object $cible): ?int
    {
        if ($cibleType === 'programme') {
            return $cible->faculte_id !== null ? (int) $cible->faculte_id : null;
        }

        if ($cibleType === 'ue') {
            return $cible->programme?->faculte_id !== null ? (int) $cible->programme->faculte_id : null;
        }

        // ECUE : faculté via l'UE canonique de la matière (ue_id nullable).
        return $cible->ue?->programme?->faculte_id !== null ? (int) $cible->ue?->programme?->faculte_id : null;
    }
}
