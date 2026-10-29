<?php

namespace Tests\Feature;

use App\Modules\Schedule\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOOP-UNIREGISTRE-PORTAILS-ENTERPRISE-01 — LOT 1.
 *
 * Le portail enseignant n'a pas de compte `users` : la révocation repose
 * sur `teachers.mot_de_passe_version`, posée en session à la connexion
 * et vérifiée par `EnsureTeacher` à chaque requête.
 */
class RevocationSessionsEnseignantTest extends TestCase
{
    use RefreshDatabase;

    private function enseignant(string $email = 'enseignant.sessions@universite.test'): Teacher
    {
        return Teacher::create([
            'nom' => 'Ben Salah',
            'prenom' => 'Karim',
            'email' => $email,
            'password' => 'Ancien@12345',
            'actif' => true,
            'statut' => 'actif',
        ]);
    }

    public function test_connexion_enseignant_pose_la_version_en_session(): void
    {
        $enseignant = $this->enseignant();

        $this->post(route('enseignant.connexion.login'), [
            'identifiant' => $enseignant->email,
            'password' => 'Ancien@12345',
        ])->assertRedirect(route('enseignant.dashboard'))
            ->assertSessionHas('enseignant_auth_id', $enseignant->id)
            ->assertSessionHas('enseignant_mdp_version', 0);
    }

    public function test_changement_mot_de_passe_enseignant_revoque_les_autres_sessions(): void
    {
        $enseignant = $this->enseignant();

        $this->withSession([
            'enseignant_auth_id' => $enseignant->id,
            'enseignant_mdp_version' => 0,
        ])->patch(route('enseignant.profil.password'), [
            'current_password' => 'Ancien@12345',
            'password' => 'Nouveau@12345',
            'password_confirmation' => 'Nouveau@12345',
        ])->assertRedirect(route('enseignant.profil.show'))
            ->assertSessionHas('enseignant_mdp_version', 1);

        $this->assertSame(1, (int) $enseignant->fresh()->mot_de_passe_version);

        // Session ouverte avant le changement : version 0.
        $this->withSession([
            'enseignant_auth_id' => $enseignant->id,
            'enseignant_mdp_version' => 0,
        ])->get(route('enseignant.profil.show'))
            ->assertRedirect(route('enseignant.connexion.show'));
    }

    public function test_mise_a_jour_administrative_du_mot_de_passe_incremente_la_version(): void
    {
        $enseignant = $this->enseignant();

        // Même chemin que l'édition back-office : update Eloquent, sans
        // passer par le contrôleur de profil.
        $enseignant->update(['password' => 'Nouveau@12345']);

        $this->assertSame(1, (int) $enseignant->fresh()->mot_de_passe_version);
    }

    public function test_session_enseignant_sans_version_reste_valide_si_base_a_zero(): void
    {
        $enseignant = $this->enseignant();

        $this->withSession(['enseignant_auth_id' => $enseignant->id])
            ->get(route('enseignant.profil.show'))
            ->assertOk();

        $this->assertSame(0, (int) $enseignant->mot_de_passe_version);
    }

    public function test_compte_enseignant_desactive_expire_la_session(): void
    {
        $enseignant = $this->enseignant();
        $enseignant->update(['actif' => false, 'statut' => 'inactif']);

        $this->withSession([
            'enseignant_auth_id' => $enseignant->id,
            'enseignant_mdp_version' => 0,
        ])->get(route('enseignant.profil.show'))
            ->assertRedirect(route('enseignant.connexion.show'));
    }
}
