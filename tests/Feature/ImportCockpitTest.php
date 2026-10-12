<?php

namespace Tests\Feature;

use App\Models\Faculte;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * PHASE 8 — Cockpit de prévisualisation : sections mapping / corrections
 * / revue affichées quand pertinentes, absentes sinon ; revue utilisable
 * sans JavaScript (formulaire classique, réponse JSON lisible).
 */
class ImportCockpitTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        Faculte::create([
            'nom' => 'Faculté Cockpit', 'code' => 'FCP',
            'description' => 'T', 'active' => true,
        ]);

        $this->agent = User::creerCompteBackOffice([
            'name' => 'Agent Cockpit',
            'email' => 'cockpit@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
    }

    private function previsionner(string $contenu): TestResponse
    {
        return $this->actingAs($this->agent)->post(
            route('admin.donnees.import.preview', 'etudiants'),
            ['fichier' => UploadedFile::fake()->createWithContent('cockpit.csv', $contenu)]
        );
    }

    public function test_sections_intelligentes_affichees_quand_pertinentes(): void
    {
        $reponse = $this->previsionner(
            "nom,prenom,surnom,adresse,telephone,date_naissance\nDupont,Jean,X,  Rue   des  Jasmins ,21234567,05/06/2026\n"
        );

        $reponse->assertOk();
        // Mapping : colonne inconnue signalée avec suggestion potentielle.
        $reponse->assertSee('Correspondance des colonnes', false);
        $reponse->assertSee('surnom', false);
        $reponse->assertSee('ignorée', false);
        // Corrections automatiques tracées.
        $reponse->assertSee('Corrections automatiques', false);
        $reponse->assertSee('ESPACES@1.0', false);
        $reponse->assertSee('TELEPHONE@1.0', false);
        // Revue : date ambiguë à décider + endpoint + accessibilité.
        $reponse->assertSee('Points à vérifier', false);
        $reponse->assertSee('donnees\\/etudiants\\/import\\/revoir', false);
        $reponse->assertSee('aria-label="Points à vérifier"', false);
        $reponse->assertSee('role="alert"', false);
    }

    public function test_sections_absentes_sur_fichier_propre(): void
    {
        // Ligne avec identifiant fort : ni correction, ni point à vérifier.
        $reponse = $this->previsionner("nom,prenom,cin\nDupont,Jean,73000001\n");

        $reponse->assertOk();
        $reponse->assertDontSee('Corrections automatiques');
        $reponse->assertDontSee('Points à vérifier');
    }

    public function test_revue_sans_javascript_formulaire_classique(): void
    {
        $preview = $this->previsionner("nom,prenom,cin\nDupont,Jean,73000002\n");
        $token = $preview->original->getData()['token'];

        // POST formulaire classique (pas de JSON) : contrat JSON conservé.
        $reponse = $this->actingAs($this->agent)->post(
            route('admin.donnees.import.revoir', 'etudiants'),
            ['token' => $token, 'decisions' => [['ligne' => 2, 'action' => 'accepter']]]
        );

        $reponse->assertOk();
        $this->assertSame(1, $reponse->json('resume.acceptees'));
    }

    public function test_chaine_origine_corrige_apercu(): void
    {
        // Adresse avec espaces : section corrections (origine → proposée)
        // ET tableau d'aperçu avec la valeur corrigée appliquée.
        $reponse = $this->previsionner(
            "nom,prenom,cin,adresse\nDupont,Jean,73000011,  Rue   des  Jasmins \n"
        );

        $reponse->assertOk();
        $reponse->assertSee('Corrections automatiques', false);
        $reponse->assertSee('ESPACES@1.0', false);
        $reponse->assertSee('Rue des Jasmins', false);
    }

    public function test_stepper_et_actions_confirmations_inchanges(): void
    {
        $reponse = $this->previsionner("nom,prenom,cin\nDupont,Jean,73000003\n");

        $reponse->assertOk();
        $reponse->assertSee("Confirmer l'import", false);
        $reponse->assertSee('Annuler', false);
    }
}
