<?php

namespace Tests\Feature;

use App\Models\Etudiant;
use App\Models\Faculte;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EspaceEtudiantTest extends TestCase
{
    use RefreshDatabase;

    protected Faculte $faculte;

    protected Etudiant $etudiant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences et Techniques',
            'code' => 'FST',
            'description' => 'Sciences et technologies',
            'active' => true,
        ]);

        $this->etudiant = Etudiant::create(['cin' => 'BK123456',
            'nom' => 'Alami',
            'prenom' => 'Mehdi',
            'email' => 'mehdi.alami@example.com',
            'telephone' => '+212600000000',
            'date_naissance' => '2004-03-15',
            'sexe' => 'M',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2025-2026',
            'adresse' => '12 Rue de la Liberté',
            'ville' => 'Casablanca',
            'code_postal' => '20000',
            'annee_bac' => '2024',
            'mention_bac' => 'Très Bien',
            // haché via le cast "hashed"
            'password' => 'motdepasse1',
        ]);
        $this->etudiant->forceFill(['num_inscription' => 'INS-2026-00001'])->save();
        $this->etudiant->definirStatut('en_attente');
    }

    public function test_page_connexion_accessible(): void
    {
        $response = $this->get(route('etudiant.connexion.show'));

        $response->assertStatus(200);
        $response->assertSee('Espace Étudiant');
    }

    public function test_connexion_redirige_vers_profil_si_deja_connecte(): void
    {
        $this->post(route('etudiant.connexion.login'), [
            'identifiant' => 'mehdi.alami@example.com',
            'password' => 'motdepasse1',
        ]);

        $this->get(route('etudiant.connexion.show'))
            ->assertRedirect(route('etudiant.profil.show'));
    }

    public function test_connexion_reussie_avec_email(): void
    {
        $response = $this->post(route('etudiant.connexion.login'), [
            'identifiant' => 'mehdi.alami@example.com',
            'password' => 'motdepasse1',
        ]);

        $response->assertRedirect(route('etudiant.profil.show'));
        $response->assertSessionHas('etudiant_auth_id', $this->etudiant->id);
        $response->assertSessionHas('success');
    }

    public function test_connexion_reussie_avec_cin_normalise(): void
    {
        $response = $this->post(route('etudiant.connexion.login'), [
            'identifiant' => 'bk123456', // minuscules → normalisé en majuscules
            'password' => 'motdepasse1',
        ]);

        $response->assertRedirect(route('etudiant.profil.show'));
        $response->assertSessionHas('etudiant_auth_id', $this->etudiant->id);
    }

    public function test_connexion_echoue_avec_mauvais_mot_de_passe(): void
    {
        $response = $this->post(route('etudiant.connexion.login'), [
            'identifiant' => 'mehdi.alami@example.com',
            'password' => 'mauvaismotdepasse',
        ]);

        $response->assertSessionHasErrors(['identifiant']);
        $this->assertNull(session('etudiant_auth_id'));
    }

    public function test_connexion_echoue_avec_identifiant_inconnu(): void
    {
        $response = $this->post(route('etudiant.connexion.login'), [
            'identifiant' => 'inconnu@example.com',
            'password' => 'motdepasse1',
        ]);

        $response->assertSessionHasErrors(['identifiant']);
        $this->assertNull(session('etudiant_auth_id'));
    }

    public function test_profil_protege_sans_authentification(): void
    {
        $this->get(route('etudiant.profil.show'))
            ->assertRedirect(route('etudiant.connexion.show'));

        $this->get(route('etudiant.profil.edit'))
            ->assertRedirect(route('etudiant.connexion.show'));
    }

    public function test_profil_affiche_les_informations_de_l_etudiant(): void
    {
        $this->post(route('etudiant.connexion.login'), [
            'identifiant' => 'mehdi.alami@example.com',
            'password' => 'motdepasse1',
        ]);

        $response = $this->get(route('etudiant.profil.show'));

        $response->assertStatus(200);
        $response->assertSee('Mehdi Alami');
        $response->assertSee('BK123456');
        $response->assertSee('INS-2026-00001');
        $response->assertSee('Faculté des Sciences et Techniques');
        $response->assertSee('En attente');
    }

    public function test_mise_a_jour_des_coordonnees_reussie(): void
    {
        $this->post(route('etudiant.connexion.login'), [
            'identifiant' => 'mehdi.alami@example.com',
            'password' => 'motdepasse1',
        ]);

        $response = $this->patch(route('etudiant.profil.update'), [
            'email' => 'nouvel.email@example.com',
            'telephone' => '+212611111111',
            'date_naissance' => '2004-05-20',
            'adresse' => '45 Avenue Hassan II',
            'ville' => 'Rabat',
            'code_postal' => '10000',
        ]);

        $response->assertRedirect(route('etudiant.profil.show'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('etudiants', [
            'id' => $this->etudiant->id,
            'email' => 'nouvel.email@example.com',
            'telephone' => '+212611111111',
            'ville' => 'Rabat',
        ]);
    }

    public function test_mise_a_jour_refuse_l_email_d_un_autre_etudiant(): void
    {
        $etudiantCree = Etudiant::create(['cin' => 'OTHER123',
            'nom' => 'Autre',
            'prenom' => 'Etudiante',
            'email' => 'autre@example.com',
            'sexe' => 'F',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L2',
            'annee_academique' => '2025-2026',
            'adresse' => '5 Rue Test',
            'ville' => 'Fès',
            'code_postal' => '30000',
            'annee_bac' => '2023',
            'password' => 'motdepasse1', ]);
        $etudiantCree->forceFill(['num_inscription' => 'INS-2026-00002'])->save();

        $this->post(route('etudiant.connexion.login'), [
            'identifiant' => 'mehdi.alami@example.com',
            'password' => 'motdepasse1',
        ]);

        $response = $this->patch(route('etudiant.profil.update'), [
            'email' => 'autre@example.com',
            'telephone' => '+212600000000',
            'date_naissance' => '2004-03-15',
            'adresse' => '12 Rue de la Liberté',
            'ville' => 'Casablanca',
            'code_postal' => '20000',
        ]);

        $response->assertSessionHasErrors(['email']);
        $this->assertDatabaseHas('etudiants', [
            'id' => $this->etudiant->id,
            'email' => 'mehdi.alami@example.com',
        ]);
    }

    public function test_mise_a_jour_ne_peut_pas_toucher_aux_identifiants_proteges(): void
    {
        $this->post(route('etudiant.connexion.login'), [
            'identifiant' => 'mehdi.alami@example.com',
            'password' => 'motdepasse1',
        ]);

        // Tentative d'injection de champs interdits : ils doivent être ignorés (validation stricte)
        $this->patch(route('etudiant.profil.update'), [
            'email' => 'mehdi.alami@example.com',
            'telephone' => '+212600000000',
            'date_naissance' => '2004-03-15',
            'adresse' => '12 Rue de la Liberté',
            'ville' => 'Casablanca',
            'code_postal' => '20000',
            'statut' => 'valide',
            'cin' => 'HACKED123',
            'niveau' => 'D3',
            'num_inscription' => 'INS-FAKE-99999',
        ])->assertRedirect(route('etudiant.profil.show'));

        $this->assertDatabaseHas('etudiants', [
            'id' => $this->etudiant->id,
            'statut' => 'en_attente',
            'cin' => 'BK123456',
            'niveau' => 'L1',
            'num_inscription' => 'INS-2026-00001',
        ]);
    }

    public function test_changement_de_mot_de_passe_reussi(): void
    {
        $this->post(route('etudiant.connexion.login'), [
            'identifiant' => 'mehdi.alami@example.com',
            'password' => 'motdepasse1',
        ]);

        $response = $this->patch(route('etudiant.profil.password'), [
            'current_password' => 'motdepasse1',
            'password' => 'nouveaumdp1',
            'password_confirmation' => 'nouveaumdp1',
        ]);

        $response->assertRedirect(route('etudiant.profil.show'));
        $response->assertSessionHas('success');

        $this->assertTrue(Hash::check('nouveaumdp1', $this->etudiant->fresh()->password));

        // L'ancien mot de passe ne doit plus fonctionner
        $this->assertFalse(Hash::check('motdepasse1', $this->etudiant->fresh()->password));
    }

    public function test_changement_de_mot_de_passe_echoue_avec_mauvais_mot_de_passe_actuel(): void
    {
        $this->post(route('etudiant.connexion.login'), [
            'identifiant' => 'mehdi.alami@example.com',
            'password' => 'motdepasse1',
        ]);

        $response = $this->patch(route('etudiant.profil.password'), [
            'current_password' => 'incorrect123',
            'password' => 'nouveaumdp1',
            'password_confirmation' => 'nouveaumdp1',
        ]);

        $response->assertSessionHasErrors(['current_password']);
        $this->assertTrue(Hash::check('motdepasse1', $this->etudiant->fresh()->password));
    }

    public function test_changement_de_mot_de_passe_refuse_un_mot_de_passe_faible(): void
    {
        $this->post(route('etudiant.connexion.login'), [
            'identifiant' => 'mehdi.alami@example.com',
            'password' => 'motdepasse1',
        ]);

        $this->patch(route('etudiant.profil.password'), [
            'current_password' => 'motdepasse1',
            'password' => 'court1',
            'password_confirmation' => 'court1',
        ])->assertSessionHasErrors(['password']);

        $this->patch(route('etudiant.profil.password'), [
            'current_password' => 'motdepasse1',
            'password' => 'motdepasse1',
            'password_confirmation' => 'motdepasse1',
        ])->assertSessionHasErrors(['password']); // identique à l'actuel

        $this->patch(route('etudiant.profil.password'), [
            'current_password' => 'motdepasse1',
            'password' => 'nouveaumdp1',
            'password_confirmation' => 'different1',
        ])->assertSessionHasErrors(['password']); // confirmation erronée
    }

    public function test_deconnexion_invalide_la_session(): void
    {
        $this->post(route('etudiant.connexion.login'), [
            'identifiant' => 'mehdi.alami@example.com',
            'password' => 'motdepasse1',
        ]);
        $this->assertNotNull(session('etudiant_auth_id'));

        $response = $this->post(route('etudiant.connexion.logout'));

        $response->assertRedirect(route('etudiant.connexion.show'));
        $this->assertNull(session('etudiant_auth_id'));

        // Après déconnexion, le profil est de nouveau protégé
        $this->get(route('etudiant.profil.show'))
            ->assertRedirect(route('etudiant.connexion.show'));
    }
}
