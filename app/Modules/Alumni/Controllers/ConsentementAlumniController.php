<?php

namespace App\Modules\Alumni\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Alumni\Models\Diplome;
use App\Modules\Alumni\Models\ProfilAlumni;
use App\Modules\Alumni\Services\ServiceAlumni;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Consentement alumni par LIEN SIGNÉ.
 *
 * Aucun compte n'est créé : le lien temporaire signé (envoyé à l'adresse
 * du diplômé) constitue le facteur de possession. Aucun dossier
 * académique n'est affiché ou transmis.
 */
final class ConsentementAlumniController extends Controller
{
    public function __construct(private readonly ServiceAlumni $alumni) {}

    public function show(Request $requete, Diplome $diplome): View
    {
        $diplome->load(['etudiant:id,nom,prenom', 'faculte:id,nom', 'programme:id,nom', 'typeDiplome:id,nom']);

        $profil = $this->alumni->profilPourDiplome($diplome);

        // Les formulaires POST ont leur propre URL signée, valable deux
        // heures : la signature du lien initial ne couvre pas une autre route.
        $expiration = now()->addHours(2);

        return view('modules.alumni.consentement', [
            'diplome' => $diplome,
            'profil' => $profil,
            'actionConsentement' => URL::temporarySignedRoute(
                'alumni.consentement.store',
                $expiration,
                ['diplome' => $diplome->id],
            ),
            'actionRetrait' => URL::temporarySignedRoute(
                'alumni.consentement.retrait',
                $expiration,
                ['diplome' => $diplome->id],
            ),
            'visibilites' => [
                ProfilAlumni::VISIBILITE_AGREGATS => 'Agrégats uniquement — je n’apparais pas dans l’annuaire.',
                ProfilAlumni::VISIBILITE_ANNUAIRE => 'Annuaire — nom, parcours et activité professionnelle.',
                ProfilAlumni::VISIBILITE_ANNUAIRE_CONTACT => 'Annuaire avec contact — e-mail et/ou téléphone visibles.',
            ],
        ]);
    }

    public function store(Request $requete, Diplome $diplome): RedirectResponse
    {
        $valides = $requete->validate([
            'consentement' => ['accepted'],
            'nom_affichage' => ['required', 'string', 'max:150'],
            'email_contact' => ['nullable', 'email', 'max:190'],
            'telephone_contact' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+().\s-]{6,30}$/'],
            'poste_actuel' => ['nullable', 'string', 'max:150'],
            'employeur' => ['nullable', 'string', 'max:150'],
            'secteur' => ['nullable', 'string', 'max:80'],
            'ville' => ['nullable', 'string', 'max:100'],
            'pays' => ['nullable', 'string', 'max:80'],
            'linkedin' => ['nullable', 'url:http,https', 'max:255'],
            'visibilite' => ['required', Rule::in(ProfilAlumni::VISIBILITES)],
            'cle_idempotence' => ['required', 'uuid'],
        ]);

        try {
            $this->alumni->consentir($diplome, $valides);
        } catch (DomainException $e) {
            return redirect()
                ->to($requete->fullUrl())
                ->withErrors(['consentement' => $e->getMessage()])
                ->withInput();
        }

        return redirect()
            ->to($requete->fullUrl())
            ->with('statut', 'consentement_enregistre');
    }

    public function retirer(Request $requete, Diplome $diplome): RedirectResponse
    {
        $valides = $requete->validate([
            'cle_idempotence' => ['required', 'uuid'],
        ]);

        $profil = $this->alumni->profilPourDiplome($diplome);

        if ($profil === null) {
            return redirect()
                ->to(URL::temporarySignedRoute(
                    'alumni.consentement.show',
                    now()->addHours(2),
                    ['diplome' => $diplome->id],
                ))
                ->withErrors(['consentement' => 'Aucun profil alumni n’existe pour ce diplôme.']);
        }

        try {
            $this->alumni->retirer($profil, $valides['cle_idempotence']);
        } catch (DomainException $e) {
            return redirect()
                ->to(URL::temporarySignedRoute(
                    'alumni.consentement.show',
                    now()->addHours(2),
                    ['diplome' => $diplome->id],
                ))
                ->withErrors(['consentement' => $e->getMessage()]);
        }

        return redirect()
            ->to(URL::temporarySignedRoute(
                'alumni.consentement.show',
                now()->addHours(2),
                ['diplome' => $diplome->id],
            ))
            ->with('statut', 'consentement_retire');
    }
}
