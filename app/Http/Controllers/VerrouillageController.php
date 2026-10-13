<?php

namespace App\Http\Controllers;

use App\Services\MfaTotpService;
use App\Services\VerrouillageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Verrouillage secondaire (LOOP N) : code PIN personnel, vérification
 * throttlée + temporisée, déconnexion depuis l'écran verrouillé.
 * Le PIN n'est jamais réaffiché ni journalisé en clair.
 */
class VerrouillageController extends Controller
{
    public function code(): View|RedirectResponse
    {
        $utilisateur = Auth::user();

        if ($utilisateur === null) {
            return redirect()->route('admin.connexion.show');
        }

        return view('verrouillage.code');
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

    public function verifier(Request $request, VerrouillageService $verrouillage): RedirectResponse
    {
        $utilisateur = Auth::user() ?? $request->user();

        if ($utilisateur === null) {
            return redirect()->route('admin.connexion.show');
        }

        $longueur = $verrouillage->longueurPin();
        $code = (string) $request->validate(['code' => ['required', 'digits:'.$longueur]])['code'];

        $resultat = $verrouillage->verifier($utilisateur, $code);

        if (! $resultat['ok']) {
            if ($resultat['attente_secondes'] > 0) {
                return back()->withErrors(['code' => 'Trop de tentatives. Réessayez dans '.$resultat['attente_secondes'].' secondes.']);
            }

            return back()->withErrors(['code' => 'Code incorrect.']);
        }

        $request->session()->forget('secondaire_verrouille');
        $request->session()->put('derniere_activite', now()->timestamp);

        // Reprise de la page verrouillée (l'URL navigateur n'a jamais
        // changé : l'écran remplaçait la réponse côté serveur).
        return back(fallback: route('admin.dashboard'));
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
