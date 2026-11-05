<?php

namespace App\Modules\Evaluation\Controllers;

use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\GroupeVirtuelExamen;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Services\ConsultationExportGroupeVirtuelService;
use App\Modules\Evaluation\Services\GroupeVirtuelExamenService;
use App\Modules\Evaluation\Services\PreparationExamenService;
use App\Modules\Schedule\Models\Room;
use App\Modules\StudentDossiers\Contracts\PdfGeneratorInterface;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Contrôleur de consultation, d'export multi-formats (XLSX, CSV, PDF, HTML)
 * et d'impression professionnelle des groupes virtuels d'examens finaux.
 */
class GroupeVirtuelExamenController
{
    public function __construct(
        private readonly ConsultationExportGroupeVirtuelService $service,
        private readonly PreparationExamenService $preparation,
        private readonly PdfGeneratorInterface $pdfGenerator,
        private readonly GroupeVirtuelExamenService $groupeVirtuelService,
    ) {}

    /**
     * Génération automatique des groupes virtuels d'examen à partir des épreuves planifiées.
     */
    public function genererAutomatique(Request $request): RedirectResponse
    {
        $this->service->verifierPermissionConsultation($request->user());
        if (! $request->user()->can('epreuves.update')) {
            abort(403, 'Permission requise pour générer automatiquement les groupes virtuels.');
        }

        $sessionId = $request->query('session_id') ?: $request->input('session_id');
        $epreuves = Epreuve::query()
            ->when($sessionId, fn ($q) => $q->where('session_id', (int) $sessionId))
            ->get();

        $crees = 0;
        foreach ($epreuves as $epreuve) {
            $gv = $this->groupeVirtuelService->proposerAutomatiquement($epreuve, (int) $request->user()->id);
            if ($gv !== null) {
                $crees++;
            }
        }

        return redirect()->route('admin.evaluation.examens.groupes-virtuels.index', $request->query())
            ->with('succes', "Génération automatique effectuée : {$crees} groupe(s) virtuel(s) d'examen ont été créés ou actualisés.");
    }

    /**
     * Phase B — Tableau principal de consultation des groupes virtuels.
     */
    public function index(Request $request): View
    {
        $this->service->verifierPermissionConsultation($request->user());

        $sessionKey = 'examens.groupes_virtuels.filtres.'.$request->user()->id;
        $params = $request->query();

        if ($request->boolean('restaurer')) {
            $memorises = session()->get($sessionKey, []);
            $params = array_merge($memorises, $params);
        }

        if ($request->boolean('memoriser')) {
            session()->put($sessionKey, array_filter([
                'tri' => $params['tri'] ?? null,
                'par_page' => $params['par_page'] ?? null,
                'annee_id' => $params['annee_id'] ?? null,
                'session_id' => $params['session_id'] ?? null,
                'faculte_id' => $params['faculte_id'] ?? null,
                'statut' => $params['statut'] ?? null,
                'recherche' => $params['recherche'] ?? null,
            ], fn ($v) => $v !== null));
        }

        $donnees = $this->service->listerGroupesVirtuels($params, $request->user());
        $commun = $this->variablesBandeau(
            $donnees['filtres_appliques']['annee_id'],
            $donnees['filtres_appliques']['faculte_id']
        );

        return view('modules.evaluation.groupes-virtuels.index', $donnees + $commun);
    }

    /**
     * Phase B.4 — Centre des anomalies consolidées des groupes virtuels.
     */
    public function anomalies(Request $request): View
    {
        $this->service->verifierPermissionConsultation($request->user());

        $donnees = $this->service->listerAnomaliesCentralisees($request->query(), $request->user());
        $commun = $this->variablesBandeau(
            $donnees['filtres_appliques']['annee_id'] ?? null,
            $donnees['filtres_appliques']['faculte_id'] ?? null
        );

        return view('modules.evaluation.groupes-virtuels.anomalies', $donnees + $commun);
    }

