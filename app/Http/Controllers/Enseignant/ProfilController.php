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
     */
    public function update(ProfilEnseignantRequest $request)
    {
        $enseignant = $this->enseignantConnecte($request);
        $enseignant->update($request->validated());

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
