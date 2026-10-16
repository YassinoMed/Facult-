<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\VerrouillageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Mot de passe oublié back-office (Phase 27) : branché sur le broker
 * `users` existant (table password_reset_tokens, expiration 60 min).
 * Throttle anti-énumération, message identique que le compte existe
 * ou non, journalisation sans secret.
 */
class MotDePasseController extends Controller
{
    public function oublie(): View
    {
        return view('admin.auth.mot-de-passe-oublie');
    }

    public function envoyerLien(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email', 'max:255']]);

        $statut = Password::broker('users')->sendResetLink(
            ['email' => strtolower(trim((string) $request->input('email')))]
        );

        return $statut === Password::RESET_LINK_SENT
            ? back()->with('success', 'Si ce compte existe, un lien de réinitialisation vient d’être envoyé.')
            : back()->with('success', 'Si ce compte existe, un lien de réinitialisation vient d’être envoyé.');
    }

    public function reinitialiser(string $token): View
    {
        return view('admin.auth.mot-de-passe-reinitialiser', [
            'token' => $token,
            'email' => request()->query('email', ''),
        ]);
    }

    public function enregistrer(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:64', 'confirmed', \Illuminate\Validation\Rules\Password::defaults()],
        ]);

        $statut = Password::broker('users')->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($utilisateur, $motDePasse) {
                $utilisateur->forceFill([
                    'password' => Hash::make($motDePasse),
                    'remember_token' => Str::random(60),
                ])->save();

                // AUTH-01 (audit auth 12/10 — P2, correctif 13/10) : la
                // réinitialisation révoque TOUTES les sessions existantes
                // du compte (driver `database`) — un cookie volé ne survit
                // pas à la réaction défensive de la victime.
                DB::table('sessions')->where('user_id', $utilisateur->id)->delete();

                // LOOP N : changement de mot de passe = purge du PIN de
                // verrouillage secondaire (le contexte ne survit pas à
                // l'événement de sécurité ; le code sera redéfini).
                app(VerrouillageService::class)->purger($utilisateur, 'reinitialisation_mot_de_passe');
            }
        );

        return $statut === Password::PASSWORD_RESET
            ? redirect()->route('admin.connexion.show')->with('success', 'Mot de passe réinitialisé, connectez-vous.')
            : back()->withErrors(['email' => 'Lien invalide ou expiré (60 minutes).']);
    }
}
