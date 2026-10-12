<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\DossierAcademique;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\Note;
use App\Models\Semestre;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActAsScolarite;
use Tests\TestCase;

/**
 * Matrice RBAC : chaque rôle n'accède qu'à ses endpoints autorisés.
 * Règle : un utilisateur sans permission ne doit même pas atteindre la ressource.
 */
class ArchiveAuthorizationTest extends TestCase
{
    use ActAsScolarite, RefreshDatabase;

    public function test_invite_redirige_vers_connexion_scolarite(): void
    {
        $this->get(route('admin.archives.index'))
            ->assertRedirect(route('admin.connexion.show'));

        $this->get(route('admin.etudiants.index'))
            ->assertRedirect(route('admin.connexion.show'));
    }

    public function test_connexion_scolarite_reussie_et_regenerere_la_session(): void
    {
        $user = User::creerCompteBackOffice([
            'name' => 'Responsable Test', 'email' => 'resp.test@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $response = $this->post(route('admin.connexion.login'), [
            'email' => 'resp.test@universite.test',
            'password' => 'Motdepasse@123',
        ]);

        $response->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_connexion_echouee_message_generique(): void
    {
        $this->post(route('admin.connexion.login'), [
            'email' => 'inconnu@universite.test',
            'password' => 'MauvaisMotdepasse1',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_matrice_des_permissions_par_role(): void
    {
        // capacité => statut HTTP attendu par rôle (302 = autorisé, 403 = interdit)
        $matrice = [
            'consultation_du_registre' => ['super_admin' => 200, 'admin_scolarite' => 200, 'agent_scolarite' => 200, 'consultation' => 200],
            'creation_de_dossier' => ['super_admin' => 302, 'admin_scolarite' => 302, 'agent_scolarite' => 302, 'consultation' => 403],
            'modification_de_dossier' => ['super_admin' => 302, 'admin_scolarite' => 302, 'agent_scolarite' => 302, 'consultation' => 403],
            'gestion_de_configuration' => ['super_admin' => 302, 'admin_scolarite' => 302, 'agent_scolarite' => 403, 'consultation' => 403],
        ];

        foreach ($matrice as $capacite => $attentesParRole) {
            foreach ($attentesParRole as $role => $statutAttendu) {
                $this->scolarite($role);

                // Une ressource dédiée par capacité : jamais de réutilisation croisée
                $etudiant = $this->etudiantAvecDossier();
                $dossier = null;

                if (in_array($capacite, ['modification_de_dossier'], true)) {
                    $dossier = DossierAcademique::create([
                        'etudiant_id' => $etudiant->id,
                        'matricule' => 'MAT-RBAC-'.strtoupper(substr(md5(uniqid('', true)), 0, 8)),
                        'date_archivage' => now()->toDateString(),
                    ]);
                }

                $reponse = match ($capacite) {
                    'consultation_du_registre' => $this->get(route('admin.archives.index')),
                    'creation_de_dossier' => $this->post(route('admin.archives.store'), [
                        'etudiant_id' => $etudiant->id, // étudiant sans dossier : création légitime
                    ]),
                    'modification_de_dossier' => $this->patch(route('admin.archives.update', $dossier), [
                        'statut' => 'clos',
                    ]),
                    'gestion_de_configuration' => $this->post(route('admin.archives.matieres.store'), [
                        'code' => 'RBAC'.strtoupper(substr(md5(uniqid('', true)), 0, 3)),
                        'nom' => 'Test '.$role,
                        'coefficient' => 1, 'credits' => 2, 'niveau' => 'L1', 'semestre' => 1,
                    ]),
                };

                $reponse->assertStatus($statutAttendu);

                // Ligne anti-fuite : un rôle interdit ne modifie jamais la base
                if ($statutAttendu === 403 && $capacite === 'creation_de_dossier') {
                    $this->assertDatabaseMissing('archives_etudiants', ['etudiant_id' => $etudiant->id]);
                }
                if ($statutAttendu === 403 && $capacite === 'modification_de_dossier') {
                    $this->assertDatabaseHas('archives_etudiants', ['id' => $dossier->id, 'statut' => 'actif']);
                }
                if ($statutAttendu === 403 && $capacite === 'gestion_de_configuration') {
                    $this->assertDatabaseMissing('matieres', ['nom' => 'Test '.$role]);
                }
            }
        }
    }

    public function test_consultation_ne_peut_pas_modifier_une_note_ni_le_statut_d_un_etudiant(): void
    {
        $this->scolarite('consultation');
        $etudiant = $this->etudiantAvecDossier();
        $inscription = InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => AnneeUniversitaire::create([
                'libelle' => '2025-2026', 'date_debut' => '2025-09-01', 'date_fin' => '2026-07-15',
            ])->id,
            'niveau' => 'L1',
        ]);
        $semestre = Semestre::create(['inscription_academique_id' => $inscription->id, 'numero' => 1]);
        $matiere = Matiere::create([
            'code' => 'RBAC101', 'nom' => 'Matière RBAC', 'coefficient' => 2, 'credits' => 3, 'niveau' => 'L1', 'semestre' => 1,
        ]);
        $note = Note::create([
            'etudiant_id' => $etudiant->id, 'inscription_academique_id' => $inscription->id,
            'semestre_id' => $semestre->id, 'matiere_id' => $matiere->id,
            'note' => 12, 'coefficient' => 2, 'credits' => 3, 'session' => 'normale',
        ]);

        $this->patch(route('admin.archives.notes.update', $note), ['note' => 18])->assertForbidden();
        $this->delete(route('admin.archives.notes.destroy', $note))->assertForbidden();
        $this->patch(route('admin.etudiants.updateStatut', $etudiant), ['statut' => 'rejete'])->assertForbidden();

        $this->assertDatabaseHas('notes', ['id' => $note->id, 'note' => 12]);
        $this->assertDatabaseHas('etudiants', ['id' => $etudiant->id, 'statut' => 'valide']);
    }

    public function test_session_etudiant_n_atteint_jamais_le_back_office(): void
    {
        $etudiant = $this->etudiantAvecDossier();
        $this->etudiantConnecte($etudiant);

        // La session étudiant n'est PAS une authentification back-office
        $this->get(route('admin.archives.index'))
            ->assertRedirect(route('admin.connexion.show'));

        $this->get(route('admin.etudiants.index'))
            ->assertRedirect(route('admin.connexion.show'));
    }

    public function test_etudiant_consulte_sa_propre_archive_uniquement(): void
    {
        $etudiant = $this->etudiantAvecDossier();
        DossierAcademique::create([
            'etudiant_id' => $etudiant->id,
            'matricule' => 'MAT-ETU-1',
            'date_archivage' => now()->toDateString(),
        ]);
        $this->etudiantConnecte($etudiant);

        $response = $this->get(route('etudiant.archives.show'));

        $response->assertOk()
            ->assertSee('Mon Dossier Académique')
            ->assertSee('MAT-ETU-1');
    }

    public function test_etudiant_sans_dossier_obtient_une_404(): void
    {
        $etudiant = $this->etudiantAvecDossier();
        $this->etudiantConnecte($etudiant);

        $this->get(route('etudiant.archives.show'))->assertNotFound();
    }
}
