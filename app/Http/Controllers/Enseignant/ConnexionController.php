<?php

namespace App\Http\Controllers\Enseignant;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConnexionEnseignantRequest;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class ConnexionController extends Controller
{
    /**
     * Affiche le formulaire de connexion (redirige vers le tableau de bord si déjà connecté).
     */
    public function show()
    {
        if (session()->has('enseignant_auth_id')) {
            return redirect()->route('enseignant.dashboard');
        }

        return view('enseignant.connexion');
    }

    /**
     * Authentifie l'enseignant par email professionnel ou matricule + mot
     * de passe (haché bcrypt). Message d'erreur générique pour éviter
     * l'énumération de comptes ; compte inactif ou sans mot de passe
     * (non activé par l'administration) refusé sans distinction.
     */
    public function login(ConnexionEnseignantRequest $request)
    {
        $identifiant = $request->input('identifiant');

        $enseignant = Teacher::where('email', strtolower($identifiant))
            ->orWhereRaw('UPPER(matricule) = ?', [mb_strtoupper($identifiant)])
            ->first();

        // Temps constant anti-énumération : même si aucun compte ne
        // correspond, on exécute un bcrypt valide pour que la durée de
        // réponse ne révèle pas l'existence du compte.
        $empreinte = $enseignant && ! empty($enseignant->password)
            ? $enseignant->password
            : '$2y$12$k1pW5dHhc72vFo3SF3rAOeLUrgzwefl91XUMRfRyMNa8FoPzyuZIi';

        if (! $enseignant || ! $enseignant->actif || ! Hash::check($request->input('password'), $empreinte)) {
            return back()->withErrors([
                'identifiant' => 'Identifiants incorrects. Vérifiez votre email/matricule et votre mot de passe.',
            ])->onlyInput('identifiant');
        }

        // Régénère l'ID de session : protection contre la fixation de session
        $request->session()->regenerate();

        $request->session()->put([
            'enseignant_auth_id' => $enseignant->id,
            'enseignant_auth_prenom' => $enseignant->prenom,
            'enseignant_auth_nom' => $enseignant->nom,
        ]);

        return redirect()->intended(route('enseignant.dashboard'))
            ->with('success', "Bienvenue {$enseignant->prenom} ! Voici votre espace enseignant.");
    }

    /**
     * Déconnecte l'enseignant et invalide complètement la session.
     */
    public function logout(Request $request)
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('enseignant.connexion.show')
            ->with('success', 'Vous avez été déconnecté de votre espace enseignant en toute sécurité.');
    }
}
