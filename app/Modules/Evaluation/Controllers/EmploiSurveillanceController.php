<?php

namespace App\Modules\Evaluation\Controllers;

use App\Models\AnneeUniversitaire;
use App\Models\Matiere;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Services\EmploiSurveillanceService;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Teacher;
use App\Modules\StudentDossiers\Contracts\PdfGeneratorInterface;
use App\Services\Io\CsvSerializer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Emploi du temps des surveillances : vue SPÉCIALISÉE en lecture seule
 * des affectations existantes (`Surveillance` ↔ `Epreuve`). AUCUNE
 * logique d'affectation ici — les actions renvoient vers les
 * contrôleurs existants (affectations, épreuves, surveillances).
 */
class EmploiSurveillanceController
{
    public const VUES = ['global', 'enseignant', 'examen'];

    public function index(Request $request, EmploiSurveillanceService $emploi): View
    {
        Gate::authorize('epreuves.view');

        $faculteId = $request->user()->faculteRestreinteId();
        $vueDemandee = (string) $request->input('vue', 'global');
        if ($vueDemandee === 'globale') {
            $vueDemandee = 'global';
        }
        $vue = in_array($vueDemandee, self::VUES, true) ? $vueDemandee : 'global';

        $resultat = $emploi->construireEmploi($faculteId, $this->filtres($request));

        // Vue examen : épreuve explicitement désignée, périmètre d'abord (404 IDOR).
        $detailExamen = null;
        if ($vue === 'examen' && $request->filled('epreuve_id')) {
            $epreuveDemandee = Epreuve::findOrFail((int) $request->input('epreuve_id'));
            $this->verifierFaculte($epreuveDemandee);
            Gate::authorize('view', $epreuveDemandee);

            foreach ($resultat['jours'] as $liste) {
                foreach ($liste as $enrichie) {
                    if ($enrichie['epreuve']->id === $epreuveDemandee->id) {
                        $detailExamen = $enrichie;
                        break 2;
                    }
                }
            }
            abort_if($detailExamen === null, 404);
        }

        // Vue enseignant : enseignant du périmètre (jamais d'autre faculté).
        $detailEnseignant = null;
        if ($vue === 'enseignant' && $request->filled('teacher_id')) {
            $enseignant = Teacher::where('actif', true)
                ->when($faculteId !== null, fn ($q) => $q->where(
                    fn ($w) => $w->whereHas('departement', fn ($d) => $d->where('faculte_id', $faculteId))
                        ->orWhereNull('departement_id')
                ))
                ->findOrFail((int) $request->input('teacher_id'));
            $detailEnseignant = $resultat['enseignants']->firstWhere('teacher_id', $enseignant->id)
                ?? ['teacher_id' => $enseignant->id, 'nom' => $enseignant->nomComplet(), 'actif' => true,
                    'gardes' => 0, 'reserves' => 0, 'jours' => 0, 'charge' => 0.0, 'dispersion' => false];
        }

        return view('modules.evaluation.surveillances-emploi', [
            'vue' => $vue,
            'jours' => $resultat['jours'],
            'enseignants' => $resultat['enseignants'],
            'stats' => $resultat['stats'],
            'couverture' => $resultat['couverture'],
            'detailExamen' => $detailExamen,
            'detailEnseignant' => $detailEnseignant,
            'sessionDominante' => $emploi->sessionDominante(
                $resultat['jours']->flatten(1)->map(fn ($e) => $e['epreuve'])
            ),
            'sessions' => SessionExamen::orderByDesc('date_debut')->get(),
            'annees' => AnneeUniversitaire::orderByDesc('date_debut')->get(),
            'groupes' => Group::where('actif', true)
                ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))
                ->orderBy('code')->get(),
            'matieres' => Matiere::orderBy('code')->get(),
            'enseignantsFiltre' => Teacher::where('actif', true)
                ->when($faculteId !== null, fn ($q) => $q->where(
                    fn ($w) => $w->whereHas('departement', fn ($d) => $d->where('faculte_id', $faculteId))
                        ->orWhereNull('departement_id')
                ))
                ->orderBy('nom')->orderBy('prenom')->get(),
            'salles' => Room::where('actif', true)
                ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId)->orWhereNull('faculte_id'))
                ->orderBy('code')->get(),
            'filtres' => $request->all(),
        ]);
    }

    /**
     * Export PDF de l'emploi (vue globale) via le moteur PDF existant.
     */
    public function exporterPdf(Request $request, EmploiSurveillanceService $emploi, PdfGeneratorInterface $pdf): Response
    {
        Gate::authorize('epreuves.view');

        $faculteId = $request->user()->faculteRestreinteId();
        $resultat = $emploi->construireEmploi($faculteId, $this->filtres($request));

        $html = view('modules.evaluation.surveillances-emploi-pdf', [
            'jours' => $resultat['jours'],
            'stats' => $resultat['stats'],
            'couverture' => $resultat['couverture'],
            'sessionDominante' => $emploi->sessionDominante(
                $resultat['jours']->flatten(1)->map(fn ($e) => $e['epreuve'])
            ),
            'filtres' => $request->all(),
        ])->render();

        $contenu = $pdf->generer($html, ['paper' => 'A4', 'orientation' => 'landscape']);

        return response($contenu, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="emploi-surveillances-'.now()->format('Y-m-d-His').'.pdf"',
        ]);
    }

    /**
     * Export CSV de l'emploi du temps (par jour ou par enseignant).
     */
    public function exporterCsv(Request $request, EmploiSurveillanceService $emploi): StreamedResponse
    {
        Gate::authorize('epreuves.view');

        $faculteId = $request->user()->faculteRestreinteId();
        $resultat = $emploi->construireEmploi($faculteId, $this->filtres($request));
        $mode = $request->input('mode', $request->input('vue') === 'enseignant' ? 'enseignant' : 'jour');

        $nomFichier = 'emploi-surveillances-'.($mode === 'enseignant' ? 'enseignants-' : 'jours-').now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($resultat, $mode) {
            $csv = app(CsvSerializer::class);
            $sortie = fopen('php://output', 'w');
            fwrite($sortie, "\xEF\xBB\xBF");

            if ($mode === 'enseignant') {
                fputcsv($sortie, $csv->neutraliserLigne(['Enseignant', 'Date', 'Horaire', 'Examen', 'Salle', 'Rôle']), ';');
                foreach ($resultat['jours'] as $liste) {
                    foreach ($liste as $enrichie) {
                        $epreuve = $enrichie['epreuve'];
                        $horaire = $enrichie['heure_debut'].' - '.$enrichie['heure_fin'];
                        $salle = $epreuve->salle?->code ?? '—';
                        $examenCode = $epreuve->code ?? ($epreuve->matiere?->code ?? 'EXAMEN');
                        foreach ($enrichie['actifs'] as $surv) {
                            fputcsv($sortie, $csv->neutraliserLigne([
                                $surv->enseignant?->nomComplet() ?? 'Enseignant inconnu',
                                $enrichie['date_iso'],
                                $horaire,
                                $examenCode,
                                $salle,
                                ucfirst((string) $surv->role),
                            ]), ';');
                        }
                    }
                }
            } else {
                fputcsv($sortie, $csv->neutraliserLigne([
                    'Date', 'Horaire', 'Examen', 'Matière', 'Niveau', 'Groupe', 'Salle', 'Enseignant', 'Rôle', 'Statut', 'Conflits',
                ]), ';');
                foreach ($resultat['jours'] as $liste) {
                    foreach ($liste as $enrichie) {
                        $epreuve = $enrichie['epreuve'];
                        $horaire = $enrichie['heure_debut'].' - '.$enrichie['heure_fin'];
                        $examenCode = $epreuve->code ?? '—';
                        $matiere = $epreuve->matiere?->nom ?? '—';
                        $niveau = $epreuve->groupe?->niveau ?? ($epreuve->matiere?->niveau ?? '—');
                        $groupe = $epreuve->groupe?->nom ?? ($epreuve->groupe?->code ?? 'Tous');
                        $salle = $epreuve->salle?->code ?? '—';
                        $statut = $enrichie['statut'];
                        $conflits = implode(' | ', array_column($enrichie['conflits'], 'message'));

                        if ($enrichie['actifs']->isEmpty()) {
                            fputcsv($sortie, $csv->neutraliserLigne([
                                $enrichie['date_iso'],
                                $horaire,
                                $examenCode,
                                $matiere,
                                $niveau,
                                $groupe,
                                $salle,
                                'Non affecté',
                                '—',
                                $statut,
                                $conflits,
                            ]), ';');
                        } else {
                            foreach ($enrichie['actifs'] as $surv) {
                                fputcsv($sortie, $csv->neutraliserLigne([
                                    $enrichie['date_iso'],
                                    $horaire,
                                    $examenCode,
                                    $matiere,
                                    $niveau,
                                    $groupe,
                                    $salle,
                                    $surv->enseignant?->nomComplet() ?? 'Enseignant inconnu',
                                    ucfirst((string) $surv->role),
                                    $statut,
                                    $conflits,
                                ]), ';');
                            }
                        }
                    }
                }
            }

            fclose($sortie);
        }, $nomFichier, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array{session_id?: ?int, annee_universitaire_id?: ?int, semestre?: ?int, date_debut?: ?string, date_fin?: ?string, niveau?: ?string, groupe_id?: ?int, matiere_id?: ?int, teacher_id?: ?int, salle_id?: ?int, statut?: ?string}
     */
    private function filtres(Request $request): array
    {
        return [
            'session_id' => $request->filled('session_id') ? (int) $request->input('session_id') : null,
            'annee_universitaire_id' => $request->filled('annee_universitaire_id') ? (int) $request->input('annee_universitaire_id') : null,
            'semestre' => $request->filled('semestre') ? (int) $request->input('semestre') : null,
            'date_debut' => $request->input('date_debut'),
            'date_fin' => $request->input('date_fin'),
            'niveau' => $request->input('niveau'),
            'groupe_id' => $request->filled('groupe_id') ? (int) $request->input('groupe_id') : null,
            'matiere_id' => $request->filled('matiere_id') ? (int) $request->input('matiere_id') : null,
            'teacher_id' => $request->filled('teacher_id') ? (int) $request->input('teacher_id') : null,
            'salle_id' => $request->filled('salle_id') ? (int) $request->input('salle_id') : null,
            'statut' => $request->input('statut'),
        ];
    }

    private function verifierFaculte(Epreuve $epreuve): void
    {
        if ($epreuve->faculteId() !== null && request()->user()?->faculteRestreinteId() !== null
            && ! request()->user()->peutAccederFaculte($epreuve->faculteId())) {
            abort(404);
        }
    }
}
