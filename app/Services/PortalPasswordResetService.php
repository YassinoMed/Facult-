<?php

namespace App\Services;

use App\Mail\PortailMotDePasseMail;
use App\Models\Etudiant;
use App\Models\PortalPasswordResetToken;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

/**
 * Réinitialisation de mot de passe des portails étudiant/enseignant.
 *
 * Garanties :
 * - token aléatoire 64 caractères, stocké uniquement sous forme de hash ;
 * - expiration 60 minutes et usage unique (verrou de ligne + `used_at`) ;
 * - réponse identique que le compte existe ou non (anti-énumération) ;
 * - limitation par e-mail (3 demandes / 10 min) en plus du throttle HTTP ;
 * - après succès, la version de mot de passe est incrémentée : toutes les
 *   sessions existantes sont invalidées par EnsureStudent/EnsureTeacher.
 */
class PortalPasswordResetService
{
    public const EXPIRATION_MINUTES = 60;

    public const MAX_DEMANDES = 3;

    public const FENETRE_MINUTES = 10;

    public function demander(string $guard, string $email): void
    {
        $email = strtolower(trim($email));
        $cleLimite = 'portail-reset:'.$guard.':'.sha1($email);

        // Au-delà du quota, on ne révèle rien : la réponse HTTP reste
        // identique et aucune nouvelle recherche de compte n'est faite.
        if (RateLimiter::tooManyAttempts($cleLimite, self::MAX_DEMANDES)) {
            return;
        }

        RateLimiter::hit($cleLimite, self::FENETRE_MINUTES * 60);

        $compte = $this->compte($guard, $email);

        if ($compte === null) {
            return;
        }

        $token = Str::random(64);
        $expiration = now()->addMinutes(self::EXPIRATION_MINUTES);

        // Un seul token actif par compte : une nouvelle demande invalide
        // immédiatement l'ancien lien.
        PortalPasswordResetToken::updateOrCreate(
            ['guard' => $guard, 'email' => $email],
            [
                'token_hash' => hash('sha256', $token),
                'expires_at' => $expiration,
                'used_at' => null,
            ],
        );

        $prefixe = $guard === 'etudiant' ? 'etudiant' : 'enseignant';
        $url = route($prefixe.'.mot-de-passe.reinitialiser', [
            'token' => $token,
            'email' => $email,
        ]);

        try {
            Mail::to($email)->send(new PortailMotDePasseMail(
                $url,
                $guard === 'etudiant' ? 'Espace Étudiant' : 'Espace Enseignant',
                self::EXPIRATION_MINUTES,
            ));
        } catch (Throwable) {
            // Ne jamais exposer l'existence du compte ni l'échec SMTP au
            // visiteur. Journalisation sans adresse e-mail (donnée PII).
            Log::error('portail_reset_email_echec', ['guard' => $guard]);
        }
    }

    /**
     * Consomme un token et change le mot de passe. Retourne false pour
     * tout token inconnu, expiré, déjà utilisé ou e-mail non concordant.
     */
    public function reinitialiser(string $guard, string $email, string $token, string $motDePasse): bool
    {
        $email = strtolower(trim($email));
        $compte = $this->compte($guard, $email);

        if ($compte === null || $token === '') {
            return false;
        }

        return DB::transaction(function () use ($guard, $email, $token, $motDePasse, $compte): bool {
            /** @var PortalPasswordResetToken|null $enregistrement */
            $enregistrement = PortalPasswordResetToken::where('guard', $guard)
                ->where('email', $email)
                ->lockForUpdate()
                ->first();

            if ($enregistrement === null
                || ! $enregistrement->estUtilisable()
                || ! hash_equals($enregistrement->token_hash, hash('sha256', $token))) {
                return false;
            }

            // Consommation atomique : un rejeu concurrent échoue ici.
            $enregistrement->forceFill(['used_at' => now()])->save();

            if ($compte instanceof Teacher) {
                // Le model event Teacher incrémente mot_de_passe_version.
                $compte->update(['password' => $motDePasse]);
            } else {
                $compte->update(['password' => $motDePasse]);
                $compte->increment('mot_de_passe_version');
            }

            return true;
        });
    }

    private function compte(string $guard, string $email): Etudiant|Teacher|null
    {
        if ($guard === 'etudiant') {
            return Etudiant::where('email', $email)->first();
        }

        if ($guard === 'enseignant') {
            return Teacher::where('email', $email)->where('actif', true)->first();
        }

        return null;
    }
}
