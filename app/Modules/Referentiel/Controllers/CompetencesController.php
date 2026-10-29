<?php

namespace App\Modules\Referentiel\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Modules\Referentiel\Models\Competence;
use App\Modules\Referentiel\Models\Maquette;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Referentiel\Services\CouvertureCompetencesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Lot 20 — Compétences et couverture pédagogique : extension du
 * référentiel LMD existant (mêmes conventions, mêmes permissions).
 *
 * Chaîne : catalogue SAISI (brouillon révisable versionné) → approbation
 * par l'AUTORITÉ des maquettes (`maquettes.approve`) → associations
 * canoniques programme/UE/ECUE (existence vérifiée, idempotentes) →
 * rapport de couverture par maquette DISTINGUANT absence de données et
 * absence de couverture → import par codes avec AMBIGUÏTÉS détectées.
 *
 * Réponses JSON pour les clients API ; la même logique sert l'UI
 * minimale du référentiel (catalogue, couverture, snapshots) sans
 * dupliquer les contrôles ni les règles métier.
 *
 * Lecture seule sur toutes les tables existantes du référentiel.
 */
final class CompetencesController extends Controller
{
    public function __construct(private readonly CouvertureCompetencesService $service) {}

    /* ── Catalogue ─────────────────────────────────────────────── */

    public function index(Request $requete): JsonResponse|View
    {
        Gate::authorize('referentiel.view');

        $restreinte = $requete->user()->faculteRestreinteId();

        $competences = Competence::query()
            ->withCount('objectifs')
            ->when($restreinte !== null, fn ($q) => $q->where(function ($w) use ($restreinte) {
                $w->whereNull('faculte_id')->orWhere('faculte_id', $restreinte);
            }))
            ->orderBy('code')
            ->limit(500)
            ->get();

        if ($requete->expectsJson()) {
            return response()->json([
                'competences' => $competences,
                'rappel' => 'Catalogue SAISI par l\'administration : aucun référentiel de compétences n\'est inventé par le système.',
            ]);
        }

        $maquettes = Maquette::query()
            ->with(['programme', 'anneeUniversitaire'])
            ->when($restreinte !== null, fn ($q) => $q->whereHas(
                'programme',
                fn ($p) => $p->where('faculte_id', $restreinte)
            ))
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return view('modules.referentiel.competences.index', [
            'competences' => $competences,
            'maquettes' => $maquettes,
            'facultes' => Faculte::query()
                ->when($restreinte !== null, fn ($q) => $q->where('id', $restreinte))
                ->orderBy('nom')
                ->get(['id', 'nom', 'code']),
        ]);
    }

    public function store(Request $requete): JsonResponse|RedirectResponse
    {
        Gate::authorize('referentiel.manage');

        $donnees = $requete->validate([
            'code' => ['required', 'string', 'max:30', 'unique:competences,code'],
            'libelle' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:4000'],
            'faculte_id' => ['nullable', 'integer', 'exists:facultes,id'],
        ]);

        if (($donnees['faculte_id'] ?? null) !== null && ! $requete->user()->peutAccederFaculte((int) $donnees['faculte_id'])) {
            abort(403, 'Compétences : faculté hors périmètre.');
        }

        $competence = $this->service->creer($donnees, (int) $requete->user()->id);

        if ($requete->expectsJson()) {
            return response()->json(['competence' => $competence], 201);
        }

        return redirect()
            ->route('admin.referentiel.competences.index')
            ->with('success', 'Compétence créée en brouillon.');
    }

    public function reviser(Request $requete, int $id): JsonResponse|RedirectResponse
    {
        Gate::authorize('referentiel.manage');

        $competence = Competence::query()->findOrFail($id);
        $this->garantirPerimetre($requete, $competence);

        $donnees = $requete->validate([
            'libelle' => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:4000'],
            'faculte_id' => ['nullable', 'integer', 'exists:facultes,id'],
        ]);

        try {
            $competence = $this->service->reviser($competence, $donnees);
        } catch (\DomainException $e) {
            return $requete->expectsJson()
                ? response()->json(['erreur' => $e->getMessage()], 422)
                : back()->withErrors(['competence' => $e->getMessage()]);
        }

        if ($requete->expectsJson()) {
            return response()->json(['competence' => $competence]);
        }

        return back()->with('success', 'Brouillon révisé (version '.$competence->version.').');
    }

