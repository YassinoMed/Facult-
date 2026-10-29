<?php

namespace App\Modules\Evaluation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Evaluation\Events\EvaluationChanged;
use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\EvaluationIncident;
use App\Modules\Evaluation\Models\Surveillance;
use App\Modules\Evaluation\Services\ConvocationService;
use App\Modules\Schedule\Models\Teacher;
use App\Services\Io\CsvSerializer;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Espace Enseignant — mes surveillances et mes épreuves.
 * Résolution exclusive depuis la session (jamais d'ID enseignant client) :
 * - index : mes affectations actives (rôle affiché, réserve distinguée) ;
 * - show : détail de MON épreuve (mon rôle, ma salle, effectifs par rôle —
 *   jamais les noms des autres surveillants) + incidents + signalement ;
 * - emargement : liste d'émargement PDF de mon épreuve (réutilise
 *   ConvocationService, comme le back-office) ;
 * - signaler : constat post-hoc sur mon épreuve (mêmes règles métier que
 *   StoreIncidentRequest, auteur = teacher_id). Le verrou de l'épreuve ne
 *   bloque jamais le signalement. Aucune transition de statut ici
 *   (traitement réservé à l'administration).
 */
class MesSurveillancesController extends Controller
{
    public function index(Request $request): View
    {
        $enseignant = $this->enseignantConnecte($request);

        $gardes = Surveillance::with(['epreuve.matiere', 'epreuve.session', 'epreuve.salle', 'salle'])
            ->where('teacher_id', $enseignant->id)
            ->where('evaluation_surveillants.statut', '!=', 'annule')
            ->whereHas('epreuve', fn ($q) => $q->where('statut', '!=', 'cancelled'))
            ->join('evaluation_epreuves', 'evaluation_surveillants.epreuve_id', '=', 'evaluation_epreuves.id')
            ->orderBy('evaluation_epreuves.date')
            ->orderBy('evaluation_epreuves.heure_debut')
            ->select('evaluation_surveillants.*')
            ->paginate(15)
            ->withQueryString();

        return view('modules.evaluation.mes-surveillances', [
            'enseignant' => $enseignant,
            'gardes' => $gardes,
        ]);
    }

    public function show(Request $request, Epreuve $epreuve): View
    {
        $enseignant = $this->enseignantConnecte($request);
        $affectation = $this->monAffectation($enseignant, $epreuve);

        $epreuve->load(['matiere', 'salle', 'groupe', 'session', 'incidents' => fn ($q) => $q->latest('id')]);

        $actifs = $epreuve->surveillants()->where('statut', '!=', 'annule')->where('role', '!=', 'reserve')->count();
        $gardes = $epreuve->surveillants()->where('statut', '!=', 'annule')->where('role', 'reserve')->count();

        return view('modules.evaluation.ma-surveillance', [
            'enseignant' => $enseignant,
            'epreuve' => $epreuve,
            'affectation' => $affectation,
            'nbActifs' => $actifs,
            'nbGardes' => $gardes,
        ]);
    }

    public function emargement(Request $request, Epreuve $epreuve, ConvocationService $convocations): Response
    {
        $enseignant = $this->enseignantConnecte($request);
        $this->monAffectation($enseignant, $epreuve);

        return $convocations->listeEmargement($epreuve);
    }

    /**
     * Relevé CSV de MES surveillances (mes lignes uniquement — jamais
     * celles d'autres enseignants).
     */
    public function exporter(Request $request): StreamedResponse
    {
        $enseignant = $this->enseignantConnecte($request);

        $gardes = Surveillance::with(['epreuve.matiere', 'epreuve.session', 'salle'])
            ->where('teacher_id', $enseignant->id)
            ->where('evaluation_surveillants.statut', '!=', 'annule')
            ->whereHas('epreuve', fn ($q) => $q->where('statut', '!=', 'cancelled'))
            ->join('evaluation_epreuves', 'evaluation_surveillants.epreuve_id', '=', 'evaluation_epreuves.id')
            ->orderBy('evaluation_epreuves.date')
            ->orderBy('evaluation_epreuves.heure_debut')
            ->select('evaluation_surveillants.*')
            ->get();

        $nomFichier = 'mes-surveillances-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($gardes) {
            $csv = app(CsvSerializer::class);
            $sortie = fopen('php://output', 'w');
            fwrite($sortie, "\xEF\xBB\xBF");
            fputcsv($sortie, $csv->neutraliserLigne(['Date', 'Horaire', 'Épreuve', 'Matière', 'Salle', 'Rôle', 'Statut']), ';');

            foreach ($gardes as $garde) {
                fputcsv($sortie, $csv->neutraliserLigne([
                    $garde->epreuve->date->format('d/m/Y'),
                    substr((string) $garde->epreuve->heure_debut, 0, 5).' - '.substr((string) $garde->epreuve->heure_fin, 0, 5),
                    $garde->epreuve->code,
                    $garde->epreuve->matiere?->nom ?? '—',
                    $garde->salle?->code ?? $garde->epreuve->salle?->code ?? '—',
                    $garde->role,
                    $garde->statut,
                ]), ';');
            }

            fclose($sortie);
        }, $nomFichier, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function signaler(Request $request, Epreuve $epreuve)
    {
        $enseignant = $this->enseignantConnecte($request);
        $this->monAffectation($enseignant, $epreuve);

        // Mêmes règles métier que StoreIncidentRequest (dont l'authorize
        // est réservé au back-office : ici l'autorisation = mon épreuve).
        $valides = $request->validate([
            'type' => ['required', Rule::in(EvaluationIncident::TYPES)],
            'description' => ['required', 'string', 'max:2000'],
            'etudiant_id' => ['nullable', 'integer', 'exists:etudiants,id'],
        ]);

        if (! empty($valides['etudiant_id'])
            && ! Candidat::where('epreuve_id', $epreuve->id)->where('etudiant_id', $valides['etudiant_id'])->exists()) {
            return back()->withErrors(['etudiant_id' => 'Étudiant non convoqué à cette épreuve.'])->withInput();
        }

        $incident = new EvaluationIncident([
            'type' => $valides['type'],
            'description' => $valides['description'],
            'etudiant_id' => $valides['etudiant_id'] ?? null,
        ]);
        $incident->epreuve_id = $epreuve->id;
        $incident->teacher_id = $enseignant->id;
        $incident->save();

        EvaluationChanged::dispatch('exam.incident.signale', $incident->id);

        return redirect()->route('enseignant.epreuves.show', $epreuve)
            ->with('success', 'Incident signalé. La scolarité le traitera.');
    }

    /**
     * Mon affectation active sur l'épreuve (404 si épreuve annulée ou sans
     * affectation me concernant — anti-IDOR et anti-énumération).
     */
    private function monAffectation(Teacher $enseignant, Epreuve $epreuve): Surveillance
    {
        abort_if($epreuve->statut === 'cancelled', 404);

        return Surveillance::where('epreuve_id', $epreuve->id)
            ->where('teacher_id', $enseignant->id)
            ->where('statut', '!=', 'annule')
            ->firstOrFail();
    }

    private function enseignantConnecte(Request $request): Teacher
    {
        return Teacher::findOrFail($request->session()->get('enseignant_auth_id'));
    }
}
