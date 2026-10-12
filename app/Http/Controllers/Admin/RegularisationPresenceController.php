<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TeacherReclamation;
use App\Modules\Attendance\Models\TeacherPresence;
use App\Modules\Attendance\Services\PresenceEmploiDuTempsService;
use App\Services\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Régularisations de présence enseignants (file scolarité).
 * Pont entre les signalements `TeacherReclamation` (catégorie `presence`,
 * présence liée) et les décisions tracées sur `TeacherPresence`
 * (correction via le service existant, motif obligatoire).
 *
 * - index/show : `attendance.view` + périmètre faculté → 404 ;
 * - traiter : `attendance.create` (mêmes habilités que valider/corriger),
 *   `lockForUpdate` réclamation (+ présence), idempotent (statut déjà
 *   tranché = refus propre), auteur = User admin (jamais l'enseignant
 *   demandeur — séparation des responsabilités par construction).
 */
class RegularisationPresenceController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->can('attendance.view'), 403);

        $faculteId = $request->user()->faculteRestreinteId();

        $reclamations = TeacherReclamation::with(['teacher.departement', 'presence.slot.matiere'])
            ->where('categorie', 'presence')
            ->whereIn('statut', ['ouverte', 'en_cours'])
            ->when($request->filled('statut'), fn ($q) => $q->where('statut', $request->input('statut')))
            ->when($faculteId !== null, function ($q) use ($faculteId) {
                $q->where('faculte_id', $faculteId)
                    ->orWhere(fn ($w) => $w->whereNull('faculte_id')
                        ->whereHas('teacher.departement', fn ($d) => $d->where('faculte_id', $faculteId)));
            })
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return view('admin.regularisations.index', [
            'reclamations' => $reclamations,
        ]);
    }

    public function show(Request $request, TeacherReclamation $reclamation): View
    {
        abort_unless($request->user()->can('attendance.view'), 403);
        $this->abortHorsPerimetre($request, $reclamation);

        $reclamation->loadMissing([
            'teacher.departement.faculte',
            'presence.slot.matiere', 'presence.slot.room', 'presence.slot.schedule.groupe',
            'traiteePar',
        ]);

        return view('admin.regularisations.show', compact('reclamation'));
    }

    public function traiter(Request $request, TeacherReclamation $reclamation, PresenceEmploiDuTempsService $service, NotificationService $notifications): RedirectResponse
    {
        abort_unless($request->user()->can('attendance.create'), 403);
        $this->abortHorsPerimetre($request, $reclamation);

        // Séparation des responsabilités (§14) : un enseignant ayant un compte admin
        // ne peut jamais trancher sa propre demande de régularisation.
        $reclamation->loadMissing('teacher');
        $userTeacherId = $request->user()->teacher?->id;
        $teacherUserId = $reclamation->teacher?->user_id;
        if (($userTeacherId !== null && (int) $reclamation->teacher_id === (int) $userTeacherId)
            || ($teacherUserId !== null && (int) $teacherUserId === (int) $request->user()->id)) {
            abort(403, 'Séparation des responsabilités : un enseignant ne peut pas approuver ou trancher sa propre demande de régularisation.');
        }

        $valides = $request->validate([
            'decision' => ['required', Rule::in(['resoudre', 'rejeter'])],
            'statut_valide' => ['nullable', Rule::in(TeacherPresence::STATUTS_VALIDES)],
            'motif' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        try {
            $message = DB::transaction(function () use ($request, $reclamation, $service, $notifications, $valides) {
                /** @var TeacherReclamation $verrouillee */
                $verrouillee = TeacherReclamation::whereKey($reclamation->id)->lockForUpdate()->firstOrFail();

                // Idempotence : un dossier déjà tranché ne se retraite pas.
                if (! in_array($verrouillee->statut, ['ouverte', 'en_cours'], true)) {
                    throw new \DomainException("Demande déjà traitée (statut « {$verrouillee->statut} »).");
                }

                $motif = $valides['motif'];
                $auteurId = (int) $request->user()->id;

                if ($valides['decision'] === 'rejeter') {
                    $this->clore($verrouillee, 'rejetee', $motif, $auteurId);

                    $notifications->notifierEnseignant(
                        $verrouillee->teacher, 'regularisation.rejetee', 'Régularisation rejetée',
                        "Votre demande {$verrouillee->reference} a été rejetée : {$motif}",
                        route('enseignant.reclamations.show', $verrouillee)
                    );

                    return 'Demande rejetée, enseignant notifié.';
                }

                // Résolution : avec présence liée, la décision corrige la
                // présence via le service existant (motif + trace repris).
                if ($verrouillee->teacher_presence_id !== null) {
                    if (empty($valides['statut_valide'])) {
                        throw new \DomainException('Un statut validé est requis pour corriger la présence liée.');
                    }

                    /** @var TeacherPresence $presence */
                    $presence = TeacherPresence::whereKey($verrouillee->teacher_presence_id)->lockForUpdate()->firstOrFail();
                    $service->corrigerPresence($presence, ['statut_valide' => $valides['statut_valide']], $motif, $auteurId);
                }

                $this->clore($verrouillee, 'resolue', $motif, $auteurId);

                $notifications->notifierEnseignant(
                    $verrouillee->teacher, 'regularisation.resolue', 'Régularisation traitée',
                    "Votre demande {$verrouillee->reference} a été traitée : {$motif}",
                    $verrouillee->teacher_presence_id !== null
                        ? route('enseignant.presences.show', $verrouillee->teacher_presence_id)
                        : route('enseignant.reclamations.show', $verrouillee)
                );

                return 'Demande résolue, présence corrigée le cas échéant, enseignant notifié.';
            });
        } catch (\DomainException $e) {
            return back()->withErrors(['decision' => $e->getMessage()]);
        }

        return redirect()->route('admin.presences.regularisations.index')->with('success', $message);
    }

    private function clore(TeacherReclamation $reclamation, string $statut, string $motif, int $auteurId): void
    {
        $historique = $reclamation->historique ?? [];
        $historique[] = [
            'date' => now()->toIso8601String(),
            'action' => $statut,
            'auteur' => 'Scolarité',
            'message' => $motif,
        ];

        $reclamation->update([
            'statut' => $statut,
            'reponse' => $motif,
            'traitee_par' => $auteurId,
            'traitee_le' => now(),
            'historique' => $historique,
        ]);
    }

    /**
     * Téléchargement sécurisé de la pièce jointe justificative (disque privé,
     * isolation faculté, permission attendance.view requise).
     */
    public function pieceJointe(Request $request, TeacherReclamation $reclamation): StreamedResponse
    {
        abort_unless($request->user()->can('attendance.view'), 403);
        $this->abortHorsPerimetre($request, $reclamation);

        abort_if(! $reclamation->piece_jointe_chemin || ! Storage::disk('private')->exists($reclamation->piece_jointe_chemin), 404);

        return Storage::disk('private')->download($reclamation->piece_jointe_chemin, $reclamation->piece_jointe_nom);
    }

    /**
     * Périmètre faculté (miroir `verifierPerimetreEnseignant`) : faculté
     * posée sur la demande, sinon faculté du département enseignant.
     */
    private function abortHorsPerimetre(Request $request, TeacherReclamation $reclamation): void
    {
        $restreinte = $request->user()->faculteRestreinteId();

        if ($restreinte === null) {
            return;
        }

        $reclamation->loadMissing('teacher.departement');
        $faculte = $reclamation->faculte_id ?? $reclamation->teacher?->departement?->faculte_id;

        abort_unless($faculte !== null && (int) $faculte === $restreinte, 404);
    }
}
