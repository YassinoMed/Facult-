<?php

namespace App\Services\Iam;

use App\Models\Faculte;
use App\Models\Iam\ApprobationIam;
use App\Models\Iam\DemandeIam;
use App\Models\Iam\ProfilIam;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Service de gestion du workflow à quatre yeux (LOOP GLOBAL IAM/RBAC).
 *
 * Invariants stricts :
 *  1. L'initiateur d'une demande ne peut jamais l'approuver lui-même (`initiateur_id !== approbateur_id`) ;
 *  2. Le bénéficiaire d'une affectation ne peut pas approuver sa propre affectation ;
 *  3. Quorum requis paramétrable (`config('iam.approbateurs_requis', 1)`) ;
 *  4. Non-rejeu : toute demande déjà statuée (approuvée, rejetée, annulée, expirée) est scellée ;
 *  5. Application atomique : l'effet métier n'est exécuté que si le quorum d'approbation est atteint ;
 *  6. Le payload est scellé (empreinte SHA-256, expiration, version du profil) : toute
 *     altération en base ou toute approbation tardive est refusée ;
 *  7. Traçabilité append-only via `ServiceAuditIam`.
 */
final class ServiceDemandesIam
{
    /**
     * Métadonnées scellées dans le payload JSON (aucune colonne additive
     * nécessaire) : empreinte, expiration et version du profil visé.
     */
    private const META_EMPREINTE = '__empreinte';

    private const META_EXPIRE_LE = '__expire_le';

    private const META_VERSION_PROFIL = '__version_profil';

    /**
     * Types réellement exécutables par ce service. Les autres types du
     * modèle restent déclarés pour l'évolution du domaine, mais une
     * demande qui ne produirait aucun effet métier est refusée à la
     * création plutôt que d'être approuvée silencieusement.
     */
    private const TYPES_SUPPORTES = [
        DemandeIam::TYPE_ACTIVATION_PROFIL,
        DemandeIam::TYPE_AFFECTATION_PROFIL,
    ];

    public function __construct(
        private readonly ServiceAuditIam $audit,
        private readonly ServiceProfilsIam $profils,
        private readonly ServiceAffectationsIam $affectations
    ) {}

    /**
     * Soumettre une nouvelle demande nécessitant double validation.
     */
    public function creerDemande(
        string $type,
        User $initiateur,
        ?User $cible = null,
        ?ProfilIam $profil = null,
        ?Faculte $faculte = null,
        ?array $payload = null,
        string $motif = ''
    ): DemandeIam {
        if (! in_array($type, DemandeIam::TYPES, true)) {
            throw new InvalidArgumentException("Type de demande IAM inconnu : « {$type} ».");
        }

        if (! in_array($type, self::TYPES_SUPPORTES, true)) {
            throw new UnprocessableEntityHttpException(
                "Ce type de demande n'est pas encore exécutable par la gouvernance IAM : « {$type} »."
            );
        }

        if (trim($motif) === '') {
            throw new UnprocessableEntityHttpException('Un motif explicite est obligatoire pour toute demande à quatre yeux.');
        }

        $this->verifierActeur($initiateur);

        // Un bénéficiaire ne peut pas être l'initiateur de sa propre
        // affectation : la règle des quatre yeux serait contournée.
        if ($cible !== null && (int) $cible->id === (int) $initiateur->id) {
            throw new AuthorizationException('Vous ne pouvez pas initier une demande dont vous êtes le bénéficiaire.');
        }

        if ($faculte !== null && $profil !== null && $profil->faculte_id !== null
            && (int) $faculte->id !== (int) $profil->faculte_id) {
            throw new AuthorizationException('La faculté de la demande ne correspond pas à celle du rôle.');
        }

        $payload = $this->scellerPayload($payload ?? [], $profil);
        $faculteDemande = $faculte?->id ?? $profil?->faculte_id;

        // Périmètre facultaire de l'initiateur : jamais élargi par le client.
        $restreinte = $initiateur->faculteRestreinteId();
        if ($restreinte !== null && (int) $faculteDemande !== (int) $restreinte) {
            throw new AuthorizationException('Vous ne pouvez initier une demande que dans le périmètre de votre faculté.');
        }

        $this->validerCibles($type, $cible, $profil);

        // Une seule demande en attente pour un même objet : évite les
        // doublons d'approbation et les courses de statut.
        $dejaEnAttente = DemandeIam::query()
            ->where('type', $type)
            ->where('statut', DemandeIam::STATUT_SOUMISE)
            ->where('profil_id', $profil?->id)
            ->where('cible_user_id', $cible?->id)
            ->exists();

        if ($dejaEnAttente) {
            throw new ConflictHttpException('Une demande en attente existe déjà pour cet objet.');
        }

        return DB::transaction(function () use ($type, $initiateur, $cible, $profil, $faculteDemande, $payload, $motif): DemandeIam {
            $demande = DemandeIam::query()->create([
                'type' => $type,
                'statut' => DemandeIam::STATUT_SOUMISE,
                'initiateur_id' => $initiateur->id,
                'cible_user_id' => $cible?->id,
                'profil_id' => $profil?->id,
                'faculte_id' => $faculteDemande,
                'payload' => $payload,
                'motif' => $motif,
            ]);

            $this->audit->enregistrer(
                type: 'iam.demande.soumise',
                acteur: $initiateur,
                cible: $cible,
                objetType: DemandeIam::class,
                objetId: $demande->id,
                avant: null,
                apres: [
                    'reference' => $demande->reference,
                    'type' => $demande->type,
                    'profil_id' => $demande->profil_id,
                    'motif' => $demande->motif,
                ]
            );

            return $demande;
        });
    }

