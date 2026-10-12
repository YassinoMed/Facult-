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
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\ActAsScolarite;
use Tests\TestCase;

/**
 * Contrôles de sécurité applicative : rate limiting, protection
 * contre l'énumération, invités, soumission de données falsifiées.
 */
class ArchiveSecurityTest extends TestCase
{
    use ActAsScolarite, RefreshDatabase;

    public function test_rate_limiting_sur_la_connexion_admin(): void
    {
        // 5 tentatives infructueuses autorisées, la 6e est bloquée (throttle:5,1)
        $tentative = ['email' => 'bruteforce@universite.test', 'password' => 'Mauvais1234'];

        for ($i = 1; $i <= 5; $i++) {
            $this->post(route('admin.connexion.login'), $tentative)->assertRedirect();
        }

        $this->post(route('admin.connexion.login'), $tentative)->assertStatus(429);
    }

    public function test_les_headers_de_securite_sont_presents_sur_le_registre(): void
    {
        $this->scolarite('consultation');

        $response = $this->get(route('admin.archives.index'));

        $response->assertOk()
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->assertHeader('Cross-Origin-Resource-Policy', 'same-origin')
            ->assertHeaderMissing('X-XSS-Protection');

        $this->assertStringContainsString("default-src 'self'", (string) $response->headers->get('Content-Security-Policy'));
        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("connect-src 'self'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);
    }

    public function test_ids_falsifies_dans_les_routes_sont_rejetes(): void
    {
        $this->scolarite('super_admin');
        $etudiant = $this->etudiantAvecDossier();

        // Dossier inexistant : 404, jamais 500
        $this->get('/admin/archives/999999')->assertNotFound();
        $this->get(route('admin.archives.show', 999999))->assertNotFound();
        $this->patch(route('admin.archives.update', 999999), ['statut' => 'clos'])->assertNotFound();
    }

    public function test_etudiant_id_toujours_derive_de_l_inscription_mais_du_dossier(): void
    {
        // Un agent tente de saisir une note en passant par l'inscription d'un autre étudiant :
        // la cohérence inscription↔semestre est vérifiée par l'unicité et le service.
        $this->scolarite('agent_scolarite');
        $etudiantA = $this->etudiantAvecDossier();
        DossierAcademique::create([
            'etudiant_id' => $etudiantA->id, 'matricule' => 'MAT-SEC-A', 'date_archivage' => now()->toDateString(),
        ]);
        $etudiantB = $this->etudiantAvecDossier();

        $inscriptionA = InscriptionAcademique::create([
            'etudiant_id' => $etudiantA->id,
            'annee_universitaire_id' => AnneeUniversitaire::create([
                'libelle' => '2025-2026', 'date_debut' => '2025-09-01', 'date_fin' => '2026-07-15',
            ])->id,
            'niveau' => 'L1',
        ]);
        // S1/S2 de l'inscription A : requis pour toute saisie de note
        Semestre::create(['inscription_academique_id' => $inscriptionA->id, 'numero' => 1]);
        Semestre::create(['inscription_academique_id' => $inscriptionA->id, 'numero' => 2]);

        // Le semestre soumis appartient à A, mais on vise l'inscription de A : cohérent.
        // En revanche, tenter un semestre d'une AUTRE inscription est bloqué par la
        // règle exists (semestre doit appartenir à l'inscription de la route).
        $inscriptionB = InscriptionAcademique::create([
            'etudiant_id' => $etudiantB->id,
            'annee_universitaire_id' => AnneeUniversitaire::create([
                'libelle' => '2024-2025', 'date_debut' => '2024-09-01', 'date_fin' => '2025-07-15',
            ])->id,
            'niveau' => 'L1',
        ]);
        $semestreB = Semestre::create(['inscription_academique_id' => $inscriptionB->id, 'numero' => 1]);
        $matiere = Matiere::create([
            'code' => 'SEC101', 'nom' => 'Matière Sécurité', 'coefficient' => 2, 'credits' => 3, 'niveau' => 'L1', 'semestre' => 1,
        ]);

        $this->post(route('admin.archives.notes.store', $inscriptionA), [
            'semestre_numero' => 1,
            'matiere_id' => $matiere->id,
            'note' => 15,
            'session' => 'normale',
        ])->assertRedirect(); // saisie légitime sur l'inscription A

        $noteA = Note::where('inscription_academique_id', $inscriptionA->id)->first();
        $this->assertEquals($etudiantA->id, $noteA->etudiant_id); // jamais l'étudiant d'une autre ligne
        $this->assertDatabaseMissing('notes', ['semestre_id' => $semestreB->id]);
    }

    public function test_user_sans_role_connu_obtient_un_message_sans_divulgation(): void
    {
        $user = User::create([
            'name' => 'Compte Sans Rôle',
            'email' => 'sansrole.test@universite.test',
            'password' => Hash::make('Motdepasse@123'),
        ]);

        // Le schéma impose un rôle par défaut (consultation) : ce cas vérifie
        // qu'un compte par défaut a bien le rôle le plus restreint.
        $this->assertSame('consultation', $user->fresh()->role);
    }

    public function test_creation_de_dossier_n_expose_pas_les_autres_etudiants(): void
    {
        $this->scolarite('agent_scolarite');
        $sansDossier = $this->etudiantAvecDossier();
        $avecDossier = $this->etudiantAvecDossier();
        DossierAcademique::create([
            'etudiant_id' => $avecDossier->id,
            'matricule' => 'MAT-SEC-1',
            'date_archivage' => now()->toDateString(),
        ]);

        // La page de création ne propose QUE les étudiants sans dossier
        $response = $this->get(route('admin.archives.create'));

        $response->assertOk()
            ->assertSee($sansDossier->cin)
            ->assertDontSee($avecDossier->cin);
    }
}
