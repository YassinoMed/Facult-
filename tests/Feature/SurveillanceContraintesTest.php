<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Models\Surveillance;
use App\Modules\Evaluation\Models\SurveillanceAssignmentRun;
use App\Modules\Evaluation\Models\SurveillanceConstraintConfig;
use App\Modules\Evaluation\Services\ContraintesSurveillance;
use App\Modules\Evaluation\Services\SurveillanceConstraintResolver;
use App\Modules\Evaluation\Services\SurveillanceEquilibrageService;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SurveillanceContraintesTest extends TestCase
{
    use RefreshDatabase;

    private User $adminScolarite;

    private User $adminFacA;

    private User $adminFacB;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private AnneeUniversitaire $annee;

    private SessionExamen $session;

    private Examen $examen;

    private Programme $programme;

    private Matiere $matiere;

    private Room $salle;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.evaluation.active' => true]);

        $this->faculteA = Faculte::create(['nom' => 'Faculté des Sciences', 'code' => 'FST', 'active' => true]);
        $this->faculteB = Faculte::create(['nom' => 'Faculté des Lettres', 'code' => 'FLSH', 'active' => true]);

        $this->adminScolarite = User::creerCompteBackOffice([
            'name' => 'Admin Scolarité Global',
            'email' => 'admin.global@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->adminFacA = User::creerCompteBackOffice([
            'name' => 'Admin Scolarité FST',
            'email' => 'admin.fst@universite.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteA->id,
        ], 'admin_faculte');

        $this->adminFacB = User::creerCompteBackOffice([
            'name' => 'Admin Scolarité FLSH',
            'email' => 'admin.flsh@universite.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteB->id,
        ], 'admin_faculte');

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->programme = Programme::create([
            'code' => 'L-INFO',
            'nom' => 'Licence Informatique',
            'faculte_id' => $this->faculteA->id,
            'cycle' => 'licence',
            'duree_annees' => 3,
            'actif' => true,
        ]);

        $this->session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'SESS-2026-TEST',
            'nom' => 'Session Janvier 2027',
            'type' => 'principale',
            'statut' => 'open',
            'date_debut' => '2027-01-10',
            'date_fin' => '2027-01-20',
        ]);

        $this->examen = Examen::create([
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programme->id,
            'libelle' => 'Examen Test',
            'type' => 'examen',
            'statut' => 'scheduled',
            'date_debut' => '2027-01-10',
            'date_fin' => '2027-01-15',
        ]);

        $this->matiere = Matiere::create([
            'code' => 'INFO-101',
            'nom' => 'Algorithmique',
            'niveau' => 'L1',
            'semestre' => 1,
            'coefficient' => 2,
            'credits' => 3,
        ]);

        $this->salle = Room::create([
            'code' => 'AMPHI-A',
            'nom' => 'Amphithéâtre A',
            'capacite' => 150,
            'actif' => true,
            'faculte_id' => $this->faculteA->id,
        ]);
    }

    public function test_valeurs_par_defaut_identiques_aux_constantes_historiques(): void
    {
        $resolver = app(SurveillanceConstraintResolver::class);
        $contraintes = $resolver->resoudre(null, null, null);

        $this->assertSame(SurveillanceEquilibrageService::MIN_SURVEILLANTS_ACTIFS, $contraintes->minSurveillants);
        $this->assertSame(SurveillanceEquilibrageService::RATIO_ETUDIANTS_PAR_SURVEILLANT, $contraintes->ratioEtudiants);
        $this->assertSame(SurveillanceEquilibrageService::MIN_GARDES_RESERVE, $contraintes->nbGardes);
        $this->assertSame(SurveillanceEquilibrageService::CIBLE_JOURS_PRESENCE, $contraintes->maxJours);
        $this->assertSame('souple', $contraintes->modeLimite);
        $this->assertEquals(SurveillanceEquilibrageService::POIDS_CHARGE_TOTALE, $contraintes->poidsChargeTotaleEffectif());
        $this->assertEquals(SurveillanceEquilibrageService::POIDS_CHARGE_JOUR, $contraintes->poidsChargeJourEffectif());
        $this->assertEquals(SurveillanceEquilibrageService::POIDS_GARDES, $contraintes->poidsGardesEffectif());
        $this->assertEquals(SurveillanceEquilibrageService::PENALITE_DEPASSEMENT_3_JOURS, $contraintes->penaliteDepassementEffectif());
        $this->assertEquals(SurveillanceEquilibrageService::PENALITE_DISPERSION_TEMPORELLE, $contraintes->penaliteDispersionEffectif());
        $this->assertEquals(SurveillanceEquilibrageService::PENALITE_NOUVEAU_JOUR, $contraintes->penaliteNouveauJourEffectif());
        $this->assertEquals(SurveillanceEquilibrageService::POIDS_SURVEILLANCE_ACTIVE, $contraintes->poidsSurveillanceActive);
        $this->assertEquals(SurveillanceEquilibrageService::POIDS_GARDE_RESERVE, $contraintes->poidsGardeReserve);
    }

    public function test_resolution_priorite_hierarchique(): void
    {
        $resolver = app(SurveillanceConstraintResolver::class);

        // 1. Système
        SurveillanceConstraintConfig::create([
            'faculte_id' => null,
            'annee_universitaire_id' => null,
            'session_examen_id' => null,
            'min_surveillants' => 3,
        ]);

        $c = $resolver->resoudre($this->faculteA->id, $this->annee->id, $this->session->id);
        $this->assertSame(3, $c->minSurveillants);

        // 2. Année
        SurveillanceConstraintConfig::create([
            'faculte_id' => null,
            'annee_universitaire_id' => $this->annee->id,
            'session_examen_id' => null,
            'min_surveillants' => 4,
        ]);

        $c = $resolver->resoudre($this->faculteA->id, $this->annee->id, $this->session->id);
        $this->assertSame(4, $c->minSurveillants);

        // 3. Faculté + Année
        SurveillanceConstraintConfig::create([
            'faculte_id' => $this->faculteA->id,
            'annee_universitaire_id' => $this->annee->id,
            'session_examen_id' => null,
            'min_surveillants' => 5,
        ]);

        $c = $resolver->resoudre($this->faculteA->id, $this->annee->id, $this->session->id);
        $this->assertSame(5, $c->minSurveillants);

        // 4. Session spécifique (priorité maximale)
        SurveillanceConstraintConfig::create([
            'faculte_id' => null,
            'annee_universitaire_id' => null,
            'session_examen_id' => $this->session->id,
            'min_surveillants' => 6,
        ]);

        $c = $resolver->resoudre($this->faculteA->id, $this->annee->id, $this->session->id);
        $this->assertSame(6, $c->minSurveillants);
    }

    public function test_validation_rejets_valeurs_invalides(): void
    {
        $this->actingAs($this->adminScolarite);

        // A. ratio <= 0
        $response = $this->post(route('admin.evaluations.surveillances.contraintes.store'), [
            'ratio_etudiants' => 0,
            'min_surveillants' => 2,
            'nb_gardes' => 1,
            'max_jours' => 3,
            'mode_limite' => 'souple',
            'poids_surveillance_active' => 1.0,
            'poids_garde_reserve' => 0.5,
            'poids_charge_totale' => 10,
            'poids_charge_jour' => 15,
            'poids_gardes' => 8,
            'penalite_depassement_jours' => 35,
            'penalite_dispersion' => 10,
            'penalite_nouveau_jour' => 2,
        ]);
        $response->assertSessionHasErrors(['ratio_etudiants']);

        // B. min_surveillants < 1
        $response = $this->post(route('admin.evaluations.surveillances.contraintes.store'), [
            'ratio_etudiants' => 50,
            'min_surveillants' => 0,
            'nb_gardes' => 1,
            'max_jours' => 3,
            'mode_limite' => 'souple',
            'poids_surveillance_active' => 1.0,
            'poids_garde_reserve' => 0.5,
            'poids_charge_totale' => 10,
            'poids_charge_jour' => 15,
            'poids_gardes' => 8,
            'penalite_depassement_jours' => 35,
            'penalite_dispersion' => 10,
            'penalite_nouveau_jour' => 2,
        ]);
        $response->assertSessionHasErrors(['min_surveillants']);

        // C. max_surveillants < min_surveillants
        $response = $this->post(route('admin.evaluations.surveillances.contraintes.store'), [
            'ratio_etudiants' => 50,
            'min_surveillants' => 4,
            'max_surveillants' => 2,
            'nb_gardes' => 1,
            'max_jours' => 3,
            'mode_limite' => 'souple',
            'poids_surveillance_active' => 1.0,
            'poids_garde_reserve' => 0.5,
            'poids_charge_totale' => 10,
            'poids_charge_jour' => 15,
            'poids_gardes' => 8,
            'penalite_depassement_jours' => 35,
            'penalite_dispersion' => 10,
            'penalite_nouveau_jour' => 2,
        ]);
        $response->assertSessionHasErrors(['max_surveillants']);

        // D. nb_gardes < 0
        $response = $this->post(route('admin.evaluations.surveillances.contraintes.store'), [
            'ratio_etudiants' => 50,
            'min_surveillants' => 2,
            'nb_gardes' => -1,
            'max_jours' => 3,
            'mode_limite' => 'souple',
            'poids_surveillance_active' => 1.0,
            'poids_garde_reserve' => 0.5,
            'poids_charge_totale' => 10,
            'poids_charge_jour' => 15,
            'poids_gardes' => 8,
            'penalite_depassement_jours' => 35,
            'penalite_dispersion' => 10,
            'penalite_nouveau_jour' => 2,
        ]);
        $response->assertSessionHasErrors(['nb_gardes']);
    }

    public function test_creation_et_modification_configuration_avec_revision_audit(): void
    {
        $this->actingAs($this->adminScolarite);

        $donnees = [
            'session_examen_id' => $this->session->id,
            'ratio_etudiants' => 60,
            'min_surveillants' => 3,
            'nb_gardes' => 2,
            'max_jours' => 4,
            'mode_limite' => 'strict',
            'poids_surveillance_active' => 1.5,
            'poids_garde_reserve' => 0.8,
            'poids_charge_totale' => 12.0,
            'poids_charge_jour' => 18.0,
            'poids_gardes' => 9.0,
            'penalite_depassement_jours' => 40.0,
            'penalite_dispersion' => 12.0,
            'penalite_nouveau_jour' => 3.0,
            'actif_charge_totale' => 1,
            'actif_charge_jour' => 1,
            'actif_gardes' => 1,
            'actif_limite_jours' => 1,
            'actif_dispersion' => 1,
            'actif_nouveau_jour' => 1,
        ];

        $response = $this->post(route('admin.evaluations.surveillances.contraintes.store'), $donnees);
        $response->assertRedirect();

        $config = SurveillanceConstraintConfig::where('session_examen_id', $this->session->id)->first();
        $this->assertNotNull($config);
        $this->assertSame(1, $config->version);
        $this->assertSame(60, $config->ratio_etudiants);
        $this->assertSame('strict', $config->mode_limite);
        $this->assertSame($this->adminScolarite->id, $config->created_by);

        // Une révision d'audit doit avoir été générée
        $this->assertDatabaseHas('surveillance_constraint_revisions', [
            'config_id' => $config->id,
            'version' => 1,
            'auteur_id' => $this->adminScolarite->id,
        ]);

        // Deuxième mise à jour : incrémentation de version
        $donnees['ratio_etudiants'] = 75;
        $this->post(route('admin.evaluations.surveillances.contraintes.store'), $donnees);

        $config->refresh();
        $this->assertSame(2, $config->version);
        $this->assertSame(75, $config->ratio_etudiants);

        $this->assertDatabaseHas('surveillance_constraint_revisions', [
            'config_id' => $config->id,
            'version' => 2,
        ]);
    }

    public function test_reinitialisation_configuration_scope(): void
    {
        $this->actingAs($this->adminScolarite);

        $config = SurveillanceConstraintConfig::create([
            'session_examen_id' => $this->session->id,
            'min_surveillants' => 4,
            'version' => 1,
        ]);

        $this->post(route('admin.evaluations.surveillances.contraintes.reset'), [
            'session_id' => $this->session->id,
        ])->assertRedirect();

        $this->assertDatabaseMissing('surveillance_constraint_configs', [
            'id' => $config->id,
        ]);
    }

    public function test_simulation_en_memoire_sans_ecriture_en_base(): void
    {
        $this->actingAs($this->adminScolarite);

        // Créer 1 épreuve
        Epreuve::create([
            'session_id' => $this->session->id,
            'examen_id' => $this->examen->id,
            'matiere_id' => $this->matiere->id,
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'EPR-SIMUL-1',
            'date' => '2027-01-11',
            'heure_debut' => '08:30:00',
            'heure_fin' => '10:30:00',
            'duree_minutes' => 120,
            'salle_id' => $this->salle->id,
            'capacite_necessaire' => 60,
            'statut' => 'scheduled',
        ]);

        // Créer 4 enseignants
        for ($i = 1; $i <= 4; $i++) {
            Teacher::create([
                'nom' => "Nom {$i}",
                'prenom' => "Prenom {$i}",
                'email' => "teacher{$i}@universite.test",
                'actif' => true,
            ]);
        }

        $surveillancesAvant = Surveillance::count();

        $payload = [
            'session_id' => $this->session->id,
            'ratio_etudiants' => 50,
            'min_surveillants' => 2,
            'nb_gardes' => 1,
            'max_jours' => 3,
            'mode_limite' => 'souple',
            'poids_surveillance_active' => 1.0,
            'poids_garde_reserve' => 0.5,
            'poids_charge_totale' => 10,
            'poids_charge_jour' => 15,
            'poids_gardes' => 8,
            'penalite_depassement_jours' => 35,
            'penalite_dispersion' => 10,
            'penalite_nouveau_jour' => 2,
        ];

        $response = $this->postJson(route('admin.evaluations.surveillances.contraintes.simuler'), $payload);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('total_epreuves', 1)
            ->assertJsonPath('total_surveillants_actifs', 2)
            ->assertJsonPath('total_gardes_reserve', 1);

        $this->assertContains($response->json('statut'), ['OPTIMALE', 'REALISABLE']);

        // Vérifier 0 écriture en base
        $this->assertSame($surveillancesAvant, Surveillance::count());
    }

    public function test_mode_strict_hard_bloque_depassement_jours_et_produit_diagnostic_structure(): void
    {
        $equilibrage = app(SurveillanceEquilibrageService::class);

        // Créer 1 enseignant unique
        $teacher = Teacher::create([
            'nom' => 'Solo',
            'prenom' => 'Prof',
            'email' => 'solo@universite.test',
            'actif' => true,
        ]);

        // Créer 2 épreuves sur 2 jours différents (J1 et J2)
        Epreuve::create([
            'session_id' => $this->session->id,
            'examen_id' => $this->examen->id,
            'matiere_id' => $this->matiere->id,
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'EPR-J1',
            'date' => '2027-01-11',
            'heure_debut' => '08:30:00',
            'heure_fin' => '10:30:00',
            'duree_minutes' => 120,
            'salle_id' => $this->salle->id,
            'capacite_necessaire' => 20,
            'statut' => 'scheduled',
        ]);

        Epreuve::create([
            'session_id' => $this->session->id,
            'examen_id' => $this->examen->id,
            'matiere_id' => $this->matiere->id,
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'EPR-J2',
            'date' => '2027-01-12',
            'heure_debut' => '08:30:00',
            'heure_fin' => '10:30:00',
            'duree_minutes' => 120,
            'salle_id' => $this->salle->id,
            'capacite_necessaire' => 20,
            'statut' => 'scheduled',
        ]);

        // Contrainte STRICT : max_jours = 1 (interdit formellement d'ouvrir un 2e jour pour cet enseignant)
        $contraintesStrict = ContraintesSurveillance::depuisParams([
            'min_surveillants' => 1,
            'nb_gardes' => 0,
            'max_jours' => 1,
            'mode_limite' => 'strict',
        ]);

        $simulation = $equilibrage->simulerSession($this->session, [
            'contraintes' => $contraintesStrict,
        ]);

        $this->assertSame('IMPOSSIBLE', $simulation['statut']);
        $this->assertGreaterThan(0, $simulation['conflits_detectes']);

        // Vérification de la présence du diagnostic structuré
        $this->assertNotEmpty($simulation['anomalies']);
        $anomalie = $simulation['anomalies'][0];
        $this->assertStringContainsString('plafond strict de 1 jours', $anomalie);
    }

    public function test_mode_souple_soft_permet_solution_realisable_avec_degradation(): void
    {
        $equilibrage = app(SurveillanceEquilibrageService::class);

        Teacher::create([
            'nom' => 'Solo',
            'prenom' => 'Prof',
            'email' => 'solo2@universite.test',
            'actif' => true,
        ]);

        Epreuve::create([
            'session_id' => $this->session->id,
            'examen_id' => $this->examen->id,
            'matiere_id' => $this->matiere->id,
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'EPR-S1',
            'date' => '2027-01-11',
            'heure_debut' => '08:30:00',
            'heure_fin' => '10:30:00',
            'duree_minutes' => 120,
            'salle_id' => $this->salle->id,
            'capacite_necessaire' => 20,
            'statut' => 'scheduled',
        ]);

        Epreuve::create([
            'session_id' => $this->session->id,
            'examen_id' => $this->examen->id,
            'matiere_id' => $this->matiere->id,
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'EPR-S2',
            'date' => '2027-01-12',
            'heure_debut' => '08:30:00',
            'heure_fin' => '10:30:00',
            'duree_minutes' => 120,
            'salle_id' => $this->salle->id,
            'capacite_necessaire' => 20,
            'statut' => 'scheduled',
        ]);

        // Mode SOUPLE avec max_jours = 1 : l'enseignant peut prendre le 2e jour avec pénalité
        $contraintesSouple = ContraintesSurveillance::depuisParams([
            'min_surveillants' => 1,
            'nb_gardes' => 0,
            'max_jours' => 1,
            'mode_limite' => 'souple',
        ]);

        $simulation = $equilibrage->simulerSession($this->session, [
            'contraintes' => $contraintesSouple,
        ]);

        $this->assertSame('REALISABLE', $simulation['statut']);
        $this->assertNotEmpty($simulation['contraintes_souples_degradees']);
    }

    public function test_isolation_faculte_et_idor_protection(): void
    {
        // Admin Fac A tente de modifier la configuration pour Faculte B
        $this->actingAs($this->adminFacA);

        $response = $this->post(route('admin.evaluations.surveillances.contraintes.store'), [
            'faculte_id' => $this->faculteB->id,
            'annee_universitaire_id' => $this->annee->id,
            'ratio_etudiants' => 50,
            'min_surveillants' => 2,
            'nb_gardes' => 1,
            'max_jours' => 3,
            'mode_limite' => 'souple',
            'poids_surveillance_active' => 1.0,
            'poids_garde_reserve' => 0.5,
            'poids_charge_totale' => 10,
            'poids_charge_jour' => 15,
            'poids_gardes' => 8,
            'penalite_depassement_jours' => 35,
            'penalite_dispersion' => 10,
            'penalite_nouveau_jour' => 2,
        ]);

        $response->assertForbidden();

        // Tentative d'accès IDOR à la configuration de la Faculté B en modification -> 404
        $configB = SurveillanceConstraintConfig::create([
            'faculte_id' => $this->faculteB->id,
            'annee_universitaire_id' => $this->annee->id,
            'min_surveillants' => 2,
            'version' => 1,
        ]);

        $this->put(route('admin.evaluations.surveillances.contraintes.update', $configB), [
            'ratio_etudiants' => 50,
            'min_surveillants' => 3,
            'nb_gardes' => 1,
            'max_jours' => 3,
            'mode_limite' => 'souple',
            'poids_surveillance_active' => 1.0,
            'poids_garde_reserve' => 0.5,
            'poids_charge_totale' => 10,
            'poids_charge_jour' => 15,
            'poids_gardes' => 8,
            'penalite_depassement_jours' => 35,
            'penalite_dispersion' => 10,
            'penalite_nouveau_jour' => 2,
        ])->assertNotFound();
    }

    public function test_determinisme_deux_executions_avec_meme_snapshot_produisent_meme_resultat(): void
    {
        $equilibrage = app(SurveillanceEquilibrageService::class);

        // Créer 6 enseignants
        for ($i = 1; $i <= 6; $i++) {
            Teacher::create([
                'nom' => "Enseignant {$i}",
                'prenom' => "Prenom {$i}",
                'email' => "det{$i}@universite.test",
                'actif' => true,
            ]);
        }

        // Créer 2 épreuves
        Epreuve::create([
            'session_id' => $this->session->id,
            'examen_id' => $this->examen->id,
            'matiere_id' => $this->matiere->id,
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'EPR-DET-1',
            'date' => '2027-01-11',
            'heure_debut' => '08:30:00',
            'heure_fin' => '10:30:00',
            'duree_minutes' => 120,
            'salle_id' => $this->salle->id,
            'capacite_necessaire' => 40,
            'statut' => 'scheduled',
        ]);

        Epreuve::create([
            'session_id' => $this->session->id,
            'examen_id' => $this->examen->id,
            'matiere_id' => $this->matiere->id,
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'EPR-DET-2',
            'date' => '2027-01-11',
            'heure_debut' => '14:00:00',
            'heure_fin' => '16:00:00',
            'duree_minutes' => 120,
            'salle_id' => $this->salle->id,
            'capacite_necessaire' => 40,
            'statut' => 'scheduled',
        ]);

        $contraintes = new ContraintesSurveillance;

        $simul1 = $equilibrage->simulerSession($this->session, ['contraintes' => $contraintes]);
        $simul2 = $equilibrage->simulerSession($this->session, ['contraintes' => $contraintes]);

        $this->assertEquals($simul1['propositions_epreuves'], $simul2['propositions_epreuves']);
        $this->assertEquals($simul1['indicateurs_charge'], $simul2['indicateurs_charge']);
    }

    public function test_auto_assigner_sauvegarde_le_config_snapshot_dans_le_run(): void
    {
        $this->actingAs($this->adminScolarite);

        // Créer 1 épreuve
        Epreuve::create([
            'session_id' => $this->session->id,
            'examen_id' => $this->examen->id,
            'matiere_id' => $this->matiere->id,
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'EPR-RUN-1',
            'date' => '2027-01-11',
            'heure_debut' => '08:30:00',
            'heure_fin' => '10:30:00',
            'duree_minutes' => 120,
            'salle_id' => $this->salle->id,
            'capacite_necessaire' => 30,
            'statut' => 'scheduled',
        ]);

        // Créer une config spécifique pour cette session
        SurveillanceConstraintConfig::create([
            'session_examen_id' => $this->session->id,
            'min_surveillants' => 4,
            'ratio_etudiants' => 45,
            'version' => 1,
        ]);

        $response = $this->post(route('admin.evaluations.affectations.auto'), [
            'session_id' => $this->session->id,
        ]);

        $run = SurveillanceAssignmentRun::where('session_examen_id', $this->session->id)->latest('id')->first();
        $this->assertNotNull($run);
        $this->assertNotNull($run->config_snapshot);
        $this->assertSame(4, $run->config_snapshot['min_surveillants']);
        $this->assertSame(45, $run->config_snapshot['ratio_etudiants']);
        $this->assertArrayHasKey('figee_le', $run->config_snapshot);
    }
}
