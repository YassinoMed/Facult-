<?php

namespace App\Providers;

use App\Contracts\EnregistrementPresenceInterface;
use App\Contracts\PlanificationParcoursInterface;
use App\Contracts\SimulationParcoursInterface;
use App\Events\DossierDecisionPrise;
use App\Events\InscriptionValidee;
use App\Events\PeriodeInscriptionOuverte;
use App\Events\PopulationChanged;
use App\Events\StudentRegistered;
use App\Listeners\AffecterApresDecisionDossier;
use App\Listeners\AuditPopulation;
use App\Listeners\DetecterIncoherencesInscription;
use App\Listeners\JournaliserInscription;
use App\Listeners\NotifierDecisionDossier;
use App\Listeners\NotifierDepotRecu;
use App\Listeners\NotifierPeriodeOuverte;
use App\Listeners\ProvisionnerDossierInscription;
use App\Listeners\TenterAffectationAutomatique;
use App\Models\Etudiant;
use App\Modules\Announcements\Events\AnnoncePubliee;
use App\Modules\Announcements\Events\CampagneEnvoyee;
use App\Modules\Announcements\Listeners\AuditAnnonce;
use App\Modules\Announcements\Listeners\AuditCampagne;
use App\Modules\Announcements\Models\Annonce;
use App\Modules\Announcements\Models\Campagne;
use App\Modules\Announcements\Policies\AnnoncePolicy;
use App\Modules\Announcements\Policies\CampagnePolicy;
use App\Modules\Attendance\Events\AbsenceJustifiee;
use App\Modules\Attendance\Events\PresenceEnregistree;
use App\Modules\Attendance\Events\PresenceSupprimee;
use App\Modules\Attendance\Listeners\AuditerPresence;
use App\Modules\Attendance\Listeners\AuditerSuppressionPresence;
use App\Modules\Attendance\Listeners\AuditJustification;
use App\Modules\Attendance\Models\AttendanceTemplate;
use App\Modules\Attendance\Models\Justification;
use App\Modules\Attendance\Models\Presence;
use App\Modules\Attendance\Models\TeacherPresence;
use App\Modules\Attendance\Policies\AttendanceTemplatePolicy;
use App\Modules\Attendance\Policies\JustificationPolicy;
use App\Modules\Attendance\Policies\PresencePolicy;
use App\Modules\Attendance\Policies\TeacherPresencePolicy;
use App\Modules\Attendance\Services\AttendanceService;
use App\Modules\Calendar\Models\EvenementCalendrier;
use App\Modules\Calendar\Policies\CalendrierPolicy;
use App\Modules\Enseignement\Events\EnseignementChanged;
use App\Modules\Enseignement\Listeners\AuditEnseignement;
use App\Modules\Enseignement\Models\Enseignement;
use App\Modules\Enseignement\Policies\EnseignementPolicy;
use App\Modules\Evaluation\Events\EvaluationChanged;
use App\Modules\Evaluation\Listeners\AuditEvaluation;
use App\Modules\Evaluation\Listeners\GenererRelevesPublication;
use App\Modules\Evaluation\Models\Deliberation;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\NoteEpreuve;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Policies\EvaluationPolicy;
use App\Modules\Finances\Events\FactureEmise;
use App\Modules\Finances\Events\PaiementConfirme;
use App\Modules\Finances\Events\RemboursementTraite;
use App\Modules\Finances\Listeners\AuditFinance;
use App\Modules\Finances\Models\Facture;
use App\Modules\Finances\Models\Paiement;
use App\Modules\Finances\Models\Remboursement;
use App\Modules\Finances\Models\Tarif;
use App\Modules\Finances\Policies\FinancePolicy;
use App\Modules\OnlineRequests\Events\OnlineRequestApproved;
use App\Modules\OnlineRequests\Events\OnlineRequestCompleted;
use App\Modules\OnlineRequests\Events\OnlineRequestCreated;
use App\Modules\OnlineRequests\Events\OnlineRequestDocumentGenerated;
use App\Modules\OnlineRequests\Events\OnlineRequestRejected;
use App\Modules\OnlineRequests\Events\OnlineRequestSubmitted;
use App\Modules\OnlineRequests\Events\OnlineRequestWaitingForStudent;
use App\Modules\OnlineRequests\Listeners\AuditOnlineRequest;
use App\Modules\OnlineRequests\Listeners\NotifyDemande;
use App\Modules\OnlineRequests\Models\OnlineRequest;
use App\Modules\OnlineRequests\Policies\OnlineRequestPolicy;
use App\Modules\ParcoursAcademique\Events\ParcoursDecisionEnregistree;
use App\Modules\ParcoursAcademique\Listeners\AuditerParcoursDecision;
use App\Modules\ParcoursAcademique\Listeners\NotifierParcoursDecision;
use App\Modules\ParcoursAcademique\Models\ParcoursAlerteRisque;
use App\Modules\ParcoursAcademique\Models\ParcoursDispense;
use App\Modules\ParcoursAcademique\Models\ParcoursEquivalence;
use App\Modules\ParcoursAcademique\Models\ParcoursPasserelle;
use App\Modules\ParcoursAcademique\Models\ParcoursPlan;
use App\Modules\ParcoursAcademique\Models\ParcoursReorientation;
use App\Modules\ParcoursAcademique\Models\ParcoursSimulation;
use App\Modules\ParcoursAcademique\Policies\ParcoursPolicy;
use App\Modules\ParcoursAcademique\Services\PlanParcoursService;
use App\Modules\ParcoursAcademique\Services\SimulationParcoursService;
use App\Modules\Referentiel\Events\MaquetteTransitionnee;
use App\Modules\Referentiel\Events\OrdreModifie;
use App\Modules\Referentiel\Listeners\AuditMaquette;
use App\Modules\Referentiel\Listeners\AuditOrdre;
use App\Modules\Referentiel\Models\Departement;
use App\Modules\Referentiel\Models\Domaine;
use App\Modules\Referentiel\Models\Maquette;
use App\Modules\Referentiel\Models\Mention;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\Specialite;
use App\Modules\Referentiel\Models\TypeDiplome;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Referentiel\Policies\ReferentielPolicy;
use App\Modules\Schedule\Events\ScheduleChanged;
use App\Modules\Schedule\Listeners\AuditSchedule;
use App\Modules\Schedule\Listeners\NotifierPlanning;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Policies\SchedulePolicy;
use App\Modules\Sentinel\Listeners\CapterJobEchoueSentinel;
use App\Modules\Sentinel\Models\SentinelIncident;
use App\Modules\Sentinel\Policies\SentinelIncidentPolicy;
use App\Modules\Sentinel\Services\SentinelRegistry;
use App\Modules\StudentArchive\Events\ArchiveConsultee;
use App\Modules\StudentArchive\Listeners\AuditerArchive;
use App\Modules\StudentArchive\Services\ArchiveTimelineRegistry;
use App\Modules\StudentArchive\Services\Providers\AcademicDocumentTimelineProvider;
use App\Modules\StudentArchive\Services\Providers\AcademicTimelineProvider;
use App\Modules\StudentArchive\Services\Providers\AttendanceTimelineProvider;
use App\Modules\StudentArchive\Services\Providers\GroupTimelineProvider;
use App\Modules\StudentArchive\Services\Providers\RegistrationTimelineProvider;
use App\Modules\StudentArchive\Services\Providers\RequestTimelineProvider;
use App\Modules\StudentArchive\Services\Providers\SensitiveTimelineProvider;
use App\Modules\StudentArchive\Services\Providers\SituationTimelineProvider;
use App\Modules\StudentDossiers\Contracts\AntivirusScannerInterface;
use App\Modules\StudentDossiers\Contracts\PdfGeneratorInterface;
use App\Modules\StudentDossiers\Events\DocumentArchived;
use App\Modules\StudentDossiers\Events\DocumentDeleted;
use App\Modules\StudentDossiers\Events\DocumentRejected;
use App\Modules\StudentDossiers\Events\DocumentReplaced;
use App\Modules\StudentDossiers\Events\DocumentUploaded;
use App\Modules\StudentDossiers\Events\DocumentValidated;
use App\Modules\StudentDossiers\Events\StudentDossierPdfDownloaded;
use App\Modules\StudentDossiers\Events\StudentDossierPdfGenerated;
use App\Modules\StudentDossiers\Events\StudentDossierPdfPreviewed;
use App\Modules\StudentDossiers\Events\StudentDossierPdfPrinted;
use App\Modules\StudentDossiers\Listeners\AuditDocumentArchiving;
use App\Modules\StudentDossiers\Listeners\AuditDocumentDeletion;
use App\Modules\StudentDossiers\Listeners\AuditDocumentRejection;
use App\Modules\StudentDossiers\Listeners\AuditDocumentReplacement;
use App\Modules\StudentDossiers\Listeners\AuditDocumentUpload;
use App\Modules\StudentDossiers\Listeners\AuditDocumentValidation;
use App\Modules\StudentDossiers\Listeners\AuditStudentDossierPdf;
use App\Modules\StudentDossiers\Listeners\LancerExtractionOcr;
use App\Modules\StudentDossiers\Listeners\NotifyDocumentRejete;
use App\Modules\StudentDossiers\Listeners\NotifyDocumentValide;
use App\Modules\StudentDossiers\Models\StudentDocument;
use App\Modules\StudentDossiers\Models\StudentDossier;
use App\Modules\StudentDossiers\Policies\StudentDocumentPolicy;
use App\Modules\StudentDossiers\Policies\StudentDossierPdfPolicy;
use App\Modules\StudentDossiers\Services\NullAntivirusScanner;
use App\Modules\StudentDossiers\Services\Pdf\DompdfPdfGenerator;
use App\Modules\Tasks\Events\TaskChanged;
use App\Modules\Tasks\Listeners\AuditTask;
use App\Modules\Tasks\Listeners\CreerTacheDemande;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Policies\TaskPolicy;
use App\Modules\Versioning\Models\Release;
use App\Modules\Versioning\Policies\ReleasePolicy;
use App\Services\Documents\DefaultDocumentGenerator;
use App\Services\Documents\DocumentGenerator;
use App\Services\Recherche\DbSearchEngine;
use App\Services\Recherche\SearchEngine;
use App\Support\Modules\ModuleRegistry;
use Carbon\Carbon;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

