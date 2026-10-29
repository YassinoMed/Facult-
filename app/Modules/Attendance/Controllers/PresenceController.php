<?php

namespace App\Modules\Attendance\Controllers;

use App\Contracts\EnregistrementPresenceInterface;
use App\Http\Controllers\Controller;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\IntegrationLog;
use App\Models\Matiere;
use App\Modules\Attendance\Events\PresenceSupprimee;
use App\Modules\Attendance\Models\AttendanceTemplate;
use App\Modules\Attendance\Models\Presence;
use App\Modules\Attendance\Models\SeanceAppel;
use App\Modules\Attendance\Models\TeacherPresence;
use App\Modules\Attendance\Requests\StorePresenceRequest;
use App\Modules\Attendance\Services\AttendanceQrSecurityService;
use App\Modules\Attendance\Services\AttendanceTemplateRenderer;
use App\Modules\Attendance\Services\PresenceEmploiDuTempsService;
use App\Modules\Referentiel\Models\Departement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use App\Modules\StudentDossiers\Contracts\PdfGeneratorInterface;
use App\Services\Io\CsvSerializer;
use App\Services\ParametreService;
use App\Services\PopulationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Module Attendance (§18) : contrôleur fin — toute la logique vit
 * dans AttendanceService. Isolation faculté + permissions sur chaque
 * action, comme le cœur (anti-IDOR/BOLA).
 */
class PresenceController extends Controller
{
    public function __construct(
        private readonly EnregistrementPresenceInterface $presences,
        private readonly PresenceEmploiDuTempsService $serviceEnseignants,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Presence::class);

        $faculteId = $request->user()->faculteRestreinteId();
        $dateSelectionnee = (string) ($request->input('date') ?: now()->toDateString());
        $toleranceRetard = $this->toleranceRetardAdministrable();

        // 1. Validation et filtres pour les séances enseignants (emploi du temps)
        $filtresEnseignantsValidation = $request->validate([
            'date' => ['nullable', 'date'],
            'statut' => ['nullable', 'string'],
            'statut_enseignant' => ['nullable', 'string'],
            'groupe_id' => ['nullable', 'integer', 'exists:groups,id'],
            'teacher_id' => ['nullable', 'integer', 'exists:teachers,id'],
            'departement_id' => ['nullable', 'integer', 'exists:departements,id'],
            'type' => ['nullable', 'string', 'in:course,td,tp,exam,meeting,workshop,other'],
            'a_verifier' => ['nullable'],
        ]);

        if (! empty($filtresEnseignantsValidation['groupe_id'])) {
            $groupe = Group::findOrFail($filtresEnseignantsValidation['groupe_id']);
            abort_unless($request->user()->peutAccederFaculte((int) $groupe->faculte_id), 404);
        }

        if (! empty($filtresEnseignantsValidation['teacher_id'])) {
            $teacher = Teacher::with('departement')->findOrFail($filtresEnseignantsValidation['teacher_id']);
            if ($teacher->departement?->faculte_id) {
                abort_unless($request->user()->peutAccederFaculte((int) $teacher->departement->faculte_id), 404);
            }
        }

        if (! empty($filtresEnseignantsValidation['departement_id'])) {
            $departement = Departement::findOrFail($filtresEnseignantsValidation['departement_id']);
            if ($departement->faculte_id) {
                abort_unless($request->user()->peutAccederFaculte((int) $departement->faculte_id), 404);
            }
        }

        $filtresEnseignants = [
            'groupe_id' => $filtresEnseignantsValidation['groupe_id'] ?? null,
            'teacher_id' => $filtresEnseignantsValidation['teacher_id'] ?? null,
            'departement_id' => $filtresEnseignantsValidation['departement_id'] ?? null,
            'type' => $filtresEnseignantsValidation['type'] ?? null,
            'statut' => $filtresEnseignantsValidation['statut_enseignant'] ?? null,
            'a_verifier_uniquement' => $request->boolean('a_verifier'),
        ];

        // Récupération et diagnostic des séances enseignants du jour
        $seancesEnseignants = $this->serviceEnseignants->seancesDuJour(
            $dateSelectionnee,
            $faculteId,
            $filtresEnseignants,
            $toleranceRetard
        );

