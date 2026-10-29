<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\PortalPasswordResetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Mot de passe oublié des portails étudiant/enseignant.
 *
 * Le même contrôleur sert les deux espaces ; le `guard` vient d'un
 * default de route (jamais d'un paramètre client). Toutes les réponses
 * de demande sont génériques afin de ne pas révéler l'existence d'un
 * compte.
 */
class PortailMotDePasseController extends Controller
{
    public function oublie(Request $request): View
    {
        return view('auth.portail-mot-de-passe-oublie', $this->contexte($request));
    }

    public function envoyerLien(Request $request, PortalPasswordResetService $service): RedirectResponse
    {
        $valide = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $guard = $this->guard($request);
        $service->demander($guard, (string) $valide['email']);

        return redirect()
            ->route($this->prefixe($guard).'.mot-de-passe.oublie')
            ->with('success', 'Si ce compte existe, un lien de réinitialisation vient d’être envoyé. Il est valable 60 minutes.');
    }

    public function reinitialiser(Request $request, string $token): View
    {
        return view('auth.portail-mot-de-passe-reinitialiser', [
            ...$this->contexte($request),
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function enregistrer(Request $request, PortalPasswordResetService $service): RedirectResponse
    {
        $valide = $request->validate([
            'token' => ['required', 'string', 'max:128'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:64', 'confirmed', Password::defaults()],
        ]);

        $guard = $this->guard($request);

        $reussi = $service->reinitialiser(
            $guard,
            (string) $valide['email'],
            (string) $valide['token'],
            (string) $valide['password'],
        );

        if (! $reussi) {
            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => 'Lien invalide ou expiré (60 minutes). Demandez un nouveau lien.']);
        }

        return redirect()
            ->route($this->prefixe($guard).'.connexion.show')
            ->with('success', 'Mot de passe réinitialisé. Connectez-vous avec votre nouveau mot de passe.');
    }

    private function guard(Request $request): string
    {
        $guard = (string) ($request->route('guard') ?? 'etudiant');

        return in_array($guard, ['etudiant', 'enseignant'], true) ? $guard : 'etudiant';
    }

    private function prefixe(string $guard): string
    {
        return $guard === 'enseignant' ? 'enseignant' : 'etudiant';
    }

    /**
     * @return array{guard: string, prefixe: string, espace: string, routeConnexion: string}
     */
    private function contexte(Request $request): array
    {
        $guard = $this->guard($request);
        $prefixe = $this->prefixe($guard);

        return [
            'guard' => $guard,
            'prefixe' => $prefixe,
            'espace' => $guard === 'enseignant' ? 'Espace Enseignant' : 'Espace Étudiant',
            'routeConnexion' => $prefixe.'.connexion.show',
        ];
    }
}
