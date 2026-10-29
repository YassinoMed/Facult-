<?php

namespace Tests\Feature\Security;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Modules\Referentiel\Models\Departement;
use App\Modules\Schedule\Models\Teacher;
use App\Notifications\CodeVerificationDossierExistant;
use App\Services\PeriodeInscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * LOOP-SECURITY-REMEDIATION-01 — changements d'identité sécurisés :
 * - la vérification OTP du parcours « dossier existant » RÉGÉNÈRE la
 *   session (anti-fixation) tout en conservant l'autorisation ;
 * - l'email (identifiant de connexion) n'est pas modifiable en direct
 *   depuis l'espace étudiant : parcours vérifié à double code exigé ;
 * - la date de naissance (facteur d'identité du parcours existant) n'est
 *   plus modifiable depuis l'espace étudiant ;
 * - côté enseignant, le changement d'email exige le mot de passe actuel.
 */
class ProfilEtIdentiteSecurisesTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences',
            'code' => 'FS',
            'description' => 'Test',
            'active' => true,
        ]);
    }

    private function creerEtudiant(array $surcharge = []): Etudiant
    {
        $etudiant = Etudiant::create(array_merge([
            'cin' => '87654321',
            'nom' => 'ALAMI',
            'prenom' => 'Sara',
            'email' => 'sara.alami@example.com',
            'telephone' => '+212612345678',
            'date_naissance' => '2003-05-17',
            'sexe' => 'F',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L2',
            'annee_academique' => '2025-2026',
            'adresse' => '45 Avenue Hassan II',
            'ville' => 'Rabat',
            'code_postal' => '10000',
            'annee_bac' => '2022',
            'mention_bac' => 'Bien',
            'password' => 'MotdepasseSecret123',
        ], $surcharge));
        $etudiant->definirStatut('en_attente');

        return $etudiant;
    }

    private function creerEnseignant(array $surcharge = []): Teacher
    {
        $departement = Departement::create([
            'code' => 'DEP-'.uniqid(), 'nom' => 'Département', 'faculte_id' => $this->faculte->id,
        ]);

        return Teacher::create(array_merge([
            'nom' => 'Nom', 'prenom' => 'Prenom',
            'email' => 'ens.profil@univ.test',
            'matricule' => 'ENS-PROFIL',
            'departement_id' => $departement->id,
            'actif' => true, 'statut' => 'actif',
            'password' => 'Motdepasse@123',
        ], $surcharge));
    }

    /* ── Espace étudiant : identité verrouillée ─────────────────── */

    public function test_etudiant_ne_peut_pas_changer_son_email_depuis_le_profil(): void
    {
        $etudiant = $this->creerEtudiant();

        $reponse = $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->patch(route('etudiant.profil.update'), [
                'email' => 'pirate@evil.test',
                'telephone' => '+212612345678',
                'adresse' => '45 Avenue Hassan II',
                'ville' => 'Rabat',
                'code_postal' => '10000',
            ]);

        $reponse->assertSessionHasErrors('email');
        $this->assertSame('sara.alami@example.com', $etudiant->refresh()->email);
    }

    public function test_etudiant_modifie_ses_coordonnees_sans_toucher_a_son_identite(): void
    {
        $etudiant = $this->creerEtudiant();

        $reponse = $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->patch(route('etudiant.profil.update'), [
                'email' => 'sara.alami@example.com',
                'telephone' => '+212699999999',
                'adresse' => '12 Rue Nouvelle',
                'ville' => 'Casablanca',
                'code_postal' => '20000',
                // Champ d'identité injecté : doit être totalement ignoré.
                'date_naissance' => '1990-01-01',
            ]);

        $reponse->assertRedirect(route('etudiant.profil.show'));
        $reponse->assertSessionHasNoErrors();

        $etudiant->refresh();
        $this->assertSame('+212699999999', $etudiant->telephone);
        $this->assertSame('12 Rue Nouvelle', $etudiant->adresse);
        $this->assertSame('Casablanca', $etudiant->ville);
        $this->assertSame('20000', $etudiant->code_postal);
        $this->assertSame('sara.alami@example.com', $etudiant->email);
        $this->assertSame('2003-05-17', $etudiant->date_naissance->toDateString());
    }

    /* ── Espace enseignant : email = identifiant, mot de passe exigé ── */

    public function test_enseignant_ne_peut_pas_changer_d_email_sans_mot_de_passe(): void
    {
        $enseignant = $this->creerEnseignant();

        $reponse = $this->withSession(['enseignant_auth_id' => $enseignant->id])
            ->patch(route('enseignant.profil.update'), [
                'email' => 'nouveau.email@univ.test',
                'telephone' => '+212612345678',
                'bureau' => 'Bâtiment B',
            ]);

        $reponse->assertSessionHasErrors('current_password');
        $this->assertSame('ens.profil@univ.test', $enseignant->refresh()->email);
    }

    public function test_enseignant_ne_peut_pas_changer_d_email_avec_un_mauvais_mot_de_passe(): void
    {
        $enseignant = $this->creerEnseignant();

        $reponse = $this->withSession(['enseignant_auth_id' => $enseignant->id])
            ->patch(route('enseignant.profil.update'), [
                'email' => 'nouveau.email@univ.test',
                'current_password' => 'MauvaisMotDePasse!',
                'telephone' => '+212612345678',
                'bureau' => 'Bâtiment B',
            ]);

        $reponse->assertSessionHasErrors('current_password');
        $this->assertSame('ens.profil@univ.test', $enseignant->refresh()->email);
    }

    public function test_enseignant_change_d_email_avec_son_mot_de_passe_actuel(): void
    {
        $enseignant = $this->creerEnseignant();

        $reponse = $this->withSession(['enseignant_auth_id' => $enseignant->id])
            ->patch(route('enseignant.profil.update'), [
                'email' => 'nouveau.email@univ.test',
                'current_password' => 'Motdepasse@123',
                'telephone' => '+212612345678',
                'bureau' => 'Bâtiment B',
            ]);

        $reponse->assertRedirect(route('enseignant.profil.show'));
        $reponse->assertSessionHasNoErrors();

        $enseignant->refresh();
        $this->assertSame('nouveau.email@univ.test', $enseignant->email);
        $this->assertSame('Bâtiment B', $enseignant->bureau);
    }

    public function test_enseignant_modifie_ses_coordonnees_sans_mot_de_passe_si_email_inchange(): void
    {
        $enseignant = $this->creerEnseignant();

        $reponse = $this->withSession(['enseignant_auth_id' => $enseignant->id])
            ->patch(route('enseignant.profil.update'), [
                'email' => 'ens.profil@univ.test',
                'telephone' => '+212699999999',
                'bureau' => 'Bâtiment C',
            ]);

        $reponse->assertRedirect(route('enseignant.profil.show'));

        $enseignant->refresh();
        $this->assertSame('+212699999999', $enseignant->telephone);
        $this->assertSame('Bâtiment C', $enseignant->bureau);
    }

    /* ── Parcours dossier existant : la vérification OTP régénère la session ── */

    public function test_verification_otp_dossier_existant_regenere_la_session(): void
    {
        $annee = AnneeUniversitaire::create([
            'libelle' => '2025-2026', 'date_debut' => '2025-09-01', 'date_fin' => '2026-06-30', 'active' => true,
        ]);
        app(PeriodeInscriptionService::class)->creerNormale([
            'annee_universitaire_id' => $annee->id,
            'opened_at' => '2020-01-01 00:00', 'closed_at' => '2030-12-31 23:59',
        ], null);

        $etudiant = $this->creerEtudiant();

        Notification::fake();

        $this->post(route('inscription.existant.recherche'), [
            'cin' => $etudiant->cin,
            'date_naissance' => $etudiant->date_naissance->toDateString(),
        ])->assertRedirect(route('inscription.existant.accueil'));

        $code = Notification::sent($etudiant, CodeVerificationDossierExistant::class)->first()->code;

        $jetonAvant = session()->token();

        $this->post(route('inscription.existant.verification'), ['code' => $code])
            ->assertRedirect(route('inscription.existant.formulaire'));

        // Anti-fixation : le jeton de session (émis par regenerate()) a
        // changé — un identifiant fixé par un tiers ne peut pas hériter
        // de l'autorisation obtenue.
        $this->assertNotSame($jetonAvant, session()->token());

        // L'autorisation (données de session) survit à la régénération :
        // le dossier reste consultable.
        $this->get(route('inscription.existant.formulaire'))->assertOk();
    }
}
