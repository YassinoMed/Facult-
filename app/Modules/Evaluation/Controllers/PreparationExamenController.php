<?php

namespace App\Modules\Evaluation\Controllers;

use App\Modules\Evaluation\Models\DocumentExamenGenere;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Services\ConvocationService;
use App\Modules\Evaluation\Services\PreparationExamenService;
use App\Modules\Schedule\Models\Room;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * LOOP-EXAM-PREPARATION-01 — assistant de préparation des examens
 * (route cible /admin/examens/preparation).
 *
 * Surface agrégée sur l'existant : les mutations de sessions,
 * d'épreuves, de salles et de surveillance restent celles des
 * contrôleurs/services existants — ce contrôleur n'ajoute que la
 * validation de population (G4/G5) et l'émargement par salle avec
 * aperçu (G2/G3). Zéro duplication fonctionnelle.
 */
class PreparationExamenController
{
    public function __construct(private PreparationExamenService $preparation) {}

    /**
     * Tableau de bord de préparation : sessions récentes, épreuves
     * par statut, salles manquantes, candidats, documents générés.
     */
    public function index(Request $request): View
    {
        Gate::authorize('evaluations.view');

        $anneeId = $request->filled('annee_id') ? (int) $request->input('annee_id') : null;

        return view('modules.evaluation.preparation-index', [
            'tableau' => $this->preparation->tableauDeBord($anneeId, $request->user()->faculteRestreinteId()),
            'anneeId' => $anneeId,
        ]);
    }

    /**
     * Assistant d'une session : session → épreuves → salles →
     * candidats → vérifications → validation → PDF. Les actions de
     * planification renvoient vers les routes existantes.
     */
    public function session(Request $request, SessionExamen $session): View
    {
        Gate::authorize('evaluations.view');

        $faculteRestreinte = $request->user()->faculteRestreinteId();

        $epreuves = $session->epreuves()
            ->with(['matiere', 'salle', 'groupe', 'candidats.salle'])
            ->when($faculteRestreinte !== null, fn ($q) => $q->pourFaculteId((int) $faculteRestreinte))
            ->orderBy('date')
            ->orderBy('heure_debut')
            ->get();

        $verifications = $epreuves->map(fn (Epreuve $epreuve) => [
            'epreuve' => $epreuve,
            'controles' => $this->preparation->verifierEpreuve($epreuve),
        ]);

        $documents = DocumentExamenGenere::query()
            ->whereHas('epreuve', fn ($q) => $q->where('session_id', $session->id))
            ->with(['salle', 'generePar'])
            ->latest('created_at')
            ->limit(10)
            ->get();

        return view('modules.evaluation.preparation-session', [
            'session' => $session,
            'verifications' => $verifications,
            'documents' => $documents,
        ]);
    }

    /**
     * G4 + G5 : fige le snapshot de population de l'épreuve et
     * attribue les numéros anonymes manquants. Idempotent.
     */
    public function validerPopulation(Request $request, Epreuve $epreuve): RedirectResponse
    {
        Gate::authorize('evaluations.update');
        $this->verifierFaculte($epreuve);

        try {
            $this->preparation->validerPopulation($epreuve, (int) $request->user()->id);
        } catch (DomainException $e) {
            return back()->withErrors(['population' => $e->getMessage()]);
        }

        return back()->with('succes', 'Population validée : liste figée (snapshot), numéros anonymes attribués.');
    }

    /**
     * G2 + G3 : émargement PDF par épreuve (toutes salles, sections)
     * ou par salle filtrée ; aperçu inline (?apercu=1) ou
     * téléchargement. Historique tracé par le service.
     */
    public function emargement(Request $request, Epreuve $epreuve, ConvocationService $convocations): Response
    {
        $this->verifierFaculte($epreuve);
        Gate::authorize('view', $epreuve);

        $salle = $request->filled('salle_id')
            ? Room::query()->whereKey((int) $request->input('salle_id'))->firstOrFail()
            : null;

        return $convocations->listeEmargement($epreuve, $salle, $request->boolean('apercu'));
    }

    /**
     * Isolation faculté (même règle que EpreuveController) : un compte
     * restreint ne voit pas les épreuves hors périmètre (404).
     */
    private function verifierFaculte(Epreuve $epreuve): void
    {
        if ($epreuve->faculteId() !== null && request()->user()?->faculteRestreinteId() !== null
            && ! request()->user()->peutAccederFaculte($epreuve->faculteId())) {
            abort(404);
        }
    }
}