        // KPIs : partition EXHAUSTIVE des statuts effectifs — la somme des
        // compteurs couvre exactement le total, aucune séance ignorée ni
        // comptée deux fois (définition documentée §5.A).
        $kpisEnseignants = [
            'seances_prevues' => $seancesEnseignants->count(),
            'presences_confirmees' => $seancesEnseignants->where('statut_effectif', 'presence_confirmee')->count(),
            'retards_potentiels' => $seancesEnseignants->where('statut_effectif', 'retard_potentiel')->count(),
            'pointages_manquants' => $seancesEnseignants->where('statut_effectif', 'pointage_manquant')->count(),
            'absences_a_verifier' => $seancesEnseignants->where('statut_effectif', 'absence_a_verifier')->count(),
            'absences_validees' => $seancesEnseignants->where('statut_effectif', 'absence_validee')->count(),
            'seances_annulees' => $seancesEnseignants->where('statut_effectif', 'seance_annulee')->count(),
            'dispenses_missions' => $seancesEnseignants->where('statut_effectif', 'dispense_mission')->count(),
            'retards_justifies' => $seancesEnseignants->where('statut_effectif', 'retard_justifie')->count(),
            'absences_rejetees' => $seancesEnseignants->where('statut_effectif', 'absence_rejetee')->count(),
            'seances_a_venir' => $seancesEnseignants->where('statut_effectif', 'a_venir')->count(),
            'seances_en_cours' => $seancesEnseignants->where('statut_effectif', 'en_cours')->count(),
        ];

        // 2. Relevés de présence étudiants (maintien intégral de la compatibilité ascendante)
        $queryEtudiants = Presence::with('etudiant.faculte')->latest('date')->latest('id');

        if ($faculteId !== null) {
            $queryEtudiants->whereHas('etudiant', fn ($q) => $q->where('faculte_id', $faculteId));
        }

        if (! empty($filtresEnseignantsValidation['date'])) {
            $queryEtudiants->whereDate('date', $filtresEnseignantsValidation['date']);
        }

        if (! empty($filtresEnseignantsValidation['statut']) && in_array($filtresEnseignantsValidation['statut'], Presence::STATUTS, true)) {
            $queryEtudiants->where('statut', $filtresEnseignantsValidation['statut']);
        }

        if (! empty($filtresEnseignantsValidation['groupe_id'])) {
            $queryEtudiants->whereHas('etudiant', fn ($q) => $q->where('groupe_id', $filtresEnseignantsValidation['groupe_id']));
        }

        $presences = $queryEtudiants->paginate(12)->withQueryString();

