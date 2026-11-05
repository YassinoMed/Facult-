<?php

namespace App\Modules\Evaluation\Services;

use App\Modules\Evaluation\Events\EvaluationChanged;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\ExamenEvenement;
use App\Modules\Schedule\Services\ExamScheduleEngineService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Transitions d'examen (Phase 10 + LOOP EX-LIFECYCLE) : matrice serveur +
 * transaction verrouillée + historisation append-only + event d'audit.
 *
 * Point d'entrée métier UNIQUE des transitions simples d'examen :
 *  - le contrôleur ne modifie jamais `statut` directement ;
 *  - le circuit de gouvernance (§2.7/§2.8) reste le seul chemin vers
 *    `published` et appelle ce même service ;
 *  - les effets secondaires passent par `EvaluationChanged`.
 *
 * LOOP EX-LIFECYCLE — corrections additives (écarts démontrés dans
 * docs/loop/examens-cycle-vie/00-audit-existant.md) :
 *  1. `lockForUpdate()` au sein de la transaction : le contrôle décisif
 *     (matrice + garde critique) est évalué APRÈS acquisition du verrou
 *     — aligné sur la convention des services de gouvernance/publication.
 *  2. Historisation append-only dans `evaluation_examen_evenements`
 *     (avant/après, acteur, motif) — complète `PlanningEvenement` sans
 *     le dupliquer.
 *  3. Explicabilité : `transitionsDisponibles()` et
 *     `previsualiserTransition()` (lecture pure, aucune écriture).
 */
class ExamenService
{
    public function transiter(Examen $examen, string $cible, ?int $acteurId = null, ?string $motif = null): Examen
    {
        return DB::transaction(function () use ($examen, $cible, $acteurId, $motif) {
            /** @var Examen $examen */
            $examen = Examen::whereKey($examen->id)->lockForUpdate()->firstOrFail();

            $statutAvant = $examen->statut;

            if ($examen->estCritique() && $cible !== 'archived') {
                throw new DomainException('Examen verrouillé.');
            }

            if (! $examen->peutTransiterVers($cible)) {
                throw new DomainException("Transition {$examen->statut} → {$cible} interdite.");
            }

            if ($cible === 'published') {
                $audit = app(ExamScheduleEngineService::class)->auditerSessionExamens($examen);
                if (! $audit['conforme']) {
                    throw new DomainException('Publication impossible : contraintes dures violées ('.implode(', ', $audit['conflits_bloquants']).').');
                }
            }

            $examen->update([
                'statut' => $cible,
                'published_at' => $cible === 'published' ? now() : $examen->published_at,
            ]);

            // Historisation append-only — dans la transaction : un échec
            // ne laisse ni état appliqué sans trace, ni trace sans état.
            ExamenEvenement::query()->create([
                'examen_id' => $examen->id,
                'action' => ExamenEvenement::ACTION_TRANSITION,
                'acteur_id' => $acteurId,
                'statut_avant' => $statutAvant,
                'statut_apres' => $cible,
                'motif' => $motif,
                'created_at' => now(),
            ]);

            EvaluationChanged::dispatch('examen.statut', $examen->id);

            return $examen->fresh();
        });
    }

    /**
     * Historise la création d'un examen (état initial `draft`, forcé
     * serveur — le client ne choisit jamais l'état).
     */
    public function historiserCreation(Examen $examen, ?int $acteurId = null): void
    {
        ExamenEvenement::query()->create([
            'examen_id' => $examen->id,
            'action' => ExamenEvenement::ACTION_CREATION,
            'acteur_id' => $acteurId,
            'statut_avant' => 'draft',
            'statut_apres' => 'draft',
            'motif' => 'Création de l\'examen (état initial forcé serveur).',
            'created_at' => now(),
        ]);
    }

    /**
     * Transitions effectivement disponibles pour un examen : intersection
     * de la matrice déclarée et du garde `estCritique()`. Sert à
     * l'affichage des actions (G3) — le serveur reste l'autorité finale.
     *
     * @return array<int, array<string, mixed>>
     */
    public function transitionsDisponibles(Examen $examen): array
    {
        $examen->refresh();
        $resultat = [];

        foreach ($examen->peutTransiterVers('') ? [] : (Examen::TRANSITIONS[$examen->statut] ?? []) as $cible) {
            $bloquages = $this->bloquagesDe($examen, $cible);

            $resultat[] = [
                'cible' => $cible,
                'acceptable' => $bloquages === [],
                'bloquages' => $bloquages,
            ];
        }

        return $resultat;
    }

    /**
     * Prévisualisation d'une transition (D7) : conditions satisfaites et
     * bloquages, sans aucune écriture. La prévisualisation est
     * informative uniquement — la décision définitive est recalculée
     * dans `transiter()` au moment de l'application.
     */
    public function previsualiserTransition(Examen $examen, string $cible): ResultatTransition
    {
        $examen->refresh();

        $satisfaites = [];
        $bloquages = $this->bloquagesDe($examen, $cible);

        if (in_array($cible, Examen::TRANSITIONS[$examen->statut] ?? [], true)) {
            $satisfaites[] = "La matrice autorise la transition {$examen->statut} → {$cible}.";
        }

        if (! $examen->estCritique()) {
            $satisfaites[] = 'L\'examen n\'est pas dans un état critique (verrou serveur non actif).';
        }

        if ($cible === 'published') {
            if ($examen->statut === 'scheduled') {
                $satisfaites[] = 'La publication officielle exige le circuit de gouvernance (certification + double approbation) : cette prévisualisation ne préjuge pas du résultat du circuit.';
            } else {
                $bloquages[] = 'La publication n\'est atteignable que depuis l\'état « scheduled » et uniquement via le circuit de gouvernance.';
            }

            try {
                $audit = app(ExamScheduleEngineService::class)->auditerSessionExamens($examen);
                if ((bool) ($audit['conforme'] ?? false)) {
                    $satisfaites[] = 'Audit de conformité du planning : satisfait.';
                } else {
                    $bloquages[] = 'Contraintes dures violées : '.implode(', ', $audit['conflits_bloquants'] ?? []).'.'; // @codeCoverageIgnore
                }
            } catch (Throwable) {
                $bloquages[] = 'Audit de conformité momentanément indisponible.';
            }
        }

        return new ResultatTransition(
            examenId: $examen->id,
            statutCourant: $examen->statut,
            cible: $cible,
            acceptable: $bloquages === [],
            conditionsSatisfaites: $satisfaites,
            bloquages: $bloquages,
            message: $bloquages === []
                ? "Transition {$examen->statut} → {$cible} prévisible : les conditions courantes sont satisfaites."
                : "Transition {$examen->statut} → {$cible} actuellement bloquée : ".implode(' ', $bloquages),
        );
    }

    /**
     * Bloquages courants d'une cible pour un examen (réplique EXACTE de
     * l'ordre d'évaluation de `transiter()` — aucune règle nouvelle).
     *
     * @return array<int, string>
     */
    private function bloquagesDe(Examen $examen, string $cible): array
    {
        $bloquages = [];

        if ($examen->estCritique() && $cible !== 'archived') {
            $bloquages[] = 'L\'examen est dans un état critique (verrouillé) : seule l\'archivage reste possible.';
        }

        if (! in_array($cible, Examen::TRANSITIONS[$examen->statut] ?? [], true)) {
            $bloquages[] = "La transition {$examen->statut} → {$cible} n'existe pas dans la matrice serveur.";
        }

        return $bloquages;
    }
}
