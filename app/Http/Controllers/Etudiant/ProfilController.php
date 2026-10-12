<?php

namespace App\Http\Controllers\Etudiant;

use App\Http\Controllers\Controller;
use App\Http\Requests\MotDePasseEtudiantRequest;
use App\Http\Requests\ProfilEtudiantRequest;
use App\Models\Etudiant;
use Illuminate\Http\Request;

class ProfilController extends Controller
{
    /**
     * Fiche complète du dossier de l'étudiant connecté.
     */
    public function show(Request $request)
    {
        $etudiant = $this->etudiantConnecte($request);

        return view('etudiant.profil', compact('etudiant'));
    }

    /**
     * Formulaire de mise à jour des coordonnées.
     */
    public function edit(Request $request)
    {
        $etudiant = $this->etudiantConnecte($request);

        return view('etudiant.modifier', compact('etudiant'));
    }

    /**
     * Enregistre les coordonnées modifiées (validation stricte ProfilEtudiantRequest).
     */
    public function update(ProfilEtudiantRequest $request)
    {
        $etudiant = $this->etudiantConnecte($request);
        $etudiant->update($request->validated());

        return redirect()->route('etudiant.profil.show')
            ->with('success', 'Vos coordonnées ont été mises à jour avec succès.');
    }

    /**
     * Change le mot de passe après vérification du mot de passe actuel.
     */
    public function updatePassword(MotDePasseEtudiantRequest $request)
    {
        $etudiant = $this->etudiantConnecte($request);
        $etudiant->update(['password' => $request->validated('password')]);

        // Hygiène de session après changement d'identifiant (audit §P3-22) :
        // un nouvel ID invalide toute fixation préalable.
        $request->session()->regenerate();

        return redirect()->route('etudiant.profil.show')
            ->with('success', 'Votre mot de passe a été modifié avec succès.');
    }

    /**
     * Récupère l'étudiant authentifié depuis la session (eager loading de la faculté).
     */
    private function etudiantConnecte(Request $request): Etudiant
    {
        return Etudiant::with('faculte')
            ->findOrFail($request->session()->get('etudiant_auth_id'));
    }
}
