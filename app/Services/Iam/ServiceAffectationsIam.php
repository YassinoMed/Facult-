<?php

namespace App\Services\Iam;

use App\Models\Faculte;
use App\Models\Iam\AffectationIam;
use App\Models\Iam\ProfilIam;
use App\Models\User;
use App\Support\Iam\MoteurAutorisation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Service d'affectations de profils IAM (LOOP 43-BIS / LOOP GLOBAL).
 *
 * Une affectation lie un compte utilisateur à un profil IAM pour une fenêtre
 * temporelle (début, expiration). La version du profil (`version_profil`) est
 * figée au moment de l'affectation : une modification ultérieure du profil
 * n'élargit jamais l'accès sans ré-affectation explicite.
 */
final class ServiceAffectationsIam
{
    public function __construct(
        private readonly ServiceAuditIam $audit
    ) {}

    /**
     * Affecter un profil personnalisé à un utilisateur.
     */
    public function affecter(
        User $utilisateur,
        ProfilIam $profil,
        ?Faculte $faculte = null,
        ?User $acteur = null,
        ?array $payload = null,
        ?string $expireLe = null
    ): AffectationIam {
        if (! $profil->estActif()) {
            throw new UnprocessableEntityHttpException("Impossible d'affecter un rôle inactif ou archivé : « {$profil->nom} » (statut : {$profil->statut}).");
        }

        // Vérification de l'isolation facultaire
        $faculteId = $faculte?->id ?? $profil->faculte_id;
        if ($profil->faculte_id !== null && $faculteId !== (int) $profil->faculte_id) {
            throw new AuthorizationException('Ce rôle est strictement limité à sa faculté d’appartenance.');
        }

        if ($acteur !== null && $acteur->faculteRestreinteId() !== null) {
            $restreinte = $acteur->faculteRestreinteId();
            if ($faculteId !== $restreinte) {
                throw new AuthorizationException('Vous ne pouvez affecter des rôles que dans le périmètre de votre faculté.');
            }
        }

        // Aucun acteur ne peut s'affecter lui-même un rôle, même temporaire.
        if ($acteur !== null && (int) $acteur->id === (int) $utilisateur->id) {
            throw new AuthorizationException('Vous ne pouvez pas vous affecter vous-même un rôle.');
        }

        // Fenêtre temporelle bornée par la politique institutionnelle.
        $dureeMax = max(1, (int) config('iam.duree_max_affectation_jours', 365));
        $expiration = $expireLe !== null && $expireLe !== '' ? now()->parse($expireLe) : null;

        if ($expiration !== null && $expiration->isPast()) {
            throw new UnprocessableEntityHttpException('La date d’expiration d’une affectation doit être future.');
        }

        if ($expiration !== null && $expiration->greaterThan(now()->copy()->addDays($dureeMax))) {
            throw new UnprocessableEntityHttpException("La durée maximale d’une affectation est de {$dureeMax} jours.");
        }

        // Vérification si déjà affecté actif
        $existeActive = AffectationIam::query()
            ->where('user_id', $utilisateur->id)
            ->where('profil_id', $profil->id)
            ->where('statut', AffectationIam::STATUT_ACTIVE)
            ->where(fn ($q) => $q->whereNull('expire_le')->orWhere('expire_le', '>', now()))
            ->exists();

        if ($existeActive) {
            throw new ConflictHttpException("L'utilisateur « {$utilisateur->name} » possède déjà ce rôle actif.");
        }

        return DB::transaction(function () use ($utilisateur, $profil, $faculteId, $acteur, $payload, $expireLe): AffectationIam {
            $affectation = AffectationIam::query()->create([
                'user_id' => $utilisateur->id,
                'profil_id' => $profil->id,
                'role' => $profil->code,
                'faculte_id' => $faculteId,
                'version_profil' => $profil->version,
                'statut' => AffectationIam::STATUT_ACTIVE,
                'debut_le' => now(),
                'expire_le' => $expireLe ? now()->parse($expireLe) : null,
                'motif' => $payload['motif'] ?? null,
                'cree_par_id' => $acteur?->id,
            ]);

            MoteurAutorisation::marquerChangement($utilisateur->id);

            $this->audit->enregistrer(
                type: 'iam.affectation.creee',
                acteur: $acteur,
                cible: $utilisateur,
                objetType: AffectationIam::class,
                objetId: $affectation->id,
                avant: null,
                apres: [
                    'user_id' => $utilisateur->id,
                    'profil_id' => $profil->id,
                    'role' => $profil->code,
                    'faculte_id' => $faculteId,
                    'version_profil' => $profil->version,
                    'expire_le' => $affectation->expire_le?->toIso8601String(),
                ]
            );

            return $affectation;
        });
    }

    /**
     * Révoquer une affectation de profil.
     */
    public function revoquer(AffectationIam $affectation, ?User $acteur = null, ?string $motif = null): AffectationIam
    {
        if ($affectation->statut !== AffectationIam::STATUT_ACTIVE) {
            throw new ConflictHttpException('Cette affectation n’est plus active : aucune révocation possible.');
        }

        return DB::transaction(function () use ($affectation, $acteur, $motif): AffectationIam {
            $avant = [
                'statut' => $affectation->statut,
                'revoque_le' => $affectation->revoque_le,
            ];

            $affectation->statut = AffectationIam::STATUT_REVOQUEE;
            $affectation->revoque_par_id = $acteur?->id;
            $affectation->revoque_le = now();
            if ($motif) {
                $affectation->motif = ($affectation->motif ? $affectation->motif.' | ' : '')."Révocation : {$motif}";
            }
            $affectation->save();

            MoteurAutorisation::marquerChangement($affectation->user_id);

            $this->audit->enregistrer(
                type: 'iam.affectation.revoquee',
                acteur: $acteur,
                cible: $affectation->user,
                objetType: AffectationIam::class,
                objetId: $affectation->id,
                avant: $avant,
                apres: [
                    'statut' => AffectationIam::STATUT_REVOQUEE,
                    'revoque_le' => $affectation->revoque_le?->toIso8601String(),
                    'motif' => $motif,
                ]
            );

            return $affectation->fresh();
        });
    }

    /**
     * Marquer une affectation comme expirée.
     */
    public function expirer(AffectationIam $affectation): AffectationIam
    {
        return DB::transaction(function () use ($affectation): AffectationIam {
            $affectation->statut = AffectationIam::STATUT_EXPIREE;
            $affectation->save();

            MoteurAutorisation::marquerChangement($affectation->user_id);

            return $affectation->fresh();
        });
    }

    public function estActive(AffectationIam $affectation): bool
    {
        return $affectation->statut === AffectationIam::STATUT_ACTIVE
            && $affectation->debut_le->isPast()
            && ($affectation->expire_le === null || $affectation->expire_le->isFuture());
    }

    public function estVersionFigee(AffectationIam $affectation, ProfilIam $profil): bool
    {
        return (int) $affectation->version_profil === (int) $profil->version;
    }
}
