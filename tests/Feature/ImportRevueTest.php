<?php

namespace Tests\Feature;

use App\Models\Faculte;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * PHASE 4 — Revue semi-automatique : accepter / modifier / rejeter /
 * attente, décisions individuelles validées, token cloisonné, rapport tracé.
 */
class ImportRevueTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        Faculte::create([
            'nom' => 'Faculté des Sciences de Tunis', 'code' => 'FST',
            'description' => 'Sciences', 'active' => true,
        ]);

        $this->agent = User::creerCompteBackOffice([
            'name' => 'Agent Revue',
            'email' => 'revue@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
    }

    private function fichier(string $contenu): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('revue.csv', $contenu);
    }

    private function previsionner(string $contenu = "nom,prenom,cin,faculte\nDupont,Jean,72000001,FST\nMartin,Claire,72000002,FST\n"): string
    {
        $reponse = $this->actingAs($this->agent)->post(
            route('admin.donnees.import.preview', 'etudiants'),
            ['fichier' => $this->fichier($contenu)]
        );

        $reponse->assertOk();

        return $reponse->original->getData()['token'];
    }

    private function confirmer(string $token, string $mode = 'creer'): void
    {
        $this->actingAs($this->agent)->post(
            route('admin.donnees.import.confirmer', 'etudiants'),
            ['token' => $token, 'mode' => $mode]
        )->assertOk();
    }

    public function test_modifier_valide_puis_confirmer(): void
    {
        $token = $this->previsionner();

        $reponse = $this->actingAs($this->agent)->postJson(
            route('admin.donnees.import.revoir', 'etudiants'),
            ['token' => $token, 'decisions' => [
                ['ligne' => 2, 'action' => 'modifier', 'valeurs' => ['prenom' => 'Jeanne']],
            ]]
        );

        $reponse->assertOk();
        $this->assertSame(1, $reponse->json('resume.modifiees'));
        $this->assertSame(2, $reponse->json('resume.restantes'));

        $this->confirmer($token);

        $this->assertDatabaseHas('etudiants', ['nom' => 'Dupont', 'prenom' => 'Jeanne']);
        $this->assertDatabaseHas('etudiants', ['nom' => 'Martin', 'prenom' => 'Claire']);
    }

    public function test_modifier_invalide_refuse_proprement(): void
    {
        $token = $this->previsionner();

        $this->actingAs($this->agent)->postJson(
            route('admin.donnees.import.revoir', 'etudiants'),
            ['token' => $token, 'decisions' => [
                ['ligne' => 2, 'action' => 'modifier', 'valeurs' => ['nom' => '']],
            ]]
        )->assertStatus(422);

        // Rien n'a changé : la confirmation importe les deux lignes d'origine.
        $this->confirmer($token);
        $this->assertDatabaseHas('etudiants', ['nom' => 'Dupont', 'prenom' => 'Jean']);
    }

    public function test_rejeter_exclut_la_ligne_du_confirm(): void
    {
        $token = $this->previsionner();

        $reponse = $this->actingAs($this->agent)->postJson(
            route('admin.donnees.import.revoir', 'etudiants'),
            ['token' => $token, 'decisions' => [['ligne' => 3, 'action' => 'rejeter']]]
        );

        $reponse->assertOk();
        $this->assertSame(1, $reponse->json('resume.rejetees'));
        $this->assertSame(1, $reponse->json('resume.restantes'));

        $this->confirmer($token);

        $this->assertDatabaseHas('etudiants', ['nom' => 'Dupont']);
        $this->assertDatabaseMissing('etudiants', ['nom' => 'Martin']);
    }

    public function test_attente_exclut_la_ligne_sans_la_rejeter(): void
    {
        $token = $this->previsionner();

        $this->actingAs($this->agent)->postJson(
            route('admin.donnees.import.revoir', 'etudiants'),
            ['token' => $token, 'decisions' => [['ligne' => 2, 'action' => 'attente']]]
        )->assertOk();

        $this->confirmer($token);

        $this->assertDatabaseMissing('etudiants', ['nom' => 'Dupont']);
        $this->assertDatabaseHas('etudiants', ['nom' => 'Martin']);
    }

    public function test_ligne_inconnue_double_decision_et_token_autrui_refuses(): void
    {
        $token = $this->previsionner();

        // Ligne absente du lot.
        $this->actingAs($this->agent)->postJson(
            route('admin.donnees.import.revoir', 'etudiants'),
            ['token' => $token, 'decisions' => [['ligne' => 99, 'action' => 'rejeter']]]
        )->assertStatus(422);

        // Décision en double.
        $this->actingAs($this->agent)->postJson(
            route('admin.donnees.import.revoir', 'etudiants'),
            ['token' => $token, 'decisions' => [
                ['ligne' => 2, 'action' => 'accepter'],
                ['ligne' => 2, 'action' => 'rejeter'],
            ]]
        )->assertStatus(422);

        // Colonne inconnue.
        $this->actingAs($this->agent)->postJson(
            route('admin.donnees.import.revoir', 'etudiants'),
            ['token' => $token, 'decisions' => [
                ['ligne' => 2, 'action' => 'modifier', 'valeurs' => ['password' => 'x']],
            ]]
        )->assertStatus(422);

        // Token d'un autre auteur (BOLA).
        $autre = User::creerCompteBackOffice([
            'name' => 'Autre Agent',
            'email' => 'autre.revue@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->actingAs($autre)->postJson(
            route('admin.donnees.import.revoir', 'etudiants'),
            ['token' => $token, 'decisions' => [['ligne' => 2, 'action' => 'rejeter']]]
        )->assertStatus(422);
    }

    public function test_revoir_exige_permission_et_authentification(): void
    {
        $token = $this->previsionner();

        // Invité JSON → 401 (pas de redirection pour les requêtes JSON).
        $this->app['auth']->forgetGuards();
        $this->postJson(
            route('admin.donnees.import.revoir', 'etudiants'),
            ['token' => $token, 'decisions' => [['ligne' => 2, 'action' => 'rejeter']]]
        )->assertUnauthorized();

        // Sans data.import → 403.
        $lecteur = User::creerCompteBackOffice([
            'name' => 'Lecteur',
            'email' => 'lecteur.revue@universite.test',
            'password' => 'Motdepasse@123',
        ], 'consultation');

        $this->actingAs($lecteur)->postJson(
            route('admin.donnees.import.revoir', 'etudiants'),
            ['token' => $token, 'decisions' => [['ligne' => 2, 'action' => 'rejeter']]]
        )->assertForbidden();
    }

    public function test_decisions_tracees_dans_le_rapport(): void
    {
        $token = $this->previsionner();

        $this->actingAs($this->agent)->postJson(
            route('admin.donnees.import.revoir', 'etudiants'),
            ['token' => $token, 'decisions' => [
                ['ligne' => 3, 'action' => 'rejeter'],
                ['ligne' => 2, 'action' => 'accepter'],
            ]]
        )->assertOk();

        $reponse = $this->actingAs($this->agent)->post(
            route('admin.donnees.import.confirmer', 'etudiants'),
            ['token' => $token, 'mode' => 'creer']
        );

        $reponse->assertOk();
        $rapport = $reponse->original->getData()['rapport'];
        $revue = $rapport->preview['revue'] ?? null;

        $this->assertNotNull($revue);
        $this->assertSame(
            [['ligne' => 3, 'action' => 'rejeter'], ['ligne' => 2, 'action' => 'accepter']],
            array_map(fn ($d) => ['ligne' => $d['ligne'], 'action' => $d['action']], $revue['decisions'])
        );
        // Aucune valeur PII dans la trace.
        $this->assertStringNotContainsString('Martin', json_encode($revue));
    }

    public function test_accepter_conserve_la_ligne(): void
    {
        $token = $this->previsionner("nom,prenom,cin,faculte\nDupont,Jean,72000001,FST\n");

        $this->actingAs($this->agent)->postJson(
            route('admin.donnees.import.revoir', 'etudiants'),
            ['token' => $token, 'decisions' => [['ligne' => 2, 'action' => 'accepter']]]
        )->assertOk();

        $this->confirmer($token);
        $this->assertDatabaseHas('etudiants', ['nom' => 'Dupont', 'prenom' => 'Jean']);
    }

    public function test_revue_incomplete_bloque_la_confirmation(): void
    {
        $token = $this->previsionner(
            "nom,prenom,cin,faculte,date_naissance\nDupont,Jean,75000001,FST,05/06/2026\nMartin,Claire,75000002,FST,2004-03-10\n"
        );

        // Décision sur la seule ligne saine : la ligne ambiguë reste non décidée.
        $this->actingAs($this->agent)->postJson(
            route('admin.donnees.import.revoir', 'etudiants'),
            ['token' => $token, 'decisions' => [['ligne' => 3, 'action' => 'accepter']]]
        )->assertOk();

        $reponse = $this->actingAs($this->agent)->post(
            route('admin.donnees.import.confirmer', 'etudiants'),
            ['token' => $token, 'mode' => 'creer']
        );

        $reponse->assertRedirect();
        $reponse->assertSessionHasErrors('fichier');
        $this->assertDatabaseMissing('etudiants', ['nom' => 'Martin']);

        // Revue complétée : la confirmation passe.
        $this->actingAs($this->agent)->postJson(
            route('admin.donnees.import.revoir', 'etudiants'),
            ['token' => $token, 'decisions' => [['ligne' => 2, 'action' => 'accepter']]]
        )->assertOk();

        $this->confirmer($token);
        $this->assertDatabaseHas('etudiants', ['nom' => 'Martin']);
    }

    public function test_modifier_vers_un_doublon_refuse(): void
    {
        $token = $this->previsionner();

        $this->actingAs($this->agent)->postJson(
            route('admin.donnees.import.revoir', 'etudiants'),
            ['token' => $token, 'decisions' => [
                ['ligne' => 2, 'action' => 'modifier', 'valeurs' => ['cin' => '72000002']],
            ]]
        )->assertStatus(422);

        // Le lot reste intact : confirmation des deux lignes d'origine.
        $this->confirmer($token);
        $this->assertDatabaseHas('etudiants', ['cin' => '72000001']);
        $this->assertDatabaseHas('etudiants', ['cin' => '72000002']);
    }
}
