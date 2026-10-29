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
 * proposee → validee|rejetee ; validee → revoquee :
 * transitions définies dans la constante TRANSITIONS (source unique).
 * Verrou pessimiste + transaction + audit + event.
 * Aucune écriture dans notes/inscriptions/décisions.
 */
class AmenagementParcoursService
{
    /**
     * Machine à états décisionnelle (§8-11) : source unique des
     * transitions autorisées. `champ` = champ consommé par la
     * décision de la catégorie ; `entrees` = cible => états sources
     * autorisés. Les contrôleurs valident l'entrée avec cette carte,
     * le service la re-vérifie sous transaction + verrou pessimiste.
     * Tout ajout de statut passe ici, jamais par une liste en ligne.
     */
    public const TRANSITIONS = [
        'equivalences' => [
            'champ' => 'decision', // statut final dérivé : accordee→validee, refusee→rejetee
            'entrees' => [
                'accordee' => ['proposee'],
                'refusee' => ['proposee'],
            ],
        ],
        'dispenses' => [
            'champ' => 'statut',
            'entrees' => [
                'validee' => ['proposee'],
                'rejetee' => ['proposee'],
                'revoquee' => ['validee'],
            ],
        ],
        'reorientations' => [
            'champ' => 'statut', // 'annulee' : état réservé du modèle, aucune transition ouverte
            'entrees' => [
                'validee' => ['proposee'],
                'rejetee' => ['proposee'],
            ],
        ],
        'passerelles' => [
            'champ' => 'statut',
            'entrees' => [
                'admise' => ['proposee', 'eligible', 'non_eligible'],
                'refusee' => ['proposee', 'eligible', 'non_eligible'],
            ],
        ],
    ];

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
        abort_unless(isset(self::TRANSITIONS['equivalences']['entrees'][$decision]), 422);

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
        $depuis = self::TRANSITIONS['dispenses']['entrees'][$statut] ?? null;
        abort_unless($depuis !== null, 422);

        return DB::transaction(function () use ($dispense, $statut, $decision, $depuis, $decideurId) {
            $verrouillee = ParcoursDispense::whereKey($dispense->id)->lockForUpdate()->firstOrFail();

            $message = $statut === 'revoquee'
                ? 'Seule une dispense validée peut être révoquée.'
                : 'Dispense déjà traitée.';
            abort_if(! in_array($verrouillee->statut, $depuis, true), 409, $message);

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
        $depuis = self::TRANSITIONS['reorientations']['entrees'][$statut] ?? null;
        abort_unless($depuis !== null, 422);

        return DB::transaction(function () use ($reorientation, $statut, $decision, $depuis, $decideurId) {
            $verrouillee = ParcoursReorientation::whereKey($reorientation->id)->lockForUpdate()->firstOrFail();
            abort_if(! in_array($verrouillee->statut, $depuis, true), 409, 'Réorientation déjà traitée.');

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
        $depuis = self::TRANSITIONS['passerelles']['entrees'][$statut] ?? null;
        abort_unless($depuis !== null, 422);

        return DB::transaction(function () use ($passerelle, $statut, $decisionAdmission, $depuis, $decideurId) {
            $verrouillee = ParcoursPasserelle::whereKey($passerelle->id)->lockForUpdate()->firstOrFail();
            abort_if(! in_array($verrouillee->statut, $depuis, true), 409, 'Passerelle déjà décidée.');

            $verrouillee->update(['statut' => $statut, 'decision_admission' => $decisionAdmission]);

            Event::dispatch(new ParcoursDecisionEnregistree(
                $verrouillee->etudiant, 'passerelle', $verrouillee->id, $statut, $decideurId
            ));

            return $verrouillee->fresh();
        });
    }
}
