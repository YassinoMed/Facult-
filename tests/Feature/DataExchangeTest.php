<?php

namespace Tests\Feature;

use App\Models\Faculte;
use App\Models\ImportReport;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\ActAsApiClient;
use Tests\TestCase;

/**
 * Centre Import/Export unifié (§38-39) : pipeline commun CSV/XLSX/JSON/XML,
 * preview → confirmation → transaction, exports filtrés, permissions,
 * anti-injection CSV, API stateless + dry-run.
 */
class DataExchangeTest extends TestCase
{
    use ActAsApiClient, RefreshDatabase;

    private User $admin;

    private User $lecteur;

    protected function setUp(): void
    {
        parent::setUp();

        Faculte::create(['nom' => 'Faculté des Sciences', 'code' => 'FST', 'description' => 'T', 'active' => true]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité Test',
            'email' => 'scolarite.dx@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        // Agent : export seul, pas d'import.
        $this->lecteur = User::creerCompteBackOffice([
            'name' => 'Agent Test',
            'email' => 'agent.dx@universite.test',
            'password' => 'Motdepasse@123',
        ], 'agent_scolarite');
    }

    private function csvSalles(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'salles.csv',
            "code;nom;capacite\nB12;Salle B12;40\nB13;Salle B13;30\n",
            'text/csv'
        );
    }

    // ── Import : fichier valide, deux phases ──────────────────────────

    public function test_preview_n_ecrit_rien_puis_confirmation_importe(): void
    {
        $reponse = $this->actingAs($this->admin)
            ->post('/admin/donnees/salles/import/preview', ['fichier' => $this->csvSalles()])
            ->assertOk();

        $reponse->assertViewHas('resultat');
        $this->assertSame(0, Room::count());

        $token = $reponse->viewData('token');
        $this->assertNotEmpty($token);

        $this->actingAs($this->admin)
            ->post('/admin/donnees/salles/import/confirmer', ['token' => $token, 'mode' => 'creer'])
            ->assertOk();

        $this->assertSame(2, Room::count());
        $this->assertDatabaseHas('import_reports', [
            'type' => 'data.salles', 'status' => 'completed', 'valid_rows' => 2,
        ]);
        $this->assertDatabaseHas('integration_logs', [
            'integration' => 'data-exchange', 'operation' => 'data.import.salles', 'status' => 'completed',
        ]);
    }

    public function test_import_partiel_les_lignes_valides_passent_les_erreurs_sont_ignorees(): void
    {
        $fichier = UploadedFile::fake()->createWithContent(
            'salles.csv',
            "code;nom;capacite\nB12;Salle B12;40\n;Sans code;10\nB13;Salle B13;pas-un-nombre\n",
            'text/csv'
        );

        $reponse = $this->actingAs($this->admin)
            ->post('/admin/donnees/salles/import/preview', ['fichier' => $fichier])
            ->assertOk();

        $resultat = $reponse->viewData('resultat');
        $this->assertCount(1, $resultat->valides);
        $this->assertNotEmpty($resultat->erreurs);

        $this->actingAs($this->admin)
            ->post('/admin/donnees/salles/import/confirmer', [
                'token' => $reponse->viewData('token'), 'mode' => 'creer',
            ])->assertOk();

        $this->assertSame(1, Room::count());
        $this->assertTrue(Room::where('code', 'B12')->exists());
    }

    public function test_colonnes_manquantes_rejettent_le_fichier(): void
    {
        $fichier = UploadedFile::fake()->createWithContent('salles.csv', "nom;capacite\nSalle X;10\n", 'text/csv');

        $this->actingAs($this->admin)
            ->post('/admin/donnees/salles/import/preview', ['fichier' => $fichier])
            ->assertRedirect()
            ->assertSessionHasErrors('fichier');

        $this->assertSame(0, Room::count());
    }

    public function test_fichier_vide_rejete(): void
    {
        $fichier = UploadedFile::fake()->createWithContent('salles.csv', '', 'text/csv');

        $this->actingAs($this->admin)
            ->post('/admin/donnees/salles/import/preview', ['fichier' => $fichier])
            ->assertRedirect();

        $this->assertSame(0, Room::count());
    }

    public function test_doublons_dans_le_fichier_signales(): void
    {
        $fichier = UploadedFile::fake()->createWithContent(
            'salles.csv',
            "code;nom;capacite\nB12;Salle 1;40\nB12;Salle 2;30\n",
            'text/csv'
        );

        $reponse = $this->actingAs($this->admin)
            ->post('/admin/donnees/salles/import/preview', ['fichier' => $fichier])
            ->assertOk();

        $codes = array_column($reponse->viewData('resultat')->erreurs, 'code');
        $this->assertContains('DUPLICATE_FILE', $codes);
    }

