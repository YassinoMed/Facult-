<?php

namespace Tests\Feature;

use App\Models\Faculte;
use App\Modules\Referentiel\Models\Departement;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Espace Enseignant : authentification, profil, isolation.
 * Miroir d'EspaceEtudiantTest — session `enseignant_auth_id` + ownership
 * stricte, jamais de compte back-office.
 */
class EspaceEnseignantTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'active' => true]);
    }

    private function creerEnseignant(array $surcharge = []): Teacher
    {
        $departement = Departement::create([
            'code' => 'DEP-ENS-'.uniqid(), 'nom' => 'Département', 'faculte_id' => $this->faculte->id,
        ]);

        return Teacher::create(array_merge([
            'nom' => 'Nom', 'prenom' => 'Prenom',
            'email' => 'ens.'.uniqid().'@univ.test',
            'matricule' => 'ENS-'.uniqid(),
            'departement_id' => $departement->id,
            'actif' => true, 'statut' => 'actif',
            'password' => 'Motdepasse@123',
        ], $surcharge));
    }

    public function test_connexion_valide_email_et_matricule(): void
    {
        $enseignant = $this->creerEnseignant();

        $this->post(route('enseignant.connexion.login'), [
            'identifiant' => $enseignant->email, 'password' => 'Motdepasse@123',
        ])->assertRedirect(route('enseignant.dashboard'));
        $this->assertSame($enseignant->id, session('enseignant_auth_id'));

        $this->post(route('enseignant.connexion.logout'));
        $this->post(route('enseignant.connexion.login'), [
            'identifiant' => strtolower($enseignant->matricule), 'password' => 'Motdepasse@123',
        ])->assertRedirect(route('enseignant.dashboard'));
    }

    public function test_connexion_refusee_generique(): void
    {
        $this->creerEnseignant(['email' => 'vrai@univ.test']);

        // Mot de passe erroné : message générique, sans distinction.
        $this->post(route('enseignant.connexion.login'), [
            'identifiant' => 'vrai@univ.test', 'password' => 'Mauvais@123',
        ])->assertSessionHasErrors(['identifiant']);
        $this->assertFalse(session()->has('enseignant_auth_id'));

        // Compte inexistant : même réponse (anti-énumération).
        $this->post(route('enseignant.connexion.login'), [
            'identifiant' => 'inconnu@univ.test', 'password' => 'Motdepasse@123',
        ])->assertSessionHasErrors(['identifiant']);
    }

    public function test_connexion_refusee_compte_inactif_ou_non_active(): void
    {
        $inactif = $this->creerEnseignant(['actif' => false]);
        $this->post(route('enseignant.connexion.login'), [
            'identifiant' => $inactif->email, 'password' => 'Motdepasse@123',
        ])->assertSessionHasErrors(['identifiant']);

        $sansMotDePasse = $this->creerEnseignant();
        $sansMotDePasse->forceFill(['password' => null])->save();
        $this->assertNull($sansMotDePasse->fresh()->password);
        $this->post(route('enseignant.connexion.login'), [
            'identifiant' => $sansMotDePasse->email, 'password' => 'Motdepasse@123',
        ])->assertSessionHasErrors(['identifiant']);
    }

    public function test_garde_redirige_sans_session(): void
    {
        $this->get(route('enseignant.dashboard'))->assertRedirect(route('enseignant.connexion.show'));
        $this->get(route('enseignant.profil.show'))->assertRedirect(route('enseignant.connexion.show'));
    }

    public function test_deconnexion_invalide_session(): void
    {
        $enseignant = $this->creerEnseignant();
        $this->withSession(['enseignant_auth_id' => $enseignant->id])
            ->post(route('enseignant.connexion.logout'))
            ->assertRedirect(route('enseignant.connexion.show'));
        $this->assertFalse(session()->has('enseignant_auth_id'));
        $this->get(route('enseignant.dashboard'))->assertRedirect(route('enseignant.connexion.show'));
    }

    public function test_profil_affiche_donnees_officielles_lecture_seule(): void
    {
        $enseignant = $this->creerEnseignant();

        $this->withSession(['enseignant_auth_id' => $enseignant->id])
            ->get(route('enseignant.profil.show'))
            ->assertOk()
            ->assertSee($enseignant->nom)
            ->assertSee($enseignant->matricule);
    }

    public function test_profil_modifie_coordonnees_autorisees_uniquement(): void
    {
        $enseignant = $this->creerEnseignant();

        $this->withSession(['enseignant_auth_id' => $enseignant->id])
            ->patch(route('enseignant.profil.update'), [
                'email' => 'nouveau@univ.test', 'bureau' => 'B12',
                // Changement d'email = identifiant de connexion : le mot de
                // passe actuel est exigé (LOOP-SECURITY-REMEDIATION-01).
                'current_password' => 'Motdepasse@123',
                'matricule' => 'PIRATE', 'statut' => 'inactif', 'role' => 'super_admin',
                'faculte_id' => 9999, 'departement_id' => 9999,
            ])
            ->assertRedirect(route('enseignant.profil.show'));

        $frais = $enseignant->fresh();
        $this->assertSame('nouveau@univ.test', $frais->email);
        $this->assertSame('B12', $frais->bureau);
        // Champs officiels ignorés (jamais mass-assignés ici).
        $this->assertSame($enseignant->matricule, $frais->matricule);
        $this->assertSame('actif', $frais->statut);
        $this->assertSame($enseignant->departement_id, $frais->departement_id);
    }

    public function test_profil_email_unique(): void
    {
        $this->creerEnseignant(['email' => 'pris@univ.test']);
        $enseignant = $this->creerEnseignant();

        $this->withSession(['enseignant_auth_id' => $enseignant->id])
            ->patch(route('enseignant.profil.update'), ['email' => 'pris@univ.test', 'bureau' => null])
            ->assertSessionHasErrors(['email']);
    }

    public function test_mot_de_passe_change_avec_verification(): void
    {
        $enseignant = $this->creerEnseignant();

        // Actuel erroné refusé.
        $this->withSession(['enseignant_auth_id' => $enseignant->id])
            ->patch(route('enseignant.profil.password'), [
                'current_password' => 'Mauvais@123',
                'password' => 'Nouveau@12345', 'password_confirmation' => 'Nouveau@12345',
            ])
            ->assertSessionHasErrors(['current_password']);

        // Changement valide puis reconnexion avec le nouveau.
        $this->withSession(['enseignant_auth_id' => $enseignant->id])
            ->patch(route('enseignant.profil.password'), [
                'current_password' => 'Motdepasse@123',
                'password' => 'Nouveau@12345', 'password_confirmation' => 'Nouveau@12345',
            ])
            ->assertRedirect(route('enseignant.profil.show'));

        $this->post(route('enseignant.connexion.logout'));
        $this->post(route('enseignant.connexion.login'), [
            'identifiant' => $enseignant->email, 'password' => 'Nouveau@12345',
        ])->assertRedirect(route('enseignant.dashboard'));
    }

    public function test_separation_profils_enseignant_et_back_office(): void
    {
        $enseignant = $this->creerEnseignant();

        // Un enseignant connecté n'accède pas au back-office.
        $this->withSession(['enseignant_auth_id' => $enseignant->id])
            ->get(route('admin.dashboard'))
            ->assertRedirect(route('admin.connexion.show'));
    }
}
