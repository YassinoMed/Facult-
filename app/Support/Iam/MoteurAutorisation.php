<?php

namespace App\Support\Iam;

use App\Models\Iam\AffectationIam;
use App\Models\Iam\DelegationIam;
use App\Models\Iam\HabilitationIam;
use App\Models\Iam\UrgenceIam;
use App\Models\Iam\VersionAutorisationIam;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Moteur d'autorisation UNIQUE de la gouvernance IAM (LOOP 43-BIS).
 *
 * Il ne remplace pas les Gates existants : il les COMPLÈTE. La matrice
 * canonique `App\Support\Permissions` reste la première source ; le
 * moteur n'ajoute que des habilitations ADDITIVES, bornées, révocables
 * et vérifiées à chaque évaluation :
 *
 *  - un profil n'accorde que la version approuvée qui lui a été affectée ;
 *  - une habilitation directe expire et se révoque ;
 *  - une délégation exige que le délégant détienne encore DIRECTEMENT
 *    la permission (aucune chaîne de délégations) ;
 *  - une urgence est bornée en heures et ne couvre jamais l'IAM ;
 *  - une permission inconnue du code est TOUJOURS refusée.
 *
 * Portée facultaire : une habilitation avec `faculte_id` ne vaut que pour
 * cette faculté. Sans contexte explicite, elle n'est reconnue que si le
 * compte est lui-même restreint à cette faculté (admin_faculte), ce qui
 * évite toute fuite inter-facultés par les Gates historiques.
 */
final class MoteurAutorisation
{
    /**
     * Cache par requête (le processus PHP ne survit pas à la requête en
     * production). Toute mutation de gouvernance appelle `invalider()`.
     *
     * @var array<string, array{permissions: array<string, array<int, int|null>>, portee: int|null, matrice: array<int, string>}>
     */
    private static array $cache = [];

    /**
     * Contexte effectif d'un compte : permissions additionnelles et leur
     * portée. La matrice de rôle n'est PAS dupliquée ici : elle est lue
     * directement dans le code.
     *
     * @return array{permissions: array<string, array<int, int|null>>, portee: int|null, matrice: array<int, string>}
     */
    public function contexte(User $utilisateur): array
    {
        $matrice = Permissions::pourRole((string) ($utilisateur->role ?? ''));
        $cle = $this->cle($utilisateur, $matrice);

        if (isset(self::$cache[$cle])) {
            return self::$cache[$cle];
        }

        $permissions = [];
        $portees = [];

        foreach ($this->affectationsActives($utilisateur) as $affectation) {
            $profil = $affectation->profil;

            if ($profil === null || ! $profil->estActif()) {
                continue;
            }

            // Version figée : une modification du profil n'élargit jamais
            // une affectation déjà accordée.
            if ((int) $profil->version !== (int) $affectation->version_profil) {
                continue;
            }

            $portee = $affectation->faculte_id !== null
                ? (int) $affectation->faculte_id
                : ($profil->faculte_id !== null ? (int) $profil->faculte_id : null);

            foreach ($profil->permissions as $ligne) {
                $code = (string) $ligne->permission_code;

                if (! CataloguePermissions::existe($code) || ! CataloguePermissions::administrable($code)) {
                    continue;
                }

                $permissions[$code][] = $portee;
                if ($portee !== null) {
                    $portees[$portee] = true;
                }
            }
        }

        foreach ($this->habilitationsActives($utilisateur) as $habilitation) {
            $code = (string) $habilitation->permission_code;

            if (! CataloguePermissions::existe($code) || ! CataloguePermissions::administrable($code)) {
                continue;
            }

            $portee = $habilitation->faculte_id !== null ? (int) $habilitation->faculte_id : null;
            $permissions[$code][] = $portee;
            if ($portee !== null) {
                $portees[$portee] = true;
            }
        }

        foreach ($this->delegationsActives($utilisateur) as $delegation) {
            $code = (string) $delegation->permission_code;

            if (! CataloguePermissions::existe($code) || ! CataloguePermissions::administrable($code)) {
                continue;
            }

            // Le délégant doit détenir DIRECTEMENT la permission, pas par
            // une autre délégation : pas de chaîne.
            $delegant = $delegation->delegant;
            if ($delegant === null || ! $delegant->estActif()
                || ! in_array($code, Permissions::pourRole((string) $delegant->role), true)) {
                continue;
            }

            $portee = $delegation->faculte_id !== null ? (int) $delegation->faculte_id : null;

            // Un délégant restreint ne peut déléguer que dans sa faculté.
            $porteeDelegant = $delegant->faculteRestreinteId();
            if ($porteeDelegant !== null && $portee !== $porteeDelegant) {
                continue;
            }

            $permissions[$code][] = $portee;
            if ($portee !== null) {
                $portees[$portee] = true;
            }
        }

        foreach ($this->urgencesActives($utilisateur) as $urgence) {
            $code = (string) $urgence->permission_code;

            if (! CataloguePermissions::existe($code) || ! CataloguePermissions::administrable($code)) {
                continue;
            }

            $portee = $urgence->faculte_id !== null ? (int) $urgence->faculte_id : null;
            $permissions[$code][] = $portee;
            if ($portee !== null) {
                $portees[$portee] = true;
            }
        }

        // Portée implicite : uniquement pour un compte sans matrice globale
        // (les rôles livrés ont tous une matrice). admin_faculte est déjà
        // restreint par `faculteRestreinteId()`.
        $portee = $utilisateur->faculteRestreinteId();
        if ($portee === null && $matrice === [] && count($portees) === 1) {
            $portee = (int) array_key_first($portees);
        }

        foreach ($permissions as $code => $scopes) {
            $permissions[$code] = array_values(array_unique($scopes, SORT_REGULAR));
        }

        return self::$cache[$cle] = [
            'permissions' => $permissions,
            'portee' => $portee,
            'matrice' => $matrice,
        ];
    }

