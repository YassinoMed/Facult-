<?php

namespace App\Http\Controllers\Enseignant;

use App\Http\Controllers\Controller;
use App\Http\Requests\MotDePasseEnseignantRequest;
use App\Http\Requests\ProfilEnseignantRequest;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Http\Request;

class ProfilController extends Controller
{
    /**
     * Fiche de l'enseignant connecté (données officielles en lecture seule).
     */
    public function show(Request $request)
    {
        $enseignant = $this->enseignantConnecte($request);

        return view('enseignant.profil', compact('enseignant'));
    }

    /**
     * Formulaire de mise à jour des coordonnées autorisées (email, bureau).
     */
    public function edit(Request $request)
    {
        $enseignant = $this->enseignantConnecte($request);

        return view('enseignant.modifier', compact('enseignant'));
    }

    /**
     * Enregistre les coordonnées modifiées (validation stricte
     * ProfilEnseignantRequest : matricule, grade, département, faculté et
     * statut ignorés — jamais modifiables ici).
     *
     * LOOP-SECURITY-REMEDIATION-01 : le changement d'email exige le mot de
     * passe actuel (règle `current_password` de la requête) et l'écriture
     * est limitée à une whitelist de colonnes ; la session est renouvelée
     * lorsque l'identifiant de connexion change.
     */
    public function update(ProfilEnseignantRequest $request)
    {
        $enseignant = $this->enseignantConnecte($request);

        $emailSoumis = strtolower(trim((string) $request->validated('email')));
        $emailChange = $emailSoumis !== strtolower((string) $enseignant->email);

        $enseignant->update($request->safe()->only(['email', 'telephone', 'bureau']));

        if ($emailChange) {
            // Hygiène de session après changement d'identifiant (email) :
            // un nouvel ID invalide toute fixation préalable.
            $request->session()->regenerate();
        }

        return redirect()->route('enseignant.profil.show')
            ->with('success', 'Vos coordonnées ont été mises à jour avec succès.');
    }

    /**
     * Change le mot de passe après vérification du mot de passe actuel.
     */
    public function updatePassword(MotDePasseEnseignantRequest $request)
    {
        $enseignant = $this->enseignantConnecte($request);
        $enseignant->update(['password' => $request->validated('password')]);

        // LOT 1 : le model event a incrémenté la version ; la session
        // courante reçoit la nouvelle valeur, les autres sessions sont
        // invalidées par EnsureTeacher.
        $request->session()->put('enseignant_mdp_version', (int) $enseignant->mot_de_passe_version);

        // Hygiène de session après changement d'identifiant :
        // un nouvel ID invalide toute fixation préalable.
        $request->session()->regenerate();

        return redirect()->route('enseignant.profil.show')
            ->with('success', 'Votre mot de passe a été modifié avec succès.');
    }

    /**
     * Récupère l'enseignant authentifié depuis la session (jamais depuis l'URL).
     */
    private function enseignantConnecte(Request $request): Teacher
    {
        return Teacher::with(['grade', 'departement.faculte', 'specialite'])
            ->findOrFail($request->session()->get('enseignant_auth_id'));
    }
}
