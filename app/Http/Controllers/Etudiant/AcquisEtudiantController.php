<?php

namespace App\Http\Controllers\Etudiant;

use App\Http\Controllers\Controller;
use App\Models\Etudiant;
use App\Services\Acquis\CalculAcquisPedagogiquesService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Portail étudiant : consultation transparente du parcours, des acquis,
 * des crédits capitalisés et des éléments à reprendre (LOOP 10).
 * Anti-IDOR : l'étudiant est exclusivement déduit de la session d'authentification.
 */
class AcquisEtudiantController extends Controller
{
    public function __construct(
        private readonly CalculAcquisPedagogiquesService $calculAcquisService,
    ) {}

    public function index(Request $request): View
    {
        $etudiant = $this->etudiantConnecte($request);

        $analyse = $this->calculAcquisService->analyserAcquis($etudiant);

        return view('etudiant.acquis.index', [
            'etudiant' => $etudiant,
            'analyse' => $analyse,
        ]);
    }

    /**
     * Résolution sécurisée de l'étudiant connecté (session ou guard).
     */
    private function etudiantConnecte(Request $request): Etudiant
    {
        $sessionId = $request->session()->get('etudiant_auth_id');
        if ($sessionId) {
            return Etudiant::with(['faculte'])->findOrFail($sessionId);
        }

        $user = Auth::user();
        if ($user instanceof Etudiant) {
            return $user;
        }

        if ($user && isset($user->id)) {
            $etudiant = Etudiant::where('user_id', $user->id)->first();
            if ($etudiant) {
                return $etudiant;
            }
        }

        abort(403, 'Accès non autorisé ou session étudiante introuvable.');
    }
}
