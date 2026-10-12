<?php

namespace App\Http\Controllers\Enseignant;

use App\Http\Controllers\Controller;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Mes documents : bibliothèque des documents disponibles pour
 * l'enseignant connecté — générés à la demande depuis SES données
 * (aucun stockage dupliqué) :
 * - listes d'émargement de ses épreuves (PDF) ;
 * - relevé de ses surveillances (CSV) ;
 * - relevé de ses présences (CSV).
 */
class DocumentsController extends Controller
{
    public function index(Request $request): View
    {
        $enseignant = Teacher::findOrFail($request->session()->get('enseignant_auth_id'));

        $epreuves = Epreuve::with(['matiere', 'session', 'salle'])
            ->whereHas('surveillants', fn ($q) => $q->where('teacher_id', $enseignant->id)->where('statut', '!=', 'annule'))
            ->where('statut', '!=', 'cancelled')
            ->orderByDesc('date')
            ->paginate(15)
            ->withQueryString();

        return view('enseignant.documents', [
            'enseignant' => $enseignant,
            'epreuves' => $epreuves,
        ]);
    }
}
