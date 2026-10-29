<?php

namespace App\Modules\Attendance\Controllers;

use App\Http\Controllers\Controller;
use App\Models\TeacherReclamation;
use App\Modules\Attendance\Models\TeacherPresence;
use App\Modules\Attendance\Services\AppelPresenceService;
use App\Modules\Attendance\Services\PresenceEmploiDuTempsService;
use App\Modules\Schedule\Models\Teacher;
use App\Services\Io\CsvSerializer;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Espace Enseignant — mes présences aux séances et appel des étudiants.
 * Lecture seule stricte pour mes présences, et ouverture de l'appel pour mes séances.
 */
class MesPresencesController extends Controller
{
    public function index(Request $request, PresenceEmploiDuTempsService $service, AppelPresenceService $serviceAppels): View
    {
        $enseignant = $this->enseignantConnecte($request);
        [$debut, $fin] = $this->periode($request);

        $dateAppel = (string) $request->input('date_appel', Carbon::today()->toDateString());
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateAppel) !== 1 || $dateAppel > Carbon::today()->toDateString()) {
            $dateAppel = Carbon::today()->toDateString();
        }
        $seancesAppel = $serviceAppels->seancesAppelables($enseignant, $dateAppel);

        $query = TeacherPresence::with(['slot.matiere', 'slot.room', 'slot.schedule.groupe'])
            ->where('teacher_id', $enseignant->id)
            ->when($debut !== null, fn ($q) => $q->whereDate('date', '>=', $debut))
            ->when($fin !== null, fn ($q) => $q->whereDate('date', '<=', $fin));

        if ($request->input('filtre') === 'anomalies') {
            $anomalies = PresenceEmploiDuTempsService::statutsAnomalie();
            $query->where(fn ($q) => $q
                ->whereIn('statut_valide', $anomalies)
                ->orWhere(fn ($s) => $s->whereNull('statut_valide')->whereIn('statut_calcule', $anomalies)));
        }

        if ($request->filled('statut')) {
            $statut = $request->input('statut');
            $query->where(fn ($q) => $q
                ->where('statut_valide', $statut)
                ->orWhere(fn ($s) => $s->whereNull('statut_valide')->where('statut_calcule', $statut)));
        }

        $presences = $query->orderByDesc('date')->paginate(15)->withQueryString();
        $pointages = $enseignant->pointages()->orderByDesc('horodatage')->limit(10)->get();

        // Statistiques sur la période affichée (défaut : 90 derniers
        // jours — une fenêtre bornée, jamais tout l'historique).
        $stats = $service->statistiquesEnseignant(
            $enseignant->id,
            $debut ?? Carbon::today()->subDays(90)->toDateString(),
            $fin ?? Carbon::today()->toDateString()
        );
        $stats = $service->statistiquesEnseignant($enseignant->id, $debut, $fin);

        $demandesEnAttente = TeacherReclamation::where('teacher_id', $enseignant->id)
            ->where('categorie', 'presence')
            ->whereIn('statut', ['ouverte', 'en_cours'])
            ->count();

        $dernieresModifications = TeacherPresence::with(['slot.matiere'])
            ->where('teacher_id', $enseignant->id)
            ->whereNotNull('historique')
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get();

        $vue = in_array($request->input('vue'), ['liste', 'semaine', 'mois'], true) ? $request->input('vue') : 'liste';

        return view('modules.attendance.mes-presences', [
            'enseignant' => $enseignant,
            'presences' => $presences,
            'pointages' => $pointages,
            'filtreStatut' => $request->input('statut'),
            'filtreAnomalies' => $request->input('filtre') === 'anomalies',
            'debut' => $debut,
            'fin' => $fin,
            'stats' => $stats,
            'demandesEnAttente' => $demandesEnAttente,
            'dernieresModifications' => $dernieresModifications,
            'vue' => $vue,
            'orphelins' => $service->pointagesOrphelins(
                $enseignant->id,
                $debut ?? Carbon::today()->subDays(90)->toDateString(),
                $fin ?? Carbon::today()->toDateString()
            ),
            'seancesAppel' => $seancesAppel,
            'dateAppel' => $dateAppel,
        ]);
    }

    public function show(Request $request, TeacherPresence $presence): View
    {
        $enseignant = $this->enseignantConnecte($request);
        abort_if((int) $presence->teacher_id !== (int) $enseignant->id, 404);

        $presence->load(['slot.matiere', 'slot.room', 'slot.schedule.groupe', 'validePar']);

        $reclamations = TeacherReclamation::where('teacher_id', $enseignant->id)
            ->where('teacher_presence_id', $presence->id)
            ->orderByDesc('id')
            ->get();

        return view('modules.attendance.ma-presence', [
            'enseignant' => $enseignant,
            'presence' => $presence,
            'reclamations' => $reclamations,
        ]);
    }

    /**
     * Export CSV de MES présences (mes lignes uniquement).
     */
    public function exporter(Request $request): StreamedResponse
    {
        $enseignant = $this->enseignantConnecte($request);
        [$debut, $fin] = $this->periode($request);

        $presences = TeacherPresence::with(['slot.matiere', 'slot.room'])
            ->where('teacher_id', $enseignant->id)
            ->when($debut !== null, fn ($q) => $q->whereDate('date', '>=', $debut))
            ->when($fin !== null, fn ($q) => $q->whereDate('date', '<=', $fin))
            ->orderByDesc('date')
            ->limit(500)
            ->get();

        $nomFichier = 'mes-presences-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($presences) {
            $csv = app(CsvSerializer::class);
            $sortie = fopen('php://output', 'w');
            fwrite($sortie, "\xEF\xBB\xBF");
            fputcsv($sortie, $csv->neutraliserLigne(['Date', 'Séance', 'Horaire', 'Salle', 'Statut effectif']), ';');

            foreach ($presences as $presence) {
                fputcsv($sortie, $csv->neutraliserLigne([
                    $presence->date->format('d/m/Y'),
                    $presence->slot?->matiere?->nom ?? '—',
                    substr((string) $presence->slot?->start_time, 0, 5).' - '.substr((string) $presence->slot?->end_time, 0, 5),
                    $presence->slot?->room?->code ?? '—',
                    $presence->statut_effectif,
                ]), ';');
            }

            fclose($sortie);
        }, $nomFichier, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Période validée (null = tout l'historique ; si une borne est posée,
     * l'autre défaute à 90 jours autour, plafond 366 jours).
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function periode(Request $request): array
    {
        if (! $request->filled('date_debut') && ! $request->filled('date_fin')) {
            return [null, null];
        }

        $debut = $this->dateOuDefaut($request->input('date_debut'), Carbon::today()->subDays(90)->toDateString());
        $fin = $this->dateOuDefaut($request->input('date_fin'), Carbon::today()->toDateString());

        if ($fin < $debut) {
            [$debut, $fin] = [$fin, $debut];
        }

        if (Carbon::parse($debut)->diffInDays(Carbon::parse($fin)) > 366) {
            $fin = Carbon::parse($debut)->addDays(366)->toDateString();
        }

        return [$debut, $fin];
    }

    private function dateOuDefaut(mixed $valeur, string $defaut): string
    {
        if (is_string($valeur) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $valeur) === 1) {
            try {
                return Carbon::createFromFormat('Y-m-d', $valeur)->toDateString();
            } catch (\Throwable) {
                // Repli : valeur par défaut ci-dessous.
            }
        }

        return $defaut;
    }

    private function enseignantConnecte(Request $request): Teacher
    {
        return Teacher::findOrFail($request->session()->get('enseignant_auth_id'));
    }
}
