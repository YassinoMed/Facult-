<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\MensReference;
use App\Services\PeriodeInscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOOP 03 : Sauvegarde automatique et reprise du formulaire.
 * Vérifie l'indicateur d'état, la reprise locale sécurisée,
 * l'absence d'écriture anticipée en base et la purge après succès.
 */
class InscriptionBrouillonTest extends TestCase
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

    public function test_page_contient_les_elements_d_indicateur_de_brouillon(): void
    {
        $response = $this->get(route('inscription.create'));

        $response->assertStatus(200);
        $response->assertSee('statut-brouillon-indicateur');
        $response->assertSee('Enregistrement…');
        $response->assertSee('Brouillon enregistré');
        $response->assertSee('Échec sauvegarde');
        $response->assertSee('Hors-ligne');
        $response->assertSee('effacerBrouillon()', false);
        $response->assertSee('brouillon.restaure', false);
    }

    public function test_page_succes_purge_le_brouillon_local(): void
    {
        $response = $this->get(route('inscription.success'));

        $response->assertStatus(200);
        $response->assertSee("localStorage.removeItem('inscription-brouillon-v1')", false);
    }

    public function test_aucune_creation_officielle_en_base_sans_soumission_explicite(): void
    {
        MensReference::create([
            'cin_normalise' => '61000001',
            'cin_original' => '61000001',
            'date_naissance' => '2004-06-12',
            'nom' => 'Idrissi',
            'prenom' => 'Hassan',
            'annee' => '2025-2026',
            'source_hash' => hash('sha256', 'DRAFT0012004-06-12'),
        ]);

        // La consultation et la vérification d'identité ne créent aucun étudiant
        $this->get(route('inscription.create'))->assertOk();

        $this->postJson(route('inscription.identifier'), [
            'cin' => '61000001',
            'date_naissance' => '2004-06-12',
        ])->assertOk();

        // Base de données strictement intacte (0 dossier créé)
        $this->assertSame(0, Etudiant::count());
    }

    public function test_soumission_officielle_enregistre_correctement_et_redirige_vers_succes(): void
    {
        MensReference::create([
            'cin_normalise' => '61000002',
            'cin_original' => '61000002',
            'date_naissance' => '2004-09-18',
            'nom' => 'Chraibi',
            'prenom' => 'Nadia',
            'annee' => '2025-2026',
            'source_hash' => hash('sha256', 'DRAFT0022004-09-18'),
        ]);

        $payload = [
            'cin' => '61000002',
            'nom' => 'Chraibi',
            'prenom' => 'Nadia',
            'date_naissance' => '2004-09-18',
            'sexe' => 'F',
            'email' => 'nadia.chraibi@example.com',
            'telephone' => '+212600000003',
            'ville' => 'Rabat',
            'code_postal' => '10000',
            'adresse' => 'Quartier Agdal',
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
        $this->assertSame(1, Etudiant::count());
        $this->assertDatabaseHas('etudiants', [
            'cin' => '61000002',
            'email' => 'nadia.chraibi@example.com',
        ]);
    }
}
