<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\MensReference;
use App\Models\User;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Services\EligibiliteParcoursService;
use App\Services\PeriodeInscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests fonctionnels et d'intégration pour LOOP 08 :
 * Contrôles d'éligibilité et orientation vers le bon parcours académique.
 */
class InscriptionEligibiliteOrientationTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculteSciences;

    private Faculte $faculteLettres;

    private Programme $programmeMathInfo;

    private Programme $programmeMasterData;

    private AnneeUniversitaire $anneeUniv;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculteSciences = Faculte::create([
            'nom' => 'Faculté des Sciences',
            'code' => 'FS',
            'active' => true,
        ]);

        $this->faculteLettres = Faculte::create([
            'nom' => 'Faculté des Lettres',
            'code' => 'FL',
            'active' => true,
        ]);

        $this->programmeMathInfo = Programme::create([
            'faculte_id' => $this->faculteSciences->id,
            'code' => 'L-MI',
            'nom' => 'Licence Mathématiques et Informatique',
            'statut' => Programme::STATUT_ACTIF,
            'capacite' => 50,
        ]);

        Niveau::create([
            'programme_id' => $this->programmeMathInfo->id,
            'code' => 'L1',
            'nom' => 'Licence 1',
            'ordre' => 1,
            'actif' => true,
        ]);
        Niveau::create([
            'programme_id' => $this->programmeMathInfo->id,
            'code' => 'L2',
            'nom' => 'Licence 2',
            'ordre' => 2,
            'actif' => true,
        ]);
        Niveau::create([
            'programme_id' => $this->programmeMathInfo->id,
            'code' => 'L3',
            'nom' => 'Licence 3',
            'ordre' => 3,
            'actif' => true,
        ]);

        $this->programmeMasterData = Programme::create([
            'faculte_id' => $this->faculteSciences->id,
            'code' => 'M-DATA',
            'nom' => 'Master Science des Données',
            'statut' => Programme::STATUT_ACTIF,
            'capacite' => 2,
        ]);

        Niveau::create([
            'programme_id' => $this->programmeMasterData->id,
            'code' => 'M1',
            'nom' => 'Master 1',
            'ordre' => 1,
            'actif' => true,
        ]);
        Niveau::create([
            'programme_id' => $this->programmeMasterData->id,
            'code' => 'M2',
            'nom' => 'Master 2',
            'ordre' => 2,
            'actif' => true,
        ]);

        $this->anneeUniv = AnneeUniversitaire::create([
            'code' => '2025-2026',
            'libelle' => '2025-2026',
            'date_debut' => '2025-09-01',
            'date_fin' => '2026-06-30',
            'active' => true,
        ]);

        app(PeriodeInscriptionService::class)->creerNormale([
            'annee_universitaire_id' => $this->anneeUniv->id,
            'opened_at' => '2020-01-01 00:00',
            'closed_at' => '2030-12-31 23:59',
        ], null);
    }

    private function creerMens(string $cin, string $dateNaissance, array $attributs = []): MensReference
    {
        return MensReference::create(array_merge([
            'cin_original' => $cin,
            'cin_normalise' => MensReference::normaliserCin($cin),
            'date_naissance' => $dateNaissance,
            'nom' => 'Alami',
            'prenom' => 'Sara',
            'sexe' => 'F',
            'nationalite' => 'Marocaine',
            'annee' => '2025-2026',
            'source_hash' => str_repeat('b', 64),
        ], $attributs));
    }

    private function donneesInscription(array $surcharges = []): array
    {
        return array_merge([
            'cin' => '08123456',
            'nom' => 'Alami',
            'prenom' => 'Sara',
            'email' => 'sara.alami@test.ma',
            'telephone' => '+212611223344',
            'date_naissance' => '2005-04-12',
            'sexe' => 'F',
            'faculte_id' => $this->faculteSciences->id,
            'programme_id' => $this->programmeMathInfo->id,
            'niveau' => 'L1',
            'annee_academique' => '2025-2026',
            'adresse' => 'Avenue Mohammed V',
            'ville' => 'Rabat',
            'code_postal' => '10000',
            'annee_bac' => (string) date('Y'),
            'mention_bac' => 'Bien',
            'type_inscription' => 'premiere',
            'password' => 'Passw0rdSecurise!',
            'password_confirmation' => 'Passw0rdSecurise!',
        ], $surcharges);
    }

    public function test_choix_valide_programme_et_niveau_conforme_accepte(): void
    {
        $this->creerMens('08123456', '2005-04-12');

        $reponse = $this->post(route('inscription.store'), $this->donneesInscription());

        $reponse->assertRedirect(route('inscription.success'));
        $this->assertDatabaseHas('etudiants', [
            'cin' => '08123456',
            'programme_id' => $this->programmeMathInfo->id,
            'niveau' => 'L1',
        ]);

        $etudiant = Etudiant::where('cin', '08123456')->firstOrFail();
        $this->assertNotNull($etudiant->niveau_id);
    }

    public function test_programme_incompatible_avec_faculte_bloque_cote_serveur(): void
    {
        $this->creerMens('08123456', '2005-04-12');

        // Programme des Sciences soumis avec Faculté des Lettres
        $reponse = $this->post(route('inscription.store'), $this->donneesInscription([
            'faculte_id' => $this->faculteLettres->id,
            'programme_id' => $this->programmeMathInfo->id,
        ]));

        $reponse->assertSessionHasErrors('programme_id');
        $this->assertSame(0, Etudiant::count());
    }

    public function test_programme_inactif_bloque_cote_serveur(): void
    {
        $this->creerMens('08123456', '2005-04-12');

        $this->programmeMathInfo->update([
            'statut' => Programme::STATUT_INACTIF,
            'actif' => false,
        ]);

        $reponse = $this->post(route('inscription.store'), $this->donneesInscription());

        $reponse->assertSessionHasErrors('programme_id');
        $this->assertSame(0, Etudiant::count());
    }

    public function test_niveau_non_propose_dans_programme_bloque_cote_serveur(): void
    {
        $this->creerMens('08123456', '2005-04-12');

        // Licence Math-Info propose L1, L2, L3 mais pas M1
        $reponse = $this->post(route('inscription.store'), $this->donneesInscription([
            'programme_id' => $this->programmeMathInfo->id,
            'niveau' => 'M1',
        ]));

        $reponse->assertSessionHasErrors('niveau');
        $this->assertSame(0, Etudiant::count());
    }

    public function test_premiere_inscription_niveau_avance_sans_admission_bloquee_avec_orientation(): void
    {
        $this->creerMens('08123456', '2005-04-12');

        // Première inscription directe en Master 1 sans admission officielle préalable
        $reponse = $this->post(route('inscription.store'), $this->donneesInscription([
            'programme_id' => $this->programmeMasterData->id,
            'niveau' => 'M1',
            'type_inscription' => 'premiere',
        ]));

        $reponse->assertSessionHasErrors('niveau');
        $this->assertSame(0, Etudiant::count());
    }

    public function test_premiere_inscription_niveau_avance_avec_admission_officielle_acceptee(): void
    {
        $this->creerMens('08123456', '2005-04-12');

        // Création d'un dossier étudiant préexistant avec admission officielle
        $etudiantExistant = Etudiant::create([
            'cin' => '08123456',
            'nom' => 'Alami',
            'prenom' => 'Sara',
            'email' => 'sara.preexistante@test.ma',
            'sexe' => 'F',
            'faculte_id' => $this->faculteSciences->id,
            'niveau' => 'L3',
            'annee_academique' => '2024-2025',
            'adresse' => 'Rabat',
            'ville' => 'Rabat',
            'code_postal' => '10000',
            'annee_bac' => '2022',
            'password' => bcrypt('Secret123!'),
        ]);

        $agent = User::factory()->create();

        Admission::create([
            'etudiant_id' => $etudiantExistant->id,
            'programme_id' => $this->programmeMasterData->id,
            'annee_universitaire_id' => $this->anneeUniv->id,
            'decision' => 'admis',
            'motif' => 'Admis sur concours Master 2025-2026',
            'decided_by' => $agent->id,
            'decided_at' => now(),
        ]);

        $service = app(EligibiliteParcoursService::class);
        $bilan = $service->evaluer([
            'faculte_id' => $this->faculteSciences->id,
            'programme_id' => $this->programmeMasterData->id,
            'niveau' => 'M1',
            'type_inscription' => 'premiere',
            'cin' => '08123456',
            'annee_academique' => '2025-2026',
        ]);

        $this->assertTrue($bilan['eligible']);
        $this->assertEmpty($bilan['bloquants']);
    }

    public function test_referentiel_incomplet_sans_programmes_traite_explicitement_sans_plantage(): void
    {
        $this->creerMens('08123456', '2005-04-12');

        // Faculté des Lettres sans aucun programme configuré
        $reponse = $this->post(route('inscription.store'), $this->donneesInscription([
            'faculte_id' => $this->faculteLettres->id,
            'programme_id' => null,
            'niveau' => 'L1',
        ]));

        $reponse->assertRedirect(route('inscription.success'));
        $this->assertDatabaseHas('etudiants', [
            'cin' => '08123456',
            'faculte_id' => $this->faculteLettres->id,
            'programme_id' => null,
            'niveau' => 'L1',
        ]);
    }

    public function test_capacite_programme_atteinte_bloque_avec_explication(): void
    {
        $this->creerMens('08123456', '2005-04-12');

        // Capacité du master data = 2 places. On en crée 2 existantes.
        for ($i = 1; $i <= 2; $i++) {
            Etudiant::create([
                'cin' => sprintf('080000%02d', $i),
                'nom' => "Etudiant{$i}",
                'prenom' => 'Test',
                'email' => "etud{$i}@test.ma",
                'sexe' => 'M',
                'faculte_id' => $this->faculteSciences->id,
                'programme_id' => $this->programmeMasterData->id,
                'niveau' => 'M1',
                'annee_academique' => '2025-2026',
                'adresse' => 'Rabat',
                'ville' => 'Rabat',
                'code_postal' => '10000',
                'annee_bac' => '2022',
                'password' => bcrypt('Secret123!'),
            ]);
        }

        $service = app(EligibiliteParcoursService::class);
        $bilan = $service->evaluer([
            'faculte_id' => $this->faculteSciences->id,
            'programme_id' => $this->programmeMasterData->id,
            'niveau' => 'M1',
            'annee_academique' => '2025-2026',
            'type_inscription' => 'premiere',
        ]);

        $this->assertFalse($bilan['eligible']);
        $this->assertArrayHasKey('programme_id', $bilan['bloquants']);
        $this->assertStringContainsString('capacité d\'accueil', $bilan['bloquants']['programme_id']);
    }

    public function test_exception_administrative_derogation_permet_inscription_et_est_tracee(): void
    {
        $this->creerMens('08123456', '2005-04-12');

        // Première inscription en M1 autorisée grâce à une dérogation administrative
        $reponse = $this->post(route('inscription.store'), $this->donneesInscription([
            'programme_id' => $this->programmeMasterData->id,
            'niveau' => 'M1',
            'derogation_administrative' => 'Décision Rectorale 2026/D-88',
        ]));

        $reponse->assertRedirect(route('inscription.success'));
        $etudiant = Etudiant::where('cin', '08123456')->firstOrFail();

        // Vérification de la trace d'audit sur l'exception accordée
        $this->assertDatabaseHas('historique_academique', [
            'etudiant_id' => $etudiant->id,
            'action' => 'qualite.derogation_eligibilite',
        ]);
    }

    public function test_changement_configuration_apres_affichage_intercepte_a_la_soumission(): void
    {
        $this->creerMens('08123456', '2005-04-12');

        // Simulation : après chargement de la page, l'administrateur désactive le programme
        $this->programmeMathInfo->update(['statut' => Programme::STATUT_INACTIF, 'actif' => false]);

        $reponse = $this->post(route('inscription.store'), $this->donneesInscription([
            'programme_id' => $this->programmeMathInfo->id,
        ]));

        $reponse->assertSessionHasErrors('programme_id');
        $this->assertSame(0, Etudiant::count());
    }

    public function test_endpoint_public_options_orientation_retourne_options_sans_donnees_sensibles(): void
    {
        $reponse = $this->getJson(route('inscription.orientation-options', [
            'faculte_id' => $this->faculteSciences->id,
            'type_inscription' => 'premiere',
        ]));

        $reponse->assertOk();
        $reponse->assertJsonStructure([
            'faculte_id',
            'programmes' => [
                '*' => [
                    'id',
                    'code',
                    'nom',
                    'actif',
                    'statut',
                    'capacite',
                    'complet',
                    'niveaux',
                ],
            ],
            'referentiel_incomplet',
        ]);

        $data = $reponse->json();
        $this->assertCount(2, $data['programmes']);
        $this->assertSame($this->faculteSciences->id, $data['faculte_id']);
    }
}