    /**
     * Décider sur une demande (Approbation ou Rejet).
     */
    public function decider(
        DemandeIam $demande,
        User $approbateur,
        string $decision,
        ?string $commentaire = null
    ): DemandeIam {
        if (! in_array($decision, [ApprobationIam::DECISION_APPROUVE, ApprobationIam::DECISION_REJETE], true)) {
            throw new InvalidArgumentException("Décision invalide : « {$decision} ».");
        }

        $this->verifierActeur($approbateur);

        if (! $demande->estApprouvable()) {
            throw new ConflictHttpException("Cette demande a déjà été clôturée (statut actuel : {$demande->statut}). Aucun rejeu possible.");
        }

        // RÈGLE CARDINALE ANTI-AUTO-APPROBATION
        if ((int) $demande->initiateur_id === (int) $approbateur->id) {
            throw new AuthorizationException('Règle des quatre yeux : vous ne pouvez pas approuver votre propre demande.');
        }

        // Un bénéficiaire ne peut pas approuver sa propre affectation.
        if ($demande->cible_user_id !== null && (int) $demande->cible_user_id === (int) $approbateur->id) {
            throw new AuthorizationException('Règle des quatre yeux : le bénéficiaire ne peut pas approuver sa propre affectation.');
        }

        // Périmètre facultaire de l'approbateur.
        $restreinte = $approbateur->faculteRestreinteId();
        if ($restreinte !== null && $demande->faculte_id !== null && (int) $demande->faculte_id !== (int) $restreinte) {
            throw new AuthorizationException('Vous ne pouvez pas approuver une demande hors de votre faculté.');
        }

        // Une seule décision par approbateur et par demande.
        if (ApprobationIam::query()
            ->where('demande_id', $demande->id)
            ->where('approbateur_id', $approbateur->id)
            ->exists()) {
            throw new ConflictHttpException('Vous avez déjà statué sur cette demande.');
        }

        // Payload scellé : expiration, empreinte et version du profil.
        $this->verifierPayload($demande, $approbateur);

        return DB::transaction(function () use ($demande, $approbateur, $decision, $commentaire): DemandeIam {
            // Enregistrement de l'approbation individuelle. La contrainte
            // d'unicité est la dernière ligne de défense en cas de course.
            try {
                ApprobationIam::query()->create([
                    'demande_id' => $demande->id,
                    'approbateur_id' => $approbateur->id,
                    'decision' => $decision,
                    'commentaire' => $commentaire,
                    'decide_le' => now(),
                ]);
            } catch (QueryException) {
                throw new ConflictHttpException('Vous avez déjà statué sur cette demande.');
            }

            if ($decision === ApprobationIam::DECISION_REJETE) {
                $demande->statut = DemandeIam::STATUT_REJETEE;
                $demande->decide_le = now();
                $demande->save();

                $this->audit->enregistrer(
                    type: 'iam.demande.rejetee',
                    acteur: $approbateur,
                    cible: $demande->cible,
                    objetType: DemandeIam::class,
                    objetId: $demande->id,
                    avant: ['statut' => DemandeIam::STATUT_SOUMISE],
                    apres: ['statut' => DemandeIam::STATUT_REJETEE, 'commentaire' => $commentaire]
                );

                return $demande->fresh();
            }

            // Vérification du quorum d'approbation
            $approbationsPositives = ApprobationIam::query()
                ->where('demande_id', $demande->id)
                ->where('decision', ApprobationIam::DECISION_APPROUVE)
                ->count();

            $quorumRequis = max(1, (int) config('iam.approbateurs_requis', 1));

            if ($approbationsPositives < $quorumRequis) {
                // Quorum partiel : la demande reste ouverte et l'acte est
                // tracé, sans exécuter l'effet métier.
                $this->audit->enregistrer(
                    type: 'iam.demande.approbation_partielle',
                    acteur: $approbateur,
                    cible: $demande->cible,
                    objetType: DemandeIam::class,
                    objetId: $demande->id,
                    avant: ['statut' => DemandeIam::STATUT_SOUMISE],
                    apres: [
                        'statut' => DemandeIam::STATUT_SOUMISE,
                        'quorum' => "{$approbationsPositives}/{$quorumRequis}",
                        'commentaire' => $commentaire,
                    ]
                );

                return $demande->fresh();
            }

            // Application de l'effet métier demandé, dans la même
            // transaction que le statut : tout échec annule l'approbation.
            $this->appliquerEffetMetier($demande, $approbateur);

            $demande->statut = DemandeIam::STATUT_APPROUVEE;
            $demande->decide_le = now();
            $demande->save();

            $this->audit->enregistrer(
                type: 'iam.demande.approuvee',
                acteur: $approbateur,
                cible: $demande->cible,
                objetType: DemandeIam::class,
                objetId: $demande->id,
                avant: ['statut' => DemandeIam::STATUT_SOUMISE],
                apres: [
                    'statut' => DemandeIam::STATUT_APPROUVEE,
                    'commentaire' => $commentaire,
                    'quorum' => "{$approbationsPositives}/{$quorumRequis}",
                ]
            );

            return $demande->fresh();
        });
    }

