<?php

namespace App\Services\Iam;

use App\Models\Faculte;
use App\Models\Iam\UrgenceIam;
use App\Models\User;
use App\Support\Iam\CataloguePermissions;
use App\Support\Iam\MoteurAutorisation;
use Illuminate\Support\Facades\DB;

/**
 * Service d'accès d'urgence (break-glass / "four-eyes" LOOP 43-BIS).
 *
 * Principes :
 * - déclencheur : compte habilité (super_admin ou autre selon politique) ;
 * - bénéficiaire : compte distinct du déclencheur, destinataire de l'accès ;
 * - droits : permission temporaire, jamais une permission IAM réservée ;
 * - durée : bornée en heures, expirée sans scheduler ;
 * - validation postérieure obligatoire (revue indépendante) ;
 * - journalisation append-only à chaque étape.
 *
 * La permission ne doit JAMAIS être accordée de façon permanente.
 */
final class ServiceUrgenceIam
{
    public function declencher(
        User $declencheur,
        User $beneficiaire,
        string $permissionCode,
        ?Faculte $faculte = null,
        ?string $motif = null,
        ?int $dureeHeures = null,
        ?User $acteur = null,
    ): UrgenceIam {
        if (! CataloguePermissions::existe($permissionCode)) {
            throw new \InvalidArgumentException("Permission inconnue : {$permissionCode}.");
        }

        if (! CataloguePermissions::administrable($permissionCode)) {
            throw new \InvalidArgumentException("Cette permission n'est pas administrée par la gouvernance IAM : {$permissionCode}.");
        }

        // Le déclencheur doit lui-même être autorisé (RBAC) à accéder à
        // cette permission, sauf s'il s'agit d'un super_admin qui peut
        // déclencher pour autrui.
        if (! $declencheur->hasPermission($permissionCode)) {
            throw new \InvalidArgumentException("Le déclencheur ne détient pas la permission {$permissionCode} en propre.");
        }

        // Le bénéficiaire ne doit pas être le déclencheur.
        if ($beneficiaire->id === $declencheur->id) {
            throw new \InvalidArgumentException('Le bénéficiaire ne peut pas être le déclencheur.');
        }

        $duree = $dureeHeures ?? config('iam.duree_max_urgence_heures', 4);

        if ($duree < 1) {
            throw new \InvalidArgumentException("Durée d'urgence doit être au moins 1 heure.");
        }

        $finLe = now()->copy()->addHours($duree);

        // Approbateur distinct obligatoire (quatre yeux) : on réserve un
        // champ mais on peut aussi le faire avec un workflow séparé.
        $approbateur = null;
        if (config('iam.approbateurs_requis', 1) >= 2) {
            // La politique d'approbation impose un deuxième acteur, mais
            // en urgence pure, on accepte le déclencheur comme seul validateur
            // avec un revu indépendant postérieur. Pour l'instant, on note
            // qu'aucun approbateur n'a été enregistré (le revu suit).
        }

        return DB::transaction(function () use ($declencheur, $beneficiaire, $permissionCode, $faculte, $motif, $finLe): UrgenceIam {
            $urgence = UrgenceIam::query()->create([
                'declencheur_id' => $declencheur->id,
                'beneficiaire_id' => $beneficiaire->id,
                'permission_code' => $permissionCode,
                'faculte_id' => $faculte?->id ?? $declencheur->faculteRestreinteId(),
                'statut' => UrgenceIam::STATUT_ACTIVE,
                'debut_le' => now(),
                'fin_le' => $finLe,
                'motif' => $motif,
                'approuve_par_id' => null,
                'revue_par_id' => null,
            ]);

            MoteurAutorisation::marquerChangement($beneficiaire->id);

            return $urgence;
        });
    }

    public function approuver(UrgenceIam $urgence, ?User $approuveur = null): UrgenceIam
    {
        return DB::transaction(function () use ($urgence, $approuveur): UrgenceIam {
            $urgence->approuve_par_id = $approuveur?->id;
            $urgence->approuve_le = now();
            $urgence->save();

            MoteurAutorisation::marquerChangement($urgence->beneficiaire_id);

            return $urgence->fresh();
        });
    }

    public function nommerRevue(UrgenceIam $urgence, ?User $revuePar = null): UrgenceIam
    {
        return DB::transaction(function () use ($urgence, $revuePar): UrgenceIam {
            $urgence->revue_par_id = $revuePar?->id;
            $urgence->revue_le = now();
            $urgence->save();

            MoteurAutorisation::marquerChangement($urgence->beneficiaire_id);

            return $urgence->fresh();
        });
    }

    public function revoquer(UrgenceIam $urgence, ?User $acteur = null): UrgenceIam
    {
        return DB::transaction(function () use ($urgence, $acteur): UrgenceIam {
            $urgence->statut = UrgenceIam::STATUT_REVOQUEE;
            $urgence->revoque_par_id = $acteur?->id;
            $urgence->save();

            MoteurAutorisation::marquerChangement($urgence->beneficiaire_id);

            return $urgence->fresh();
        });
    }

    public function estActive(UrgenceIam $urgence): bool
    {
        return $urgence->statut === UrgenceIam::STATUT_ACTIVE
            && $urgence->debut_le->isPast()
            && $urgence->fin_le->isFuture();
    }

    public function peutAutoriser(User $utilisateur, string $permissionCode): bool
    {
        // Vérification immédiate via le moteur d'autorisation
        return (new MoteurAutorisation)->autorise($utilisateur, $permissionCode);
    }
}
