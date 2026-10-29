<?php

namespace App\Modules\Evaluation\Services;

use App\Models\Etudiant;
use App\Modules\Evaluation\Exceptions\RegroupementVirtuelRefuse;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\GroupeVirtuelExamen;
use App\Modules\Evaluation\Models\GroupeVirtuelMembre;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Schedule\Models\Group;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Groupes virtuels d'examen (§2.1).
 *
 * Règles appliquées, côté serveur uniquement :
 * - correspondance académique EXPLICITE (faculté, programme, niveau,
 *   filière) : une source divergente fait échouer la proposition, elle
 *   n'est jamais « rapprochée » silencieusement ;
 * - seuls les étudiants réellement inscrits au module et éligibles sont
 *   retenus — la décision vient de `ServiceEligibiliteExamen`, jamais
 *   d'une simple appartenance à un groupe ;
 * - aucune écriture sur `etudiants`, `groups`, `inscriptions_*` : le
 *   regroupement est un calcul, pas une réaffectation ;
 * - un même module peut agréger plusieurs groupes sources ;
 * - le regroupement est propre à chaque module (la clé contient la
 *   matière) ;
 * - une nouvelle proposition remplace la précédente en CONSERVANT
 *   l'historique (`statut = remplace`) ;
 * - la proposition ne certifie ni ne publie rien.
 */
final class GroupeVirtuelExamenService
{
    public function __construct(
        private readonly ServiceEligibiliteExamen $eligibilite,
    ) {}

    /**
     * Périmètre académique de l'épreuve, dérivé de données explicites.
     *
     * Priorité : examen (programme/niveau) → groupe de l'épreuve → chaîne
     * ECUE → UE → programme. Aucune valeur n'est devinée.
     *
     * @return array{faculte_id: ?int, programme_id: ?int, niveau_id: ?int, filiere: ?string}
     */
    public function perimetreAcademique(Epreuve $epreuve): array
    {
        $epreuve->loadMissing(['examen', 'groupe', 'matiere.ue.programme']);

        $programmeId = $epreuve->examen?->programme_id
            ?? $epreuve->groupe?->programme_id
            ?? $epreuve->matiere?->ue?->programme_id;

        $niveauId = $epreuve->examen?->niveau_id
            ?? $epreuve->groupe?->niveau_id;

        $faculteId = $epreuve->examen?->faculteId()
            ?? $epreuve->groupe?->faculte_id
            ?? $epreuve->matiere?->ue?->programme?->faculte_id;

        $filiere = $epreuve->groupe?->filiere;

        return [
            'faculte_id' => $faculteId === null ? null : (int) $faculteId,
            'programme_id' => $programmeId === null ? null : (int) $programmeId,
            'niveau_id' => $niveauId === null ? null : (int) $niveauId,
            'filiere' => $filiere === null || $filiere === '' ? null : (string) $filiere,
        ];
    }

    /**
     * Détecte automatiquement les groupes sources candidats.
     *
     * Un groupe est candidat si sa correspondance académique est vérifiable
     * et qu'au moins un de ses étudiants est éligible au module de l'épreuve.
     *
     * @return array{perimetre: array<string, mixed>, sources: array<int, array<string, mixed>>, motif: ?string}
     */
    public function detecter(Epreuve $epreuve): array
    {
        $perimetre = $this->perimetreAcademique($epreuve);

        if ($perimetre['faculte_id'] === null) {
            return [
                'perimetre' => $perimetre,
                'sources' => [],
                'motif' => 'Périmètre académique indéterminé (faculté absente) : détection automatique refusée.',
            ];
        }

        if ($perimetre['programme_id'] === null && $perimetre['niveau_id'] === null) {
            return [
                'perimetre' => $perimetre,
                'sources' => [],
                'motif' => 'Périmètre académique indéterminé (programme et niveau absents) : détection automatique refusée.',
            ];
        }

        $groupes = Group::query()
            ->where('faculte_id', $perimetre['faculte_id'])
            ->where('actif', true)
            ->orderBy('code')
            ->get()
            ->filter(fn (Group $groupe) => $this->correspond($groupe, $perimetre));

        $sources = [];

        foreach ($groupes as $groupe) {
            $decisions = $this->evaluerGroupe($epreuve, $groupe);
            $eligibles = $decisions->where('decision', GroupeVirtuelMembre::DECISION_ELIGIBLE);

            if ($eligibles->isEmpty()) {
                continue;
            }

            $sources[] = [
                'groupe_id' => (int) $groupe->id,
                'code' => $groupe->code,
                'nom' => $groupe->nom,
                'effectif_source' => $decisions->count(),
                'eligibles' => $eligibles->count(),
                'non_eligibles' => $decisions->where('decision', GroupeVirtuelMembre::DECISION_NON_ELIGIBLE)->count(),
                'anomalies' => $decisions->where('decision', GroupeVirtuelMembre::DECISION_ANOMALIE)->count(),
            ];
        }

        return [
            'perimetre' => $perimetre,
            'sources' => $sources,
            'motif' => $sources === [] ? 'Aucun groupe ne contient d’étudiant éligible pour ce module.' : null,
        ];
    }

