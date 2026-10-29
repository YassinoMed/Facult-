<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminLoginRequest;
use App\Models\User;
use App\Modules\Schedule\Models\Teacher;
use App\Services\MfaRecoveryCodeService;
use App\Services\MfaTotpService;
use App\Services\VerrouillageService;
use App\Support\MfaPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Authentification du personnel de scolarité (back-office).
 * Les rôles valides sont définis dans App\Support\Permissions.
 */
class AuthController extends Controller
{
    public function show(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('admin.dashboard');
        }

        return view('admin.auth.connexion');
    }

    public function login(AdminLoginRequest $request): RedirectResponse
    {
        $credentials = $request->only('email', 'password');

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            // Message générique : aucune énumération de comptes
            return back()->withErrors([
                'email' => 'Identifiants incorrects. Vérifiez votre email et votre mot de passe.',
            ])->onlyInput('email');
        }

        /** @var User $utilisateur */
        $utilisateur = Auth::user();

        // Rôle requis (base de données, jamais le client) + compte actif :
        // un administrateur désactivé ne peut plus accéder au back-office.
        if ($utilisateur->role === null || ! $utilisateur->estActif()) {
            Auth::logout();

            return back()->withErrors([
                'email' => 'Ce compte ne dispose plus d\'un accès actif au back-office.',
            ])->onlyInput('email');
        }

        // Un admin_faculte DOIT être rattaché à une faculté (§4/§6).
        if ($utilisateur->role === 'admin_faculte' && $utilisateur->faculte_id === null) {
            Auth::logout();

            return back()->withErrors([
                'email' => 'Aucune faculté n\'est rattachée à ce compte administrateur.',
            ])->onlyInput('email');
        }

        if ($utilisateur->role === 'enseignant') {
            $teacher = $utilisateur->teacher ?? Teacher::where('email', $utilisateur->email)->first();
            if ($teacher && $teacher->actif) {
                $request->session()->regenerate();
                $request->session()->put([
                    'enseignant_auth_id' => $teacher->id,
                    'enseignant_auth_prenom' => $teacher->prenom,
                    'enseignant_auth_nom' => $teacher->nom,
                ]);

                return redirect()->intended(route('enseignant.dashboard'))
                    ->with('success', "Bienvenue {$teacher->prenom} ! Voici votre espace enseignant.");
            }
        }

        // Double facteur OBLIGATOIRE (LOOP-SECURITY-REMEDIATION-01) :
        // mot de passe valide mais rôle à privilèges sans TOTP enrôlé →
        // l'enrôlement précède tout accès au back-office. Le mot de passe
        // est re-exigé pour finaliser l'activation (voir MfaController).
        if (! $utilisateur->mfa_active && MfaPolicy::exigePour($utilisateur)) {
            $request->session()->regenerate();
            $request->session()->put('bo_auth_password', true);
            app(VerrouillageService::class)->initialiserSession($request, $utilisateur);
            $request->session()->put('mfa_enrolement_obligatoire', $utilisateur->id);

            return redirect()->route('admin.mfa.configurer')
                ->with('warning', 'Votre rôle exige la double authentification : activez-la maintenant pour accéder au back-office.');
        }

        // Double facteur : mot de passe valide mais TOTP requis → session
        // en attente (aucun accès back-office avant le défi, LOOP J).
        if ($utilisateur->mfa_active) {
            Auth::logout();
            $request->session()->put('mfa_en_attente', $utilisateur->id);

            return redirect()->route('admin.connexion.defi');
        }

        // Régénération de session : protection contre la fixation de session
        $request->session()->regenerate();
        $request->session()->put('bo_auth_password', true);
        app(VerrouillageService::class)->initialiserSession($request, $utilisateur);

        return redirect()->intended(route('admin.dashboard'))
            ->with('success', "Bienvenue {$utilisateur->name} — Espace Scolarité.");
    }

    public function defi(): View|RedirectResponse
    {
        if (! session()->has('mfa_en_attente')) {
            return redirect()->route('admin.connexion.show');
        }

        return view('admin.connexion-defi');
    }

    public function verifierDefi(Request $request, MfaTotpService $totp, MfaRecoveryCodeService $codesSecours): RedirectResponse
    {
        $utilisateur = User::find($request->session()->get('mfa_en_attente'));

        if ($utilisateur === null || ! $utilisateur->mfa_active || $utilisateur->mfa_secret === null) {
            $request->session()->forget('mfa_en_attente');

            return redirect()->route('admin.connexion.show');
        }

        // Accepte le code TOTP à 6 chiffres OU un code de secours
        // (usage unique) — jamais les deux à la fois.
        $request->merge(['code' => preg_replace('/\s+/', '', (string) $request->input('code'))]);
        $code = (string) $request->validate(['code' => ['required', 'string', 'min:6', 'max:24']])['code'];

        $valide = $totp->verifier((string) $utilisateur->mfa_secret, $code);

        if (! $valide && $codesSecours->consommer($utilisateur, $code)) {
            $valide = true;

            Log::channel(config('logging.default'))->notice('admin.mfa_code_secours_utilise', [
                'acteur_id' => $utilisateur->id,
                'adresse_ip' => $request->ip(),
                'codes_restants' => $codesSecours->restants($utilisateur),
            ]);
        }

        if (! $valide) {
            return back()->withErrors(['code' => 'Code invalide ou expiré.']);
        }

        $request->session()->forget('mfa_en_attente');
        Auth::login($utilisateur, false);
        $request->session()->regenerate();
        $request->session()->put('bo_auth_password', true);
        app(VerrouillageService::class)->initialiserSession($request, $utilisateur);

        return redirect()->intended(route('admin.dashboard'))
            ->with('success', "Bienvenue {$utilisateur->name} — Espace Scolarité.");
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.connexion.show')
            ->with('success', 'Vous avez été déconnecté du back-office en toute sécurité.');
    }
}
