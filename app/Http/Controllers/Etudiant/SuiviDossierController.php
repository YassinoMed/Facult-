<?php

namespace App\Http\Controllers\Etudiant;

use App\Http\Controllers\Controller;
use App\Models\Etudiant;
use App\Services\SuiviDossierService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Consultation du suivi de dossier par l'étudiant authentifié (LOOP 10) :
 * - Périmètre de session strict (anti-IDOR) : l'étudiant connecté
 *   consulte UNIQUEMENT son propre dossier.
 * - État réel, chronologie et prochaines actions.
 */
class SuiviDossierController extends Controller
{
    public function index(Request $request, SuiviDossierService $service): View
    {
        $etudiantId = (int) $request->session()->get('etudiant_auth_id');

        $etudiant = Etudiant::with(['faculte', 'programmeRef'])
            ->findOrFail($etudiantId);

        $donnees = $service->synthese($etudiant);

        return view('etudiant.suivi', [
            'etudiant' => $etudiant,
            'suivi' => $donnees,
        ]);
    }
}