    public function test_faculte_inexistante_provoque_rollback_complet(): void
    {
        $fichier = UploadedFile::fake()->createWithContent(
            'groupes.csv',
            "code;nom;faculte;niveau;filiere\nG1;Groupe 1;INCONNUE;L1;Info\nG2;Groupe 2;INCONNUE;L1;Info\n",
            'text/csv'
        );

        $preview = $this->actingAs($this->admin)
            ->post('/admin/donnees/groupes/import/preview', ['fichier' => $fichier])
            ->assertOk();

        // Échec à la confirmation : RIEN n'est persisté (transaction).
        $this->actingAs($this->admin)
            ->post('/admin/donnees/groupes/import/confirmer', [
                'token' => $preview->viewData('token'), 'mode' => 'creer',
            ])->assertRedirect();

        $this->assertSame(0, Group::count());
        $this->assertDatabaseHas('import_reports', ['type' => 'data.groupes', 'status' => 'failed']);
    }

    public function test_import_json_et_xml(): void
    {
        $json = UploadedFile::fake()->createWithContent(
            'salles.json',
            json_encode([['code' => 'J01', 'nom' => 'JSON', 'capacite' => 20]]),
            'application/json'
        );

        $preview = $this->actingAs($this->admin)
            ->post('/admin/donnees/salles/import/preview', ['fichier' => $json])
            ->assertOk();

        $this->actingAs($this->admin)->post('/admin/donnees/salles/import/confirmer', [
            'token' => $preview->viewData('token'), 'mode' => 'creer',
        ])->assertOk();
        $this->assertTrue(Room::where('code', 'J01')->exists());

        $xml = UploadedFile::fake()->createWithContent(
            'salles.xml',
            '<?xml version="1.0"?><rows><row><code>X01</code><nom>XML</nom><capacite>15</capacite></row></rows>',
            'application/xml'
        );

        $preview = $this->actingAs($this->admin)
            ->post('/admin/donnees/salles/import/preview', ['fichier' => $xml])
            ->assertOk();
        $this->assertCount(1, $preview->viewData('resultat')->valides);
    }

    public function test_xml_avec_doctype_rejete_anti_xxe(): void
    {
        $xml = UploadedFile::fake()->createWithContent(
            'evil.xml',
            '<?xml version="1.0"?><!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><rows><row><code>&xxe;</code></row></rows>',
            'application/xml'
        );

        $this->actingAs($this->admin)
            ->post('/admin/donnees/salles/import/preview', ['fichier' => $xml])
            ->assertRedirect()
            ->assertSessionHasErrors('fichier');
    }

    // ── Import : sécurité ─────────────────────────────────────────────

    public function test_invite_redirige_vers_connexion(): void
    {
        $this->post('/admin/donnees/salles/import/preview', ['fichier' => $this->csvSalles()])
            ->assertRedirect();
    }

    public function test_sans_permission_import_refuse(): void
    {
        $this->actingAs($this->lecteur)
            ->post('/admin/donnees/salles/import/preview', ['fichier' => $this->csvSalles()])
            ->assertForbidden();

        $this->actingAs($this->lecteur)
            ->get('/admin/donnees')
            ->assertForbidden();
    }

    public function test_extension_falsifiee_rejetee(): void
    {
        $fichier = UploadedFile::fake()->createWithContent('malware.exe', 'code;nom', 'text/csv');

        $this->actingAs($this->admin)
            ->post('/admin/donnees/salles/import/preview', ['fichier' => $fichier])
            ->assertRedirect();
    }

    public function test_mime_incoherent_rejete(): void
    {
        // Vrai fichier temporaire (finfo réel) : contenu PDF binaire avec
        // extension .csv — rejeté par cohérence MIME avant tout parse.
        $chemin = tempnam(sys_get_temp_dir(), 'dx').'.csv';
        file_put_contents($chemin, "%PDF-1.4\n%\xe2\xe3\xcf\xd3\n1 0 obj\n<< /Type /Catalog >>\n");
        $fichier = new UploadedFile($chemin, 'salles.csv', null, null, true);

        try {
            $this->actingAs($this->admin)
                ->post('/admin/donnees/salles/import/preview', ['fichier' => $fichier])
                ->assertRedirect()
                ->assertSessionHasErrors('fichier');
        } finally {
            @unlink($chemin);
        }
    }

    // ── Export ────────────────────────────────────────────────────────

    public function test_export_csv_xlsx_json(): void
    {
        Room::create(['code' => 'B12', 'nom' => 'Salle B12', 'capacite' => 40, 'actif' => true]);

        foreach (['csv', 'xlsx', 'json'] as $format) {
            $this->actingAs($this->admin)
                ->get("/admin/donnees/salles/export/{$format}")
                ->assertOk();
        }

        $csv = $this->actingAs($this->admin)->get('/admin/donnees/salles/export/csv');
        $this->assertStringContainsString('B12', $csv->streamedContent());
    }

