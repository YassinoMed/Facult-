<?php

namespace Tests\Feature;

use App\Models\Faculte;
use App\Models\ImportReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Fonction œil 👁️ (vérification des données après importation) :
 * page Voir du centre Données (y compris entités export-seul) +
 * bouton Vérifier sur les imports terminés de la page Intégrations.
 */
class VerificationOeilTest extends TestCase
{
    use RefreshDatabase;

    private User $scolarite;

    protected function setUp(): void
    {
        parent::setUp();

        Faculte::create(['nom' => 'Faculté des Sciences', 'code' => 'FST', 'description' => 'T', 'active' => true]);

        $this->scolarite = User::creerCompteBackOffice([
            'name' => 'Scolarité Œil',
            'email' => 'scolarite.oeil@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
    }

    public function test_voir_referentiel_mens_import_seul_retourne_404_pas_500(): void
    {
        $this->actingAs($this->scolarite)
            ->get('/admin/donnees/referentiel-mens/donnees')
            ->assertNotFound();
    }

    public function test_voir_factures_export_seul_est_accessible(): void
    {
        $this->actingAs($this->scolarite)
            ->get('/admin/donnees/factures/donnees')
            ->assertOk();
    }

    public function test_hub_affiche_oeil_factures_et_masque_referentiel_mens(): void
    {
        $reponse = $this->actingAs($this->scolarite)->get('/admin/donnees')->assertOk();

        $reponse->assertSee(route('admin.donnees.voir', 'factures'), false);
        $reponse->assertDontSee(route('admin.donnees.voir', 'referentiel-mens'), false);
    }

    public function test_historique_propose_verifier_apres_import(): void
    {
        ImportReport::create([
            'user_id' => $this->scolarite->id,
            'type' => 'data.salles',
            'format' => 'csv',
            'filename' => 'salles.csv',
            'direction' => 'import',
            'status' => 'completed',
            'total_rows' => 2,
            'valid_rows' => 2,
        ]);

        $reponse = $this->actingAs($this->scolarite)->get('/admin/donnees/historique')->assertOk();

        $reponse->assertSee('Vérifier', false);
        $reponse->assertSee(route('admin.donnees.voir', 'salles'), false);
    }

    public function test_rapport_mens_sans_bouton_verifier(): void
    {
        $fichier = UploadedFile::fake()->createWithContent(
            'mens.csv',
            "cin;date_naissance;nom;prenom;nom_ar;prenom_ar;sexe;nationalite;matricule_national;etablissement;annee;programme_code;niveau\n66000001;2004-05-17;DUPONT;Jean;;;;;;;2025-2026;;\n",
            'text/csv'
        );

        $apercu = $this->actingAs($this->scolarite)
            ->post('/admin/donnees/referentiel-mens/import/preview', ['fichier' => $fichier])
            ->assertOk();

        $this->actingAs($this->scolarite)
            ->post('/admin/donnees/referentiel-mens/import/confirmer', [
                'token' => $apercu->viewData('token'), 'mode' => 'creer',
            ])
            ->assertOk()
            ->assertDontSee('Vérifier les données importées', false);
    }

    public function test_integrations_affiche_oeil_verifier_apres_import_termine(): void
    {
        ImportReport::create([
            'user_id' => $this->scolarite->id,
            'type' => 'students',
            'format' => 'csv',
            'filename' => 'etudiants.csv',
            'direction' => 'import',
            'status' => 'completed',
            'total_rows' => 3,
            'valid_rows' => 3,
        ]);

        $this->actingAs($this->scolarite)
            ->get('/admin/integrations')
            ->assertOk()
            ->assertSee('Vérifier', false)
            ->assertSee(route('admin.donnees.voir', 'etudiants'), false);
    }

    public function test_integrations_sans_oeil_si_import_non_termine(): void
    {
        ImportReport::create([
            'user_id' => $this->scolarite->id,
            'type' => 'students',
            'format' => 'csv',
            'filename' => 'etudiants.csv',
            'direction' => 'import',
            'status' => 'pending',
            'total_rows' => 3,
            'valid_rows' => 3,
        ]);

        $this->actingAs($this->scolarite)
            ->get('/admin/integrations')
            ->assertOk()
            ->assertSee('Confirmer ?', false)
            ->assertDontSee(route('admin.donnees.voir', 'etudiants'), false);
    }
}
