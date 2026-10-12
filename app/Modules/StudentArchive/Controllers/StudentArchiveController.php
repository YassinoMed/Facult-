<?php

namespace App\Modules\StudentArchive\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\HistoriqueAcademique;
use App\Modules\StudentArchive\Events\ArchiveConsultee;
use App\Modules\StudentArchive\Policies\StudentArchivePolicy;
use App\Modules\StudentArchive\Services\ArchiveTimelineRegistry;
use App\Modules\StudentArchive\Services\StudentArchiveService;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Archive étudiant (§22, §34) : onglets server-side (general, academique,
 * inscriptions, documents, timeline, audit). Chaque onglet vérifie sa
 * permission ; seules ses données sont chargées (§32).
 */
class StudentArchiveController extends Controller
{
    public const ONGLETS = ['general', 'academique', 'inscriptions', 'documents', 'demandes', 'timeline', 'audit', 'confidentiel'];

    public function __construct(
        private readonly StudentArchiveService $archives,
        private readonly StudentArchivePolicy $policy,
    ) {}

    public function show(Request $request, Etudiant $etudiant): View
    {
        // Périmètre avant permission : hors faculté = 404 (anti-énumération).
        abort_unless($request->user()->peutAccederFaculte((int) $etudiant->faculte_id), 404);
        abort_unless($this->policy->voir($request->user(), $etudiant), 403);

        $donnees = $request->validate([
            'onglet' => ['nullable', 'in:'.implode(',', self::ONGLETS)],
            'annee_universitaire_id' => ['nullable', 'integer', 'exists:annees_universitaires,id'],
            'semestre' => ['nullable', 'integer', 'min:1', 'max:12'],
            'ue_id' => ['nullable', 'integer', 'exists:unites_enseignement,id'],
            'matiere_id' => ['nullable', 'integer', 'exists:matieres,id'],
            'session' => ['nullable', 'string', 'max:30'],
            'verrouillee' => ['nullable', 'in:oui,non'],
            'type' => ['nullable', 'string', 'max:40'],
            'search' => ['nullable', 'string', 'max:120'],
            'categorie' => ['nullable', 'string', 'max:30'],
            'offset' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);

        $onglet = $donnees['onglet'] ?? 'general';
        $this->autoriserOnglet($request, $etudiant, $onglet);

        ArchiveConsultee::dispatch($etudiant->id, (int) $request->user()->id, $onglet);

        if ($onglet === 'confidentiel') {
            app(AuditService::class)->tracer(
                $etudiant, HistoriqueAcademique::ACTION_SENSITIVE_VIEW, 'sensible', null
            );
        }

        $commun = [
            'etudiant' => $etudiant,
            'onglet' => $onglet,
            'filtres' => $donnees,
            'annees' => AnneeUniversitaire::orderByDesc('date_debut')->get(),
        ];

        return view('modules.student-archive.show', array_merge($commun, match ($onglet) {
            'academique' => [
                'academique' => $this->archives->academic($etudiant, $donnees),
                'notes' => $this->archives->notesQuery($etudiant, $donnees)->paginate(20)->withQueryString(),
            ],
            'inscriptions' => ['parcours' => $this->archives->registrations($etudiant)],
            'documents' => [
                'documents' => $this->archives->documents($etudiant, $donnees, $request->user()),
                'diplomes' => $this->archives->diplomas($etudiant),
                'certificats' => $this->archives->certificates($etudiant, $request->user()),
            ],
            'demandes' => [
                'demandes' => $this->archives->demandes($etudiant),
            ],
            'timeline' => [
                'timeline' => $this->archives->timeline($etudiant, $donnees + ['limite' => 20]),
                'categories' => app(ArchiveTimelineRegistry::class)->categories(),
            ],
            'audit' => [
                'audits' => HistoriqueAcademique::where('etudiant_id', $etudiant->id)
                    ->with('utilisateur')->latest('id')->paginate(20)->withQueryString(),
            ],
            'confidentiel' => [
                'sensibles' => $this->archives->sensibles($etudiant, $request->user()),
                'timelineSensible' => $this->archives->timeline($etudiant, $donnees + ['limite' => 20, 'sensible' => true]),
            ],
            default => array_merge(
                $this->archives->overview($etudiant),
                [
                    'timeline' => $this->archives->timeline($etudiant, $donnees + ['limite' => 20]),
                    'categories' => app(ArchiveTimelineRegistry::class)->categories(),
                ]
            ),
        }));
    }

    private function autoriserOnglet(Request $request, Etudiant $etudiant, string $onglet): void
    {
        $utilisateur = $request->user();

        match ($onglet) {
            'academique' => abort_unless($utilisateur->can('student_archive.academic.view'), 403),
            'inscriptions' => abort_unless($utilisateur->can('archives.view'), 403),
            'documents' => abort_unless($utilisateur->can('student_archive.documents.view'), 403),
            'demandes' => abort_unless($utilisateur->can('demandes.view'), 403),
            'timeline' => abort_unless($utilisateur->can('student_archive.timeline.view'), 403),
            'audit' => abort_unless($this->policy->voirAudit($utilisateur, $etudiant), 403),
            // §38 : sans permission sensible → 404 (ne pas révéler l'onglet).
            'confidentiel' => abort_unless($utilisateur->can('student_archive.sensitive.view'), 404),
            default => null,
        };
    }
}
