<?php

namespace App\Modules\Evaluation\Controllers;

use App\Models\AnneeUniversitaire;
use App\Modules\Evaluation\Models\Deliberation;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\NoteEpreuve;
use App\Modules\Evaluation\Models\SessionExamen;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

class TableauBordEvaluationController
{
    public function index(): View
    {
        Gate::authorize('evaluations.view');

        // Isolation faculté (audit sécurité 04/10/2026) : les compteurs
        // reflètent le périmètre du compte restreint (sessions = ressource
        // transversale, volontairement globales).
        $faculteId = request()->user()?->faculteRestreinteId();
        $perimetre = fn ($q) => $faculteId === null ? $q : $q->pourFaculteId($faculteId);

        $sessionsActives = SessionExamen::whereIn('statut', ['open', 'ongoing'])->count();
        $examensPlanifies = $perimetre(Examen::whereIn('statut', ['planned', 'scheduled', 'published', 'ongoing']))->count();
        $epreuvesSansSalle = $perimetre(Epreuve::whereNull('salle_id')->where('statut', '!=', 'cancelled'))->count();
        $epreuvesSansSurveillant = $perimetre(Epreuve::where('statut', '!=', 'cancelled')
            ->whereDoesntHave('surveillants', fn ($q) => $q->where('statut', '!=', 'annule')))
            ->count();
        $notesManquantes = NoteEpreuve::whereIn('statut', ['draft', 'entered'])
            ->when($faculteId !== null, fn ($q) => $q->whereHas('epreuve', fn ($e) => $e->pourFaculteId($faculteId)))
            ->count();
        $notesAValider = NoteEpreuve::where('statut', 'submitted')
            ->when($faculteId !== null, fn ($q) => $q->whereHas('epreuve', fn ($e) => $e->pourFaculteId($faculteId)))
            ->count();
        $deliberationsEnAttente = $perimetre(Deliberation::whereIn('statut', ['draft', 'prepared', 'in_review']))->count();

        $anneeCourante = AnneeUniversitaire::where('active', true)->first();

        return view('modules.evaluation.tableau-bord', [
            'sessionsActives' => $sessionsActives,
            'examensPlanifies' => $examensPlanifies,
            'epreuvesSansSalle' => $epreuvesSansSalle,
            'epreuvesSansSurveillant' => $epreuvesSansSurveillant,
            'notesManquantes' => $notesManquantes,
            'notesAValider' => $notesAValider,
            'deliberationsEnAttente' => $deliberationsEnAttente,
            'anneeCourante' => $anneeCourante,
        ]);
    }
}
