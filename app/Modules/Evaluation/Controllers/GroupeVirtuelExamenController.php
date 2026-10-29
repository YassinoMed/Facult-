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

        $donnees = $this->service->listerGroupesVirtuels($request->query(), $request->user());
        $commun = $this->variablesBandeau(
            $donnees['filtres_appliques']['annee_id'],
            $donnees['filtres_appliques']['faculte_id']
        );

        return view('modules.evaluation.groupes-virtuels.index', $donnees + $commun);
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

        return view('modules.evaluation.groupes-virtuels.show', [
            'fiche' => $fiche,
            'groupe' => $groupeVirtuel,
            'resume' => $fiche['resume'],
        ] + $commun);
    }

    /**
     * Réactualisation et re-synchronisation d'un groupe virtuel en dérive de population.
     */
    public function resynchroniser(Request $request, GroupeVirtuelExamen $groupeVirtuel): RedirectResponse
    {
        $this->service->verifierPermissionConsultation($request->user());

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

        return new Response(
            '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><title>Opération bloquée</title></head><body>'
            .'<div style="max-width:680px;margin:40px auto;padding:24px;border:2px solid #e11d48;border-radius:12px;background:#fff1f2;font-family:sans-serif;color:#881337;">'
            .'<h1 style="margin-top:0;font-size:18px;">Opération officielle bloquée par les contrôles de cohérence</h1>'
            .'<p style="font-size:14px;line-height:1.5;">'.e($message).'</p>'
            .'</div></body></html>',
            422,
            ['Content-Type' => 'text/html; charset=UTF-8']
        );
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
