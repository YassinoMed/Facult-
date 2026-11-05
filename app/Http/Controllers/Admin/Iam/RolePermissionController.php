<?php

namespace App\Http\Controllers\Admin\Iam;

use App\Http\Controllers\Controller;
use App\Models\Iam\DemandeIam;
use App\Models\Iam\ProfilIam;
use App\Services\Iam\ServiceDemandesIam;
use App\Services\Iam\ServiceProfilsIam;
use App\Support\Iam\CataloguePermissions;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Gestion de l'attribution et du retrait des permissions par domaine (LOOP GLOBAL IAM/RBAC).
 *
 * Implémente l'interface hiérarchique avec sélection par cases à cocher,
 * contrôles anti-escalade, protection des rôles système et aiguillage
 * vers le workflow à 4 yeux lorsque des permissions critiques sont ajoutées.
 */
class RolePermissionController extends Controller
{
    public function edit(string $identifiant, Request $request): View
    {
        $this->authorize('roles.manage');

        if (in_array($identifiant, Permissions::ROLES, true)) {
            abort(403, 'Les permissions des rôles système sont définies dans le code et ne peuvent être modifiées.');
        }

        $profil = $this->resoudreProfil($identifiant);
        $this->verifierPerimetre($profil, $request);

        $hierarchie = CataloguePermissions::hierarchie();
        $attribuees = $profil->codesPermissions();

        return view('admin.roles.permissions', [
            'profil' => $profil,
            'hierarchie' => $hierarchie,
            'attribuees' => $attribuees,
            'totalPermissions' => count(CataloguePermissions::toutes()),
        ]);
    }

    public function update(
        string $identifiant,
        Request $request,
        ServiceProfilsIam $profilService,
        ServiceDemandesIam $demandeService
    ): RedirectResponse {
        $this->authorize('roles.manage');

        if (in_array($identifiant, Permissions::ROLES, true)) {
            abort(403, 'Les permissions des rôles système ne sont pas modifiables.');
        }

        $profil = $this->resoudreProfil($identifiant);
        $this->verifierPerimetre($profil, $request);

        if ($profil->statut === ProfilIam::STATUT_ARCHIVE) {
            throw new ConflictHttpException('Un rôle archivé ne peut plus être modifié.');
        }

        $donnees = $request->validate([
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string'],
            'motif' => ['nullable', 'string', 'min:3', 'max:500'],
        ], [
            'permissions.array' => 'La sélection de permissions est invalide.',
            'permissions.*.string' => 'Chaque permission doit être un code texte.',
            'motif.min' => 'Le motif doit comporter au moins :min caractères.',
        ]);

        $permissionsDemandes = array_values(array_unique(array_map('strval', (array) ($donnees['permissions'] ?? []))));
        $motif = trim((string) ($donnees['motif'] ?? '')) ?: 'Mise à jour des permissions du rôle';

        // Détection des permissions critiques ajoutées.
        $anciennes = $profil->codesPermissions();
        $ajouts = array_values(array_diff($permissionsDemandes, $anciennes));
        $contientCritique = false;

        foreach ($ajouts as $code) {
            if (CataloguePermissions::critique($code)) {
                $contientCritique = true;
                break;
            }
        }

        // Une permission critique ajoutée par un acteur non super-admin
        // exige une approbation indépendante : la modification n'est PAS
        // appliquée tant que la demande n'est pas approuvée.
        if ($contientCritique && ! $request->user()?->estSuperAdmin()) {
            $demande = $demandeService->creerDemande(
                type: DemandeIam::TYPE_ACTIVATION_PROFIL,
                initiateur: $request->user(),
                cible: null,
                profil: $profil,
                faculte: $profil->faculte,
                payload: [
                    'permissions' => $permissionsDemandes,
                    'ajoutees' => $ajouts,
                ],
                motif: $motif.' (permissions critiques soumises à validation à quatre yeux)'
            );

            return redirect()
                ->route('admin.roles.show', $profil->id)
                ->with('info', "Des permissions critiques ont été sélectionnées. La modification (#{$demande->reference}) a été soumise au circuit de validation à quatre yeux.");
        }

        $resultat = $profilService->synchroniserPermissions(
            profil: $profil,
            permissionCodes: $permissionsDemandes,
            acteur: $request->user(),
            motif: $motif
        );

        $nbAjouts = count($resultat['ajoutees']);
        $nbRetraits = count($resultat['retirees']);

        return redirect()
            ->route('admin.roles.show', $profil->id)
            ->with('success', "Permissions mises à jour pour « {$profil->nom} » : +{$nbAjouts} ajoutée(s), -{$nbRetraits} retirée(s). Total : {$resultat['total']}.");
    }

    private function resoudreProfil(string $identifiant): ProfilIam
    {
        if (is_numeric($identifiant)) {
            return ProfilIam::where('id', (int) $identifiant)->firstOrFail();
        }

        return ProfilIam::where('code', $identifiant)->firstOrFail();
    }

    private function verifierPerimetre(ProfilIam $profil, Request $request): void
    {
        $restreinte = $request->user()?->faculteRestreinteId();

        if ($restreinte !== null && (int) ($profil->faculte_id ?? 0) !== $restreinte) {
            abort(403, 'Vous ne pouvez administrer que les permissions des rôles de votre faculté.');
        }
    }
}