    /**
     * Décision d'autorisation. `$faculteId` est le périmètre de la donnée
     * visée (jamais un paramètre client non validé : le contrôleur fournit
     * la faculté réellement chargée en base).
     */
    public function autorise(User $utilisateur, string $permission, ?int $faculteId = null): bool
    {
        if (! $utilisateur->estActif()) {
            return false;
        }

        // Refus par défaut : une permission inconnue du code n'est jamais
        // accordée, même si une ligne traînait en base.
        if (! in_array($permission, Permissions::TOUTES, true)
            && ! in_array($permission, CataloguePermissions::federees(), true)) {
            return false;
        }

        $contexte = $this->contexte($utilisateur);

        if (in_array($permission, $contexte['matrice'], true)) {
            return true;
        }

        $scopes = $contexte['permissions'][$permission] ?? [];

        if ($scopes === []) {
            return false;
        }

        if (in_array(null, $scopes, true)) {
            return true;
        }

        if ($faculteId !== null) {
            return in_array($faculteId, $scopes, true);
        }

        // Sans contexte explicite, une portée facultaire n'est acceptée
        // que si le compte est lui-même restreint à cette faculté.
        return $contexte['portee'] !== null && in_array($contexte['portee'], $scopes, true);
    }

    /**
     * @throws AuthorizationException
     */
    public function verifier(User $utilisateur, string $permission, ?int $faculteId = null): void
    {
        if (! $this->autorise($utilisateur, $permission, $faculteId)) {
            throw new AuthorizationException("Permission requise : {$permission}.");
        }
    }

    /**
     * Toutes les permissions utilisables par le compte (matrice + accès
     * additionnels), pour l'affichage uniquement. L'autorisation réelle
     * repasse toujours par `autorise()` avec le contexte de la donnée.
     *
     * @return array<int, string>
     */
    public function permissionsEffectives(User $utilisateur): array
    {
        $contexte = $this->contexte($utilisateur);

        return array_values(array_unique(array_merge(
            $contexte['matrice'],
            array_keys($contexte['permissions']),
        )));
    }

    /**
     * Origine d'une permission pour un compte (affichage et audit).
     */
    public function origine(User $utilisateur, string $permission): ?string
    {
        if (in_array($permission, Permissions::pourRole((string) ($utilisateur->role ?? '')), true)) {
            return 'role';
        }

        $contexte = $this->contexte($utilisateur);

        if (! isset($contexte['permissions'][$permission])) {
            return null;
        }

        if (UrgenceIam::query()
            ->where('beneficiaire_id', $utilisateur->id)
            ->where('permission_code', $permission)
            ->where('statut', UrgenceIam::STATUT_ACTIVE)
            ->where('debut_le', '<=', now())
            ->where('fin_le', '>', now())
            ->exists()) {
            return 'urgence';
        }

        if (DelegationIam::query()
            ->where('delegataire_id', $utilisateur->id)
            ->where('permission_code', $permission)
            ->where('statut', DelegationIam::STATUT_ACTIVE)
            ->where('debut_le', '<=', now())
            ->where('fin_le', '>', now())
            ->exists()) {
            return 'delegation';
        }

        if (HabilitationIam::query()
            ->where('user_id', $utilisateur->id)
            ->where('permission_code', $permission)
            ->where('statut', HabilitationIam::STATUT_ACTIVE)
            ->where('debut_le', '<=', now())
            ->where(fn ($q) => $q->whereNull('expire_le')->orWhere('expire_le', '>', now()))
            ->exists()) {
            return 'habilitation';
        }

        return 'profil';
    }

