<?php

namespace App\Http\Controllers\Etudiant;

use App\Http\Controllers\Controller;
use App\Http\Requests\MotDePasseEtudiantRequest;
use App\Http\Requests\ProfilEtudiantRequest;
use App\Models\Etudiant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
     *
     * LOOP-SECURITY-REMEDIATION-01 :
     * - l'email est un IDENTIFIANT de connexion ET le canal de secours du
     *   dossier : il n'est JAMAIS modifiable en direct depuis l'espace
     *   étudiant. Tout changement passe par le parcours public vérifié
     *   (code à l'ancienne PUIS à la nouvelle adresse) — un simple champ
     *   de formulaire ne suffit pas à détourner un compte ;
     * - la date de naissance (facteur d'identité du parcours « dossier
     *   existant ») n'est plus validée ni enregistrée ici : seul
     *   l'administration peut la rectifier.
     */
    public function update(ProfilEtudiantRequest $request)
    {
        $etudiant = $this->etudiantConnecte($request);

        $emailSoumis = strtolower(trim((string) $request->validated('email')));

        if ($emailSoumis !== strtolower((string) $etudiant->email)) {
            return back()
                ->withInput($request->except('email'))
                ->withErrors([
                    'email' => 'Le changement d\'adresse email doit être confirmé par un code envoyé à votre ancienne adresse, puis à la nouvelle. '
                        .'Utilisez le parcours « Modifier mon adresse email » depuis la page de récupération de dossier ; '
                        .'en cas de difficulté, contactez le service de la scolarité.',
                ]);
        }

        // Whitelist explicite : jamais $request->all(), jamais la date de
        // naissance ni un identifiant (CIN, n° inscription, faculté…).
        $etudiant->update($request->safe()->only([
            'email',
            'telephone',
            'adresse',
            'ville',
            'code_postal',
        ]));

        return redirect()->route('etudiant.profil.show')
            ->with('success', 'Vos coordonnées ont été mises à jour avec succès.');
    }

    /**
     * Change le mot de passe après vérification du mot de passe actuel.
     */
    public function updatePassword(MotDePasseEtudiantRequest $request)
    {
        $etudiant = $this->etudiantConnecte($request);

        // LOT 1 : mot de passe et version dans la même transaction. Une
        // session ouverte avec l'ancienne version est invalidée par
        // EnsureStudent dès la requête suivante.
        DB::transaction(function () use ($etudiant, $request): void {
            $etudiant->update(['password' => $request->validated('password')]);
            $etudiant->increment('mot_de_passe_version');
        });

        $request->session()->put('etudiant_mdp_version', (int) $etudiant->fresh()->mot_de_passe_version);

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
