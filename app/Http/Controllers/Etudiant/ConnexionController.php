<?php

namespace App\Http\Controllers\Etudiant;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConnexionEtudiantRequest;
use App\Models\Etudiant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class ConnexionController extends Controller
{
    /**
     * Affiche le formulaire de connexion (redirige vers le profil si déjà connecté).
     */
    public function show()
    {
        if (session()->has('etudiant_auth_id')) {
            return redirect()->route('etudiant.profil.show');
        }

        return view('etudiant.connexion');
    }

    /**
     * Authentifie l'étudiant par email ou CIN + mot de passe (haché bcrypt).
     * Message d'erreur générique pour éviter l'énumération de comptes.
     */
    public function login(ConnexionEtudiantRequest $request)
    {
        $identifiant = $request->input('identifiant');

        $etudiant = Etudiant::where('email', strtolower($identifiant))
            ->orWhere('cin', strtoupper($identifiant))
            ->first();

        // Temps constant anti-énumération (audit §P3-22) : même si aucun
        // compte ne correspond ou si le compte n'a pas de mot de passe,
        // on exécute un bcrypt valide pour que la durée de réponse ne révèle pas l'existence du compte.
        $aUnMotDePasse = $etudiant && ! empty($etudiant->password);
        $empreinte = $aUnMotDePasse
            ? $etudiant->password
            : '$2y$12$k1pW5dHhc72vFo3SF3rAOeLUrgzwefl91XUMRfRyMNa8FoPzyuZIi';

        $motDePasseValide = Hash::check($request->input('password'), $empreinte);

        if (! $etudiant || ! $aUnMotDePasse || ! $motDePasseValide) {
            return back()->withErrors([
                'identifiant' => 'Identifiants incorrects. Vérifiez votre email/CIN et votre mot de passe.',
            ])->onlyInput('identifiant');
        }

        // Régénère l'ID de session : protection contre la fixation de session
        $request->session()->regenerate();

        $request->session()->put([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_auth_prenom' => $etudiant->prenom,
            'etudiant_auth_nom' => $etudiant->nom,
            // AUTH-01 (correctif 13/10) : version du mot de passe —
            // re-vérifiée par EnsureStudent à chaque requête.
            'etudiant_mdp_version' => (int) $etudiant->mot_de_passe_version,
        ]);

        return redirect()->intended(route('etudiant.profil.show'))
            ->with('success', "Bienvenue {$etudiant->prenom} ! Voici votre dossier d'inscription.");
    }

    /**
     * Déconnecte l'étudiant et invalide complètement la session.
     */
    public function logout(Request $request)
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('etudiant.connexion.show')
            ->with('success', 'Vous avez été déconnecté de votre espace étudiant en toute sécurité.');
    }
}
