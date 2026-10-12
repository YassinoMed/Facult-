<?php

namespace App\Modules\ParcoursAcademique\Services;

use App\Models\Etudiant;
use App\Modules\ParcoursAcademique\Events\ParcoursDecisionEnregistree;
use App\Modules\ParcoursAcademique\Models\ParcoursDispense;
use App\Modules\ParcoursAcademique\Models\ParcoursEquivalence;
use App\Modules\ParcoursAcademique\Models\ParcoursPasserelle;
use App\Modules\ParcoursAcademique\Models\ParcoursReorientation;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Workflows d'aménagement et d'orientation (§8-11).
 * proposee → validee|rejetee → revoquee/annulee.
 * Verrou pessimiste + transaction + audit + event.
 * Aucune écriture dans notes/inscriptions/décisions.
 */
class AmenagementParcoursService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * @param  array<string, mixed>  $donnees
     */
    public function proposerEquivalence(Etudiant $etudiant, array $donnees, ?int $auteurId): ParcoursEquivalence
    {
        $equivalence = ParcoursEquivalence::create([
            'etudiant_id' => $etudiant->id,
            'origine_libelle' => $donnees['origine_libelle'],
            'origine_credits' => $donnees['origine_credits'] ?? 0,
            'cible_matiere_id' => $donnees['cible_matiere_id'] ?? null,
            'cible_ue_id' => $donnees['cible_ue_id'] ?? null,
            'cible_libelle' => $donnees['cible_libelle'],
            'etablissement' => $donnees['etablissement'] ?? null,
            'justification' => $donnees['justification'] ?? null,
            'statut' => 'proposee',
            'auteur_id' => $auteurId,
        ]);

        $this->audit->tracer($etudiant, 'parcours.equivalence_proposee', 'parcours_equivalence', $equivalence->id, null, [
            'cible' => $equivalence->cible_libelle,
        ]);

        return $equivalence;
    }

    public function deciderEquivalence(ParcoursEquivalence $equivalence, string $decision, ?string $justification, ?int $decideurId): ParcoursEquivalence
    {
        abort_unless(in_array($decision, ParcoursEquivalence::DECISIONS, true), 422);

        return DB::transaction(function () use ($equivalence, $decision, $justification, $decideurId) {
            $verrouillee = ParcoursEquivalence::whereKey($equivalence->id)->lockForUpdate()->firstOrFail();
            abort_if($verrouillee->statut !== 'proposee', 409, 'Équivalence déjà traitée.');

            $verrouillee->update([
                'decision' => $decision,
                'justification' => $justification ?? $verrouillee->justification,
                'decide_par' => $decideurId,
                'decide_le' => now(),
                'statut' => $decision === 'accordee' ? 'validee' : 'rejetee',
            ]);

            Event::dispatch(new ParcoursDecisionEnregistree(
                $verrouillee->etudiant, 'equivalence', $verrouillee->id, $verrouillee->statut, $decideurId
            ));

            return $verrouillee->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $donnees
     */
    public function proposerDispense(Etudiant $etudiant, array $donnees, ?int $auteurId): ParcoursDispense
    {
        $dispense = ParcoursDispense::create([
            'etudiant_id' => $etudiant->id,
            'matiere_id' => $donnees['matiere_id'] ?? null,
            'ue_id' => $donnees['ue_id'] ?? null,
            'cible_libelle' => $donnees['cible_libelle'],
            'motif' => $donnees['motif'],
            'responsable' => $donnees['responsable'] ?? null,
            'date_debut' => $donnees['date_debut'] ?? null,
            'date_fin' => $donnees['date_fin'] ?? null,
            'statut' => 'proposee',
            'auteur_id' => $auteurId,
        ]);

        $this->audit->tracer($etudiant, 'parcours.dispense_proposee', 'parcours_dispense', $dispense->id, null, [
            'cible' => $dispense->cible_libelle,
        ]);

        return $dispense;
    }

    public function deciderDispense(ParcoursDispense $dispense, string $statut, ?string $decision, ?int $decideurId): ParcoursDispense
    {
        abort_unless(in_array($statut, ['validee', 'rejetee', 'revoquee'], true), 422);

        return DB::transaction(function () use ($dispense, $statut, $decision, $decideurId) {
            $verrouillee = ParcoursDispense::whereKey($dispense->id)->lockForUpdate()->firstOrFail();

            if ($statut === 'revoquee') {
                abort_if($verrouillee->statut !== 'validee', 409, 'Seule une dispense validée peut être révoquée.');
            } else {
                abort_if($verrouillee->statut !== 'proposee', 409, 'Dispense déjà traitée.');
            }

            $verrouillee->update(['statut' => $statut, 'decision' => $decision]);

            Event::dispatch(new ParcoursDecisionEnregistree(
                $verrouillee->etudiant, 'dispense', $verrouillee->id, $statut, $decideurId
            ));

            return $verrouillee->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $donnees
     */
    public function proposerReorientation(Etudiant $etudiant, array $donnees, ?int $auteurId): ParcoursReorientation
    {
        $reorientation = ParcoursReorientation::create([
            'etudiant_id' => $etudiant->id,
            'ancien_programme_id' => $donnees['ancien_programme_id'] ?? $etudiant->programme_id,
            'ancien_niveau_id' => $donnees['ancien_niveau_id'] ?? $etudiant->niveau_id,
            'ancien_libelle' => $donnees['ancien_libelle'] ?? null,
            'nouveau_programme_id' => $donnees['nouveau_programme_id'],
            'nouveau_niveau_id' => $donnees['nouveau_niveau_id'] ?? null,
            'nouveau_libelle' => $donnees['nouveau_libelle'] ?? null,
            'annee_universitaire_id' => $donnees['annee_universitaire_id'],
            'justification' => $donnees['justification'] ?? null,
            'statut' => 'proposee',
            'auteur_id' => $auteurId,
        ]);

        $this->audit->tracer($etudiant, 'parcours.reorientation_proposee', 'parcours_reorientation', $reorientation->id, null, [
            'nouveau_programme' => $reorientation->nouveau_programme_id,
        ]);

        return $reorientation;
    }

    public function deciderReorientation(ParcoursReorientation $reorientation, string $statut, ?string $decision, ?int $decideurId): ParcoursReorientation
    {
        abort_unless(in_array($statut, ['validee', 'rejetee'], true), 422);

        return DB::transaction(function () use ($reorientation, $statut, $decision, $decideurId) {
            $verrouillee = ParcoursReorientation::whereKey($reorientation->id)->lockForUpdate()->firstOrFail();
            abort_if($verrouillee->statut !== 'proposee', 409, 'Réorientation déjà traitée.');

            $verrouillee->update(['statut' => $statut, 'decision' => $decision]);

            Event::dispatch(new ParcoursDecisionEnregistree(
                $verrouillee->etudiant, 'reorientation', $verrouillee->id, $statut, $decideurId
            ));

            return $verrouillee->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $donnees
     */
    public function proposerPasserelle(Etudiant $etudiant, array $donnees, ?int $auteurId): ParcoursPasserelle
    {
        $passerelle = ParcoursPasserelle::create([
            'etudiant_id' => $etudiant->id,
            'programme_origine_id' => $donnees['programme_origine_id'],
            'niveau_origine_id' => $donnees['niveau_origine_id'] ?? null,
            'programme_cible_id' => $donnees['programme_cible_id'],
            'niveau_cible_id' => $donnees['niveau_cible_id'] ?? null,
            'credits_obtenus' => $donnees['credits_obtenus'] ?? 0,
            'moyenne' => $donnees['moyenne'] ?? null,
            'conditions' => $donnees['conditions'] ?? null,
            'justification' => $donnees['justification'] ?? null,
            'statut' => 'proposee',
            'auteur_id' => $auteurId,
        ]);

        $this->audit->tracer($etudiant, 'parcours.passerelle_proposee', 'parcours_passerelle', $passerelle->id, null, [
            'cible' => $passerelle->programme_cible_id,
        ]);

        return $passerelle;
    }

    public function deciderPasserelle(ParcoursPasserelle $passerelle, string $statut, ?string $decisionAdmission, ?int $decideurId): ParcoursPasserelle
    {
        abort_unless(in_array($statut, ['admise', 'refusee'], true), 422);

        return DB::transaction(function () use ($passerelle, $statut, $decisionAdmission, $decideurId) {
            $verrouillee = ParcoursPasserelle::whereKey($passerelle->id)->lockForUpdate()->firstOrFail();
            abort_if(! in_array($verrouillee->statut, ['proposee', 'eligible', 'non_eligible'], true), 409, 'Passerelle déjà décidée.');

            $verrouillee->update(['statut' => $statut, 'decision_admission' => $decisionAdmission]);

            Event::dispatch(new ParcoursDecisionEnregistree(
                $verrouillee->etudiant, 'passerelle', $verrouillee->id, $statut, $decideurId
            ));

            return $verrouillee->fresh();
        });
    }
}
