<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\MfaRecoveryCodeService;
use App\Services\MfaTotpService;
use App\Support\MfaPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Double facteur TOTP du compte courant (LOOP J) : activation en deux
 * temps (QR + confirmation + mot de passe), désactivation avec mot de
 * passe, codes de secours à usage unique. Secret en attente en session
 * uniquement, jamais persisté avant confirmation.
 *
 * LOOP-SECURITY-REMEDIATION-01 : l'enrôlement exige le mot de passe
 * actuel (aucun enrôlement sur session volée) et la désactivation est
 * refusée pour les rôles qui exigent la MFA (`MfaPolicy`).
 */
class MfaController extends Controller
{
    public function configurer(Request $request, MfaTotpService $totp, MfaRecoveryCodeService $codes): View
    {
        $secret = $request->session()->get('mfa_secret_attente');

        if (! is_string($secret) || $secret === '') {
            $secret = $totp->genererSecret();
            $request->session()->put('mfa_secret_attente', $secret);
        }

        /** @var User $utilisateur */
        $utilisateur = $request->user();

        // Enrôlement : QR réel généré côté navigateur depuis l'URI
        // `otpauth://` (libellé `qrcode-generator` locale, sans réseau ni
        // fuite du secret) + saisie manuelle en secours.
        return view('admin.mfa.configurer', [
            'secret' => $secret,
            'uri' => $totp->uriProvisionnement($secret, (string) $utilisateur->email),
            'actif' => (bool) $utilisateur->mfa_active,
            'codesRestants' => $codes->restants($utilisateur),
            'mfaObligatoire' => MfaPolicy::exigePour($utilisateur),
        ]);
    }

    public function confirmer(Request $request, MfaTotpService $totp, MfaRecoveryCodeService $codes): RedirectResponse
    {
        /** @var User $utilisateur */
        $utilisateur = $request->user();

        $secret = $request->session()->get('mfa_secret_attente');

        if (! is_string($secret) || $secret === '') {
            return redirect()->route('admin.mfa.configurer');
        }

        // Enrôlement sécurisé : le mot de passe actuel est exigé pour
        // finaliser l'activation — une session volée ne peut pas enrôler
        // l'application d'un attaquant à la place de la victime.
        $request->merge(['code' => preg_replace('/\s+/', '', (string) $request->input('code'))]);
        $request->validate([
            'password' => ['required', 'string'],
            'code' => ['required', 'string', 'size:6'],
        ]);

        if (! password_verify((string) $request->input('password'), (string) $utilisateur->password)) {
            return back()->withErrors(['password' => 'Mot de passe incorrect.']);
        }

        $code = (string) $request->input('code');

        if (! $totp->verifier($secret, $code)) {
            return back()->withErrors(['code' => 'Code invalide ou expiré.']);
        }

        $utilisateur->forceFill(['mfa_secret' => $secret, 'mfa_active' => true])->save();
        $request->session()->forget('mfa_secret_attente');

        // Codes de secours : affichés UNE fois, jamais relisibles.
        $codesSecours = $codes->generer($utilisateur);

        Log::channel(config('logging.default'))->notice('admin.mfa_activee', [
            'acteur_id' => $utilisateur->id,
            'adresse_ip' => $request->ip(),
        ]);

        return redirect()->route('admin.mfa.configurer')
            ->with('success', 'Double facteur activé. Conservez vos codes de secours dès maintenant : ils ne seront plus affichés.')
            ->with('codes_secours', $codesSecours);
    }

    /**
     * Régénération des codes de secours (mot de passe exigé) : les
     * anciens codes cessent immédiatement d'être valides.
     */
    public function nouveauxCodesSecours(Request $request, MfaRecoveryCodeService $codes): RedirectResponse
    {
        /** @var User $utilisateur */
        $utilisateur = $request->user();

        if (! $utilisateur->mfa_active) {
            return back()->withErrors(['password' => 'Activez d’abord la double authentification.']);
        }

        $request->validate(['password' => ['required', 'string']]);

        if (! password_verify((string) $request->input('password'), (string) $utilisateur->password)) {
            return back()->withErrors(['password' => 'Mot de passe incorrect.']);
        }

        $codesSecours = $codes->generer($utilisateur);

        Log::channel(config('logging.default'))->notice('admin.mfa_codes_secours_regeneres', [
            'acteur_id' => $utilisateur->id,
            'adresse_ip' => $request->ip(),
        ]);

        return redirect()->route('admin.mfa.configurer')
            ->with('success', 'Nouveaux codes de secours générés — les précédents ne sont plus valides.')
            ->with('codes_secours', $codesSecours);
    }

    /**
     * Nouveau secret (repartir proprement après une erreur de recopie :
     * l'ancienne entrée de l'application doit être supprimée côté téléphone).
     */
    public function nouveauSecret(Request $request, MfaTotpService $totp): RedirectResponse
    {
        $request->session()->put('mfa_secret_attente', $totp->genererSecret());

        return redirect()->route('admin.mfa.configurer')->with('success', 'Nouveau secret généré. Supprimez l’ancienne entrée puis recommencez.');
    }

    public function desactiver(Request $request): RedirectResponse
    {
        /** @var User $utilisateur */
        $utilisateur = $request->user();

        // Rôles à privilèges : la MFA est obligatoire, la désactivation
        // est refusée (le compte serait immédiatement ré-enrôlé).
        if (MfaPolicy::exigePour($utilisateur)) {
            return back()->withErrors([
                'password' => 'La double authentification est obligatoire pour votre rôle : elle ne peut pas être désactivée. Contactez un super-administrateur en cas de perte de votre appareil.',
            ]);
        }

        $request->validate(['password' => ['required', 'string']]);

        if (! password_verify((string) $request->input('password'), (string) $utilisateur->password)) {
            return back()->withErrors(['password' => 'Mot de passe incorrect.']);
        }

        $utilisateur->forceFill([
            'mfa_secret' => null,
            'mfa_active' => false,
            'mfa_recovery_codes' => null,
        ])->save();

        Log::channel(config('logging.default'))->notice('admin.mfa_desactivee', [
            'acteur_id' => $utilisateur->id,
            'adresse_ip' => $request->ip(),
        ]);

        return redirect()->route('admin.mfa.configurer')->with('success', 'Double facteur désactivé.');
    }
}