        $groupes = Group::orderBy('code')
            ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))
            ->get();

        $enseignants = Teacher::where('actif', true)
            ->orderBy('nom')
            ->when($faculteId !== null, fn ($q) => $q->whereHas('departement', fn ($d) => $d->where('faculte_id', $faculteId)))
            ->get();

        $departements = Departement::where('actif', true)
            ->orderBy('nom')
            ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))
            ->get();

        // Pagination côté serveur du tableau : les KPIs sont calculés sur
        // l'ensemble filtré, seules les lignes affichées sont paginées.
        $parPage = 15;
        $page = max(1, (int) $request->input('page', 1));
        $seancesPaginees = new LengthAwarePaginator(
            $seancesEnseignants->slice(($page - 1) * $parPage, $parPage)->values(),
            $seancesEnseignants->count(),
            $parPage,
            $page,
            ['path' => route('admin.presences.index'), 'pageName' => 'page']
        );

        $queryAppels = SeanceAppel::with(['slot.matiere', 'slot.room', 'slot.schedule.groupe', 'enseignant'])
            ->latest('date')
            ->latest('id');

        if ($faculteId !== null) {
            $queryAppels->whereHas('slot.schedule.groupe', fn ($g) => $g->where('faculte_id', $faculteId));
        }

        if (! empty($filtresEnseignantsValidation['date'])) {
            $queryAppels->whereDate('date', $filtresEnseignantsValidation['date']);
        }

        if ($request->input('statut_appel') === 'incomplet') {
            $queryAppels->where(fn ($q) => $q->where('non_renseignes', '>', 0)->orWhere('statut', 'en_cours'));
        } elseif ($request->filled('statut_appel')) {
            $queryAppels->where('statut', $request->input('statut_appel'));
        }

        $appelsSeances = $queryAppels->paginate(12, ['*'], 'page_appels')->withQueryString();

        return view('modules.attendance.index', [
            'presences' => $presences,
            'seancesEnseignants' => $seancesPaginees,
            'kpisEnseignants' => $kpisEnseignants,
            'dateSelectionnee' => $dateSelectionnee,
            'toleranceRetard' => $toleranceRetard,
            'groupes' => $groupes,
            'enseignants' => $enseignants,
            'departements' => $departements,
            'appelsSeances' => $appelsSeances,
        ]);
    }

    public function store(StorePresenceRequest $request): RedirectResponse
    {
        /** @var Etudiant $etudiant */
        $etudiant = Etudiant::findOrFail($request->validated('etudiant_id'));

        // Périmètre avant permission : hors faculté = 404 (anti-énumération).
        abort_unless($request->user()->peutAccederFaculte((int) $etudiant->faculte_id), 404);
        Gate::authorize('create', [Presence::class, $etudiant]);

        $donnees = $request->validated();

        $this->presences->enregistrer(
            $etudiant,
            $donnees['date'],
            $donnees['statut'],
            $donnees['seance'] ?? null,
            $donnees['motif'] ?? null,
            $request->user()->id,
        );

        return redirect()->route('admin.presences.index')
            ->with('success', 'Présence enregistrée.');
    }

    public function destroy(Request $request, Presence $presence): RedirectResponse
    {
        $presence->load('etudiant');

        abort_unless($request->user()->peutAccederFaculte((int) $presence->etudiant->faculte_id), 404);
        Gate::authorize('delete', $presence);

        $presence->delete();
        PresenceSupprimee::dispatch($presence);

        return redirect()->route('admin.presences.index')
            ->with('success', 'Relevé de présence supprimé.');
    }

    /**
     * Assistant de génération d'une fiche de présence multi-modèles (§15-17).
     */
    public function nouveauFeuille(Request $request): View
    {
        Gate::authorize('viewAny', Presence::class);
        abort_unless($request->user()->can('attendance.export'), 403);

        $faculteId = $request->user()->faculteRestreinteId();

        $groupes = Group::with('faculte')
            ->orderBy('code')
            ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))
            ->get();

        $creneaux = ScheduleSlot::with(['schedule.groupe', 'matiere', 'teacher', 'room'])
            ->when($faculteId !== null, function ($q) use ($faculteId) {
                $q->whereHas('schedule.groupe', fn ($sub) => $sub->where('faculte_id', $faculteId));
            })
            ->latest('id')
            ->take(30)
            ->get();

        $modeles = AttendanceTemplate::active()->orderBy('is_system', 'desc')->orderBy('name')->get();
        $matieres = Matiere::orderBy('code')->take(50)->get();
        $annees = AnneeUniversitaire::orderByDesc('libelle')->get();

        return view('modules.attendance.feuille-generateur', [
            'groupes' => $groupes,
            'creneaux' => $creneaux,
            'modeles' => $modeles,
            'matieres' => $matieres,
            'annees' => $annees,
        ]);
    }

    /**
     * Aperçu HTML interactif avant génération PDF (§15).
     */
    public function apercuFeuille(
        Request $request,
        AttendanceTemplateRenderer $renderer
    ): Response {
        Gate::authorize('viewAny', Presence::class);
        abort_unless($request->user()->can('attendance.export'), 403);

        $filtres = $request->validate([
            'groupe_id' => ['nullable', 'integer', 'exists:groups,id'],
            'matiere_id' => ['nullable', 'integer', 'exists:matieres,id'],
            'annee_universitaire_id' => ['nullable', 'integer', 'exists:annees_universitaires,id'],
            'schedule_slot_id' => ['nullable', 'integer', 'exists:schedule_slots,id'],
            'template_id' => ['nullable', 'integer', 'exists:attendance_templates,id'],
            'template_code' => ['nullable', 'string', 'max:60'],
            'date' => ['nullable', 'date'],
            'seance' => ['nullable', 'string', 'max:60'],
            'titre' => ['nullable', 'string', 'max:150'],
            'type_examen' => ['nullable', 'string', 'max:100'],
            'session' => ['nullable', 'string', 'max:100'],
            'surveillant_1' => ['nullable', 'string', 'max:100'],
            'surveillant_2' => ['nullable', 'string', 'max:100'],
        ]);

        if (! empty($filtres['groupe_id'])) {
            $groupe = Group::findOrFail($filtres['groupe_id']);
            abort_unless($request->user()->peutAccederFaculte((int) $groupe->faculte_id), 404);
        }

        if (! empty($filtres['schedule_slot_id'])) {
            $slot = ScheduleSlot::with('schedule.groupe')->findOrFail($filtres['schedule_slot_id']);
            if ($slot->schedule?->groupe?->faculte_id) {
                abort_unless($request->user()->peutAccederFaculte((int) $slot->schedule->groupe->faculte_id), 404);
            }
            if (empty($filtres['groupe_id']) && $slot->schedule?->groupe_id) {
                $filtres['groupe_id'] = $slot->schedule->groupe_id;
            }
        }

        /** @var AttendanceTemplate|null $template */
        $template = null;
        if (! empty($filtres['template_id'])) {
            $template = AttendanceTemplate::find($filtres['template_id']);
        } elseif (! empty($filtres['template_code'])) {
            $template = AttendanceTemplate::where('code', $filtres['template_code'])->first();
        }

        if ($template === null) {
            $template = AttendanceTemplate::where('code', 'classic')->firstOrFail();
        }

        $html = $renderer->rendreHtml($template, $filtres, $request->user());

        return response($html)->header('Content-Type', 'text/html');
    }

    /**
     * Feuille de présence multi-modèles (§5-8, §13-17) :
     * Supporte à la fois les 9 modèles configurables et l'émargement standard
     * via PopulationService et AttendanceTemplateRenderer.
     */
    public function feuille(
        Request $request,
        PopulationService $populations,
        PdfGeneratorInterface $pdf,
        AttendanceTemplateRenderer $renderer
    ): Response {
        Gate::authorize('viewAny', Presence::class);
        abort_unless($request->user()->can('attendance.export'), 403);

        $filtres = $request->validate([
            'groupe_id' => ['nullable', 'integer', 'exists:groups,id'],
            'matiere_id' => ['nullable', 'integer', 'exists:matieres,id'],
            'annee_universitaire_id' => ['nullable', 'integer', 'exists:annees_universitaires,id'],
            'schedule_slot_id' => ['nullable', 'integer', 'exists:schedule_slots,id'],
            'template_id' => ['nullable', 'integer', 'exists:attendance_templates,id'],
            'template_code' => ['nullable', 'string', 'max:60'],
            'date' => ['nullable', 'date'],
            'seance' => ['nullable', 'string', 'max:60'],
            'titre' => ['nullable', 'string', 'max:150'],
            'type_examen' => ['nullable', 'string', 'max:100'],
            'session' => ['nullable', 'string', 'max:100'],
            'surveillant_1' => ['nullable', 'string', 'max:100'],
            'surveillant_2' => ['nullable', 'string', 'max:100'],
        ]);

        if (! empty($filtres['groupe_id'])) {
            $groupe = Group::findOrFail($filtres['groupe_id']);
            abort_unless($request->user()->peutAccederFaculte((int) $groupe->faculte_id), 404);
        }

        if (! empty($filtres['schedule_slot_id'])) {
            $slot = ScheduleSlot::with('schedule.groupe')->findOrFail($filtres['schedule_slot_id']);
            if ($slot->schedule?->groupe?->faculte_id) {
                abort_unless($request->user()->peutAccederFaculte((int) $slot->schedule->groupe->faculte_id), 404);
            }
            if (empty($filtres['groupe_id']) && $slot->schedule?->groupe_id) {
                $filtres['groupe_id'] = $slot->schedule->groupe_id;
            }
        }

        $jeu = $populations->jeuDonnees('attendance_list', $filtres, $request->user());

        if ($jeu['lignes'] === []) {
            return back()->withErrors(['export' => 'Aucun étudiant pour ces critères.']);
        }

        /** @var AttendanceTemplate|null $template */
        $template = null;
        if (! empty($filtres['template_id'])) {
            $template = AttendanceTemplate::find($filtres['template_id']);
        } elseif (! empty($filtres['template_code'])) {
            $template = AttendanceTemplate::where('code', $filtres['template_code'])->first();
        }

        $reference = 'PRS-'.now()->format('Y').'-'.strtoupper(Str::random(6));

        if ($template !== null) {
            $contenu = $renderer->genererPdf($template, $filtres, $request->user());
        } else {
            $contexte = implode(' · ', array_filter([
                isset($filtres['date']) ? 'Date : '.now()->parse($filtres['date'])->format('d/m/Y') : null,
                isset($filtres['seance']) ? 'Séance : '.$filtres['seance'] : null,
            ]));

            $html = view('pdf.liste', [
                'institution' => config('app.name'),
                'titreDocument' => $filtres['titre'] ?? 'Feuille de présence',
                'reference' => $reference,
                'dateGeneration' => now()->format('d/m/Y H:i'),
                'contexte' => $contexte ?: 'Appel',
                'metadonnees' => [
                    'etablissement' => config('app.name'),
                    'utilisateur' => $request->user()->name ?? $request->user()->email,
                    'total' => count($jeu['lignes']),
                ],
                'entetes' => $jeu['entetes'],
                'lignes' => $jeu['lignes'],
                'sections' => null,
                'signature' => true,
                'zoneSignature' => false,
                'orientation' => 'landscape',
            ])->render();

            $contenu = $pdf->generer($html, ['paper' => 'A4', 'orientation' => 'landscape']);
        }

        IntegrationLog::tracer(
            'export', 'export', 'export.presences.feuille', 'completed',
            null, 200, null, null, (string) Str::uuid(), $reference,
        );

        return new Response($contenu, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="feuille-presence-'.now()->format('Ymd').'.pdf"',
        ]);
    }

    /**
     * Pointage par scan de QR Code sécurisé (§8 & §22).
     */
    public function scannerQr(Request $request, AttendanceQrSecurityService $qrSecurity): JsonResponse
    {
        Gate::authorize('create', [Presence::class, new Etudiant]);

        $request->validate([
            'token' => ['required', 'string'],
        ]);

        $resultat = $qrSecurity->validerEtEnregistrer(
            $request->string('token'),
            $request->user()->id
        );

        $codeHttp = $resultat['succes'] ? 200 : 422;

        return response()->json($resultat, $codeHttp);
    }

    /**
     * Espace étudiant : SES relevés uniquement (session-scopé).
     */
    public function mesAbsences(Request $request): View
    {
        $etudiant = Etudiant::findOrFail($request->session()->get('etudiant_auth_id'));

        $presences = Presence::with('derniereJustification')
            ->where('etudiant_id', $etudiant->id)
            ->orderByDesc('date')
            ->paginate(12);

        return view('modules.attendance.mes-absences', [
            'etudiant' => $etudiant,
            'presences' => $presences,
            'taux' => $this->presences->tauxPresence($etudiant),
            'seances' => $this->presences->nombreSeances($etudiant),
        ]);
    }

    /**
     * Export CSV de MES absences (LOOP 19) : relevés personnels uniquement,
     * formules tableur neutralisées (convention CsvSerializer).
     */
    public function exporterAbsences(Request $request): StreamedResponse
    {
        $etudiant = Etudiant::findOrFail($request->session()->get('etudiant_auth_id'));

        $presences = Presence::where('etudiant_id', $etudiant->id)
            ->orderByDesc('date')
            ->limit(1000)
            ->get(['date', 'seance', 'statut']);

        return response()->streamDownload(function () use ($presences) {
            $sortie = fopen('php://output', 'w');
            fwrite($sortie, "\xEF\xBB\xBF");
            fputcsv($sortie, ['Date', 'Séance', 'Statut'], ';');

            foreach ($presences as $presence) {
                fputcsv($sortie, [
                    $presence->date->format('d/m/Y'),
                    app(CsvSerializer::class)->neutraliserFormule((string) $presence->seance),
                    $presence->statut,
                ], ';');
            }

            fclose($sortie);
        }, 'mes-absences-'.now()->format('Y-m-d-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Rapprochement et persistance manuelle déclenchés depuis l'interface (§1-§8).
     */
    public function reconcilierEnseignants(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('attendance.create'), 403);

        $donnees = $request->validate([
            'date' => ['required', 'date'],
            'tolerance' => ['nullable', 'integer', 'min:0', 'max:120'],
            'fenetre' => ['nullable', 'integer', 'min:0', 'max:180'],
        ]);

        $faculteId = $request->user()->faculteRestreinteId();
        $tolerance = (int) ($donnees['tolerance'] ?? $this->toleranceRetardAdministrable());
        $fenetre = (int) ($donnees['fenetre'] ?? $this->fenetrePointageAdministrable());

        $kpis = $this->serviceEnseignants->reconcilierEtPersister(
            $donnees['date'],
            $faculteId,
            $request->user()->id,
            $tolerance,
            $fenetre
        );

        return redirect()->back()
            ->with('success', "Rapprochement effectué pour le {$donnees['date']} ({$kpis['total_seances']} séances traitées).");
    }

    /**
     * Enregistre un pointage brut pour un enseignant avec vérification de périmètre.
     */
    public function enregistrerPointageEnseignant(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('attendance.create'), 403);

        $donnees = $request->validate([
            'teacher_id' => ['required', 'integer', 'exists:teachers,id'],
            'horodatage' => ['required', 'date'],
            'type' => ['required', 'in:entree,sortie,presence'],
            'source' => ['nullable', 'in:badgeuse,manuel,terminal,portail,qr,autre'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        /** @var Teacher $teacher */
        $teacher = Teacher::with('departement')->findOrFail($donnees['teacher_id']);

        if ($teacher->departement?->faculte_id !== null) {
            abort_unless($request->user()->peutAccederFaculte((int) $teacher->departement->faculte_id), 404);
        }

        $this->serviceEnseignants->enregistrerPointage(
            $teacher,
            $donnees['horodatage'],
            $donnees['type'],
            $donnees['source'] ?? 'manuel',
            $request->user()->id,
            $donnees['notes'] ?? null,
            $request->ip(),
            // Réconciliation immédiate STRICTEMENT dans le périmètre de
            // l'agent : un restreint n'écrit jamais d'observations hors
            // de sa faculté (isolation §6).
            $request->user()->faculteRestreinteId()
        );

        return redirect()->back()
            ->with('success', "Pointage enregistré avec succès pour l'enseignant {$teacher->nomComplet()}.");
    }

    /**
     * Valide une absence ou confirme une présence pour une séance d'emploi du temps.
     */
    public function validerPresenceEnseignant(Request $request, TeacherPresence $presence): RedirectResponse
    {
        abort_unless($request->user()->can('attendance.create'), 403);

        $this->verifierPerimetreEnseignant($request, $presence);

        $donnees = $request->validate([
            'action' => ['required', 'in:absence_validee,presence_confirmee,dispense_mission'],
            'motif' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        if ($donnees['action'] === 'absence_validee') {
            $this->serviceEnseignants->validerAbsence($presence, $donnees['motif'], $request->user()->id);
            $message = 'Absence officiellement validée.';
        } elseif ($donnees['action'] === 'presence_confirmee') {
            $this->serviceEnseignants->confirmerPresence($presence, null, null, $donnees['motif'], $request->user()->id);
            $message = 'Présence confirmée avec succès.';
        } else {
            $this->serviceEnseignants->dispenserOuMission($presence, $donnees['motif'], $request->user()->id);
            $message = 'Dispense ou mission enregistrée.';
        }

        return redirect()->back()->with('success', $message);
    }

    /**
     * Confirme manuellement une présence (ex: feuille d'émargement papier).
     */
    public function confirmerPresenceEnseignant(Request $request, TeacherPresence $presence): RedirectResponse
    {
        abort_unless($request->user()->can('attendance.create'), 403);

        $this->verifierPerimetreEnseignant($request, $presence);

        $donnees = $request->validate([
            'heure_arrivee' => ['nullable', 'date_format:H:i'],
            'heure_depart' => ['nullable', 'date_format:H:i', 'after:heure_arrivee'],
            'motif' => ['nullable', 'string', 'max:500'],
        ]);

        $this->serviceEnseignants->confirmerPresence(
            $presence,
            $donnees['heure_arrivee'] ?? null,
            $donnees['heure_depart'] ?? null,
            $donnees['motif'] ?? 'Confirmation manuelle',
            $request->user()->id
        );

        return redirect()->back()->with('success', 'Présence de l\'enseignant confirmée.');
    }

    /**
     * Corrige une observation avec justification obligatoire et trace d'audit.
     */
    public function corrigerPresenceEnseignant(Request $request, TeacherPresence $presence): RedirectResponse
    {
        abort_unless($request->user()->can('attendance.create'), 403);

        $this->verifierPerimetreEnseignant($request, $presence);

        $donnees = $request->validate([
            'statut_valide' => ['required', 'in:'.implode(',', TeacherPresence::STATUTS_VALIDES)],
            'heure_arrivee' => ['nullable', 'date_format:H:i'],
            'heure_depart' => ['nullable', 'date_format:H:i', 'after:heure_arrivee'],
            'motif' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $this->serviceEnseignants->corrigerPresence(
            $presence,
            $donnees,
            $donnees['motif'],
            $request->user()->id
        );

        return redirect()->back()->with('success', 'Correction enregistrée avec succès.');
    }

    /**
     * Enregistre un ordre de mission ou une dispense.
     */
    public function dispenserEnseignant(Request $request, TeacherPresence $presence): RedirectResponse
    {
        abort_unless($request->user()->can('attendance.create'), 403);

        $this->verifierPerimetreEnseignant($request, $presence);

        $donnees = $request->validate([
            'motif' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $this->serviceEnseignants->dispenserOuMission($presence, $donnees['motif'], $request->user()->id);

        return redirect()->back()->with('success', 'Dispense ou mission enregistrée.');
    }

    /**
     * Retourne l'historique d'audit des modifications au format JSON.
     */
    public function historiqueEnseignant(Request $request, TeacherPresence $presence): JsonResponse
    {
        abort_unless($request->user()->can('attendance.view'), 403);

        $this->verifierPerimetreEnseignant($request, $presence);

        return response()->json([
            'presence_id' => $presence->id,
            'slot_id' => $presence->schedule_slot_id,
            'date' => $presence->date->format('Y-m-d'),
            'statut_calcule' => $presence->statut_calcule,
            'statut_valide' => $presence->statut_valide,
            'statut_effectif' => $presence->statutEffectif(),
            'motif' => $presence->motif,
            'valide_par' => $presence->validePar?->name,
            'valide_le' => $presence->valide_le?->toIso8601String(),
            'historique' => $presence->historique ?? [],
        ]);
    }

    /**
     * Tolérance administrative de retard : paramètre central
     * `presences.tolerance_retard_minutes` (administrable via la page des
     * paramètres, cache + audit), repli sur la constante moteur. Aucune
     * tolérance arbitraire codée en dur comme seule source.
     */
    private function toleranceRetardAdministrable(): int
    {
        $valeur = app(ParametreService::class)
            ->get('presences.tolerance_retard_minutes', PresenceEmploiDuTempsService::TOLERANCE_PAR_DEFAUT);

        $tolerance = (int) $valeur;

        return ($tolerance >= 0 && $tolerance <= 120) ? $tolerance : PresenceEmploiDuTempsService::TOLERANCE_PAR_DEFAUT;
    }

    /**
     * Fenêtre d'appariement administrable : paramètre central
     * `presences.fenetre_pointage_minutes` (repli constante moteur,
     * borne 0..180). Miroir de la tolérance de retard.
     */
    private function fenetrePointageAdministrable(): int
    {
        $valeur = app(ParametreService::class)
            ->get('presences.fenetre_pointage_minutes', PresenceEmploiDuTempsService::FENETRE_PAR_DEFAUT);

        $fenetre = (int) $valeur;

        return ($fenetre >= 0 && $fenetre <= 180) ? $fenetre : PresenceEmploiDuTempsService::FENETRE_PAR_DEFAUT;
    }

    /**
     * Contrôle d'isolation stricte par faculté (anti-IDOR/BOLA).
     */
    private function verifierPerimetreEnseignant(Request $request, TeacherPresence $presence): void
    {
        $presence->loadMissing(['slot.schedule.groupe', 'teacher.departement']);

        $faculteGroupe = $presence->slot?->schedule?->groupe?->faculte_id;
        if ($faculteGroupe !== null) {
            abort_unless($request->user()->peutAccederFaculte((int) $faculteGroupe), 404);

            return;
        }

        $faculteEnseignant = $presence->teacher?->departement?->faculte_id;
        if ($faculteEnseignant !== null) {
            abort_unless($request->user()->peutAccederFaculte((int) $faculteEnseignant), 404);
        }

        // Séparation des responsabilités (§14) : un enseignant ayant un compte admin
        // ne peut pas valider ou corriger ses propres présences.
        $userTeacherId = $request->user()->teacher?->id;
        $teacherUserId = $presence->teacher?->user_id;
        if (($userTeacherId !== null && (int) $presence->teacher_id === (int) $userTeacherId)
            || ($teacherUserId !== null && (int) $teacherUserId === (int) $request->user()->id)) {
            abort(403, 'Séparation des responsabilités : vous ne pouvez pas valider ou corriger votre propre présence.');
        }
    }
}
