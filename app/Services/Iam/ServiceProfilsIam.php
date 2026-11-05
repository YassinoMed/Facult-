<?php

namespace App\Services\Iam;

use App\Models\Faculte;
use App\Models\Iam\AffectationIam;
use App\Models\Iam\ProfilIam;
use App\Models\Iam\ProfilPermissionIam;
use App\Models\User;
use App\Support\Iam\CataloguePermissions;
use App\Support\Iam\MoteurAutorisation;
use App\Support\Permissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Service de gestion des profils d'habilitation IAM (LOOP 43-BIS / LOOP GLOBAL).
 *
 * Responsabilités :
 *  - Cycle de vie complet du rôle personnalisé (création, modification, suspension, réactivation, archivage, suppression) ;
 *  - Attribution et synchronisation des permissions aux profils avec validation stricte contre le catalogue ;
 *  - Contrôles anti-escalade et protection des rôles système et modules réservés ;
 *  - Invalidation atomique du cache des autorisations pour tous les utilisateurs affectés ;
 *  - Traçabilité et audit append-only via `ServiceAuditIam`.
 */
final class ServiceProfilsIam
{
    public function __construct(
        private readonly ServiceAuditIam $audit
    ) {}

    /**
     * Création d'un rôle personnalisé.
     *
     * @param  array<int, string>  $permissionCodes
     */
    public function creer(
        string $code,
        string $nom,
        ?string $description = null,
        ?Faculte $faculte = null,
        ?User $createur = null,
        array $permissionCodes = [],
        string $statutInitial = ProfilIam::STATUT_BROUILLON
    ): ProfilIam {
        $this->validerCodeTechnique($code);

        if (! in_array($statutInitial, ProfilIam::STATUTS, true)) {
            throw new InvalidArgumentException("Statut initial de rôle invalide : « {$statutInitial} ».");
        }

        if ($statutInitial === ProfilIam::STATUT_ACTIF
            && ($createur === null || ! $createur->estSuperAdmin())) {
            throw new AuthorizationException('Seul un super-administrateur peut créer un rôle directement actif.');
        }

        if ($this->existe($code)) {
            throw new ConflictHttpException("Un rôle portant le code technique « {$code} » existe déjà.");
        }

        // Vérification de délégation de périmètre pour l'acteur
        if ($createur !== null && $createur->faculteRestreinteId() !== null) {
            $faculteRestreinte = $createur->faculteRestreinteId();
            if ($faculte === null || (int) $faculte->id !== $faculteRestreinte) {
                throw new AuthorizationException('Un administrateur facultaire ne peut créer des rôles que pour sa propre faculté.');
            }
        }

        return DB::transaction(function () use ($code, $nom, $description, $faculte, $createur, $permissionCodes, $statutInitial): ProfilIam {
            $profil = ProfilIam::query()->create([
                'code' => $code,
                'nom' => $nom,
                'description' => $description,
                'faculte_id' => $faculte?->id,
                'statut' => $statutInitial,
                'version' => 1,
                'cree_par_id' => $createur?->id,
                'approuve_par_id' => $statutInitial === ProfilIam::STATUT_ACTIF ? $createur?->id : null,
                'approuve_le' => $statutInitial === ProfilIam::STATUT_ACTIF ? now() : null,
            ]);

            if ($permissionCodes !== []) {
                $this->synchroniserPermissions($profil, $permissionCodes, $createur, 'Création initiale du rôle');
            }

            $this->audit->enregistrer(
                type: 'iam.profil.cree',
                acteur: $createur,
                cible: null,
                objetType: ProfilIam::class,
                objetId: $profil->id,
                avant: null,
                apres: [
                    'code' => $profil->code,
                    'nom' => $profil->nom,
                    'statut' => $profil->statut,
                    'faculte_id' => $profil->faculte_id,
                    'permissions_count' => count($permissionCodes),
                ]
            );

            return $profil;
        });
    }

    /**
     * Alias rétro-compatible.
     */
    public function creuser(string $code, string $nom, ?string $description = null, ?Faculte $faculte = null, ?User $createur = null): ProfilIam
    {
        return $this->creer($code, $nom, $description, $faculte, $createur);
    }