    /**
     * Annuler une demande en attente par son initiateur.
     */
    public function annuler(DemandeIam $demande, User $acteur, ?string $motif = null): DemandeIam
    {
        $this->verifierActeur($acteur);

        if (! $demande->estApprouvable()) {
            throw new ConflictHttpException("Impossible d'annuler une demande déjà traitée.");
        }

        if ((int) $demande->initiateur_id !== (int) $acteur->id && ! $acteur->estSuperAdmin()) {
            throw new AuthorizationException('Seul l’initiateur ou un super-administrateur peut annuler cette demande.');
        }

        $restreinte = $acteur->faculteRestreinteId();
        if ($restreinte !== null && $demande->faculte_id !== null && (int) $demande->faculte_id !== (int) $restreinte) {
            throw new AuthorizationException('Vous ne pouvez pas annuler une demande hors de votre faculté.');
        }

        return DB::transaction(function () use ($demande, $acteur, $motif): DemandeIam {
            $demande->statut = DemandeIam::STATUT_ANNULEE;
            $demande->decide_le = now();
            $demande->save();

            $this->audit->enregistrer(
                type: 'iam.demande.annulee',
                acteur: $acteur,
                cible: $demande->cible,
                objetType: DemandeIam::class,
                objetId: $demande->id,
                avant: ['statut' => DemandeIam::STATUT_SOUMISE],
                apres: ['statut' => DemandeIam::STATUT_ANNULEE, 'motif' => $motif]
            );

            return $demande->fresh();
        });
    }

