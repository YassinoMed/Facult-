<?php

namespace App\Http\Controllers;

use App\Services\SuiviDossierService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Suivi public de dossier sécurisé (LOOP 10) :
 * - Accessible aux candidats détenteurs de leur numéro officiel d'inscription ;
 * - Second facteur obligatoire (CIN ou date de naissance) pour empêcher la fuite de données ;
 * - Réponse strictement neutre en cas de référence inexistante ou invalide (anti-énumération) ;
 * - Throttling dédié contre les attaques par force brute.
 */
class SuiviPublicController extends Controller
{
    public function index(): View
    {
        return view('inscription.suivi', [
            'suivi' => null,
            'rechercheEffectuee' => false,
        ]);
    }

    public function rechercher(Request $request, SuiviDossierService $service): View
    {
        $request->validate([
            'reference' => 'required|string|max:50',
            'secondaire' => 'required|string|max:50',
        ], [
            'reference.required' => 'Le numéro d’inscription est obligatoire.',
            'secondaire.required' => 'Le numéro de CIN ou la date de naissance est obligatoire.',
        ]);

        $reference = $request->string('reference')->toString();
        $secondaire = $request->string('secondaire')->toString();

        $suivi = $service->rechercherPublic($reference, $secondaire);

        if ($suivi === null) {
            return view('inscription.suivi', [
                'suivi' => null,
                'rechercheEffectuee' => true,
                'erreurNeutre' => 'Aucun dossier correspondant n’a été trouvé avec ces références. Vérifiez vos identifiants ou connectez-vous à votre Espace Étudiant.',
            ]);
        }

        return view('inscription.suivi', [
            'suivi' => $suivi,
            'rechercheEffectuee' => true,
            'erreurNeutre' => null,
        ]);
    }
}