    /**
     * Modification des caractéristiques d'un rôle personnalisé.
     */
    public function modifier(
        ProfilIam $profil,
        ?string $nom = null,
        ?string $description = null,
        ?Faculte $faculte = null,
        ?User $modificateur = null,
        bool $changerFaculte = false
    ): ProfilIam {
        if ($this->estRoleSysteme($profil->code)) {
            throw new AuthorizationException("Les rôles système ne peuvent pas être modifiés : {$profil->code}.");
        }

        if ($profil->statut === ProfilIam::STATUT_ARCHIVE) {
            throw new ConflictHttpException("Un rôle archivé ne peut plus être modifié : {$profil->code}.");
        }

        if ($modificateur !== null && $modificateur->faculteRestreinteId() !== null) {
            $restreinte = $modificateur->faculteRestreinteId();
            if ((int) $profil->faculte_id !== $restreinte) {
                throw new AuthorizationException('Vous ne pouvez modifier que les rôles rattachés à votre faculté.');
            }
        }

        $avant = [
            'nom' => $profil->nom,
            'description' => $profil->description,
            'faculte_id' => $profil->faculte_id,
            'version' => $profil->version,
        ];

        return DB::transaction(function () use ($profil, $nom, $description, $faculte, $modificateur, $avant, $changerFaculte): ProfilIam {
            $profil->nom = $nom ?? $profil->nom;
            $profil->description = $description ?? $profil->description;
            if ($changerFaculte) {
                $profil->faculte_id = $faculte?->id;
            }
            $profil->version += 1;
            $profil->save();

            $this->invaliderUtilisateursAffectes($profil);

            $this->audit->enregistrer(
                type: 'iam.profil.modifie',
                acteur: $modificateur,
                cible: null,
                objetType: ProfilIam::class,
                objetId: $profil->id,
                avant: $avant,
                apres: [
                    'nom' => $profil->nom,
                    'description' => $profil->description,
                    'faculte_id' => $profil->faculte_id,
                    'version' => $profil->version,
                ]
            );

            return $profil;
        });
    }

    /**
     * Synchronisation en bloc des permissions d'un rôle personnalisé.
     *
     * @param  array<int, string>  $permissionCodes
     * @return array{ajoutees: array<int, string>, retirees: array<int, string>, total: int}
     */
    public function synchroniserPermissions(
        ProfilIam $profil,
        array $permissionCodes,
        ?User $acteur = null,
        ?string $motif = null,
        bool $verifierAntiEscalade = true
    ): array {
        if ($this->estRoleSysteme($profil->code)) {
            throw new AuthorizationException("Impossible de modifier les permissions d'un rôle système : {$profil->code}.");
        }

        if ($profil->statut === ProfilIam::STATUT_ARCHIVE) {
            throw new ConflictHttpException("Un rôle archivé ne peut plus être modifié : {$profil->code}.");
        }

        // 1. Validation stricte de chaque code contre le catalogue
        $codesValides = [];
        foreach ($permissionCodes as $code) {
            $code = trim((string) $code);
            if ($code === '') {
                continue;
            }

            if (! CataloguePermissions::existe($code)) {
                throw new UnprocessableEntityHttpException("Permission inconnue du catalogue système : « {$code} ».");
            }

            if (! CataloguePermissions::administrable($code)) {
                throw new AuthorizationException("La permission « {$code} » appartient à un module réservé et ne peut pas être attribuée à un rôle personnalisé.");
            }

            // Anti-escalade : l'acteur ne peut déléguer une permission que s'il est super_admin
            // ou s'il détient lui-même cette permission dans son périmètre. Une
            // demande à quatre yeux déjà approuvée applique son payload sans
            // rejouer cette règle contre l'approbateur (sinon seuls les
            // super-admins pourraient approuver des permissions critiques).
            if ($verifierAntiEscalade && $acteur !== null && ! $acteur->estSuperAdmin()) {
                if (! $acteur->hasPermission($code)) {
                    throw new AuthorizationException("Vous ne pouvez pas attribuer la permission « {$code} » car vous ne la détenez pas.");
                }
            }

            $codesValides[] = $code;
        }

        $codesValides = array_values(array_unique($codesValides));

        // Les dépendances déclarées sont un invariant serveur : on refuse
        // l'ensemble plutôt que d'ajouter silencieusement des permissions
        // que l'acteur n'a pas explicitement choisies.
        $dependancesManquantes = CataloguePermissions::dependancesManquantes($codesValides);
        if ($dependancesManquantes !== []) {
            throw new UnprocessableEntityHttpException(
                'Permissions dépendantes manquantes : '.implode(', ', $dependancesManquantes).'.'
            );
        }

        return DB::transaction(function () use ($profil, $codesValides, $acteur, $motif): array {
            $existantes = $profil->permissions()->pluck('permission_code')->all();

            $ajoutees = array_values(array_diff($codesValides, $existantes));
            $retirees = array_values(array_diff($existantes, $codesValides));

            if ($retirees !== []) {
                $profil->permissions()->whereIn('permission_code', $retirees)->delete();
            }

            if ($ajoutees !== []) {
                $nouvellesLignes = array_map(fn ($p) => [
                    'profil_id' => $profil->id,
                    'permission_code' => $p,
                    'created_at' => now(),
                    'updated_at' => now(),
                ], $ajoutees);
                ProfilPermissionIam::query()->insert($nouvellesLignes);
            }

            if ($ajoutees !== [] || $retirees !== []) {
                $profil->version += 1;
                $profil->save();

                // Invalidation immédiate du cache de tous les utilisateurs affectés à ce profil
                $this->invaliderUtilisateursAffectes($profil);

                $this->audit->enregistrer(
                    type: 'iam.profil.permissions_modifiees',
                    acteur: $acteur,
                    cible: null,
                    objetType: ProfilIam::class,
                    objetId: $profil->id,
                    avant: ['permissions' => $existantes],
                    apres: [
                        'permissions' => $codesValides,
                        'ajoutees' => $ajoutees,
                        'retirees' => $retirees,
                        'motif' => $motif,
                        'version' => $profil->version,
                    ]
                );
            }

            return [
                'ajoutees' => $ajoutees,
                'retirees' => $retirees,
                'total' => count($codesValides),
            ];
        });
    }