    public function test_export_respecte_filtre_et_selection(): void
    {
        $a = Room::create(['code' => 'B12', 'nom' => 'Salle B12', 'capacite' => 40, 'actif' => true]);
        Room::create(['code' => 'C34', 'nom' => 'Salle C34', 'capacite' => 20, 'actif' => true]);

        $filtre = $this->actingAs($this->admin)->get('/admin/donnees/salles/export/csv?search=B12');
        $this->assertStringContainsString('B12', $filtre->streamedContent());
        $this->assertStringNotContainsString('C34', $filtre->streamedContent());

        $selection = $this->actingAs($this->admin)->get("/admin/donnees/salles/export/csv?ids[]={$a->id}");
        $this->assertStringContainsString('B12', $selection->streamedContent());
        $this->assertStringNotContainsString('C34', $selection->streamedContent());
    }

    public function test_export_neutralise_injection_csv(): void
    {
        Room::create(['code' => '=CMD', 'nom' => '+2+5', 'capacite' => 10, 'actif' => true]);

        $contenu = $this->actingAs($this->admin)
            ->get('/admin/donnees/salles/export/csv')
            ->streamedContent();

        $this->assertStringContainsString("'=CMD", $contenu);
        $this->assertStringContainsString("'+2+5", $contenu);
    }

    public function test_export_sans_permission_refuse(): void
    {
        $sansDroit = User::creerCompteBackOffice([
            'name' => 'Consult',
            'email' => 'consult.dx@universite.test',
            'password' => 'Motdepasse@123',
        ], 'consultation');

        $this->actingAs($sansDroit)
            ->get('/admin/donnees/salles/export/csv')
            ->assertForbidden();
    }

    public function test_export_colonnes_autorisees_uniquement(): void
    {
        Teacher::create(['nom' => 'Benali', 'prenom' => 'Sara', 'email' => 'sara@universite.test', 'actif' => true]);

        $contenu = $this->actingAs($this->admin)
            ->get('/admin/donnees/enseignants/export/csv')
            ->streamedContent();

        $this->assertStringContainsString('Benali', $contenu);
        $this->assertStringNotContainsString('password', $contenu);
    }

    // ── Modèles, historique, rapports ─────────────────────────────────

    public function test_modele_csv_telechargeable(): void
    {
        $contenu = $this->actingAs($this->admin)
            ->get('/admin/donnees/salles/modele/csv')
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('code', $contenu);
    }

    public function test_historique_affiche_imports(): void
    {
        ImportReport::create([
            'user_id' => $this->admin->id, 'integration' => 'web', 'direction' => 'import',
            'type' => 'data.salles', 'format' => 'csv', 'filename' => 's.csv',
            'external_reference' => 'DX-TEST', 'status' => 'completed',
            'mode' => 'atomique', 'total_rows' => 2, 'valid_rows' => 2,
        ]);

        $this->actingAs($this->admin)
            ->get('/admin/donnees/historique')
            ->assertOk()
            ->assertSee('data.salles');
    }

    public function test_rapport_erreurs_csv(): void
    {
        $rapport = ImportReport::create([
            'user_id' => $this->admin->id, 'integration' => 'web', 'direction' => 'import',
            'type' => 'data.salles', 'format' => 'csv', 'filename' => 's.csv',
            'external_reference' => 'DX-ERR', 'status' => 'failed',
            'mode' => 'atomique', 'total_rows' => 1,
            'errors' => [['ligne' => 3, 'champ' => 'code', 'message' => 'requis', 'code' => 'INVALID']],
        ]);

        $contenu = $this->actingAs($this->admin)
            ->get("/admin/donnees/rapports/{$rapport->id}/erreurs/csv")
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('row;error_code;message;field;value', $contenu);
        $this->assertStringContainsString('INVALID', $contenu);
    }

    public function test_annulation_ne_persiste_rien(): void
    {
        $preview = $this->actingAs($this->admin)
            ->post('/admin/donnees/salles/import/preview', ['fichier' => $this->csvSalles()])
            ->assertOk();

        $this->actingAs($this->admin)
            ->post('/admin/donnees/salles/import/annuler', ['token' => $preview->viewData('token')])
            ->assertRedirect();

        $this->assertSame(0, Room::count());
    }

    // ── API ───────────────────────────────────────────────────────────

    public function test_api_sans_token_401(): void
    {
        $this->post('/api/v1/data/salles/import/preview', ['fichier' => $this->csvSalles()])
            ->assertUnauthorized();
    }