    /**
     * Contrôle groupé en lecture seule avec respect de l'isolation facultaire BOLA.
     */
    public function controlerSelection(Request $request): JsonResponse
    {
        $this->service->verifierPermissionConsultation($request->user());

        $validated = $request->validate([
            'ids' => 'required|array|min:1|max:50',
            'ids.*' => 'integer',
        ]);

        $ids = $validated['ids'];
        $resultats = [];
        $compteurs = [
            'total' => count($ids),
            'conformes' => 0,
            'en_derive' => 0,
            'bloquants' => 0,
            'inaccessibles' => 0,
        ];

        foreach ($ids as $id) {
            $gv = GroupeVirtuelExamen::find($id);
            if ($gv === null) {
                $compteurs['inaccessibles']++;
                $resultats[] = [
                    'id' => $id,
                    'libelle' => "Groupe #{$id}",
                    'statut' => 'inaccessible',
                    'accessible' => false,
                    'motif' => 'Groupe virtuel introuvable',
                ];

                continue;
            }

            try {
                $this->service->verifierAccesGroupeVirtuel($request->user(), $gv);
            } catch (\Throwable) {
                $compteurs['inaccessibles']++;
                $resultats[] = [
                    'id' => $id,
                    'libelle' => $gv->libelle,
                    'statut' => 'inaccessible',
                    'accessible' => false,
                    'motif' => 'Périmètre non autorisé (isolation faculté)',
                ];

                continue;
            }

            $resume = $this->service->resumerGroupeVirtuel($gv);
            $statut = $resume['statut_derive'] ?? 'conforme';
            if ($resume['en_derive'] ?? false) {
                $compteurs['en_derive']++;
            } elseif (($resume['nb_bloquants'] ?? 0) > 0) {
                $compteurs['bloquants']++;
            } else {
                $compteurs['conformes']++;
            }

            $resultats[] = [
                'id' => $gv->id,
                'libelle' => $gv->libelle,
                'statut' => $statut,
                'accessible' => true,
                'resume' => $resume,
            ];
        }

        return response()->json([
            'succes' => true,
            'compteurs' => $compteurs,
            'resultats' => $resultats,
        ]);
    }

    /**
     * Réactualisation groupée d'une sélection de groupes virtuels.
     */
    public function resynchroniserSelection(Request $request): RedirectResponse|JsonResponse
    {
        $this->service->verifierPermissionConsultation($request->user());
        if (! $request->user()->can('epreuves.update')) {
            abort(403, 'Permission requise pour resynchroniser des groupes virtuels.');
        }

        $validated = $request->validate([
            'ids' => 'required|array|min:1|max:50',
            'ids.*' => 'integer',
        ]);

        $ids = $validated['ids'];
        $succes = 0;
        $echecs = 0;

        foreach ($ids as $id) {
            $gv = GroupeVirtuelExamen::find($id);
            if ($gv === null) {
                $echecs++;

                continue;
            }

            try {
                $this->service->verifierAccesGroupeVirtuel($request->user(), $gv);
                $this->service->resynchroniserGroupeVirtuel($gv, (int) $request->user()->id);
                $succes++;
            } catch (\Throwable) {
                $echecs++;
            }
        }

        $message = "Resynchronisation terminée : {$succes} groupe(s) réactualisé(s)";
        if ($echecs > 0) {
            $message .= ", {$echecs} échec(s) ou accès refusé(s).";
        } else {
            $message .= '.';
        }

        if ($request->wantsJson()) {
            return response()->json([
                'succes' => true,
                'message' => $message,
                'reactualises' => $succes,
                'echecs' => $echecs,
            ]);
        }

        return redirect()->back()->with('succes', $message);
    }

    /**
     * Phase C — Fiche détaillée d'un groupe virtuel et liste de ses étudiants.
     */
    public function show(Request $request, GroupeVirtuelExamen $groupeVirtuel): View
    {
        $fiche = $this->service->construireFicheDetaillee($groupeVirtuel, $request->user());
        $commun = $this->variablesBandeau(
            $fiche['resume']['annee_id'] ?? null,
            $fiche['resume']['faculte_id'] ?? null
        );

        $diagnosticSalle = null;
        if ($groupeVirtuel->epreuve !== null) {
            try {
                $decisionService = app(ExamDecisionCapacitePermutationService::class);
                $diagnosticSalle = $decisionService->diagnostiquerCapaciteSalle($groupeVirtuel->epreuve, $request->user());
            } catch (\Throwable) {
                $diagnosticSalle = null;
            }
        }

        return view('modules.evaluation.groupes-virtuels.show', [
            'fiche' => $fiche,
            'groupe' => $groupeVirtuel,
            'resume' => $fiche['resume'],
            'diagnosticSalle' => $diagnosticSalle,
        ] + $commun);
    }

