<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Modules\Schedule\Models\Teacher;
use App\Support\Iam\MoteurAutorisation;
use App\Support\Permissions;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'faculte_id', 'actif'])]
#[Hidden(['password', 'remember_token', 'mfa_secret', 'mfa_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Rôles attribuables aux comptes techniques d'intégration : jamais
     * `super_admin` (moindre privilège, audit §P2-14).
     */
    public const ROLES_TECHNIQUES = [
        'admin_scolarite',
        'agent_scolarite',
        'consultation',
        'admin_faculte',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'actif' => 'boolean',
            'mfa_active' => 'boolean',
            'mfa_secret' => 'encrypted',
            'mfa_recovery_codes' => 'encrypted:array',
        ];
    }

    /* ── RBAC : rôle → permissions (module Archives Académiques) ── */

    /**
     * Attribution EXCLUSIVE d'un rôle (audit §P3-17) : `role` n'est plus
     * mass-assignable — tout changement passe par cette méthode validée.
     * Refuse les rôles inconnus et ne persiste que sur succès.
     */
    public function definirRole(string $role): void
    {
        if (! Permissions::roleValide($role)) {
            throw new \InvalidArgumentException("Rôle back-office inconnu : {$role}.");
        }

        $this->role = $role;
        $this->save();
    }

    /**
     * Création d'un compte back-office en une étape : les attributs
     * courants sont mass-assignés (whitelist Fillable), le rôle —
     * sensible — est attribué via definirRole() (validé, jamais injecté
     * depuis une entrée HTTP non filtrée).
     *
     * @param  array<string, mixed>  $attributs
     */
    public static function creerCompteBackOffice(array $attributs, string $role): self
    {
        $compte = static::query()->create($attributs);
        $compte->definirRole($role);

        return $compte;
    }

    public function hasPermission(string $permission): bool
    {
        // Vérification immédiate via le moteur d'autorisation si disponible,
        // sinon fallback sur la matrice de rôle existante.
        if (class_exists(MoteurAutorisation::class)) {
            try {
                $moteur = new MoteurAutorisation;

                return $moteur->autorise($this, $permission);
            } catch (Throwable) {
                // Fallback silencieux si le moteur n'est pas encore initialisable.
            }
        }

        // Fallback : matrice de rôle existante.
        return in_array($permission, Permissions::pourRole($this->role ?? ''), true);
    }

    /**
     * Permissions effectives du compte : matrice + profils + affectations
     * + délégations + urgences actives. Utilisée pour l'affichage (ex:
     * récapitulatif avant enregistrement), mais l'autorisation réelle doit
     * passer par `hasPermission()` ou `MoteurAutorisation::autorise()`.
     *
     * @return array<int, string>
     */
    public function permissionsEffectives(): array
    {
        if (class_exists(MoteurAutorisation::class)) {
            try {
                $moteur = new MoteurAutorisation;

                return $moteur->permissionsEffectives($this);
            } catch (Throwable) {
                // Fallback silencieux.
            }
        }

        // Fallback : matrice seule.
        return array_values(Permissions::pourRole((string) ($this->role ?? '')));
    }

    /**
     * Origine d'une permission pour un compte (affichage et audit).
     * Retourne l'une des valeurs suivantes : 'role', 'profil',
     * 'habilitation', 'delegation', 'urgence', ou null si inconnue.
     */
    public function originePermission(string $permission): ?string
    {
        if (class_exists(MoteurAutorisation::class)) {
            try {
                $moteur = new MoteurAutorisation;

                return $moteur->origine($this, $permission);
            } catch (Throwable) {
                // Fallback silencieux.
            }
        }

        // Fallback simple : si la permission vient du rôle, on le signale.
        if (in_array($permission, Permissions::pourRole($this->role ?? ''), true)) {
            return 'role';
        }

        return null;
    }

    /** @deprecated Use `MoteurAutorisation::autorise()` à la place. */
    public function peutAccéder(int $faculteId, string $permission): bool
    {
        $restreinte = $this->faculteRestreinteId();

        return $restreinte === null || $restreinte === $faculteId;
    }

    public function estSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    /**
     * Profil « Administrateur de faculté » : rattaché à UNE faculté
     * (faculte_id obligatoire). Son périmètre de données est strictement
     * limité à celle-ci.
     */
    public function estAdminFaculte(): bool
    {
        return $this->role === 'admin_faculte' && $this->faculte_id !== null;
    }

    public function estActif(): bool
    {
        // Null-safe : les instances créées en mémoire avant relecture BD
        // n'ont pas encore la valeur par défaut appliquée.
        return (bool) ($this->actif ?? true);
    }

    /**
     * Faculté de rattachement (contrôle serveur, jamais un paramètre client).
     */
    public function faculte()
    {
        return $this->belongsTo(Faculte::class);
    }

    /**
     * Profil du personnel administratif (§14, §43) — distinct du compte.
     */
    public function personnelProfil()
    {
        return $this->hasOne(Personnel::class);
    }

    /**
     * Identifiant de la faculté de périmètre, ou NULL = accès global.
     * Utilisé par les policies et requêtes scopées (anti-IDOR/BOLA §6).
     */
    public function faculteRestreinteId(): ?int
    {
        return $this->estAdminFaculte() ? (int) $this->faculte_id : null;
    }

    /**
     * L'utilisateur peut-il accéder aux données de cette faculté ?
     * Un admin_faculte n'accède qu'à sa propre faculté — même en
     * manipulant un ID d'URL ou un paramètre de filtre.
     */
    public function peutAccederFaculte(int $faculteId): bool
    {
        $restreinte = $this->faculteRestreinteId();

        return $restreinte === null || $restreinte === $faculteId;
    }

    public function getRoleLibelleAttribute(): string
    {
        return match ($this->role) {
            'super_admin' => 'Super Administrateur',
            'admin_scolarite' => 'Administrateur Scolarité',
            'agent_scolarite' => 'Agent Scolarité',
            'consultation' => 'Consultation',
            'admin_faculte' => 'Administrateur de Faculté',
            default => (string) ($this->role ?? 'Utilisateur'),
        };
    }

    /* ── Relations ── */

    public function documentsTeleverses(): HasMany
    {
        return $this->hasMany(DocumentAcademique::class, 'uploaded_by');
    }

    public function actionsHistorique(): HasMany
    {
        return $this->hasMany(HistoriqueAcademique::class, 'utilisateur_id');
    }

    public function teacher(): HasOne
    {
        return $this->hasOne(Teacher::class, 'user_id');
    }
}
