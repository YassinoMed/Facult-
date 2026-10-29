<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AnneeUniversitaire;
use App\Models\DossierCorrection;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\Note;
use App\Modules\OnlineRequests\Models\OnlineRequest;
use App\Modules\Schedule\Models\Teacher;
use App\Services\ExperienceInscriptionService;
use App\Services\Io\CsvSerializer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Statistiques transversales (§47) : agrégations filtrées
 * (année, faculté, programme), cartes KPI — aucune lib chart
 * imposée (BACK-OFFICE.md), export CSV permission-gated.
 */
class StatistiquesController extends Controller
{
    public function index(Request $request, ExperienceInscriptionService $experienceService): View
    {
        Gate::authorize('statistiques.view');

        $filtres = $request->validate([
            'annee_universitaire_id' => ['nullable', 'exists:annees_universitaires,id'],
            'faculte_id' => ['nullable', 'exists:facultes,id'],
        ]);

        $faculteId = $request->user()->faculteRestreinteId() ?? ($filtres['faculte_id'] ?? null);
        $anneeId = $filtres['annee_universitaire_id'] ?? AnneeUniversitaire::where('active', true)->value('id');

        $etudiants = Etudiant::when($faculteId, fn ($q) => $q->where('faculte_id', $faculteId));

        $syntheseExperience = $experienceService->syntheseExperience($faculteId, $anneeId);

        $indicateurs = [
            'Étudiants' => (clone $etudiants)->count(),
            'Inscriptions en cours' => InscriptionAcademique::when($anneeId, fn ($q) => $q->where('annee_universitaire_id', $anneeId))
                ->when($faculteId, fn ($q) => $q->whereHas('etudiant', fn ($e) => $e->where('faculte_id', $faculteId)))
                ->where('statut', 'en_cours')->count(),
            'Validées' => InscriptionAcademique::when($anneeId, fn ($q) => $q->where('annee_universitaire_id', $anneeId))
                ->when($faculteId, fn ($q) => $q->whereHas('etudiant', fn ($e) => $e->where('faculte_id', $faculteId)))
                ->where('statut', 'valide')->count(),
            'Enseignants' => Teacher::where('actif', true)->count(),
            'Demandes en retard' => OnlineRequest::with('type')
                ->whereIn('status', ['submitted', 'received', 'in_review'])
                ->when($faculteId, fn ($q) => $q->where('faculte_id', $faculteId))
                ->get()->filter(fn ($d) => $d->enRetard())->count(),
            'Notes saisies' => Note::when($faculteId, fn ($q) => $q->whereHas('etudiant', fn ($e) => $e->where('faculte_id', $faculteId)))->count(),
            'Délai moyen traitement inscriptions (jours)' => $this->delaiMoyenTraitement($etudiants),
            'Taux de correction appliquée (%)' => $this->tauxCorrectionAppliquee($faculteId),
            'Complétude au premier dépôt (%)' => $experienceService->completudePremierDepot($faculteId, $anneeId),
            'Taux d’abandon mesurable (%)' => $experienceService->tauxAbandonMesurable($faculteId, $anneeId),
            'Taux d’anomalies bloquantes (%)' => $experienceService->tauxAnomaliesTechniques($faculteId, $anneeId),
        ];

        return view('admin.statistiques.index', [
            'indicateurs' => $indicateurs,
            'syntheseExperience' => $syntheseExperience,
            'annees' => AnneeUniversitaire::orderByDesc('libelle')->get(),
            'facultes' => $request->user()->faculteRestreinteId() === null ? Faculte::orderBy('nom')->get() : collect(),
            'filtres' => ['annee_universitaire_id' => $anneeId, 'faculte_id' => $faculteId],
        ]);
    }