    public static function invalider(?int $userId = null): void
    {
        if ($userId === null) {
            self::$cache = [];

            return;
        }

        foreach (array_keys(self::$cache) as $cle) {
            if (str_starts_with((string) $cle, $userId.':')) {
                unset(self::$cache[$cle]);
            }
        }
    }

    /**
     * Raccourci statique pour l'évaluation de permission.
     */
    public static function autoriser(User $utilisateur, string $permission, ?int $faculteId = null): bool
    {
        return (new self)->autorise($utilisateur, $permission, $faculteId);
    }

    /**
     * Marque un changement pour un compte : incrémente le compteur
     * persistant et vide le cache du processus courant.
     */
    public static function marquerChangement(int $userId): void
    {
        // `updateOrCreate` avec une expression SQL `version + 1` échoue à
        // l'insertion (la colonne n'existe pas encore dans la clause VALUES).
        // On garantit d'abord la ligne, puis on incrémente atomiquement.
        VersionAutorisationIam::query()->firstOrCreate(
            ['user_id' => $userId],
            ['version' => 1, 'calcule_le' => now()],
        );

        VersionAutorisationIam::query()
            ->where('user_id', $userId)
            ->increment('version', 1, ['calcule_le' => now()]);

        self::invalider($userId);
    }

    /**
     * @return array<int, AffectationIam>
     */
    private function affectationsActives(User $utilisateur): array
    {
        if (! $this->tableDisponible('iam_affectations')) {
            return [];
        }

        return AffectationIam::query()
            ->where('user_id', $utilisateur->id)
            ->where('statut', AffectationIam::STATUT_ACTIVE)
            ->where('debut_le', '<=', now())
            ->where(fn ($q) => $q->whereNull('expire_le')->orWhere('expire_le', '>', now()))
            ->whereNotNull('profil_id')
            ->with(['profil.permissions', 'profil.faculte'])
            ->get()
            ->all();
    }

    /**
     * @return array<int, HabilitationIam>
     */
    private function habilitationsActives(User $utilisateur): array
    {
        if (! $this->tableDisponible('iam_habilitations')) {
            return [];
        }

        return HabilitationIam::query()
            ->where('user_id', $utilisateur->id)
            ->where('statut', HabilitationIam::STATUT_ACTIVE)
            ->where('debut_le', '<=', now())
            ->where(fn ($q) => $q->whereNull('expire_le')->orWhere('expire_le', '>', now()))
            ->get()
            ->all();
    }

    /**
     * @return array<int, DelegationIam>
     */
    private function delegationsActives(User $utilisateur): array
    {
        if (! $this->tableDisponible('iam_delegations')) {
            return [];
        }

        return DelegationIam::query()
            ->where('delegataire_id', $utilisateur->id)
            ->where('statut', DelegationIam::STATUT_ACTIVE)
            ->where('debut_le', '<=', now())
            ->where('fin_le', '>', now())
            ->with('delegant')
            ->get()
            ->all();
    }

    /**
     * @return array<int, UrgenceIam>
     */
    private function urgencesActives(User $utilisateur): array
    {
        if (! $this->tableDisponible('iam_urgences')) {
            return [];
        }

        return UrgenceIam::query()
            ->where('beneficiaire_id', $utilisateur->id)
            ->where('statut', UrgenceIam::STATUT_ACTIVE)
            ->where('debut_le', '<=', now())
            ->where('fin_le', '>', now())
            ->get()
            ->all();
    }

    private function tableDisponible(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<int, string>  $matrice
     */
    private function cle(User $utilisateur, array $matrice): string
    {
        return $utilisateur->id.':'.($utilisateur->role ?? '').':'.($utilisateur->faculte_id ?? '').':'.count($matrice);
    }
}
