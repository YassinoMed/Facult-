<?php

namespace Tests\Feature;

use App\Models\AffectationGroupe;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\HistoriqueAcademique;
use App\Models\SituationEtudiant;
use App\Models\User;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use App\Services\Affectations\MassAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Suite de tests mature pour le module d'affectation en masse par niveau :
 * Couvre l'ensemble des 15 boucles : règles académiques, équilibrage, simulation zéro-écriture,
 * anomalies, prévisualisation, transaction, concurrence, idempotence, audit, sécurité.
 */
class MassAssignmentMatureTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private AnneeUniversitaire $annee;

    private Programme $programmeL1;

    private Group $groupe1;

    private Group $groupe2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculteA = Faculte::create([
            'nom' => 'Faculté des Sciences de Tunis',
            'code' => 'FST',
            'description' => 'Sciences',
            'active' => true,
        ]);

        $this->faculteB = Faculte::create([
            'nom' => 'Faculté de Médecine de Tunis',
            'code' => 'FMT',
            'description' => 'Médecine',
            'active' => true,
        ]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Admin Scolarité',
            'email' => 'admin.mature@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->programmeL1 = Programme::create([
            'faculte_id' => $this->faculteA->id,
            'code' => 'LIC-INFO',
            'nom' => 'Licence Informatique',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
        ]);

        $this->groupe1 = Group::create([
            'code' => 'L1-INFO-G1',
            'nom' => 'Groupe 1 Informatique L1',
            'faculte_id' => $this->faculteA->id,
            'programme_id' => $this->programmeL1->id,
            'niveau' => 'L1',
            'capacite' => 20,
            'actif' => true,
        ]);

        $this->groupe2 = Group::create([
            'code' => 'L1-INFO-G2',
            'nom' => 'Groupe 2 Informatique L1',
            'faculte_id' => $this->faculteA->id,
            'programme_id' => $this->programmeL1->id,
            'niveau' => 'L1',
            'capacite' => 20,
            'actif' => true,
        ]);
    }

    /**
     * Helper pour créer un étudiant complet et valide pour les tests.
     */
    private function creerEtudiant(
        string $cin = '11223344',
        string $niveau = 'L1',
        ?int $faculteId = null,
        ?int $programmeId = null,
        bool $avecInscription = true,
        string $statutInscription = 'en_cours'
    ): Etudiant {
        static $compteur = 100;
        $compteur++;

        $etudiant = Etudiant::create([
            'cin' => $cin,
            'num_inscription' => 'MAT-'.$compteur,
            'nom' => 'Etudiant'.$compteur,
            'prenom' => 'Test',
            'email' => "etudiant{$compteur}@mature.test",
            'date_naissance' => '2004-01-15',
            'sexe' => 'M',
            'nationalite' => 'Tunisienne',
            'lieu_naissance' => 'Tunis',
            'telephone' => '98000'.$compteur,
            'faculte_id' => $faculteId ?? $this->faculteA->id,
            'programme_id' => $programmeId ?? $this->programmeL1->id,
            'niveau' => $niveau,
            'annee_academique' => $this->annee->libelle,
            'adresse' => 'Avenue Habib Bourguiba',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2023',
            'password' => 'Secret@123',
        ]);

        $etudiant->definirStatut('valide');

        if ($avecInscription) {
            $etudiant->inscriptionsAcademiques()->create([
                'annee_universitaire_id' => $this->annee->id,
                'niveau' => $niveau,
                'statut' => $statutInscription,
            ]);
        }

        return $etudiant;
    }

    /**
     * LOOP 5 : Mode Simulation ZÉRO ÉCRITURE.
     */
    public function test_simulation_produit_zero_ecriture_en_base(): void
    {
        $e1 = $this->creerEtudiant('01010101');
        $e2 = $this->creerEtudiant('02020202');

        $nbAffectationsAvant = AffectationGroupe::count();
        $nbHistoriqueAvant = HistoriqueAcademique::count();

        $response = $this->actingAs($this->admin)->post(route('admin.affectations.masse.analyser'), [
            'annee_universitaire_id' => $this->annee->id,
            'faculte_id' => $this->faculteA->id,
            'programme_id' => $this->programmeL1->id,
            'niveau' => 'L1',
        ]);

        $response->assertOk();
        $response->assertViewIs('admin.affectations.masse-simulation');
        $response->assertViewHas('analyse');

        // Vérification absolue : AUCUNE écriture en base de données pendant la simulation
        $this->assertSame($nbAffectationsAvant, AffectationGroupe::count(), 'La simulation a indûment écrit dans affectations_groupes');
        $this->assertSame($nbHistoriqueAvant, HistoriqueAcademique::count(), 'La simulation a indûment écrit dans historique_academique');
        $this->assertNull($e1->fresh()->groupe_id);
        $this->assertNull($e2->fresh()->groupe_id);
    }

    /**
     * LOOP 2 : Règle académique stricte par niveau (incompatibilité rend le groupe inéligible).
     */
    public function test_regle_academique_stricte_niveau_different_bloquant(): void
    {
        // Étudiant en Master 1 alors que les groupes disponibles sont en L1
        $etudiantM1 = $this->creerEtudiant('03030303', niveau: 'M1');

        $service = app(MassAssignmentService::class);
        $analyse = $service->analyser([
            'annee_universitaire_id' => $this->annee->id,
            'faculte_id' => $this->faculteA->id,
            'etudiant_ids' => [$etudiantM1->id],
        ], $this->admin);

        $this->assertSame(0, $analyse['assigned_count']);
        $this->assertCount(1, $analyse['anomalies']);
        $this->assertSame('AUCUN_GROUPE_COMPATIBLE', $analyse['anomalies'][0]['code']);
        $this->assertStringContainsString('M1', $analyse['anomalies'][0]['message']);
    }

    /**
     * LOOP 2 : Cloisonnement strict par Faculté (étudiants d'une autre faculté exclus).
     */
    public function test_regle_academique_faculte_differente_exclue(): void
    {
        // Étudiant rattaché à la Faculté B
        $etudiantFaculteB = $this->creerEtudiant('04040404', faculteId: $this->faculteB->id);

        $service = app(MassAssignmentService::class);
        $analyse = $service->analyser([
            'annee_universitaire_id' => $this->annee->id,
            'faculte_id' => $this->faculteA->id, // On recherche pour la faculté A
            'etudiant_ids' => [$etudiantFaculteB->id],
        ], $this->admin);

        // L'étudiant de la faculté B ne doit pas trouver de groupe compatible dans la faculté A
        $this->assertSame(0, $analyse['assigned_count']);
        $this->assertCount(1, $analyse['anomalies']);
        $this->assertSame('AUCUN_GROUPE_COMPATIBLE', $analyse['anomalies'][0]['code']);
    }

    /**
     * LOOP 2 : Spécificité Programme / Parcours (programme différent exclu).
     */
    public function test_regle_academique_programme_different_ineligible(): void
    {
        $autreProgramme = Programme::create([
            'faculte_id' => $this->faculteA->id,
            'code' => 'LIC-MATH',
            'nom' => 'Licence Mathématiques',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
        ]);

        $etudiantMath = $this->creerEtudiant('05050505', programmeId: $autreProgramme->id);

        $service = app(MassAssignmentService::class);
        $analyse = $service->analyser([
            'annee_universitaire_id' => $this->annee->id,
            'faculte_id' => $this->faculteA->id,
            'programme_id' => $autreProgramme->id,
        ], $this->admin);

        // Les groupes 1 et 2 sont en informatique, l'étudiant en maths ne doit pas y être affecté
        $this->assertSame(0, $analyse['assigned_count']);
    }

    /**
     * LOOP 6 : Détection des anomalies bloquantes (suspension, absence d'inscription, niveau manquant).
     */
    public function test_rapport_des_anomalies_detecte_suspension_et_inscription_absente(): void
    {
        // 1. Sans inscription
        $eSansInscription = $this->creerEtudiant('06060601', avecInscription: false);

        // 2. Suspendu
        $eSuspendu = $this->creerEtudiant('06060602');
        SituationEtudiant::create([
            'etudiant_id' => $eSuspendu->id,
            'type' => 'suspension',
            'motif' => 'Conseil de discipline',
            'date_effet' => now()->subDay(),
            'date_fin' => null,
        ]);

        // 3. Inscription non validée
        $eInscriptionRejetee = $this->creerEtudiant('06060603', statutInscription: 'rejetee');

        $service = app(MassAssignmentService::class);
        $analyse = $service->analyser([
            'annee_universitaire_id' => $this->annee->id,
            'faculte_id' => $this->faculteA->id,
            'etudiant_ids' => [$eSansInscription->id, $eSuspendu->id, $eInscriptionRejetee->id],
        ], $this->admin);

        $codesAnomalies = array_column($analyse['anomalies'], 'code');
        $this->assertContains('INSCRIPTION_ABSENTE', $codesAnomalies);
        $this->assertContains('ETUDIANT_SUSPENDU', $codesAnomalies);
        $this->assertContains('INSCRIPTION_INVALIDE', $codesAnomalies);
        $this->assertSame(0, $analyse['assigned_count']);
    }

    /**
     * LOOP 4 : Algorithme d'équilibrage minimisant max(effectif) - min(effectif).
     */
    public function test_algorithme_equilibrage_repartit_harmonieusement(): void
    {
        // Groupe 1 commence avec 6 étudiants
        for ($i = 1; $i <= 6; $i++) {
            $eExistant = $this->creerEtudiant("EXIS000{$i}");
            AffectationGroupe::create([
                'etudiant_id' => $eExistant->id,
                'groupe_id' => $this->groupe1->id,
                'annee_universitaire_id' => $this->annee->id,
                'statut' => 'active',
                'date_debut' => now(),
            ]);
        }

        // Groupe 2 a 0 étudiant au départ
        // On a 8 nouveaux étudiants à affecter
        $nouveaux = [];
        for ($i = 1; $i <= 8; $i++) {
            $nouveaux[] = $this->creerEtudiant("NOUV000{$i}")->id;
        }

        $service = app(MassAssignmentService::class);
        $analyse = $service->analyser([
            'annee_universitaire_id' => $this->annee->id,
            'faculte_id' => $this->faculteA->id,
            'programme_id' => $this->programmeL1->id,
            'niveau' => 'L1',
            'etudiant_ids' => $nouveaux,
        ], $this->admin);

        $this->assertSame(8, $analyse['assigned_count']);

        // G1 (avait 6) et G2 (avait 0) avec 8 nouveaux doivent finir avec (6+1 = 7) et (0+7 = 7) !
        $summary = collect($analyse['groups_summary'])->keyBy('id');
        $g1Summary = $summary->get($this->groupe1->id);
        $g2Summary = $summary->get($this->groupe2->id);

        $this->assertSame(6, $g1Summary['before']);
        $this->assertSame(1, $g1Summary['assigned']);
        $this->assertSame(7, $g1Summary['after']);

        $this->assertSame(0, $g2Summary['before']);
        $this->assertSame(7, $g2Summary['assigned']);
        $this->assertSame(7, $g2Summary['after']);

        // Équilibre parfait : écart après = 0 !
        $this->assertSame(0, abs($g1Summary['after'] - $g2Summary['after']));
    }

    /**
     * LOOP 5 & 6 : Détection de capacité épuisée.
     */
    public function test_capacite_epuisee_detectee_lors_de_la_simulation(): void
    {
        // Deux groupes avec 1 place chacun (capacité = 1)
        $this->groupe1->update(['capacite' => 1]);
        $this->groupe2->update(['capacite' => 1]);

        // 3 étudiants candidats
        $e1 = $this->creerEtudiant('CAP0001');
        $e2 = $this->creerEtudiant('CAP0002');
        $e3 = $this->creerEtudiant('CAP0003');

        $service = app(MassAssignmentService::class);
        $analyse = $service->analyser([
            'annee_universitaire_id' => $this->annee->id,
            'faculte_id' => $this->faculteA->id,
            'programme_id' => $this->programmeL1->id,
            'niveau' => 'L1',
            'etudiant_ids' => [$e1->id, $e2->id, $e3->id],
        ], $this->admin);

        $this->assertSame(2, $analyse['assigned_count']);
        $this->assertSame(1, $analyse['unassigned_count']);

        $codes = array_column($analyse['anomalies'], 'code');
        $this->assertContains('CAPACITE_EPUISEE', $codes);
    }

    /**
     * LOOP 8, 9 & 10 : Confirmation transactionnelle, idempotence et audit.
     */
    public function test_confirmation_transactionnelle_applique_affectations_et_audit(): void
    {
        $e1 = $this->creerEtudiant('CONF0001');
        $e2 = $this->creerEtudiant('CONF0002');

        $response = $this->actingAs($this->admin)->post(route('admin.affectations.masse.confirmer'), [
            'annee_universitaire_id' => $this->annee->id,
            'motif' => 'Affectation automatique test de rentrée',
            'confirmer' => '1',
            'selections' => [
                ['student_id' => $e1->id, 'groupe_id' => $this->groupe1->id],
                ['student_id' => $e2->id, 'groupe_id' => $this->groupe2->id],
            ],
        ]);

        $response->assertRedirect(route('admin.affectations.masse'));
        $response->assertSessionHas('success');

        // Vérification des affectations actives
        $this->assertSame($this->groupe1->id, $e1->fresh()->groupe_id);
        $this->assertSame($this->groupe2->id, $e2->fresh()->groupe_id);

        $this->assertDatabaseHas('affectations_groupe', [
            'etudiant_id' => $e1->id,
            'groupe_id' => $this->groupe1->id,
            'annee_universitaire_id' => $this->annee->id,
            'statut' => 'active',
        ]);

        $this->assertDatabaseHas('affectations_groupe', [
            'etudiant_id' => $e2->id,
            'groupe_id' => $this->groupe2->id,
            'annee_universitaire_id' => $this->annee->id,
            'statut' => 'active',
        ]);

        // LOOP 10 : Audit tracé
        $this->assertDatabaseHas('historique_academique', [
            'action' => 'assignment.mass_executed',
        ]);
    }

    /**
     * LOOP 9 : Idempotence de confirmation (cliquer deux fois ne duplique pas).
     */
    public function test_confirmation_idempotente_ne_cree_pas_de_doublon(): void
    {
        $e = $this->creerEtudiant('IDEM0001');

        $payload = [
            'annee_universitaire_id' => $this->annee->id,
            'motif' => 'Affectation idempotente',
            'confirmer' => '1',
            'selections' => [
                ['student_id' => $e->id, 'groupe_id' => $this->groupe1->id],
            ],
        ];

        // 1er clic
        $this->actingAs($this->admin)->post(route('admin.affectations.masse.confirmer'), $payload);
        $this->assertSame(1, AffectationGroupe::where('etudiant_id', $e->id)->count());

        // 2nd clic immédiat
        $this->actingAs($this->admin)->post(route('admin.affectations.masse.confirmer'), $payload);
        $this->assertSame(1, AffectationGroupe::where('etudiant_id', $e->id)->count());
        $this->assertSame('active', AffectationGroupe::where('etudiant_id', $e->id)->first()->statut);
    }

    /**
     * LOOP 8 : Concurrence et dépassement de capacité bloqué au moment de la confirmation.
     */
    public function test_confirmation_respecte_capacite_sous_verrou_concurrence(): void
    {
        $this->groupe1->update(['capacite' => 1]);

        $e1 = $this->creerEtudiant('CONC0001');
        $e2 = $this->creerEtudiant('CONC0002');

        // On tente d'affecter 2 étudiants dans un groupe de 1 place sans force
        $service = app(MassAssignmentService::class);
        $rapport = $service->confirmer([
            ['student_id' => $e1->id, 'groupe_id' => $this->groupe1->id],
            ['student_id' => $e2->id, 'groupe_id' => $this->groupe1->id],
        ], $this->annee->id, $this->admin, 'Concurrence test', false);

        $this->assertSame(1, $rapport['affectes']);
        $this->assertSame(1, $rapport['ignores']);
        $this->assertArrayHasKey($e2->id, $rapport['erreurs']);

        // Capacité du groupe jamais dépassée
        $this->assertSame(1, $this->groupe1->effectifActuel());
    }

    /**
     * LOOP 11 & 14 : Support de `selections_json` pour gros volumes (> max_input_vars).
     */
    public function test_selections_json_supporte_les_gros_volumes(): void
    {
        $e1 = $this->creerEtudiant('JSON0001');
        $e2 = $this->creerEtudiant('JSON0002');

        $jsonSelections = json_encode([
            ['student_id' => $e1->id, 'groupe_id' => $this->groupe1->id],
            ['student_id' => $e2->id, 'groupe_id' => $this->groupe2->id],
        ]);

        $response = $this->actingAs($this->admin)->post(route('admin.affectations.masse.confirmer'), [
            'annee_universitaire_id' => $this->annee->id,
            'motif' => 'Gros volume via JSON',
            'confirmer' => '1',
            'selections_json' => $jsonSelections,
        ]);

        $response->assertRedirect(route('admin.affectations.masse'));
        $this->assertSame($this->groupe1->id, $e1->fresh()->groupe_id);
        $this->assertSame($this->groupe2->id, $e2->fresh()->groupe_id);
    }

    /**
     * LOOP 13 : Sécurité BOLA/IDOR (interdiction d'affecter dans une faculté non autorisée).
     */
    public function test_securite_faculte_restreinte_rejette_groupes_hors_perimetre(): void
    {
        // Créer un utilisateur restreint à la faculté B
        $adminFaculteB = User::creerCompteBackOffice([
            'name' => 'Admin FMT',
            'email' => 'admin.fmt@universite.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteB->id,
        ], 'admin_faculte');

        $e = $this->creerEtudiant('SECU0001'); // Étudiant en Faculté A

        // Tenter d'affecter l'étudiant dans le groupe 1 (Faculté A) avec l'agent Faculté B
        $service = app(MassAssignmentService::class);
        $rapport = $service->confirmer([
            ['student_id' => $e->id, 'groupe_id' => $this->groupe1->id],
        ], $this->annee->id, $adminFaculteB, 'Tentative IDOR cross-faculté', false);

        $this->assertSame(0, $rapport['affectes']);
        $this->assertSame(1, $rapport['ignores']);
        $this->assertNull($e->fresh()->groupe_id);
    }

    /**
     * LOOP 5 : L'endpoint de simulation répond en JSON pour les clients API ou Alpine.
     */
    public function test_simulation_repond_en_json_quand_demande(): void
    {
        $this->creerEtudiant('JSON001');

        $response = $this->actingAs($this->admin)->postJson(route('admin.affectations.masse.analyser'), [
            'annee_universitaire_id' => $this->annee->id,
            'faculte_id' => $this->faculteA->id,
            'programme_id' => $this->programmeL1->id,
            'niveau' => 'L1',
        ]);

        $response->assertOk();
        $response->assertJsonStructure([
            'total_students',
            'eligible_count',
            'assigned_count',
            'unassigned_count',
            'warnings_count',
            'groups_summary',
            'propositions',
            'anomalies',
        ]);
    }

    /**
     * LOOP 8 : Validation stricte des données de confirmation (rejet sans sélection).
     */
    public function test_confirmation_vide_renvoie_erreur_validation(): void
    {
        $response = $this->actingAs($this->admin)->post(route('admin.affectations.masse.confirmer'), [
            'annee_universitaire_id' => $this->annee->id,
            'motif' => 'Test sans sélection',
            'confirmer' => '1',
            'selections' => [],
        ]);

        $response->assertStatus(422);
    }

    /**
     * LOOP 11 : Redirection sécurisée en cas d'accès direct en GET sur /analyser.
     */
    public function test_redirection_get_analyser_vers_cockpit(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.affectations.masse.analyser.rediriger'));

        $response->assertRedirect(route('admin.affectations.masse'));
    }

    /**
     * LOOP 8 & 13 : Forçage de capacité autorisé si l'administrateur possède 'groupes.manage'.
     */
    public function test_forcage_capacite_avec_permission_groupes_manage(): void
    {
        $this->groupe1->update(['capacite' => 1]);
        $e1 = $this->creerEtudiant('FORCE01');
        $e2 = $this->creerEtudiant('FORCE02');

        // $this->admin est admin_scolarite, qui possède nativement 'groupes.manage'
        $this->assertTrue($this->admin->hasPermission('groupes.manage'));

        $response = $this->actingAs($this->admin)->post(route('admin.affectations.masse.confirmer'), [
            'annee_universitaire_id' => $this->annee->id,
            'motif' => 'Forçage exceptionnel de rentrée',
            'confirmer' => '1',
            'force' => '1',
            'selections' => [
                ['student_id' => $e1->id, 'groupe_id' => $this->groupe1->id],
                ['student_id' => $e2->id, 'groupe_id' => $this->groupe1->id],
            ],
        ]);

        $response->assertRedirect(route('admin.affectations.masse'));
        $this->assertSame(2, $this->groupe1->effectifActuel());
    }

    /**
     * LOOP 13 : Forçage refusé (403) si l'utilisateur ne possède pas 'groupes.manage'.
     */
    public function test_forcage_capacite_sans_permission_renvoie_403(): void
    {
        $agent = User::creerCompteBackOffice([
            'name' => 'Agent sans groupes.manage',
            'email' => 'agent.no.manage@universite.test',
            'password' => 'Motdepasse@123',
        ], 'agent_scolarite');

        $e = $this->creerEtudiant('FORCE03');

        $response = $this->actingAs($agent)->post(route('admin.affectations.masse.confirmer'), [
            'annee_universitaire_id' => $this->annee->id,
            'motif' => 'Tentative forçage non autorisé',
            'confirmer' => '1',
            'force' => '1',
            'selections' => [
                ['student_id' => $e->id, 'groupe_id' => $this->groupe1->id],
            ],
        ]);

        $response->assertForbidden();
    }
}
