<?php

namespace App\Modules\Schedule\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\TimetableGeneration;
use App\Modules\Schedule\Services\ScheduleGenerationSettings;
use App\Modules\Schedule\Services\ScheduleService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * PHASE B (Smart Timetables) — pilotage du moteur de génération depuis le
 * back-office : simulation sans écriture puis génération d'un BROUILLON.
 *
 * Règle absolue respectée : aucune publication automatique — la génération
 * ne modifie jamais le statut du planning, la validation humaine reste le
 * seul chemin vers « valide » puis « publie ».
 */
class ScheduleGenerationController extends Controller
{
    /** Statuts dans lesquels une génération ne doit jamais écrire. */
    private const STATUTS_NON_MODIFIABLES = ['publie', 'verrouille', 'archive'];

    public function __construct(
        private readonly ScheduleService $plannings,
        private readonly ScheduleGenerationSettings $parametresGeneration,
    ) {}

    public function index(Request $request, Schedule $planning): View
    {
        $this->autoriserLecture($request, $planning);

        return view('modules.schedule.generation', [
            'planning' => $planning,
            'controle' => $this->plannings->controlePublication($planning),
            'historique' => TimetableGeneration::query()
                ->where('schedule_id', $planning->id)
                ->latest()
                ->limit(20)
                ->get(),
            'jours' => ScheduleSlot::JOURS,
            'generationPossible' => ! in_array((string) $planning->statut, self::STATUTS_NON_MODIFIABLES, true),
            'ponderations' => [
                'poids_trous' => $this->parametresGeneration->poidsTrous(),
                'poids_equilibre_jours' => $this->parametresGeneration->poidsEquilibreJours(),
                'poids_continuite' => $this->parametresGeneration->poidsContinuite(),
                'duree_max_sequence_minutes' => $this->parametresGeneration->dureeMaxSequenceMinutes(),
                'iteration_max' => $this->parametresGeneration->iterationMax(),
            ],
        ]);
    }

    /**
     * Simulation à blanc : aucune écriture, aucun changement de statut.
     */
    public function simuler(Request $request, Schedule $planning): RedirectResponse
    {
        $this->autoriserEcriture($request, $planning);

        try {
            $generation = $this->plannings->simulerGeneration($planning, $this->options($request));
        } catch (DomainException $exception) {
            return $this->retourErreur($planning, $exception);
        }

        return $this->retourSucces(
            $planning,
            $generation,
            "Simulation terminée : {$generation->nombre_seances_placees}/{$generation->nombre_seances_a_placer} séance(s) plaçable(s)"
            .' — aucune écriture en base.'
        );
    }

    /**
     * Génération du brouillon : écrit les créneaux, jamais le statut.
     */
    public function generer(Request $request, Schedule $planning): RedirectResponse
    {
        $this->autoriserEcriture($request, $planning);

        try {
            $generation = $this->plannings->genererAutomatiquement($planning, $this->options($request));
        } catch (DomainException $exception) {
            return $this->retourErreur($planning, $exception);
        }

        return $this->retourSucces(
            $planning,
            $generation,
            "Génération terminée : {$generation->nombre_seances_placees}/{$generation->nombre_seances_a_placer} séance(s) placée(s)"
            .' — le planning reste en BROUILLON jusqu\'à validation humaine.'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function options(Request $request): array
    {
        $valide = $request->validate([
            'ecraser_brouillon' => 'nullable|boolean',
            'jours_autorises' => 'nullable|array|min:1|max:7',
            'jours_autorises.*' => 'in:'.implode(',', ScheduleSlot::JOURS),
            'iteration_max' => 'nullable|integer|min:100|max:1000000',
            'timeout_secondes' => 'nullable|integer|min:1|max:60',
        ]);

        return [
            'ecraser_brouillon' => (bool) ($valide['ecraser_brouillon'] ?? false),
            'jours_autorises' => $valide['jours_autorises'] ?? null,
            'iteration_max' => $valide['iteration_max'] ?? null,
            'timeout_secondes' => $valide['timeout_secondes'] ?? null,
            'auteur_id' => $request->user()->id,
        ];
    }

    private function autoriserLecture(Request $request, Schedule $planning): void
    {
        $planning->loadMissing('groupe');

        // Isolation faculté (IDOR) : hors périmètre → 404, jamais silencieux.
        abort_unless($request->user()->peutAccederFaculte((int) $planning->groupe->faculte_id), 404);

        Gate::authorize('view', $planning);
    }

    private function autoriserEcriture(Request $request, Schedule $planning): void
    {
        $this->autoriserLecture($request, $planning);

        Gate::authorize('manage', $planning);
    }

    private function retourSucces(Schedule $planning, TimetableGeneration $generation, string $message): RedirectResponse
    {
        $score = $generation->score_qualite !== null ? " (score qualité {$generation->score_qualite}/100)" : '';

        return redirect()
            ->route('admin.emplois-du-temps.generation.index', $planning)
            ->with('success', $message.$score);
    }

    private function retourErreur(Schedule $planning, DomainException $exception): RedirectResponse
    {
        return redirect()
            ->route('admin.emplois-du-temps.generation.index', $planning)
            ->withErrors(['generation' => $exception->getMessage()]);
    }
}
