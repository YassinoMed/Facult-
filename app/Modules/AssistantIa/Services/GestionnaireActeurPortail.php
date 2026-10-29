<?php

namespace App\Modules\AssistantIa\Services;

use App\Models\Etudiant;
use App\Models\User;
use App\Modules\AssistantIa\DTO\ActeurPortail;
use App\Modules\AssistantIa\Exceptions\SecuriteIaException;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Http\Request;

/**
 * Service de résolution et de validation sécurisée de l'acteur multiportail.
 * Aucun IDOR possible : l'identité provient exclusivement du guard web ou des sessions d'authentification vérifiées.
 */
class GestionnaireActeurPortail
{
    /**
     * Résout l'acteur courant à partir de la session ou de la requête, ou renvoie null si anonyme.
     */
    public function resoudreActeur(Request $request): ?ActeurPortail
    {
        // 1. Guard web back-office (User)
        if ($request->user() instanceof User) {
            $user = $request->user();
            if ($user->estActif()) {
                return ActeurPortail::depuisAdmin($user);
            }

            return null;
        }

        // 2. Session Enseignant vérifiée
        if ($request->session()->has('enseignant_auth_id')) {
            $teacherId = (int) $request->session()->get('enseignant_auth_id');
            $teacher = Teacher::find($teacherId);
            if ($teacher && ($teacher->actif ?? true)) {
                return ActeurPortail::depuisEnseignant($teacher);
            }

            return null;
        }

        // 3. Session Étudiant vérifiée
        if ($request->session()->has('etudiant_auth_id')) {
            $etudiantId = (int) $request->session()->get('etudiant_auth_id');
            $etudiant = Etudiant::find($etudiantId);
            if ($etudiant && in_array($etudiant->statut, ['valide', 'en_attente'], true)) {
                return ActeurPortail::depuisEtudiant($etudiant);
            }

            return null;
        }

        return null;
    }

    /**
     * Résout l'acteur et exige une authentification active avec permission Assistant IA, sinon lève une exception.
     *
     * @throws SecuriteIaException
     */
    public function resoudreActeurAutorise(Request $request): ActeurPortail
    {
        $acteur = $this->resoudreActeur($request);

        if ($acteur === null) {
            throw new SecuriteIaException('Authentification requise pour accéder à l\'assistant UniRegistre AI.');
        }

        if (! $acteur->peutAccederAssistantIa()) {
            throw new SecuriteIaException('Accès refusé : votre profil ou statut ne vous autorise pas à utiliser l\'assistant IA.');
        }

        return $acteur;
    }
}