    /**
     * Ajout d'une permission unique à un rôle personnalisé.
     */
    public function ajouterPermission(ProfilIam $profil, string $permissionCode, ?User $acteur = null): ProfilPermissionIam
    {
        $res = $this->synchroniserPermissions(
            $profil,
            array_merge($profil->codesPermissions(), [$permissionCode]),
            $acteur
        );

        return $profil->permissions()->where('permission_code', $permissionCode)->firstOrFail();
    }

    /**
     * Retrait d'une permission unique d'un rôle personnalisé.
     */
    public function retirerPermission(ProfilIam $profil, string $permissionCode, ?User $acteur = null): bool
    {
        $actuelles = $profil->codesPermissions();
        $restantes = array_values(array_filter($actuelles, fn ($p) => $p !== $permissionCode));

        $res = $this->synchroniserPermissions($profil, $restantes, $acteur);

        return in_array($permissionCode, $res['retirees'], true);
    }

    /**
     * Validation et activation d'un profil.
     */
    public function valider(ProfilIam $profil, ?User $approuveur = null): ProfilIam
    {
        if ($this->estRoleSysteme($profil->code)) {
            throw new AuthorizationException("Un rôle système ne peut pas être activé via cette interface : {$profil->code}.");
        }

        if ($profil->statut === ProfilIam::STATUT_ARCHIVE) {
            throw new ConflictHttpException('Un rôle archivé ne peut pas être activé.');
        }

        if ($profil->statut === ProfilIam::STATUT_ACTIF) {
            return $profil;
        }

        // Quatre yeux : hors super_admin, l'approbateur ne peut pas être
        // le créateur du profil. La règle est aussi vérifiée côté demande.
        if ($approuveur !== null
            && $profil->cree_par_id !== null
            && (int) $profil->cree_par_id === (int) $approuveur->id
            && ! $approuveur->estSuperAdmin()) {
            throw new AuthorizationException('Règle des quatre yeux : le créateur ne peut pas activer lui-même son rôle.');
        }

        return DB::transaction(function () use ($profil, $approuveur): ProfilIam {
            $ancienStatut = $profil->statut;

            $profil->statut = ProfilIam::STATUT_ACTIF;
            $profil->version += 1;
            $profil->approuve_par_id = $approuveur?->id;
            $profil->approuve_le = now();
            $profil->save();

            $this->invaliderUtilisateursAffectes($profil);

            $this->audit->enregistrer(
                type: 'iam.profil.active',
                acteur: $approuveur,
                cible: null,
                objetType: ProfilIam::class,
                objetId: $profil->id,
                avant: ['statut' => $ancienStatut],
                apres: ['statut' => ProfilIam::STATUT_ACTIF, 'version' => $profil->version]
            );

            return $profil;
        });
    }

