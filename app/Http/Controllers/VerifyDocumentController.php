<?php

namespace App\Http\Controllers;

use App\Services\Documents\DocumentVerificationService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Vérification publique (Phase 16 §16.3) : QR + code court, sans
 * authentification, strict minimum (type, statut, date, nom partiel
 * masqué). Rate limiting anti-énumération, codes non séquentiels,
 * réponses distinctes sans fuite, IP hachée journalisée.
 */
class VerifyDocumentController extends Controller
{
    public function verifier(Request $request, string $reference, DocumentVerificationService $verification): View
    {
        $resultat = $verification->verifier($reference, (string) $request->ip());

        // Hors registre : 404 (contrat Phase 7 conservé — jamais de
        // confirmation d'existence) ; la tentative reste journalisée
        // (IP hachée) par le service avant cette réponse.
        abort_if(! $resultat['trouve'], 404);

        return view('documents.verifier', [
            'valide' => $resultat['valide'],
            'reference' => $resultat['code'],
            'type' => $resultat['type'] ?? '—',
            'date' => $resultat['date'],
            'statut' => $resultat['statut'],
            'nomMasque' => $resultat['nom_masque'],
            'trouve' => $resultat['trouve'],
            'etablissement' => config('app.name'),
        ]);
    }
}
