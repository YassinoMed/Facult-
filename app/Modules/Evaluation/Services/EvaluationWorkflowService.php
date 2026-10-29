<?php

namespace App\Modules\Evaluation\Services;

use App\Models\User;
use App\Modules\Evaluation\Events\EvaluationChanged;
use App\Modules\Evaluation\Models\Deliberation;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\NoteEpreuve;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Service d'orchestration et formalisation du workflow de validation et publication
 * (LOOP-UNIREGISTRE-NOTES-EVALUATIONS-ADVANCED-02 — Lot 3).
 *
 * Applique la matrice de transitions stricte pour les épreuves, notes et délibérations,
 * avec contrôles d'autorisations et d'anomalies bloquantes.
 */
class EvaluationWorkflowService
{
    public function __construct(
        private SaisieNoteService $saisieNoteService,
        private DeliberationService $deliberationService,
        private EvaluationQualityService $qualityService,
    ) {}

    /**
     * Matrice canonique des transitions du module Évaluations.
     *
     * @return array<string, array<string, array{cible: string, permission: string, description: string}>>
     */
    public static function matriceTransitions(): array
    {
        return [
            'note_epreuve' => [
                'soumettre' => [
                    'source' => 'entered',
                    'cible' => 'submitted',
                    'permission' => 'notes_eval.enter',
                    'description' => 'Soumission des notes par l’enseignant pour validation officielle',
                ],
                'valider' => [
                    'source' => 'submitted',
                    'cible' => 'validated',
                    'permission' => 'notes_eval.validate',
                    'description' => 'Validation officielle et synchronisation dans le relevé central',
                ],
                'verrouiller' => [
                    'source' => 'validated',
                    'cible' => 'locked',
                    'permission' => 'notes_eval.lock',
                    'description' => 'Verrouillage de la note pour délibération de jury',
                ],
                'demander_correction' => [
                    'source' => 'locked',
                    'cible' => 'correction_demandee',
                    'permission' => 'notes_eval.update',
                    'description' => 'Demande motivée de correction d’une note verrouillée',
                ],
                'autoriser_correction' => [
                    'source' => 'correction_demandee',
                    'cible' => 'entered',
                    'permission' => 'notes_eval.correct',
                    'description' => 'Autorisation formelle de révision et réouverture de la saisie',
                ],
            ],
            'deliberation' => [
                'preparer' => [
                    'source' => 'draft',
                    'cible' => 'prepared',
                    'permission' => 'deliberations.prepare',
                    'description' => 'Compilation automatique des moyennes, décisions et anomalies',
                ],
                'valider' => [
                    'source' => 'prepared',
                    'cible' => 'validated',
                    'permission' => 'deliberations.validate',
                    'description' => 'Approbation du PV de délibération avec vérification du quorum',
                ],
                'publier' => [
                    'source' => 'validated',
                    'cible' => 'published',
                    'permission' => 'deliberations.publish',
                    'description' => 'Publication officielle aux étudiants et mise à disposition des relevés',
                ],
            ],
        ];
    }

    /**
     * Soumission groupée de toutes les notes saisies d'une épreuve par l'enseignant.
     *
     * @return array{soumises: int, ignorees: int}
     */
    public function soumettreNotesEpreuve(Epreuve $epreuve, User $user): array
    {
        Gate::forUser($user)->authorize('saisirNote', $epreuve);

        if ($epreuve->estVerrouillee()) {
            throw new DomainException('Épreuve verrouillée : soumission interdite.');
        }

        return DB::transaction(function () use ($epreuve) {
            $notes = NoteEpreuve::where('epreuve_id', $epreuve->id)
                ->where('statut', 'entered')
                ->whereNotNull('valeur')
                ->lockForUpdate()
                ->get();

            $soumises = 0;
            foreach ($notes as $note) {
                if ($note->peutTransiterVers('submitted')) {
                    $note->update(['statut' => 'submitted']);
                    $soumises++;
                }
            }

            EvaluationChanged::dispatch('notes.soumises', $epreuve->id);

            return [
                'soumises' => $soumises,
                'ignorees' => $notes->count() - $soumises,
            ];
        });
    }

    /**
     * Validation collective de toutes les notes d'une épreuve avec synchronisation officielle.
     *
     * @return array{validees: int, ignorees: int, erreurs: array<int, string>}
     */
    public function validerNotesEpreuve(Epreuve $epreuve, User $user, ?string $motif = null): array
    {
        Gate::forUser($user)->authorize('validerNotesEpreuve', $epreuve);

        return $this->saisieNoteService->validerTout(
            $epreuve,
            (int) $user->id,
            $motif ?? 'Validation officielle collective'
        );
    }

    /**
     * Verrouillage groupé de toutes les notes validées d'une épreuve.
     *
     * @return array{verrouillees: int, ignorees: int}
     */
    public function verrouillerNotesEpreuve(Epreuve $epreuve, User $user, string $motif): array
    {
        Gate::forUser($user)->authorize('validerNotesEpreuve', $epreuve);

        return DB::transaction(function () use ($epreuve, $user, $motif) {
            $notes = NoteEpreuve::where('epreuve_id', $epreuve->id)
                ->where('statut', 'validated')
                ->lockForUpdate()
                ->get();

            $verrouillees = 0;
            foreach ($notes as $note) {
                $this->saisieNoteService->verrouiller($note, (int) $user->id, $motif);
                $verrouillees++;
            }

            return [
                'verrouillees' => $verrouillees,
                'ignorees' => $notes->count() - $verrouillees,
            ];
        });
    }

    /**
     * Validation formelle d'une délibération par le président du jury.
     */
    public function validerDeliberation(Deliberation $deliberation, User $user): Deliberation
    {
        Gate::forUser($user)->authorize('deliberations.validate');

        // Contrôle qualité pré-validation : aucune anomalie bloquante
        $bloquantes = $this->qualityService->verifierPublication($deliberation);
        if (! empty($bloquantes)) {
            $messages = array_map(fn ($b) => $b['libelle'], array_slice($bloquantes, 0, 3));
            throw new DomainException('Validation de délibération bloquée : '.implode(' | ', $messages));
        }

        return $this->deliberationService->valider($deliberation);
    }

    /**
     * Publication officielle de la délibération et déverrouillage des résultats étudiants.
     */
    public function publierDeliberation(Deliberation $deliberation, User $user): Deliberation
    {
        Gate::forUser($user)->authorize('deliberations.publish');

        return $this->deliberationService->publier($deliberation);
    }
}
