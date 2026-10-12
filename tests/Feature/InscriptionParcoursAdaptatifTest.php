<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\MensReference;
use App\Services\PeriodeInscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOOP 02 : Assistant d'inscription guidé et parcours adaptatif.
 * Vérifie l'adaptation de l'interface, l'alignement des étapes 1 à 6,
 * le sélecteur de catégorie et la validation serveur du type d'inscription.
 */
class InscriptionParcoursAdaptatifTest extends TestCase
{
    use RefreshDatabase;

    protected Faculte $faculte;

    protected AnneeUniversitaire $annee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences et Techniques',
            'code' => 'FST',
            'description' => 'Sciences et technologies',
            'active' => true,
        ]);

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2025-2026',
            'date_debut' => '2025-09-01',
            'date_fin' => '2026-06-30',
            'active' => true,
        ]);

        app(PeriodeInscriptionService::class)->creerNormale([
            'annee_universitaire_id' => $this->annee->id,
            'opened_at' => '2020-01-01 00:00',
            'closed_at' => '2030-12-31 23:59',
        ], null);
    }

    public function test_page_affiche_assistant_guide_et_indicateurs_de_progression(): void
    {
        $response = $this->get(route('inscription.create'));

        $response->assertStatus(200);
        $response->assertSee('Parcours guidé');
        $response->assertSee('Prochaine action :');
        $response->assertSee('libelleCategorie()', false);
        $response->assertSee('titreEtapeCourante()', false);
        $response->assertSee('Catégorie d\'inscription / Situation académique', false);
        $response->assertSee('Première inscription');
        $response->assertSee('Réinscription');
        $response->assertSee('Transfert / Passerelle');
    }

    public function test_alignement_etapes_stepper_et_champs_x_show(): void
    {
        $contenu = (string) $this->get(route('inscription.create'))->getContent();

        $this->assertStringContainsString('data-etape-contenu="2" x-show="etape === 2"', $contenu);
        $this->assertStringContainsString('data-etape-contenu="3" x-show="etape === 3"', $contenu);
        $this->assertStringContainsString('data-etape-contenu="4" x-show="etape === 4"', $contenu);
        $this->assertStringContainsString('data-etape-contenu="5" x-show="etape === 5"', $contenu);
        $this->assertStringContainsString('data-etape-contenu="6" x-show="etape === 6"', $contenu);
    }

    public function test_soumission_avec_type_inscription_premiere_reussie(): void
    {
        MensReference::create([
            'cin_normalise' => '08000001',
            'cin_original' => '08000001',
            'date_naissance' => '2004-05-10',
            'nom' => 'Benali',
            'prenom' => 'Karim',
            'annee' => '2025-2026',
            'source_hash' => hash('sha256', '080000012004-05-10'),
        ]);

        $payload = [
            'cin' => '08000001',
            'nom' => 'Benali',
            'prenom' => 'Karim',
            'date_naissance' => '2004-05-10',
            'sexe' => 'M',
            'email' => 'karim.benali@example.com',
            'telephone' => '+212600000001',
            'ville' => 'Rabat',
            'code_postal' => '10000',
            'adresse' => 'Avenue Mohammed V',
            'annee_bac' => '2024',
            'mention_bac' => 'Bien',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2025-2026',
            'type_inscription' => 'premiere',
            'password' => 'Secret2026@Pass',
            'password_confirmation' => 'Secret2026@Pass',
            'confirmation' => '1',
        ];

        $response = $this->post(route('inscription.store'), $payload);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('inscription.success'));
        $this->assertDatabaseHas('etudiants', [
            'cin' => '08000001',
            'email' => 'karim.benali@example.com',
            'faculte_id' => $this->faculte->id,
        ]);
    }

    public function test_soumission_avec_type_inscription_transfert_reussie(): void
    {
        MensReference::create([
            'cin_normalise' => '08000002',
            'cin_original' => '08000002',
            'date_naissance' => '2003-08-20',
            'nom' => 'Mansouri',
            'prenom' => 'Sara',
            'annee' => '2025-2026',
            'source_hash' => hash('sha256', '080000022003-08-20'),
        ]);

        $payload = [
            'cin' => '08000002',
            'nom' => 'Mansouri',
            'prenom' => 'Sara',
            'date_naissance' => '2003-08-20',
            'sexe' => 'F',
            'email' => 'sara.mansouri@example.com',
            'telephone' => '+212600000002',
            'ville' => 'Casablanca',
            'code_postal' => '20000',
            'adresse' => 'Boulevard d\'Anfa',
            'annee_bac' => '2022',
            'mention_bac' => 'Très Bien',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L3',
            'annee_academique' => '2025-2026',
            'type_inscription' => 'transfert',
            'password' => 'Secret2026@Pass',
            'password_confirmation' => 'Secret2026@Pass',
            'confirmation' => '1',
        ];

        $response = $this->post(route('inscription.store'), $payload);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('inscription.success'));
        $this->assertDatabaseHas('etudiants', [
            'cin' => '08000002',
            'email' => 'sara.mansouri@example.com',
        ]);
    }

    public function test_soumission_avec_type_inscription_invalide_rejettee_par_serveur(): void
    {
        $payload = [
            'cin' => '08000003',
            'nom' => 'Test',
            'prenom' => 'Invalid',
            'date_naissance' => '2004-01-01',
            'sexe' => 'M',
            'email' => 'test.invalid@example.com',
            'type_inscription' => 'categorie_frauduleuse',
        ];

        $response = $this->post(route('inscription.store'), $payload);

        $response->assertSessionHasErrors(['type_inscription']);
    }
}
