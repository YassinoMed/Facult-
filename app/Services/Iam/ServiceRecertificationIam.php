<?php

namespace App\Services\Iam;

use App\Models\Faculte;
use App\Models\Iam\AffectationIam;
use App\Models\Iam\DelegationIam;
use App\Models\Iam\HabilitationIam;
use App\Models\Iam\RecertificationIam;
use App\Models\User;
use App\Support\Iam\MoteurAutorisation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Service de recertification des accès (LOOP 43-BIS).
 *
 * Principe : chaque période, chaque bénéficiaire d'un accès (affectation,
 * habilitation ou délégation) doit être revu par un acteur distinct.
 * Le processus garantit que l'accès est toujours nécessaire, justifié et
 * conforme à la politique institutionnelle.
 *
 * Ce service gère :
 * - la création des campagnes ;
 * - l'assignation de réviseurs ;
 * - la confirmation ou la révocation ;
 * - le tracking des échéances.
 *
 * Un bénéficiaire ne peut jamais certifier son propre accès.
 */
final class ServiceRecertificationIam
{
    public static function lancerCampagne(
        string $campagne,
        string $objetType,
        int $objetId,
        ?Faculte $faculte = null,
        ?int $echeanceJours = null,
        ?User $assigneA = null,
    ): RecertificationIam {
        // Vérification qu'aucune campagne n'est déjà en cours pour cet objet
        if (RecertificationIam::query()
            ->where('campagne', $campagne)
            ->where('objet_type', $objetType)
            ->where('objet_id', $objetId)
            ->where('statut', RecertificationIam::STATUT_A_FAIRE)
            ->exists()) {
            throw new \InvalidArgumentException('Une recertification en cours existe déjà pour ce bien.');
        }

        $echeance = $echeanceJours !== null
            ? now()->copy()->addDays($echeanceJours)
            : null;

        return DB::transaction(function () use ($campagne, $objetType, $objetId, $faculte, $echeance, $assigneA): RecertificationIam {
            $recertification = RecertificationIam::query()->create([
                'campagne' => $campagne,
                'objet_type' => $objetType,
                'objet_id' => $objetId,
                'faculte_id' => $faculte?->id,
                'statut' => RecertificationIam::STATUT_A_FAIRE,
                'echeance_le' => $echeance,
                'assigne_a_id' => $assigneA?->id,
            ]);

            return $recertification;
        });
    }

    public function confirmer(RecertificationIam $recertification, ?User $decideur = null): RecertificationIam
    {
        return DB::transaction(function () use ($recertification, $decideur): RecertificationIam {
            $recertification->statut = RecertificationIam::STATUT_CONFIRMEE;
            $recertification->decide_par_id = $decideur?->id;
            $recertification->decide_le = now();
            $recertification->save();

            MoteurAutorisation::marquerChangement($recertification->user_id);

            return $recertification->fresh();
        });
    }

    public function retirer(RecertificationIam $recertification, ?User $decideur = null): RecertificationIam
    {
        return DB::transaction(function () use ($recertification, $decideur): RecertificationIam {
            $recertification->statut = RecertificationIam::STATUT_RETIREE;
            $recertification->decide_par_id = $decideur?->id;
            $recertification->decide_le = now();
            $recertification->save();

            MoteurAutorisation::marquerChangement($recertification->user_id);

            return $recertification->fresh();
        });
    }

    public function expurger(RecertificationIam $recertification): RecertificationIam
    {
        return DB::transaction(function () use ($recertification): RecertificationIam {
            $recertification->statut = RecertificationIam::STATUT_EXPIREE;
            $recertification->save();

            MoteurAutorisation::marquerChangement($recertification->user_id);

            return $recertification->fresh();
        });
    }

    public function estEnCours(RecertificationIam $recertification): bool
    {
        return $recertification->statut === RecertificationIam::STATUT_A_FAIRE;
    }

    public function estExigible(RecertificationIam $recertification): bool
    {
        return $recertification->estEnCours()
            && ($recertification->echeance_le === null || $recertification->echeance_le->isPast());
    }

    public function objetsARecertifier(User $acteur, ?string $objetType = null): array
    {
        if (! Schema::hasTable('iam_affectations') && ! Schema::hasTable('iam_habilitations') && ! Schema::hasTable('iam_delegations')) {
            return [];
        }

        $query = RecertificationIam::query()
            ->where('statut', RecertificationIam::STATUT_A_FAIRE);

        if ($objetType !== null) {
            $query->where('objet_type', $objetType);
        }

        $recertifications = $query->get()->all();

        $resultats = [];

        foreach ($recertifications as $rc) {
            $objets = match ($rc->objet_type) {
                AffectationIam::class => AffectationIam::where('user_id', $rc->user_id)->where('profil_id', $rc->objet_id)->get()->all(),
                HabilitationIam::class => HabilitationIam::where('user_id', $rc->user_id)->where('permission_code', $rc->objet_id)->get()->all(),
                DelegationIam::class => DelegationIam::where('delegataire_id', $rc->user_id)->where('permission_code', $rc->objet_id)->where('statut', DelegationIam::STATUT_ACTIVE)->get()->all(),
                default => [],
            };

            foreach ($objets as $objet) {
                $resultats[] = [
                    'recertification' => $rc,
                    'objet' => $objet,
                    'user' => $objet instanceof User ? $objet : null,
                    'echeance' => $rc->echeance_le,
                    'jours_restants' => $rc->echeance_le !== null ? max(0, (int) $rc->echeance_le->diffInDays(now())) : null,
                ];
            }
        }

        return $resultats;
    }
}
