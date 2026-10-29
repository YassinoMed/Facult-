<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Token de réinitialisation des portails étudiant/enseignant.
 * Le token en clair n'est jamais persisté : seule son empreinte SHA-256
 * est stockée, avec une expiration et un marqueur d'utilisation unique.
 */
class PortalPasswordResetToken extends Model
{
    protected $table = 'portal_password_reset_tokens';

    protected $fillable = [
        'guard', 'email', 'token_hash', 'expires_at', 'used_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function estUtilisable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }

    /**
     * Neutralise tous les jetons encore utilisables d'un compte.
     *
     * Appelée dès qu'un mot de passe change (profil, administration,
     * service de récupération) : un lien émis avant la rotation ne doit
     * plus permettre de reprendre le compte. `used_at` est renseigné
     * plutôt que le jeton supprimé, afin de conserver la trace de son
     * usage unique.
     */
    public static function invaliderActifs(string $guard, string $email): int
    {
        return static::query()
            ->where('guard', $guard)
            ->where('email', strtolower(trim($email)))
            ->whereNull('used_at')
            ->update(['used_at' => now()]);
    }
}
