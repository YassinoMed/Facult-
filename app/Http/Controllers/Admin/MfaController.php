<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\MfaTotpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Double facteur TOTP du compte courant (LOOP J) : activation en deux
 * temps (QR + confirmation), désactivation avec mot de passe. Secret
 * en attente en session uniquement, jamais persisté avant confirmation.
 */
class MfaController extends Controller
{
    public function configurer(Request $request, MfaTotpService $totp): View
    {
        $secret = $request->session()->get('mfa_secret_attente');

        if (! is_string($secret) || $secret === '') {
            $secret = $totp->genererSecret();
            $request->session()->put('mfa_secret_attente', $secret);
        }

        /** @var User $utilisateur */
        $utilisateur = $request->user();

        // Note : `QrCodeSvg` est un visuel décoratif (hash), pas un QR
        // encodé — l'enrôlement se fait par saisie manuelle du secret,
        // supportée par toutes les applications d'authentification.
        return view('admin.mfa.configurer', [
            'secret' => $secret,
            'uri' => $totp->uriProvisionnement($secret, (string) $utilisateur->email),
            'actif' => (bool) $utilisateur->mfa_active,
        ]);
    }

    public function confirmer(Request $request, MfaTotpService $totp): RedirectResponse
    {
        $secret = $request->session()->get('mfa_secret_attente');

        if (! is_string($secret) || $secret === '') {
            return redirect()->route('admin.mfa.configurer');
        }

        $code = (string) $request->validate(['code' => ['required', 'string', 'size:6']])['code'];

        if (! $totp->verifier($secret, $code)) {
            return back()->withErrors(['code' => 'Code invalide ou expiré.']);
        }

        /** @var User $utilisateur */
        $utilisateur = $request->user();
        $utilisateur->forceFill(['mfa_secret' => $secret, 'mfa_active' => true])->save();
        $request->session()->forget('mfa_secret_attente');

        Log::channel(config('logging.default'))->notice('admin.mfa_activee', [
            'acteur_id' => $utilisateur->id,
            'adresse_ip' => $request->ip(),
        ]);

        return redirect()->route('admin.mfa.configurer')->with('success', 'Double facteur activé.');
    }

    public function desactiver(Request $request): RedirectResponse
    {
        /** @var User $utilisateur */
        $utilisateur = $request->user();

        $request->validate(['password' => ['required', 'string']]);

        if (! password_verify((string) $request->input('password'), (string) $utilisateur->password)) {
            return back()->withErrors(['password' => 'Mot de passe incorrect.']);
        }

        $utilisateur->forceFill(['mfa_secret' => null, 'mfa_active' => false])->save();

        Log::channel(config('logging.default'))->notice('admin.mfa_desactivee', [
            'acteur_id' => $utilisateur->id,
            'adresse_ip' => $request->ip(),
        ]);

        return redirect()->route('admin.mfa.configurer')->with('success', 'Double facteur désactivé.');
    }
}