    /**
     * Approbation : acte de l'AUTORITÉ d'approbation des maquettes
     * (`maquettes.approve`) — la même clé, aucune clé inventée.
     */
    public function approuver(Request $requete, int $id): JsonResponse|RedirectResponse
    {
        Gate::authorize('maquettes.approve');

        $competence = Competence::query()->findOrFail($id);
        $this->garantirPerimetre($requete, $competence);

        try {
            $competence = $this->service->approuver($competence, (int) $requete->user()->id);
        } catch (\DomainException $e) {
            return $requete->expectsJson()
                ? response()->json(['erreur' => $e->getMessage()], 422)
                : back()->withErrors(['competence' => $e->getMessage()]);
        }

        if ($requete->expectsJson()) {
            return response()->json(['competence' => $competence]);
        }

        return back()->with('success', 'Compétence approuvée et figée.');
    }

    public function archiver(Request $requete, int $id): JsonResponse|RedirectResponse
    {
        Gate::authorize('referentiel.manage');

        $competence = Competence::query()->findOrFail($id);
        $this->garantirPerimetre($requete, $competence);

        try {
            $competence = $this->service->archiver($competence);
        } catch (\DomainException $e) {
            return $requete->expectsJson()
                ? response()->json(['erreur' => $e->getMessage()], 422)
                : back()->withErrors(['competence' => $e->getMessage()]);
        }

        if ($requete->expectsJson()) {
            return response()->json(['competence' => $competence]);
        }

        return back()->with('success', 'Compétence archivée : associations existantes conservées, plus aucune nouvelle association.');
    }

    /* ── Associations canoniques ───────────────────────────────── */

    public function associer(Request $requete, int $id): JsonResponse
    {
        Gate::authorize('referentiel.manage');

        $competence = Competence::query()->findOrFail($id);
        $this->garantirPerimetre($requete, $competence);

        $donnees = $requete->validate([
            'cible_type' => ['required', 'string', Rule::in(['programme', 'ue', 'ecue'])],
            'cible_id' => ['required', 'integer'],
            'niveau_attendu' => ['nullable', 'string', 'max:60'],
        ]);

        $this->garantirPerimetreCible($requete, $donnees['cible_type'], (int) $donnees['cible_id']);

        try {
            $objectif = $this->service->associer(
                $competence, $donnees['cible_type'], (int) $donnees['cible_id'],
                $donnees['niveau_attendu'] ?? null, (int) $requete->user()->id,
            );
        } catch (\DomainException $e) {
            return response()->json(['erreur' => $e->getMessage()], 422);
        }

        return response()->json(['objectif' => $objectif->wasRecentlyCreated ? $objectif : $objectif->fresh()], $objectif->wasRecentlyCreated ? 201 : 200);
    }

    public function dissocier(Request $requete, int $id): JsonResponse
    {
        Gate::authorize('referentiel.manage');

        $competence = Competence::query()->findOrFail($id);
        $this->garantirPerimetre($requete, $competence);

        $donnees = $requete->validate([
            'cible_type' => ['required', 'string', Rule::in(['programme', 'ue', 'ecue'])],
            'cible_id' => ['required', 'integer'],
        ]);

        $supprime = $this->service->dissocier($competence, $donnees['cible_type'], (int) $donnees['cible_id']);

        return response()->json(['supprime' => $supprime]);
    }

    /* ── Couverture, cohérence, import ─────────────────────────── */

    /**
     * Rapport de couverture d'une maquette : périmètre via la policy du
     * référentiel (view + faculté du programme).
     */
    public function couverture(Request $requete, Maquette $maquette): JsonResponse|View
    {
        $maquette->loadMissing('programme');
        Gate::authorize('view', $maquette);

        $rapport = $this->service->rapportCouverture($maquette);

        if ($requete->expectsJson()) {
            if ($requete->boolean('snapshots')) {
                $rapport['snapshots'] = $this->service->historiqueRapports($maquette)
                    ->map(fn ($snapshot) => [
                        'id' => $snapshot->id,
                        'empreinte' => $snapshot->empreinte,
                        'created_at' => $snapshot->created_at?->toIso8601String(),
                    ])->all();
            }

            return response()->json($rapport);
        }

        return view('modules.referentiel.competences.couverture', [
            'maquette' => $maquette,
            'rapport' => $rapport,
            'snapshots' => $this->service->historiqueRapports($maquette),
        ]);
    }