    public function test_api_sans_scope_403(): void
    {
        [, $token] = $this->clientApi(['students:read']);

        $this->post('/api/v1/data/salles/import/preview', ['fichier' => $this->csvSalles()], [
            'Authorization' => 'Bearer '.$token,
        ])->assertForbidden();
    }

    public function test_api_preview_dry_run_n_ecrit_rien(): void
    {
        [, $token] = $this->clientApi(['data:exchange']);

        $reponse = $this->post('/api/v1/data/salles/import/preview', [
            'fichier' => $this->csvSalles(), 'dry_run' => true,
        ], ['Authorization' => 'Bearer '.$token])->assertOk();

        $this->assertTrue($reponse->json('valid'));
        $this->assertSame(2, $reponse->json('valid_rows'));
        $this->assertSame(0, Room::count());
    }

    public function test_api_confirm_importe_en_transaction(): void
    {
        [, $token] = $this->clientApi(['data:exchange']);

        $reponse = $this->post('/api/v1/data/salles/import/confirm', [
            'fichier' => $this->csvSalles(), 'mode' => 'creer',
        ], ['Authorization' => 'Bearer '.$token])->assertOk();

        $this->assertSame(2, $reponse->json('importes'));
        $this->assertSame(2, Room::count());
    }

    public function test_api_confirm_bloque_si_erreurs(): void
    {
        [, $token] = $this->clientApi(['data:exchange']);

        $fichier = UploadedFile::fake()->createWithContent('salles.csv', "code;nom\n;X\n", 'text/csv');

        $this->post('/api/v1/data/salles/import/confirm', [
            'fichier' => $fichier, 'mode' => 'creer',
        ], ['Authorization' => 'Bearer '.$token])->assertUnprocessable();

        $this->assertSame(0, Room::count());
    }

    public function test_api_export_csv(): void
    {
        [, $token] = $this->clientApi(['data:exchange']);
        Room::create(['code' => 'B12', 'nom' => 'Salle B12', 'capacite' => 40, 'actif' => true]);

        $this->get('/api/v1/data/salles/export/csv', ['Authorization' => 'Bearer '.$token])
            ->assertOk();
    }

    public function test_api_entite_inconnue_404(): void
    {
        [, $token] = $this->clientApi(['data:exchange']);

        $this->post('/api/v1/data/inconnus/import/preview', ['fichier' => $this->csvSalles()], [
            'Authorization' => 'Bearer '.$token,
        ])->assertNotFound();
    }

    // ── Enseignants / matières : définitions branchées ────────────────

    public function test_import_enseignants_et_matieres(): void
    {
        $enseignants = UploadedFile::fake()->createWithContent(
            'enseignants.csv',
            "nom;prenom;email\nBenali;Sara;sara@universite.test\n",
            'text/csv'
        );

        $preview = $this->actingAs($this->admin)
            ->post('/admin/donnees/enseignants/import/preview', ['fichier' => $enseignants])
            ->assertOk();

        $this->actingAs($this->admin)->post('/admin/donnees/enseignants/import/confirmer', [
            'token' => $preview->viewData('token'), 'mode' => 'creer',
        ])->assertOk();
        $this->assertTrue(Teacher::where('email', 'sara@universite.test')->exists());

        $matieres = UploadedFile::fake()->createWithContent(
            'matieres.csv',
            "code;nom;coefficient;credits;niveau;semestre\nALG201;Algèbre;2;6;L1;1\n",
            'text/csv'
        );

        $preview = $this->actingAs($this->admin)
            ->post('/admin/donnees/matieres/import/preview', ['fichier' => $matieres])
            ->assertOk();

        $this->actingAs($this->admin)->post('/admin/donnees/matieres/import/confirmer', [
            'token' => $preview->viewData('token'), 'mode' => 'creer',
        ])->assertOk();
        $this->assertTrue(Matiere::where('code', 'ALG201')->exists());
    }

    public function test_visualisation_donnees_oeil(): void
    {
        Room::create(['code' => 'S101', 'nom' => 'Amphi A', 'capacite' => 150]);
        Room::create(['code' => 'S102', 'nom' => 'Salle TP', 'capacite' => 30]);

        // Accès vue vérification / œil pour admin
        $this->actingAs($this->admin)
            ->get('/admin/donnees/salles/donnees')
            ->assertOk()
            ->assertViewIs('admin.donnees.voir')
            ->assertSee('Amphi A')
            ->assertSee('Salle TP')
            ->assertSee('Enregistrements en base');

        // Test avec filtre de recherche
        $this->actingAs($this->admin)
            ->get('/admin/donnees/salles/donnees?search=Amphi')
            ->assertOk()
            ->assertSee('Amphi A')
            ->assertDontSee('Salle TP');
    }
}