    /**
     * Exécute l'effet métier d'une demande approuvée. Toute anomalie
     * annule la transaction d'approbation : jamais d'approbation sans
     * effet, jamais d'effet sans approbation.
     */
    private function appliquerEffetMetier(DemandeIam $demande, User $approbateur): void
    {
        $payload = $this->sansMetas($demande->payload ?? []);

        switch ($demande->type) {
            case DemandeIam::TYPE_ACTIVATION_PROFIL:
                $profil = $demande->profil;

                if ($profil === null) {
                    throw new UnprocessableEntityHttpException('La demande d’activation ne référence aucun rôle.');
                }

                $serviceProfils = $this->profils;

                // Une demande de modification critique embarque la liste
                // complète des permissions à appliquer AVANT l'activation.
                if (array_key_exists('permissions', $payload)) {
                    if (! is_array($payload['permissions'])) {
                        throw new UnprocessableEntityHttpException('La liste de permissions de la demande est invalide.');
                    }

                    $serviceProfils->synchroniserPermissions(
                        profil: $profil,
                        permissionCodes: array_values(array_map('strval', $payload['permissions'])),
                        acteur: $approbateur,
                        motif: "Approbation de la demande {$demande->reference}",
                        // Le payload a déjà franchi le circuit à quatre yeux :
                        // l'approbateur autorise l'ensemble, il ne le délègue
                        // pas depuis ses propres permissions.
                        verifierAntiEscalade: false
                    );

                    $profil->refresh();
                }

                if (! $profil->estActif()) {
                    $serviceProfils->valider($profil, $approbateur);
                }

                break;

            case DemandeIam::TYPE_AFFECTATION_PROFIL:
                if ($demande->cible === null || $demande->profil === null) {
                    throw new UnprocessableEntityHttpException('La demande d’affectation ne référence pas un compte et un rôle.');
                }

                $serviceAffectations = $this->affectations;
                $serviceAffectations->affecter(
                    utilisateur: $demande->cible,
                    profil: $demande->profil,
                    faculte: $demande->faculte,
                    acteur: $approbateur,
                    payload: $payload,
                    expireLe: isset($payload['expire_le']) ? (string) $payload['expire_le'] : null
                );

                break;

            default:
                throw new UnprocessableEntityHttpException(
                    "Aucun effet métier n'est défini pour le type de demande « {$demande->type} »."
                );
        }
    }

    /**
     * Vérifie qu'un acteur est actif avant toute opération de gouvernance.
     */
    private function verifierActeur(User $acteur): void
    {
        if (! $acteur->estActif()) {
            throw new AuthorizationException('Un compte inactif ne peut pas participer au circuit de validation.');
        }
    }

    /**
     * Valide la cohérence des cibles selon le type de demande.
     */
    private function validerCibles(string $type, ?User $cible, ?ProfilIam $profil): void
    {
        switch ($type) {
            case DemandeIam::TYPE_ACTIVATION_PROFIL:
                if ($profil === null) {
                    throw new UnprocessableEntityHttpException('Une demande d’activation doit référencer un rôle.');
                }

                if ($profil->statut === ProfilIam::STATUT_ARCHIVE) {
                    throw new UnprocessableEntityHttpException('Un rôle archivé ne peut pas être soumis à activation.');
                }

                break;

            case DemandeIam::TYPE_AFFECTATION_PROFIL:
                if ($cible === null || $profil === null) {
                    throw new UnprocessableEntityHttpException('Une demande d’affectation doit référencer un compte et un rôle.');
                }

                if (! $cible->estActif()) {
                    throw new UnprocessableEntityHttpException('Le compte bénéficiaire est inactif.');
                }

                if (! $profil->estActif()) {
                    throw new UnprocessableEntityHttpException('Seul un rôle actif peut faire l’objet d’une affectation.');
                }

                if ($profil->faculte_id !== null) {
                    if ($cible->faculte_id === null || (int) $cible->faculte_id !== (int) $profil->faculte_id) {
                        throw new AuthorizationException('Ce compte n’appartient pas à la faculté de ce rôle.');
                    }
                }

                break;
        }
    }

    /**
     * Scelle le payload : retire les métadonnées fournies par le client,
     * ajoute l'expiration, la version du profil et l'empreinte.
     */
    private function scellerPayload(array $payload, ?ProfilIam $profil): array
    {
        $payload = $this->sansMetas($payload);

        $payload[self::META_EXPIRE_LE] = now()
            ->addHours(max(1, (int) config('iam.demande_expiration_heures', 72)))
            ->toIso8601String();
        $payload[self::META_VERSION_PROFIL] = $profil?->version;
        $payload[self::META_EMPREINTE] = $this->empreinte($payload);

        return $payload;
    }