    /**
     * Réactualisation et re-synchronisation d'un groupe virtuel en dérive de population.
     */
    public function resynchroniser(Request $request, GroupeVirtuelExamen $groupeVirtuel): RedirectResponse
    {
        $this->service->verifierPermissionConsultation($request->user());
        if (! $request->user()->can('epreuves.update')) {
            abort(403, 'Permission requise pour resynchroniser un groupe virtuel.');
        }

        try {
            $nouveauGroupe = $this->service->resynchroniserGroupeVirtuel($groupeVirtuel, (int) $request->user()->id);
        } catch (DomainException $e) {
            return redirect()->back()->with('erreur', $e->getMessage());
        }

        return redirect()->route('admin.evaluation.examens.groupes-virtuels.show', $nouveauGroupe->id)
            ->with('succes', 'Le groupe virtuel a été réactualisé avec succès (ancienne proposition archivée en historique).');
    }

    /**
     * Phase D — Export multi-formats (xlsx, csv, pdf, html) d'un groupe virtuel ou de ses étudiants.
     */
    public function exporterGroupe(Request $request, GroupeVirtuelExamen $groupeVirtuel, string $format): Response|JsonResponse
    {
        $type = (string) $request->query('type', 'etudiants');

        try {
            $resultat = $this->service->exporterGroupeVirtuel($groupeVirtuel, $format, $type, $request->user());
        } catch (DomainException $e) {
            return $this->reponseBlocageMetier($request, $e->getMessage());
        }

        $disposition = strtolower($format) === 'html' ? 'inline' : 'attachment';

        return new Response($resultat['contenu'], 200, [
            'Content-Type' => $resultat['mime'],
            'Content-Disposition' => $disposition.'; filename="'.$resultat['nom_fichier'].'"',
            'X-Export-Effectif' => (string) $resultat['effectif'],
            'Cache-Control' => 'no-cache, private',
        ]);
    }

    /**
     * Phase D — Export multi-formats (xlsx, csv, pdf, html) d'une matière, d'une session ou de la sélection filtrée.
     */
    public function exporterSelection(Request $request, string $format): Response|JsonResponse
    {
        if ($request->filled('groupe_virtuel_id')) {
            $groupe = GroupeVirtuelExamen::findOrFail((int) $request->query('groupe_virtuel_id'));

            return $this->exporterGroupe($request, $groupe, $format);
        }

        try {
            $resultat = $this->service->exporterSelection($request->query(), $format, $request->user());
        } catch (DomainException $e) {
            return $this->reponseBlocageMetier($request, $e->getMessage());
        }

        $disposition = strtolower($format) === 'html' ? 'inline' : 'attachment';

        return new Response($resultat['contenu'], 200, [
            'Content-Type' => $resultat['mime'],
            'Content-Disposition' => $disposition.'; filename="'.$resultat['nom_fichier'].'"',
            'X-Export-Effectif' => (string) $resultat['effectif'],
            'Cache-Control' => 'no-cache, private',
        ]);
    }