    /**
     * Figer le rapport courant : trace immuable pour un rapport publié.
     * Permission de même nature que l'export (`referentiel.export`).
     */
    public function figerCouverture(Request $requete, Maquette $maquette): JsonResponse|RedirectResponse
    {
        Gate::authorize('referentiel.export');
        $maquette->loadMissing('programme');
        Gate::authorize('view', $maquette);

        $snapshot = $this->service->figerRapport($maquette, (int) $requete->user()->id);

        if ($requete->expectsJson()) {
            return response()->json([
                'snapshot' => [
                    'id' => $snapshot->id,
                    'empreinte' => $snapshot->empreinte,
                    'created_at' => $snapshot->created_at?->toIso8601String(),
                ],
            ], 201);
        }

        return redirect()
            ->route('admin.referentiel.maquettes.couverture', $maquette)
            ->with('success', 'Rapport figé — snapshot immuable #'.$snapshot->id.' (empreinte '.substr($snapshot->empreinte, 0, 12).'…).');
    }

    /**
     * Contrôle de cohérence global des associations (lecture seule).
     */
    public function coherence(Request $requete): JsonResponse|View
    {
        Gate::authorize('referentiel.view');

        $anomalies = $this->service->verifierCoherence();

        if ($requete->expectsJson()) {
            return response()->json(['anomalies' => $anomalies]);
        }

        return view('modules.referentiel.competences.coherence', [
            'anomalies' => $anomalies,
        ]);
    }

    /**
     * Import d'associations PAR CODES : les ambiguïtés (code matchant
     * plusieurs entités) sont signalées par ligne, jamais résolues
     * arbitrairement (permission `referentiel.import` réutilisée).
     * `cible_contexte` lève une ambiguïté sans choix arbitraire.
     */
    public function importer(Request $requete): JsonResponse
    {
        Gate::authorize('referentiel.import');

        $lignes = $requete->validate([
            'lignes' => ['required', 'array', 'max:'.CouvertureCompetencesService::PLAFOND_LIGNES_IMPORT],
            'lignes.*.competence_code' => ['required', 'string', 'max:30'],
            'lignes.*.cible_type' => ['required', 'string', Rule::in(['programme', 'ue', 'ecue'])],
            'lignes.*.cible_code' => ['required', 'string', 'max:30'],
            'lignes.*.cible_contexte' => ['nullable', 'string', 'max:30'],
            'lignes.*.niveau_attendu' => ['nullable', 'string', 'max:60'],
        ])['lignes'];

        $resultat = $this->service->importer($lignes, (int) $requete->user()->id);

        return response()->json([
            ...$resultat,
            'rappel' => 'Une ligne ambiguë (code matchant plusieurs entités) est rejetée avec ses candidats : `cible_contexte` (faculté/programme/UE) lève l\'ambiguïté, sans choix arbitraire.',
        ]);
    }

    /* ── Contrôles internes ─────────────────────────────────────── */

    /**
     * L'utilisateur doit pouvoir accéder à la faculté d'une compétence
     * locale (les compétences d'établissement restent globales).
     */
    private function garantirPerimetre(Request $requete, Competence $competence): void
    {
        if ($competence->faculte_id !== null
            && ! $requete->user()->peutAccederFaculte((int) $competence->faculte_id)) {
            abort(403, 'Compétences : faculté hors périmètre.');
        }
    }

    /**
     * L'utilisateur doit pouvoir accéder à la faculté de la cible visée
     * (403) — la cohérence compétence↔cible reste une règle métier (422,
     * service).
     */
    private function garantirPerimetreCible(Request $requete, string $cibleType, int $cibleId): void
    {
        $faculteId = match ($cibleType) {
            'programme' => Programme::query()->find($cibleId)?->faculte_id,
            'ue' => UniteEnseignement::query()->with('programme')->find($cibleId)?->programme?->faculte_id,
            'ecue' => Matiere::query()->with('ue.programme')->find($cibleId)?->ue?->programme?->faculte_id,
            default => null,
        };

        if ($faculteId !== null && ! $requete->user()->peutAccederFaculte((int) $faculteId)) {
            abort(403, 'Compétences : cible hors périmètre.');
        }
    }
}