    /**
     * Refuse une demande expirée, altérée ou devenue obsolète.
     */
    private function verifierPayload(DemandeIam $demande, ?User $acteur = null): void
    {
        $payload = $demande->payload ?? [];
        $empreinte = $payload[self::META_EMPREINTE] ?? null;

        if (! is_string($empreinte) || $empreinte === '' || ! hash_equals($empreinte, $this->empreinte($payload))) {
            $this->audit->enregistrer(
                type: 'iam.demande.integrite_refusee',
                acteur: $acteur,
                cible: $demande->cible,
                objetType: DemandeIam::class,
                objetId: $demande->id,
                apres: ['motif' => 'Empreinte du payload absente ou invalide.']
            );

            throw new ConflictHttpException('Le contenu de cette demande a été altéré. Elle ne peut pas être approuvée.');
        }

        $expireLe = $payload[self::META_EXPIRE_LE] ?? null;
        if (is_string($expireLe) && $expireLe !== '') {
            try {
                $expiration = Carbon::parse($expireLe);
            } catch (\Throwable) {
                throw new ConflictHttpException('La date d’expiration de cette demande est invalide.');
            }

            if (now()->greaterThan($expiration)) {
                $demande->statut = DemandeIam::STATUT_EXPIREE;
                $demande->decide_le = now();
                $demande->save();

                $this->audit->enregistrer(
                    type: 'iam.demande.expiree',
                    acteur: $acteur,
                    cible: $demande->cible,
                    objetType: DemandeIam::class,
                    objetId: $demande->id,
                    avant: ['statut' => DemandeIam::STATUT_SOUMISE],
                    apres: ['statut' => DemandeIam::STATUT_EXPIREE, 'expire_le' => $expireLe]
                );

                throw new ConflictHttpException('Cette demande a expiré et ne peut plus être approuvée.');
            }
        }

        $versionScellee = $payload[self::META_VERSION_PROFIL] ?? null;
        $profil = $demande->profil;

        if ($profil !== null && $versionScellee !== null && (int) $versionScellee !== (int) $profil->version) {
            $this->audit->enregistrer(
                type: 'iam.demande.obsolete_refusee',
                acteur: $acteur,
                cible: $demande->cible,
                objetType: DemandeIam::class,
                objetId: $demande->id,
                apres: [
                    'motif' => 'Le rôle a été modifié depuis la soumission.',
                    'version_scellee' => (int) $versionScellee,
                    'version_courante' => (int) $profil->version,
                ]
            );

            throw new ConflictHttpException('Le rôle a été modifié depuis la soumission de cette demande. Elle doit être rejouée.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function sansMetas(array $payload): array
    {
        return array_filter(
            $payload,
            fn ($cle): bool => ! is_string($cle) || ! str_starts_with($cle, '__'),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function empreinte(array $payload): string
    {
        $canonique = $this->canonique($this->sansMetas($payload));

        $json = json_encode($canonique, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new UnprocessableEntityHttpException('Le contenu de la demande ne peut pas être sérialisé.');
        }

        return hash('sha256', $json);
    }

    /**
     * Canonicalisation déterministe : clés triées, listes de scalaires
     * triées (l'ordre des permissions n'est pas sémantique).
     *
     * @param  array<mixed>  $valeurs
     * @return array<mixed>
     */
    private function canonique(array $valeurs): array
    {
        foreach ($valeurs as $cle => $valeur) {
            if (is_array($valeur)) {
                $valeurs[$cle] = $this->canonique($valeur);
            }
        }

        $estListeDeScalaires = array_is_list($valeurs)
            && $valeurs !== []
            && array_reduce(
                $valeurs,
                fn (bool $ok, mixed $valeur): bool => $ok && (is_scalar($valeur) || $valeur === null),
                true
            );

        if ($estListeDeScalaires) {
            sort($valeurs, SORT_STRING);

            return $valeurs;
        }

        ksort($valeurs);

        return $valeurs;
    }
}