/**
 * Câblage CORE + MODULES (§3/§5-7) : le cœur ne connaît aucun module
 * en dur — tout passe par ModuleRegistry + contracts + events.
 *
 * Ajouter un module = déclarer dans config/modules.php + compléter
 * ce provider (binding, policy, routes, listeners), sans toucher aux
 * contrôleurs/routes/policies du cœur (voir docs/ADDING-A-MODULE.md).
 */
class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ModuleRegistry::class);

        // Contrat → implémentation : remplaçable sans toucher les consommateurs.
        $this->app->bind(EnregistrementPresenceInterface::class, AttendanceService::class);
        $this->app->bind(PlanificationParcoursInterface::class, PlanParcoursService::class);
        $this->app->bind(SimulationParcoursInterface::class, SimulationParcoursService::class);
        $this->app->bind(AntivirusScannerInterface::class, NullAntivirusScanner::class);
        $this->app->bind(PdfGeneratorInterface::class, DompdfPdfGenerator::class);
        $this->app->bind(DocumentGenerator::class, DefaultDocumentGenerator::class);
        $this->app->bind(SearchEngine::class, DbSearchEngine::class);
        $this->app->singleton(SentinelRegistry::class);
    }

    public function boot(): void
    {        // CORE → modules : le cœur annonce, les modules réagissent.
        Event::listen(StudentRegistered::class, JournaliserInscription::class);
        Event::listen(StudentRegistered::class, NotifierDepotRecu::class);
        Event::listen(StudentRegistered::class, ProvisionnerDossierInscription::class);
        Event::listen(StudentRegistered::class, DetecterIncoherencesInscription::class);
        Event::listen(PopulationChanged::class, AuditPopulation::class);
        Event::listen(InscriptionValidee::class, TenterAffectationAutomatique::class);
        Event::listen(DossierDecisionPrise::class, NotifierDecisionDossier::class);
        Event::listen(DossierDecisionPrise::class, AffecterApresDecisionDossier::class);
        Event::listen(PeriodeInscriptionOuverte::class, NotifierPeriodeOuverte::class);

        Gate::policy(Presence::class, PresencePolicy::class);
        Gate::policy(TeacherPresence::class, TeacherPresencePolicy::class);
        Gate::policy(Justification::class, JustificationPolicy::class);
        Gate::policy(AttendanceTemplate::class, AttendanceTemplatePolicy::class);
        Gate::policy(SentinelIncident::class, SentinelIncidentPolicy::class);
        Gate::policy(Annonce::class, AnnoncePolicy::class);
        Gate::policy(Campagne::class, CampagnePolicy::class);
        Gate::policy(EvenementCalendrier::class, CalendrierPolicy::class);
        Gate::policy(Task::class, TaskPolicy::class);
        Gate::policy(Facture::class, FinancePolicy::class);
        Gate::policy(Paiement::class, FinancePolicy::class);
        Gate::policy(Remboursement::class, FinancePolicy::class);
        Gate::policy(Tarif::class, FinancePolicy::class);

        Event::listen(PresenceEnregistree::class, AuditerPresence::class);
        Event::listen(PresenceSupprimee::class, AuditerSuppressionPresence::class);
        Event::listen(AbsenceJustifiee::class, AuditJustification::class);
        Event::listen(AnnoncePubliee::class, AuditAnnonce::class);
        Event::listen(CampagneEnvoyee::class, AuditCampagne::class);
        Event::listen(TaskChanged::class, AuditTask::class);
        Event::listen(FactureEmise::class, [AuditFinance::class, 'facture']);
        Event::listen(PaiementConfirme::class, [AuditFinance::class, 'paiement']);
        Event::listen(RemboursementTraite::class, [AuditFinance::class, 'remboursement']);
        Event::listen(OnlineRequestSubmitted::class, CreerTacheDemande::class);

        Gate::policy(StudentDossier::class, StudentDossierPdfPolicy::class);
        Gate::policy(StudentDocument::class, StudentDocumentPolicy::class);
        Gate::policy(OnlineRequest::class, OnlineRequestPolicy::class);
        Gate::policy(Schedule::class, SchedulePolicy::class);
        Gate::policy(ScheduleSlot::class, SchedulePolicy::class);
        Gate::policy(Enseignement::class, EnseignementPolicy::class);
        Gate::policy(SessionExamen::class, EvaluationPolicy::class);
        Gate::policy(Examen::class, EvaluationPolicy::class);
        Gate::policy(Epreuve::class, EvaluationPolicy::class);
        Gate::policy(NoteEpreuve::class, EvaluationPolicy::class);
        Gate::policy(Deliberation::class, EvaluationPolicy::class);
        Gate::policy(Release::class, ReleasePolicy::class);

        foreach ([ParcoursSimulation::class, ParcoursPlan::class, ParcoursEquivalence::class,
            ParcoursDispense::class, ParcoursReorientation::class, ParcoursPasserelle::class,
            ParcoursAlerteRisque::class] as $modeleParcours) {
            Gate::policy($modeleParcours, ParcoursPolicy::class);
        }

        Event::listen(ParcoursDecisionEnregistree::class, AuditerParcoursDecision::class);
        Event::listen(ParcoursDecisionEnregistree::class, NotifierParcoursDecision::class);

        Event::listen(EnseignementChanged::class, AuditEnseignement::class);
        Event::listen(EvaluationChanged::class, AuditEvaluation::class);
        Event::listen(EvaluationChanged::class, GenererRelevesPublication::class);
        Event::listen(ArchiveConsultee::class, AuditerArchive::class);

        // Timeline archive : providers cœur toujours disponibles (sources
        // cœur, aucune dépendance module) ; les modules ajouteront les leurs.
        $this->app->singleton(ArchiveTimelineRegistry::class, function () {
            $registre = new ArchiveTimelineRegistry;
            $registre->enregistrer(new RegistrationTimelineProvider);
            $registre->enregistrer(new GroupTimelineProvider);
            $registre->enregistrer(new SituationTimelineProvider);
            $registre->enregistrer(new AcademicDocumentTimelineProvider);
            $registre->enregistrer(new AcademicTimelineProvider);
            $registre->enregistrer(new RequestTimelineProvider);
            $registre->enregistrer(new AttendanceTimelineProvider);
            $registre->enregistrer(new SensitiveTimelineProvider);

            return $registre;
        });

        foreach ([Departement::class, Domaine::class, Mention::class, Specialite::class,
            TypeDiplome::class, Programme::class, Niveau::class, SemestreLmd::class,
            UniteEnseignement::class, Maquette::class] as $modele) {
            Gate::policy($modele, ReferentielPolicy::class);
        }

        Event::listen(MaquetteTransitionnee::class, AuditMaquette::class);
        Event::listen(OrdreModifie::class, AuditOrdre::class);

        Event::listen(DocumentUploaded::class, AuditDocumentUpload::class);
        Event::listen(DocumentUploaded::class, LancerExtractionOcr::class);
        Event::listen(DocumentValidated::class, AuditDocumentValidation::class);
        Event::listen(DocumentValidated::class, NotifyDocumentValide::class);
        Event::listen(DocumentRejected::class, AuditDocumentRejection::class);
        Event::listen(DocumentRejected::class, NotifyDocumentRejete::class);
        Event::listen(DocumentReplaced::class, AuditDocumentReplacement::class);
        Event::listen(DocumentDeleted::class, AuditDocumentDeletion::class);
        Event::listen(DocumentArchived::class, AuditDocumentArchiving::class);

        Event::listen(StudentDossierPdfGenerated::class, [AuditStudentDossierPdf::class, 'generation']);
        Event::listen(StudentDossierPdfPreviewed::class, [AuditStudentDossierPdf::class, 'preview']);
        Event::listen(StudentDossierPdfDownloaded::class, [AuditStudentDossierPdf::class, 'download']);
        Event::listen(StudentDossierPdfPrinted::class, [AuditStudentDossierPdf::class, 'print']);

        Event::listen(OnlineRequestCreated::class, [AuditOnlineRequest::class, 'creation']);
        Event::listen(OnlineRequestSubmitted::class, [AuditOnlineRequest::class, 'soumission']);
        Event::listen(OnlineRequestApproved::class, [AuditOnlineRequest::class, 'approbation']);
        Event::listen(OnlineRequestRejected::class, [AuditOnlineRequest::class, 'rejet']);
        Event::listen(OnlineRequestCompleted::class, [AuditOnlineRequest::class, 'cloture']);
        Event::listen(OnlineRequestDocumentGenerated::class, [AuditOnlineRequest::class, 'document']);
        Event::listen(OnlineRequestApproved::class, [NotifyDemande::class, 'approbation']);
        Event::listen(OnlineRequestRejected::class, [NotifyDemande::class, 'rejet']);
        Event::listen(OnlineRequestCompleted::class, [NotifyDemande::class, 'cloture']);
        Event::listen(OnlineRequestWaitingForStudent::class, [NotifyDemande::class, 'pieceAttendee']);
        Event::listen(OnlineRequestRejected::class, [NotifyDemande::class, 'rejet']);
        Event::listen(OnlineRequestCompleted::class, [NotifyDemande::class, 'cloture']);
        Event::listen(ScheduleChanged::class, AuditSchedule::class);
        Event::listen(ScheduleChanged::class, NotifierPlanning::class);
        Event::listen(JobFailed::class, CapterJobEchoueSentinel::class);

        $registre = $this->app->make(ModuleRegistry::class);

        // Routes des modules : enregistrées dans tous les cas, l'accès
        // est verrouillé par le middleware `module:xxx` (404 si
        // désactivé) — testable par simple bascule de config.
        Route::middleware('web')->group(
            base_path('app/Modules/Attendance/Routes/web.php')
        );
        Route::middleware('web')->group(
            base_path('app/Modules/Announcements/Routes/web.php')
        );
        Route::middleware('web')->group(
            base_path('app/Modules/Calendar/Routes/web.php')
        );
        Route::middleware('web')->group(
            base_path('app/Modules/Tasks/Routes/web.php')
        );
        Route::middleware('web')->group(
            base_path('app/Modules/Finances/Routes/web.php')
        );
        Route::middleware('web')->group(
            base_path('app/Modules/StudentDossiers/Routes/web.php')
        );
        Route::middleware('web')->group(
            base_path('app/Modules/OnlineRequests/Routes/web.php')
        );
        Route::middleware('web')->group(
            base_path('app/Modules/Schedule/Routes/web.php')
        );
        Route::middleware('web')->group(
            base_path('app/Modules/Referentiel/Routes/web.php')
        );
        Route::middleware('web')->group(
            base_path('app/Modules/Enseignement/Routes/web.php')
        );
        Route::middleware('web')->group(
            base_path('app/Modules/Evaluation/Routes/web.php')
        );
        Route::middleware('web')->group(
            base_path('app/Modules/StudentArchive/Routes/web.php')
        );
        Route::middleware('web')->group(
            base_path('app/Modules/ParcoursAcademique/Routes/web.php')
        );
        Route::middleware('web')->group(
            base_path('app/Modules/Versioning/Routes/web.php')
        );
        Route::middleware('web')->group(
            base_path('app/Modules/Sentinel/Routes/web.php')
        );

        // Widgets de dashboard : enregistrés dans tous les cas (comme les
        // routes) — les vues restent filtrées par le registre au rendu,
        // chaque composer est défensif (session/groupe absents = rien).
        View::composer('modules.tasks.widget-admin', function ($vue) {
            $query = Task::whereIn('statut', ['pending', 'in_progress', 'waiting', 'expired']);

            if (auth()->check() && auth()->user()->faculteRestreinteId() !== null) {
                $faculteId = auth()->user()->faculteRestreinteId();
                $query->where(fn ($w) => $w->whereNull('faculte_id')->orWhere('faculte_id', $faculteId));
            }

            $vue->with([
                'tachesActives' => (clone $query)->count(),
                'tachesCritiques' => (clone $query)->where('priorite', 'critical')->count(),
            ]);
        });

        View::composer('modules.announcements.widget-admin', function ($vue) {
            $vue->with([
                'annoncesPubliees' => Annonce::where('statut', 'published')
                    ->where(fn ($q) => $q->whereNull('expire_a')->orWhere('expire_a', '>', now()))
                    ->count(),
            ]);
        });

        View::composer('modules.evaluation.widget-admin', function ($vue) {
            $vue->with([
                'epreuvesSansSalle' => Epreuve::whereNull('salle_id')->where('statut', '!=', 'cancelled')->count(),
                'notesAValider' => NoteEpreuve::where('statut', 'submitted')->count(),
                'deliberationsEnAttente' => Deliberation::whereIn('statut', ['draft', 'prepared', 'in_review'])->count(),
            ]);
        });

        View::composer('modules.schedule.widget-admin', function ($vue) {
            $query = ScheduleSlot::where('status', '!=', 'cancelled');

            if (auth()->check() && auth()->user()->faculteRestreinteId() !== null) {
                $faculteId = auth()->user()->faculteRestreinteId();
                $query->whereHas('schedule.groupe', fn ($q) => $q->where('faculte_id', $faculteId));
            }

            $vue->with(['creneauxActifs' => $query->count()]);
        });

        View::composer('modules.schedule.widget-etudiant', function ($vue) {
            $etudiantId = session('etudiant_auth_id');

            if ($etudiantId === null) {
                return;
            }

            $etudiant = Etudiant::find($etudiantId);

            if ($etudiant === null || $etudiant->groupe_id === null) {
                return;
            }

            $planning = Schedule::where('groupe_id', $etudiant->groupe_id)
                ->where('actif', true)
                ->orderByDesc('id')
                ->first();

            if ($planning === null) {
                return;
            }

            $vue->with([
                'coursAujourdhui' => ScheduleSlot::where('schedule_id', $planning->id)
                    ->where('day_of_week', strtolower(Carbon::now()->format('l')))
                    ->where('status', '!=', 'cancelled')
                    ->count(),
                'groupeCode' => $planning->groupe->code,
                'prochainCours' => self::prochainCours($planning->id),
            ]);
        });

        View::composer('modules.online-requests.widget-admin', function ($vue) {
            $base = OnlineRequest::query()
                ->whereIn('status', ['submitted', 'received', 'in_review', 'waiting_student']);

            if (auth()->check() && auth()->user()->faculteRestreinteId() !== null) {
                $faculteId = auth()->user()->faculteRestreinteId();
                $base->where('faculte_id', $faculteId);
            }

            $vue->with([
                'demandesNouvelles' => (clone $base)->whereIn('status', ['submitted', 'received'])->count(),
                'demandesEnCours' => (clone $base)->whereIn('status', ['in_review', 'waiting_student'])->count(),
                'demandesRetard' => (clone $base)->get()->filter(fn ($d) => $d->enRetard())->count(),
            ]);
        });

        View::composer('modules.online-requests.widget-etudiant', function ($vue) {
            $etudiantId = session('etudiant_auth_id');

            if ($etudiantId === null) {
                return;
            }

            $vue->with([
                'mesEnCours' => OnlineRequest::where('etudiant_id', $etudiantId)
                    ->whereIn('status', ['submitted', 'received', 'in_review', 'waiting_student'])->count(),
                'mesDisponibles' => OnlineRequest::where('etudiant_id', $etudiantId)
                    ->whereIn('status', ['approved', 'completed'])
                    ->whereHas('documents')->count(),
            ]);
        });

        View::composer('modules.student-dossiers.widget-dossier', function ($vue) {
            $etudiantId = session('etudiant_auth_id');

            if ($etudiantId === null) {
                return;
            }

            $dossier = StudentDossier::withCount([
                'documents',
                'documents as valides' => fn ($q) => $q->where('status', 'validated'),
                'documents as en_attente' => fn ($q) => $q->where('status', 'pending'),
            ])->where('etudiant_id', $etudiantId)->first();

            if ($dossier !== null) {
                $vue->with(['dossierWidget' => $dossier]);
            }
        });

        View::composer('modules.student-dossiers.widget-admin', function ($vue) {
            $base = StudentDocument::query()->where('status', 'pending');

            if (auth()->check() && auth()->user()->faculteRestreinteId() !== null) {
                $faculteId = auth()->user()->faculteRestreinteId();
                $base->whereHas('dossier.etudiant', fn ($q) => $q->where('faculte_id', $faculteId));
            }

            $vue->with(['documentsEnAttente' => $base->count()]);
        });

        View::composer('modules.attendance.widget-taux', function ($vue) {
            $etudiantId = session('etudiant_auth_id');

            if ($etudiantId === null) {
                return;
            }

            $service = $this->app->make(EnregistrementPresenceInterface::class);
            $etudiant = Etudiant::find($etudiantId);

            if ($etudiant !== null) {
                $vue->with([
                    'tauxWidget' => $service->tauxPresence($etudiant),
                    'seancesWidget' => $service->nombreSeances($etudiant),
                ]);
            }
        });

        View::composer('modules.attendance.widget-admin', function ($vue) {
            $query = Presence::query()->whereDate('date', today());

            if (auth()->check() && auth()->user()->faculteRestreinteId() !== null) {
                $faculteId = auth()->user()->faculteRestreinteId();
                $query->whereHas('etudiant', fn ($q) => $q->where('faculte_id', $faculteId));
            }

            $vue->with(['presencesJour' => $query->count()]);
        });
    }

    /**
     * Prochain cours du jour (§10) : premier créneau restant, exception
     * du jour appliquée (annulé = on passe au suivant).
     *
     * @return array{debut: string, fin: string, matiere: string, salle: ?string}|null
     */
    private static function prochainCours(int $scheduleId): ?array
    {
        $aujourdhui = strtolower(Carbon::now()->format('l'));
        $maintenant = now()->format('H:i:s');
        $date = Carbon::today()->toDateString();

        $slots = ScheduleSlot::where('schedule_id', $scheduleId)
            ->where('day_of_week', $aujourdhui)
            ->where('status', '!=', 'cancelled')
            ->with(['matiere', 'room', 'exceptions' => fn ($q) => $q->whereDate('exception_date', $date)->with('nouvelleSalle')])
            ->orderBy('start_time')
            ->get();

        foreach ($slots as $slot) {
            $exception = $slot->exceptions->first();

            if ($exception !== null && $exception->status === 'cancelled') {
                continue;
            }

            $debut = $exception?->new_start_time ?? $slot->start_time;

            if ($debut < $maintenant) {
                continue;
            }

            return [
                'debut' => substr((string) $debut, 0, 5),
                'fin' => substr((string) ($exception?->new_end_time ?? $slot->end_time), 0, 5),
                'matiere' => $slot->matiere->nom,
                'salle' => $exception?->nouvelleSalle?->code ?? $slot->room?->code,
            ];
        }

        return null;
    }
}