    /**
     * Propose (et persiste) un groupe virtuel à partir de sources explicites.
     *
     * @param  array<int, int|string>  $sourcesIds
     *
     * @throws RegroupementVirtuelRefuse
     */
    public function proposer(Epreuve $epreuve, array $sourcesIds, ?int $auteurId = null): GroupeVirtuelExamen
    {
        $ids = array_values(array_unique(array_map('intval', $sourcesIds)));

        if ($ids === []) {
            throw new RegroupementVirtuelRefuse('Aucun groupe source fourni.');
        }

        return DB::transaction(function () use ($epreuve, $ids, $auteurId) {
            /** @var Epreuve $verrouillee */
            $verrouillee = Epreuve::whereKey($epreuve->id)->lockForUpdate()->firstOrFail();

            if ($verrouillee->matiere_id === null) {
                throw new RegroupementVirtuelRefuse('Module absent : un groupe virtuel doit viser un module explicite.');
            }

            $perimetre = $this->perimetreAcademique($verrouillee);

            if ($perimetre['faculte_id'] === null || ($perimetre['programme_id'] === null && $perimetre['niveau_id'] === null)) {
                throw new RegroupementVirtuelRefuse('Périmètre académique indéterminé : regroupement refusé.');
            }

            $sources = Group::query()->whereIn('id', $ids)->orderBy('code')->get();

            if ($sources->count() !== count($ids)) {
                throw new RegroupementVirtuelRefuse('Un ou plusieurs groupes sources sont introuvables.');
            }

            $incompatibles = $sources->reject(fn (Group $groupe) => $this->correspond($groupe, $perimetre));

            if ($incompatibles->isNotEmpty()) {
                throw new RegroupementVirtuelRefuse(
                    'Correspondance académique incompatible pour : '
                    .$incompatibles->map(fn (Group $g) => $g->code)->implode(', ')
                    .'. Niveau, programme ou filière différents — la fusion est refusée.'
                );
            }

            $cle = GroupeVirtuelExamen::calculerCle(
                (int) $verrouillee->examen_id,
                $verrouillee->matiere_id === null ? null : (int) $verrouillee->matiere_id,
                $perimetre['programme_id'],
                $perimetre['niveau_id'],
                $perimetre['filiere'],
                $ids,
            );

            $existante = GroupeVirtuelExamen::query()
                ->where('examen_id', $verrouillee->examen_id)
                ->where('matiere_id', $verrouillee->matiere_id)
                ->where('statut', GroupeVirtuelExamen::STATUT_PROPOSE)
                ->where('cle', $cle)
                ->first();

            // Idempotence : même périmètre et mêmes sources ⇒ même proposition.
            if ($existante !== null) {
                return $existante->load('membres');
            }

            $groupe = GroupeVirtuelExamen::create([
                'examen_id' => $verrouillee->examen_id,
                'epreuve_id' => $verrouillee->id,
                'matiere_id' => $verrouillee->matiere_id,
                'programme_id' => $perimetre['programme_id'],
                'niveau_id' => $perimetre['niveau_id'],
                'filiere' => $perimetre['filiere'],
                'libelle' => $this->libelle($verrouillee, $sources),
                'cle' => $cle,
                'statut' => GroupeVirtuelExamen::STATUT_PROPOSE,
                'sources' => $ids,
                'cree_par' => $auteurId,
            ]);

            $membres = [];

            foreach ($sources as $source) {
                foreach ($this->evaluerGroupe($verrouillee, $source) as $decision) {
                    // Un étudiant présent dans deux sources ne compte qu'une fois.
                    if (isset($membres[$decision['etudiant_id']])) {
                        continue;
                    }

                    $membres[$decision['etudiant_id']] = $decision + [
                        'groupe_virtuel_id' => $groupe->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }

            if ($membres !== []) {
                GroupeVirtuelMembre::insert(array_values($membres));
            }

            // Remplacement : l'ancienne proposition reste consultable.
            $precedentes = GroupeVirtuelExamen::query()
                ->where('examen_id', $verrouillee->examen_id)
                ->where('matiere_id', $verrouillee->matiere_id)
                ->where('statut', GroupeVirtuelExamen::STATUT_PROPOSE)
                ->where('id', '!=', $groupe->id)
                ->get();

            foreach ($precedentes as $precedente) {
                $precedente->update([
                    'statut' => GroupeVirtuelExamen::STATUT_REMPLACE,
                    'remplace_par_id' => $groupe->id,
                ]);
            }

            if ($verrouillee->population_validated_at !== null) {
                $verrouillee->forceFill(['population_validated_at' => null])->save();
            }

            app(EvaluationNotificationService::class)->regroupementVirtuelPropose($groupe, $auteurId);

            return $groupe->fresh('membres');
        });
    }

    /**
     * Propose automatiquement le regroupement détecté.
     */
    public function proposerAutomatiquement(Epreuve $epreuve, ?int $auteurId = null): ?GroupeVirtuelExamen
    {
        $detection = $this->detecter($epreuve);

        if ($detection['sources'] === []) {
            return null;
        }

        return $this->proposer(
            $epreuve,
            array_map(fn (array $source) => $source['groupe_id'], $detection['sources']),
            $auteurId,
        );
    }

    /**
     * Membres éligibles d'une proposition, sans PII superflue.
     *
     * @return Collection<int, GroupeVirtuelMembre>
     */
    public function membresEligibles(GroupeVirtuelExamen $groupe): Collection
    {
        return $groupe->membres()
            ->with('etudiant:id,nom,prenom,cin,groupe_id')
            ->where('decision', GroupeVirtuelMembre::DECISION_ELIGIBLE)
            ->orderBy('etudiant_id')
            ->get();
    }

    /**
     * Correspondance académique explicite d'un groupe source.
     *
     * @param  array{faculte_id: ?int, programme_id: ?int, niveau_id: ?int, filiere: ?string}  $perimetre
     */
    public function correspond(Group $groupe, array $perimetre): bool
    {
        if ($perimetre['faculte_id'] !== null && (int) $groupe->faculte_id !== $perimetre['faculte_id']) {
            return false;
        }

        if ($perimetre['programme_id'] !== null) {
            if ($groupe->programme_id === null || (int) $groupe->programme_id !== $perimetre['programme_id']) {
                return false;
            }
        }

        if ($perimetre['niveau_id'] !== null) {
            if ($groupe->niveau_id !== null) {
                if ((int) $groupe->niveau_id !== $perimetre['niveau_id']) {
                    return false;
                }
            } else {
                // Repli explicite et vérifiable : le code niveau du groupe doit
                // correspondre au code du niveau de l'épreuve. Aucune
                // correspondance approximative n'est acceptée.
                $code = $groupe->niveauRef?->code
                    ?? Niveau::whereKey($perimetre['niveau_id'])->value('code');

                if ($code === null || (string) $groupe->niveau !== (string) $code) {
                    return false;
                }
            }
        }

        if ($perimetre['filiere'] !== null && $groupe->filiere !== null
            && (string) $groupe->filiere !== $perimetre['filiere']) {
            return false;
        }

        return true;
    }

    /**
     * Évalue chaque étudiant du groupe source, module de l'épreuve à l'appui.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function evaluerGroupe(Epreuve $epreuve, Group $groupe): Collection
    {
        return Etudiant::query()
            ->where('groupe_id', $groupe->id)
            ->orderBy('id')
            ->get()
            ->map(function (Etudiant $etudiant) use ($epreuve, $groupe): array {
                $decision = $this->eligibilite->evaluer($epreuve, $etudiant);

                return [
                    'etudiant_id' => (int) $etudiant->id,
                    'groupe_source_id' => (int) $groupe->id,
                    'inscription_academique_id' => $decision->inscriptionAcademiqueId,
                    'inscription_pedagogique_id' => $decision->inscriptionPedagogiqueId,
                    'decision' => $decision->decision,
                    'regle' => $decision->regle,
                    'motif' => $decision->motif,
                ];
            });
    }

    /**
     * @param  Collection<int, Group>  $sources
     */
    private function libelle(Epreuve $epreuve, Collection $sources): string
    {
        $module = $epreuve->matiere?->code ?? ('Module #'.$epreuve->matiere_id);

        return mb_substr('GV '.$module.' — '.$sources->map(fn (Group $g) => $g->code)->implode(' + '), 0, 150);
    }
}