    /**
     * Soumettre un profil au circuit de validation à quatre yeux.
     */
    public function soumettre(ProfilIam $profil, ?User $acteur = null, ?string $motif = null): ProfilIam
    {
        if ($this->estRoleSysteme($profil->code)) {
            throw new AuthorizationException("Un rôle système ne peut pas être soumis : {$profil->code}.");
        }

        if (! in_array($profil->statut, [ProfilIam::STATUT_BROUILLON, ProfilIam::STATUT_SUSPENDU], true)) {
            throw new ConflictHttpException("Ce rôle ne peut pas être soumis depuis le statut « {$profil->statut} ».");
        }

        return DB::transaction(function () use ($profil, $acteur, $motif): ProfilIam {
            $ancienStatut = $profil->statut;
            $profil->statut = ProfilIam::STATUT_SOUMIS;
            $profil->save();

            $this->audit->enregistrer(
                type: 'iam.profil.soumis',
                acteur: $acteur,
                cible: null,
                objetType: ProfilIam::class,
                objetId: $profil->id,
                avant: ['statut' => $ancienStatut],
                apres: ['statut' => ProfilIam::STATUT_SOUMIS, 'motif' => $motif]
            );

            return $profil;
        });
    }

    /**
     * Suspension d'un rôle (désactivation réversible).
     */
    public function suspendre(ProfilIam $profil, ?User $suspendeur = null, ?string $motif = null): ProfilIam
    {
        if ($this->estRoleSysteme($profil->code)) {
            throw new AuthorizationException("Un rôle système ne peut pas être suspendu : {$profil->code}.");
        }

        if ($profil->statut === ProfilIam::STATUT_ARCHIVE) {
            throw new ConflictHttpException("Un rôle archivé ne peut pas être suspendu : {$profil->code}.");
        }

        return DB::transaction(function () use ($profil, $suspendeur, $motif): ProfilIam {
            $ancienStatut = $profil->statut;
            $profil->statut = ProfilIam::STATUT_SUSPENDU;
            $profil->save();

            $this->invaliderUtilisateursAffectes($profil);

            $this->audit->enregistrer(
                type: 'iam.profil.suspendu',
                acteur: $suspendeur,
                cible: null,
                objetType: ProfilIam::class,
                objetId: $profil->id,
                avant: ['statut' => $ancienStatut],
                apres: ['statut' => ProfilIam::STATUT_SUSPENDU, 'motif' => $motif]
            );

            return $profil;
        });
    }

    /**
     * Réactivation d'un rôle suspendu.
     */
    public function reactiver(ProfilIam $profil, ?User $reactivateur = null): ProfilIam
    {
        if ($this->estRoleSysteme($profil->code)) {
            throw new AuthorizationException("Un rôle système ne peut pas être réactivé via cette interface : {$profil->code}.");
        }

        if ($profil->statut === ProfilIam::STATUT_ARCHIVE) {
            throw new ConflictHttpException("Un rôle archivé ne peut pas être réactivé : {$profil->code}.");
        }

        return DB::transaction(function () use ($profil, $reactivateur): ProfilIam {
            $ancienStatut = $profil->statut;
            $profil->statut = ProfilIam::STATUT_ACTIF;
            // La réactivation ne modifie pas le sous-ensemble de permissions :
            // la version reste figée pour que les affectations existantes
            // retrouvent leur effet, sans ré-affectation implicite.
            $profil->save();

            $this->invaliderUtilisateursAffectes($profil);

            $this->audit->enregistrer(
                type: 'iam.profil.reactive',
                acteur: $reactivateur,
                cible: null,
                objetType: ProfilIam::class,
                objetId: $profil->id,
                avant: ['statut' => $ancienStatut],
                apres: ['statut' => ProfilIam::STATUT_ACTIF, 'version' => $profil->version]
            );

            return $profil;
        });
    }

