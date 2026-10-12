<?php

namespace Tests\Feature;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * PHASE 9 — Sécurité élargie des nouveaux flux : revue liée à son
 * entité, décisions non rejouables, confirmation cloisonnée par auteur,
 * export résultats soumis à data.export.
 */
class ImportSecuriteElargieTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        Faculte::create([
            'nom' => 'Faculté Sécurité', 'code' => 'FSC',
            'description' => 'T', 'active' => true,
        ]);

        $this->agent = User::creerCompteBackOffice([
            'name' => 'Agent Sécurité',
            'email' => 'securite@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
    }

    private function previsionner(): string
    {
        $reponse = $this->actingAs($this->agent)->post(
            route('admin.donnees.import.preview', 'etudiants'),
            ['fichier' => UploadedFile::fake()->createWithContent(
                'sec.csv', "nom,prenom,cin\nDupont,Jean,74000001\n"
            )]
        );

        $reponse->assertOk();

        return $reponse->original->getData()['token'];
    }

    public function test_revue_refusee_sur_autre_entite(): void
    {
        $token = $this->previsionner();

        $this->actingAs($this->agent)->postJson(
            route('admin.donnees.import.revoir', 'salles'),
            ['token' => $token, 'decisions' => [['ligne' => 2, 'action' => 'rejeter']]]
        )->assertStatus(422);
    }

    public function test_decision_non_rejouable(): void
    {
        $token = $this->previsionner();

        $decision = ['token' => $token, 'decisions' => [['ligne' => 2, 'action' => 'rejeter']]];

        $this->actingAs($this->agent)->postJson(
            route('admin.donnees.import.revoir', 'etudiants'), $decision
        )->assertOk();

        // Rejeu : la ligne n'est plus dans le jeu → refusé.
        $this->actingAs($this->agent)->postJson(
            route('admin.donnees.import.revoir', 'etudiants'), $decision
        )->assertStatus(422);
    }

    public function test_confirmation_par_un_autre_auteur_refusee(): void
    {
        $token = $this->previsionner();

        $autre = User::creerCompteBackOffice([
            'name' => 'Autre Sécurité',
            'email' => 'autre.securite@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->actingAs($autre)->post(
            route('admin.donnees.import.confirmer', 'etudiants'),
            ['token' => $token, 'mode' => 'creer']
        )->assertRedirect();

        $this->assertSame(0, Etudiant::count());
    }

    public function test_export_listes_sans_permission_refuse(): void
    {
        $lecteur = User::creerCompteBackOffice([
            'name' => 'Lecteur Listes',
            'email' => 'lecteur.listes@universite.test',
            'password' => 'Motdepasse@123',
        ], 'consultation');

        $this->actingAs($lecteur)->get(
            route('admin.listes.exporter', ['format' => 'csv'])
        )->assertForbidden();
    }

    public function test_route_revue_entite_inconnue_404(): void
    {
        $this->actingAs($this->agent)->postJson(
            '/admin/donnees/entite-inexistante/import/revoir',
            ['token' => '00000000-0000-0000-0000-000000000000', 'decisions' => [['ligne' => 2, 'action' => 'rejeter']]]
        )->assertNotFound();
    }
}