    public function exporter(Request $request, ExperienceInscriptionService $experienceService): StreamedResponse
    {
        Gate::authorize('statistiques.export');

        $indicateurs = $this->indicateursPourExport($request, $experienceService);

        return response()->streamDownload(function () use ($indicateurs) {
            $csv = app(CsvSerializer::class);
            $sortie = fopen('php://output', 'w');
            fwrite($sortie, "\xEF\xBB\xBF");
            fputcsv($sortie, $csv->neutraliserLigne(['indicateur', 'valeur']), ';');

            foreach ($indicateurs as $libelle => $valeur) {
                fputcsv($sortie, $csv->neutraliserLigne([$libelle, $valeur]), ';');
            }

            fclose($sortie);
        }, 'statistiques-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array<string, int|float|null> */
    private function indicateursPourExport(Request $request, ExperienceInscriptionService $experienceService): array
    {
        // Mêmes filtres que l'index : le périmètre faculté s'applique.
        // Parité stricte index/export (tout indicateur affiché est exporté).
        $faculteId = $request->user()->faculteRestreinteId() ?? $request->input('faculte_id');
        $anneeId = $request->input('annee_universitaire_id') ?? AnneeUniversitaire::where('active', true)->value('id');

        $etudiants = Etudiant::when($faculteId, fn ($q) => $q->where('faculte_id', $faculteId));

        return [
            'Étudiants' => (clone $etudiants)->count(),
            'Inscriptions en cours' => InscriptionAcademique::when($anneeId, fn ($q) => $q->where('annee_universitaire_id', $anneeId))
                ->when($faculteId, fn ($q) => $q->whereHas('etudiant', fn ($e) => $e->where('faculte_id', $faculteId)))
                ->where('statut', 'en_cours')->count(),
            'Validées' => InscriptionAcademique::when($anneeId, fn ($q) => $q->where('annee_universitaire_id', $anneeId))
                ->when($faculteId, fn ($q) => $q->whereHas('etudiant', fn ($e) => $e->where('faculte_id', $faculteId)))
                ->where('statut', 'valide')->count(),
            'Enseignants' => Teacher::where('actif', true)->count(),
            'Demandes en retard' => OnlineRequest::with('type')
                ->whereIn('status', ['submitted', 'received', 'in_review'])
                ->when($faculteId, fn ($q) => $q->where('faculte_id', $faculteId))
                ->get()->filter(fn ($d) => $d->enRetard())->count(),
            'Notes saisies' => Note::when($faculteId, fn ($q) => $q->whereHas('etudiant', fn ($e) => $e->where('faculte_id', $faculteId)))->count(),
            'Délai moyen traitement inscriptions (jours)' => $this->delaiMoyenTraitement($etudiants),
            'Taux de correction appliquée (%)' => $this->tauxCorrectionAppliquee($faculteId),
            'Complétude au premier dépôt (%)' => $experienceService->completudePremierDepot($faculteId, $anneeId),
            'Taux d’abandon mesurable (%)' => $experienceService->tauxAbandonMesurable($faculteId, $anneeId),
            'Taux d’anomalies bloquantes (%)' => $experienceService->tauxAnomaliesTechniques($faculteId, $anneeId),
        ];
    }

    /**
     * Délai moyen de traitement d'un dossier d'inscription (LOOP 22) :
     * moyenne en jours entre le dépôt (`etudiants.created_at`) et la
     * première transition officielle vers « valide »
     * (`situations_etudiants.date_effet`, type `statut`). Portable
     * SQLite/MySQL (moyenne calculée en PHP, cf. DemandesStatsService).
     * Source unique : SituationEtudiant — jamais une estimation.
     */
    private function delaiMoyenTraitement($etudiants): ?float
    {
        $durees = (clone $etudiants)
            ->where('statut', 'valide')
            ->whereHas('situations', fn ($q) => $q->where('type', 'statut')->where('nouvelle_valeur', 'valide'))
            ->with(['situations' => fn ($q) => $q->where('type', 'statut')->where('nouvelle_valeur', 'valide')->orderBy('date_effet')])
            ->get(['id', 'created_at'])
            ->map(function (Etudiant $etudiant) {
                $premiereValidation = $etudiant->situations->first();

                return $premiereValidation !== null
                    ? $etudiant->created_at->diffInDays($premiereValidation->date_effet)
                    : null;
            })
            ->filter(fn ($j) => $j !== null && $j >= 0)
            ->values();

        return $durees->isNotEmpty() ? round($durees->avg(), 1) : null;
    }

    /**
     * Taux de correction appliquée (LOOP 22) : corrections d'inscription
     * appliquées ÷ corrections décidées (appliquées + rejetées), en %.
     * Source : `dossier_corrections` (4-eyes, statuts serveur). NULL
     * affiché « — » quand aucune correction n'a été décidée (jamais 0 %
     * trompeur).
     */
    private function tauxCorrectionAppliquee(?int $faculteId): ?float
    {
        $decidees = DossierCorrection::whereIn('statut', ['appliquee', 'rejetee'])
            ->when($faculteId !== null, fn ($q) => $q->whereHas('etudiant', fn ($e) => $e->where('faculte_id', $faculteId)))
            ->count();

        if ($decidees === 0) {
            return null;
        }

        $appliquees = DossierCorrection::where('statut', 'appliquee')
            ->when($faculteId !== null, fn ($q) => $q->whereHas('etudiant', fn ($e) => $e->where('faculte_id', $faculteId)))
            ->count();

        return round(100 * $appliquees / $decidees, 1);
    }
}
