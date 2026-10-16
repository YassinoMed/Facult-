<?php

namespace App\Http\Controllers;

use App\Models\VerrouillagePin;
use App\Services\MfaTotpService;
use App\Services\VerrouillageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verrouillage secondaire (LOOP N) : code PIN personnel, vérification
 * throttlée + temporisée, déconnexion depuis l'écran verrouillé.
 * Le PIN n'est jamais réaffiché ni journalisé en clair.
 */
class VerrouillageController extends Controller
{
    public function code(VerrouillageService $verrouillage): View|RedirectResponse
    {
        $utilisateur = Auth::user();

        if ($utilisateur === null) {
            return redirect()->route('admin.connexion.show');
        }

        return view('verrouillage.code', [
            'pinDefini' => VerrouillagePin::where('user_id', $utilisateur->id)->exists(),
            'delaiMinutes' => (int) round($verrouillage->delaiSecondes() / 60),
            'longueur' => $verrouillage->longueurPin(),
            'mfaActif' => (bool) $utilisateur->mfa_active,
        ]);
    }

    public function definir(Request $request, VerrouillageService $verrouillage): RedirectResponse
    {
        $utilisateur = $request->user();

        if ($utilisateur === null) {
            abort(403);
        }

        $longueur = $verrouillage->longueurPin();
        $donnees = $request->validate([
            'password' => ['required', 'string'],
            'code' => ['required', 'digits:'.$longueur, 'confirmed'],
        ]);

        if (! Hash::check($donnees['password'], (string) $utilisateur->password)) {
            return back()->withErrors(['password' => 'Mot de passe incorrect.']);
        }

        if ($utilisateur->mfa_active) {
            $totp = app(MfaTotpService::class);
            $request->validate(['totp' => ['required', 'string', 'size:6']]);

            if ($utilisateur->mfa_secret === null || ! $totp->verifier($utilisateur->mfa_secret, (string) $request->input('totp'))) {
                return back()->withErrors(['totp' => 'Code TOTP invalide ou expiré.']);
            }
        }

        $verrouillage->definir($utilisateur, $donnees['code']);
        $request->session()->put('derniere_activite', now()->timestamp);
        $request->session()->put('secondaire_eligible', true);

        return redirect()->route('admin.verrouillage.code')->with('success', 'Code de verrouillage enregistré.');
    }

    public function verifier(Request $request, VerrouillageService $verrouillage): RedirectResponse|JsonResponse
    {
        $utilisateur = Auth::user() ?? $request->user();

        if ($utilisateur === null) {
            return redirect()->route('admin.connexion.show');
        }

        $longueur = $verrouillage->longueurPin();
        $code = (string) $request->validate(['code' => ['required', 'digits:'.$longueur]])['code'];

        $resultat = $verrouillage->verifier($utilisateur, $code);

        if (! $resultat['ok']) {
            // Message générique : aucune indication sur le code attendu.
            $message = $resultat['attente_secondes'] > 0
                ? 'Trop de tentatives. Réessayez plus tard.'
                : 'Code incorrect.';

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 422);
            }

            return back()->withErrors(['code' => $message]);
        }

        $request->session()->forget('secondaire_verrouille');
        $request->session()->put('derniere_activite', now()->timestamp);
        $request->session()->put('secondaire_eligible', true);

        if ($request->expectsJson()) {
            // Contrat fetch du voile client : 200 = voile levé sans
            // rechargement (page et données non enregistrées conservées).
            return response()->json(['ok' => true]);
        }

        // Reprise de la page verrouillée (l'URL navigateur n'a jamais
        // changé : l'écran remplaçait la réponse côté serveur).
        return back(fallback: route('admin.dashboard'));
    }

    /**
     * Prolongation explicite (« Rester connecté » ou saisie réelle,
     * jamais automatique) : 204 si le minuteur est touché, 423 si le
     * verrou est déjà acquis — aucun réveil rétroactif possible.
     */
    public function prolonger(Request $request, VerrouillageService $verrouillage): Response
    {
        abort_if($request->user() === null, 401);

        abort_unless($verrouillage->prolonger($request), 423);

        return response()->noContent();
    }

    public function verrouiller(Request $request): RedirectResponse
    {
        $request->session()->put('secondaire_verrouille', true);

        Log::channel(config('logging.default'))->notice('verrouillage.manuel', [
            'acteur_id' => $request->user()?->id,
        ]);

        return back();
    }

    public function deconnecter(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.connexion.show');
    }
}
