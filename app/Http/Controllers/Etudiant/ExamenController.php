<?php

namespace App\Http\Controllers\Etudiant;

use App\Http\Controllers\Controller;
use App\Models\Etudiant;
use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Evaluation\Services\ConvocationService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Consultation étudiante des examens et convocations (§12, §18) :
 * - Résolu STRICTEMENT depuis la session auth étudiant (anti-IDOR/BOLA)
 * - Convocations restreintes aux épreuves publiées de l'étudiant
 */
class ExamenController extends Controller
{
    public function index(Request $request): View
    {
        $etudiant = $this->etudiantConnecte($request);

        $candidatures = Candidat::where('etudiant_id', $etudiant->id)
            ->whereHas('epreuve', fn ($q) => $q->whereIn('statut', ['published', 'ongoing', 'completed', 'locked']))
            ->with(['epreuve.matiere', 'epreuve.salle', 'epreuve.session', 'salle'])
            ->get()
            ->sortBy(fn (Candidat $c) => $c->epreuve->date->format('Y-m-d').' '.$c->epreuve->heure_debut);

        return view('etudiant.examens.index', [
            'etudiant' => $etudiant,
            'candidatures' => $candidatures,
        ]);
    }

    public function convocation(Request $request, Candidat $candidat, ConvocationService $convocations): Response
    {
        $etudiant = $this->etudiantConnecte($request);

        // Anti-IDOR / BOLA (§12, §18) : un étudiant consulte UNIQUEMENT sa propre convocation
        abort_unless((int) $candidat->etudiant_id === (int) $etudiant->id, 404);

        // L'épreuve doit être publiée ou ultérieure
        abort_if(in_array($candidat->epreuve?->statut, ['draft', 'cancelled'], true), 404);

        return $convocations->convocation($candidat);
    }

    private function etudiantConnecte(Request $request): Etudiant
    {
        return Etudiant::with('faculte')
            ->findOrFail($request->session()->get('etudiant_auth_id'));
    }
}