    /**
     * Archivage définitif d'un rôle.
     */
    public function archiver(ProfilIam $profil, ?User $archiveur = null): ProfilIam
    {
        if ($this->estRoleSysteme($profil->code)) {
            throw new AuthorizationException("Un rôle système ne peut pas être archivé : {$profil->code}.");
        }

        return DB::transaction(function () use ($profil, $archiveur): ProfilIam {
            $ancienStatut = $profil->statut;
            $profil->statut = ProfilIam::STATUT_ARCHIVE;
            $profil->save();

            $this->invaliderUtilisateursAffectes($profil);

            $this->audit->enregistrer(
                type: 'iam.profil.archive',
                acteur: $archiveur,
                cible: null,
                objetType: ProfilIam::class,
                objetId: $profil->id,
                avant: ['statut' => $ancienStatut],
                apres: ['statut' => ProfilIam::STATUT_ARCHIVE]
            );

            return $profil;
        });
    }

    /**
     * Suppression physique d'un rôle personnalisé.
     *
     * Refusée si :
     *  - C'est un rôle système ;
     *  - Le rôle est encore affecté à des utilisateurs ;
     *  - Le rôle fait l'objet d'historique ou de demandes en cours.
     */
    public function supprimer(ProfilIam $profil, ?User $acteur = null): bool
    {
        if ($this->estRoleSysteme($profil->code)) {
            throw new AuthorizationException("Un rôle système ne peut jamais être supprimé : {$profil->code}.");
        }

        if ($this->affectationsActives($profil) !== []) {
            throw new ConflictHttpException("Impossible de supprimer ce rôle : il est actuellement affecté à des utilisateurs actifs. Désactivez ou révoquez d'abord les affectations.");
        }

        $nbTotalAffectations = AffectationIam::where('profil_id', $profil->id)->count();
        if ($nbTotalAffectations > 0) {
            throw new ConflictHttpException("Ce rôle possède un historique d'affectations ({$nbTotalAffectations}). Utilisez l'archivage au lieu de la suppression physique.");
        }

        return DB::transaction(function () use ($profil, $acteur): bool {
            $info = [
                'code' => $profil->code,
                'nom' => $profil->nom,
                'faculte_id' => $profil->faculte_id,
            ];

            $profil->permissions()->delete();
            $profil->delete();

            $this->audit->enregistrer(
                type: 'iam.profil.supprime',
                acteur: $acteur,
                cible: null,
                objetType: ProfilIam::class,
                objetId: $profil->id,
                avant: $info,
                apres: null
            );

            return true;
        });
    }

    /**
     * @return array<int, AffectationIam>
     */
    public function affectationsActives(ProfilIam $profil): array
    {
        if (! Schema::hasTable('iam_affectations')) {
            return [];
        }

        return AffectationIam::query()
            ->where('profil_id', $profil->id)
            ->where('statut', AffectationIam::STATUT_ACTIVE)
            ->where('debut_le', '<=', now())
            ->where(fn ($q) => $q->whereNull('expire_le')->orWhere('expire_le', '>', now()))
            ->with(['user', 'faculte'])
            ->get()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function permissionsEffectives(ProfilIam $profil): array
    {
        $profil->loadMissing('permissions');

        return array_values(array_unique($profil->permissions->pluck('permission_code')->all()));
    }

    public function existe(string $code): bool
    {
        return ProfilIam::query()->where('code', $code)->exists();
    }

    public function estRoleSysteme(string $code): bool
    {
        return in_array($code, Permissions::ROLES, true);
    }

    private function validerCodeTechnique(string $code): void
    {
        if (! preg_match('/^[a-z0-9_\-]+$/', $code)) {
            throw new InvalidArgumentException("Le code technique « {$code} » est invalide (uniquement lettres minuscules, chiffres, tirets et underscores).");
        }

        if ($this->estRoleSysteme($code)) {
            throw new ConflictHttpException("Le code « {$code} » entre en collision avec un rôle système protégé.");
        }
    }

    private function invaliderUtilisateursAffectes(ProfilIam $profil): void
    {
        if (! Schema::hasTable('iam_affectations')) {
            return;
        }

        $userIds = AffectationIam::query()
            ->where('profil_id', $profil->id)
            ->where('statut', AffectationIam::STATUT_ACTIVE)
            ->pluck('user_id')
            ->unique()
            ->all();

        $moteur = new MoteurAutorisation;
        foreach ($userIds as $id) {
            $moteur->marquerChangement((int) $id);
        }
    }
}