    /**
     * Option B — Téléchargement du pack ZIP d'émargements complet pour les centres d'examen.
     */
    public function telechargerPackZip(Request $request): BinaryFileResponse|JsonResponse
    {
        $this->service->verifierPermissionConsultation($request->user());

        try {
            $pack = $this->service->genererPackZipEmargements($request->query(), $request->user());
        } catch (DomainException $e) {
            return $this->reponseBlocageMetier($request, $e->getMessage());
        }

        return response()->download($pack['chemin'], $pack['nom_fichier'], [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Phase E — Impression professionnelle d'un document de groupe virtuel (fiche, etudiants, emargement).
     */
    public function imprimerGroupe(Request $request, GroupeVirtuelExamen $groupeVirtuel, string $document): Response|JsonResponse
    {
        $enPdf = strtolower((string) $request->query('format', 'html')) === 'pdf';

        try {
            $html = $this->service->rendreDocumentGroupe($groupeVirtuel, $document, $request->user(), $enPdf);
        } catch (DomainException $e) {
            return $this->reponseBlocageMetier($request, $e->getMessage());
        }

        if ($enPdf) {
            $orientation = $document === 'emargement' ? 'landscape' : 'portrait';
            $binaire = $this->pdfGenerator->generer($html, ['paper' => 'A4', 'orientation' => $orientation]);
            $nom = 'impression_groupe_virtuel_'.$groupeVirtuel->id.'_'.$document.'_'.now()->format('Ymd').'.pdf';

            return new Response($binaire, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.$nom.'"',
                'Cache-Control' => 'no-cache, private',
            ]);
        }

        return new Response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    /**
     * Phase E — Impression professionnelle d'une synthèse par matière ou globale de la session.
     */
    public function imprimerSelection(Request $request, string $document): Response|JsonResponse
    {
        $enPdf = strtolower((string) $request->query('format', 'html')) === 'pdf';

        try {
            $html = $this->service->rendreDocumentSelection($request->query(), $document, $request->user(), $enPdf);
        } catch (DomainException $e) {
            return $this->reponseBlocageMetier($request, $e->getMessage());
        }

        if ($enPdf) {
            $binaire = $this->pdfGenerator->generer($html, ['paper' => 'A4', 'orientation' => 'landscape']);
            $nom = 'impression_'.$document.'_'.now()->format('Ymd').'.pdf';

            return new Response($binaire, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.$nom.'"',
                'Cache-Control' => 'no-cache, private',
            ]);
        }

        return new Response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    /**
     * Réponse HTTP 422 normalisée lorsqu'un export ou une impression officielle est bloquée.
     */
    private function reponseBlocageMetier(Request $request, string $message): Response|JsonResponse
    {
        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'succes' => false,
                'message' => $message,
            ], 422);
        }

        $raisons = array_filter(array_map('trim', explode('.', $message)));

        $html = view('modules.evaluation.groupes-virtuels.blocage', [
            'message' => $message,
            'raisons' => $raisons,
            'urlRetour' => url()->previous() ?: route('admin.evaluation.examens.groupes-virtuels.index'),
            'urlAnomalies' => route('admin.evaluation.examens.groupes-virtuels.anomalies'),
        ])->render();

        return new Response($html, 422, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    /**
     * Variables communes pour la barre d'onglets Examens Advanced.
     *
     * @return array<string, mixed>
     */
    private function variablesBandeau(?int $anneeId, ?int $faculteId): array
    {
        $statsPreparation = $this->preparation->tableauDeBord($anneeId, $faculteId);

        $sessionsStats = [
            'total' => SessionExamen::query()->when($anneeId !== null, fn ($q) => $q->where('annee_universitaire_id', $anneeId))->count(),
            'draft' => SessionExamen::where('statut', 'draft')->count(),
            'open' => SessionExamen::where('statut', 'open')->count(),
            'ongoing' => SessionExamen::where('statut', 'ongoing')->count(),
            'closed' => SessionExamen::where('statut', 'closed')->count(),
            'locked' => SessionExamen::where('statut', 'locked')->count(),
        ];

        $sallesSansCapacite = Room::where(fn ($q) => $q->whereNull('capacite')->orWhere('capacite', '<=', 0))->count();

        $epreuvesSansSurveillant = Epreuve::query()
            ->when($faculteId !== null, fn ($q) => $q->pourFaculteId($faculteId))
            ->whereDoesntHave('surveillants')
            ->count();

        return [
            'statsPreparation' => $statsPreparation,
            'sessionsStats' => $sessionsStats,
            'sallesSansCapacite' => $sallesSansCapacite,
            'epreuvesSansSurveillant' => $epreuvesSansSurveillant,
        ];
    }
}
