<?php

namespace App\Http\Controllers\Etudiant;

use App\Http\Controllers\Controller;
use App\Models\Etudiant;
use App\Services\DossierComplementService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Contrôleur de l'espace étudiant pour la consultation et soumission
 * des demandes de complément et corrections (LOOP 11).
 *
 * Périmètre strict :
 * - Authentifié par session (middleware etudiant.auth) : l'étudiant
 *   ne consulte et ne modifie QUE son propre dossier (anti-IDOR).
 * - Seuls les champs explicitement demandés sont pris en compte.
 * - Aucune modification n'est admise après décision définitive.
 */
class DossierComplementController extends Controller
{
    /**
     * Affiche l'interface de correction et téléversement des pièces requises.
     */
    public function index(Request $request, DossierComplementService $service): View
    {
        $etudiantId = (int) $request->session()->get('etudiant_auth_id');
        $etudiant = Etudiant::with(['faculte', 'programmeRef'])->findOrFail($etudiantId);

        $elements = $service->recupererElementsACorriger($etudiant);

        return view('etudiant.corrections', [
            'etudiant' => $etudiant,
            'elements' => $elements,
        ]);
    }

    /**
     * Enregistre les rectifications et pièces transmises par l'étudiant.
     */
    public function store(Request $request, DossierComplementService $service): RedirectResponse|JsonResponse
    {
        $request->validate([
            'attestation_honneur' => ['required', 'accepted'],
        ], [
            'attestation_honneur.accepted' => 'Vous devez certifier sur l’honneur l’exactitude des informations et documents transmis.',
            'attestation_honneur.required' => 'Vous devez certifier sur l’honneur l’exactitude des informations et documents transmis.',
        ]);

        $etudiantId = (int) $request->session()->get('etudiant_auth_id');
        $etudiant = Etudiant::findOrFail($etudiantId);

        try {
            $champs = (array) $request->input('champs', []);
            $pieces = (array) $request->file('pieces', []);

            $resultat = $service->soumettreCorrection($etudiant, $champs, $pieces);
        } catch (DomainException $e) {
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 422);
            }

            return redirect()->back()
                ->withErrors(['correction' => $e->getMessage()])
                ->withInput();
        } catch (ValidationException $e) {
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Les informations transmises comportent des erreurs.',
                    'errors' => $e->errors(),
                ], 422);
            }

            throw $e;
        }

        $message = "Vos compléments et corrections ont été enregistrés avec succès. Le dossier a été ré-analysé (score : {$resultat['score']}/100, verdict : {$resultat['verdict']}).";

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => $message,
                'resultat' => $resultat,
            ]);
        }

        return redirect()->route('etudiant.suivi')
            ->with('status', $message);
    }
}
