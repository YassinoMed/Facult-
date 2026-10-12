<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\Note;
use App\Models\Semestre;
use App\Models\User;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\Evaluation\Models\Deliberation;
use App\Modules\Evaluation\Models\RegleEvaluation;
use App\Modules\ParcoursAcademique\Models\ParcoursEquivalence;
use App\Modules\ParcoursAcademique\Models\ParcoursSimulation;
use App\Modules\ParcoursAcademique\Services\AmenagementParcoursService;
use App\Modules\ParcoursAcademique\Services\AnomalieParcoursService;
use App\Modules\ParcoursAcademique\Services\AssistantParcoursService;
use App\Modules\ParcoursAcademique\Services\ComparaisonParcoursService;
use App\Modules\ParcoursAcademique\Services\CreditParcoursService;
use App\Modules\ParcoursAcademique\Services\DetteAcademiqueService;
use App\Modules\ParcoursAcademique\Services\HistoriqueParcoursService;
use App\Modules\ParcoursAcademique\Services\ParcoursRegleService;
use App\Modules\ParcoursAcademique\Services\PlanParcoursService;
use App\Modules\ParcoursAcademique\Services\ProspectiveParcoursService;
use App\Modules\ParcoursAcademique\Services\RisqueAcademiqueService;
use App\Modules\ParcoursAcademique\Services\SimulationParcoursService;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\StudentDossiers\Services\Pdf\OfficialDocumentService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActAsApiClient;
use Tests\TestCase;

/**
 * Module Parcours académique avancé LMD : planification,
 * simulations sans effet officiel, crédits, dettes,
 * compensation, équivalences, dispenses, réorientations,
 * passerelles, risques, historique, comparaison, sécurité.
 */
class ParcoursAcademiqueTest extends TestCase
{
    use ActAsApiClient, RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private Faculte $autreFaculte;

    private AnneeUniversitaire $annee;

    private AnneeUniversitaire $anneeSuivante;

    private Programme $licence;

    private Programme $master;

    /** @var array<string, Niveau> */
    private array $niveaux = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.parcours-academique.active' => true]);

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->autreFaculte = Faculte::create(['nom' => 'FMP', 'code' => 'FMP', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.parcours@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2025-2026', 'date_debut' => '2025-09-01', 'date_fin' => '2026-06-30', 'active' => false,
        ]);
        $this->anneeSuivante = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);

        $this->licence = Programme::create([
            'faculte_id' => $this->faculte->id, 'code' => 'LIC', 'nom' => 'Licence Info',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $this->master = Programme::create([
            'faculte_id' => $this->faculte->id, 'code' => 'MAS', 'nom' => 'Master Info',
            'duree_annees' => 2, 'semestres_nombre' => 4, 'credits_requis' => 120,
        ]);

        foreach (['L1' => 1, 'L2' => 2, 'L3' => 3] as $code => $ordre) {
            $this->niveaux[$code] = Niveau::create([
                'programme_id' => $this->licence->id, 'code' => $code, 'nom' => $code, 'ordre' => $ordre,
            ]);
        }
        foreach (['M1' => 1, 'M2' => 2] as $code => $ordre) {
            $this->niveaux[$code] = Niveau::create([
                'programme_id' => $this->master->id, 'code' => $code, 'nom' => $code, 'ordre' => $ordre,
            ]);
        }
    }

    public function test_module_desactive_repond_404(): void
    {
        config(['modules.parcours-academique.active' => false]);

        $this->actingAs($this->admin)->get(route('admin.parcours.index'))->assertNotFound();
    }

    public function test_l1_vers_l2_simulation_passage(): void
    {
        $etudiant = $this->creerEtudiant('PAR-L1');
        $inscription = $this->inscrire($etudiant, 'L1');
        $this->decider($etudiant, $inscription, 'admis');

        $simulation = app(SimulationParcoursService::class)->simuler($etudiant, 'passage', [], $inscription, $this->admin->id);

        $this->assertSame('passage', $simulation->scenario);
        $this->assertSame('progresser', $simulation->resultat['resultat_final']);
        $this->assertNotEmpty($simulation->resultat['regles']);
        $this->assertStringContainsString('sans effet officiel', $simulation->resultat['avertissement']);
    }

    public function test_l2_vers_l3_simulation_passage(): void
    {
        $etudiant = $this->creerEtudiant('PAR-L2');
        $inscription = $this->inscrire($etudiant, 'L2');
        $this->decider($etudiant, $inscription, 'admis_compensation');

        $simulation = app(SimulationParcoursService::class)->simuler($etudiant, 'passage', [], $inscription, $this->admin->id);

        $this->assertSame('progresser', $simulation->resultat['resultat_final']);
    }

    public function test_l3_sans_admission_master_jamais_automatique(): void
    {
        $etudiant = $this->creerEtudiant('PAR-L3');
        $inscription = $this->inscrire($etudiant, 'L3');
        $this->decider($etudiant, $inscription, 'admis');

        $simulation = app(SimulationParcoursService::class)->simuler($etudiant, 'passage', [], $inscription, $this->admin->id);

        $this->assertSame('non_progression_sans_admission', $simulation->resultat['resultat_final']);

        $passerelle = app(SimulationParcoursService::class)->simuler($etudiant, 'passerelle_master', [], $inscription, $this->admin->id);

        $this->assertSame('non_eligible', $passerelle->resultat['resultat_final']);
        $this->assertContains(
            'Décision d\'admission Master (commission) : jamais automatique',
            $passerelle->resultat['conditions_non_satisfaites']
        );
        // Aucune inscription créée par la simulation.
        $this->assertSame(1, $etudiant->inscriptionsAcademiques()->count());
    }

    public function test_m1_vers_m2(): void
    {
        $etudiant = $this->creerEtudiant('PAR-M1');
        $inscription = $this->inscrire($etudiant, 'M1', $this->master->id);
        $this->decider($etudiant, $inscription, 'admis');

        $simulation = app(SimulationParcoursService::class)->simuler($etudiant, 'passage', [], $inscription, $this->admin->id);

        $this->assertSame('progresser', $simulation->resultat['resultat_final']);
    }

    public function test_m2_admis_fin_de_cycle(): void
    {
        $etudiant = $this->creerEtudiant('PAR-M2');
        $inscription = $this->inscrire($etudiant, 'M2', $this->master->id);
        $this->decider($etudiant, $inscription, 'admis');

        $simulation = app(SimulationParcoursService::class)->simuler($etudiant, 'passage', [], $inscription, $this->admin->id);

        $this->assertSame('fin_cycle', $simulation->resultat['resultat_final']);
        $this->assertSame(1, $etudiant->inscriptionsAcademiques()->count());
    }

    public function test_redoublement_reste_meme_niveau(): void
    {
        $etudiant = $this->creerEtudiant('PAR-RED');
        $inscription = $this->inscrire($etudiant, 'L1');
        $this->decider($etudiant, $inscription, 'ajourne');

        $simulation = app(SimulationParcoursService::class)->simuler($etudiant, 'redoublement', [], $inscription, $this->admin->id);

        $this->assertSame('redoubler', $simulation->resultat['resultat_final']);
    }

    public function test_dette_academique_identifiee(): void
    {
        $etudiant = $this->creerEtudiant('PAR-DET');
        $inscription = $this->inscrire($etudiant, 'L1');
        $this->noter($etudiant, $inscription, ['ALG' => 6.0, 'ANA' => 14.0]);

        $dettes = app(DetteAcademiqueService::class)->lister($etudiant);

        $this->assertCount(1, $dettes);
        $this->assertSame('ALG', $dettes[0]['matiere']);
        $this->assertTrue($dettes[0]['repassable']);
        $this->assertNotEmpty($dettes[0]['impact']);
    }

    public function test_compensation_simulee(): void
    {
        $etudiant = $this->creerEtudiant('PAR-COMP');
        $inscription = $this->inscrire($etudiant, 'L1');
        $this->noter($etudiant, $inscription, ['ALG' => 11.0, 'ANA' => 12.0]);

        $simulation = app(SimulationParcoursService::class)->simuler($etudiant, 'compensation', [], $inscription, $this->admin->id);

        $this->assertArrayHasKey('moyenne_avant', $simulation->resultat);
        $this->assertArrayHasKey('resultat_apres', $simulation->resultat);
        $this->assertSame(1, $etudiant->inscriptionsAcademiques()->count()); // aucune inscription créée
    }

    public function test_compensation_moyenne_sous_seuil(): void
    {
        $etudiant = $this->creerEtudiant('PAR-COMPBS');
        $inscription = $this->inscrire($etudiant, 'L1');
        $this->noter($etudiant, $inscription, ['ALG' => 9.0, 'ANA' => 9.0]);

        $simulation = app(SimulationParcoursService::class)->simuler($etudiant, 'compensation', [], $inscription, $this->admin->id);

        $this->assertContains($simulation->resultat['resultat_apres'], ['rattrapage', 'ajourne', 'a_examiner']);
        $this->assertNotEmpty($simulation->resultat['conditions_non_satisfaites']);
    }

    public function test_compensation_desactivee(): void
    {
        $etudiant = $this->creerEtudiant('PAR-COMPOF');
        $inscription = $this->inscrire($etudiant, 'L1');
        $this->noter($etudiant, $inscription, ['ALG' => 11.0, 'ANA' => 5.0]);

        RegleEvaluation::create([
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->licence->id,
            'seuil_validation' => 10.0, 'seuil_rattrapage' => 8.0,
            'compensation_ecue' => true, 'compensation_ue' => true, 'compensation_semestre' => false,
            'arrondi' => '2dec', 'version' => 1, 'actif' => true,
        ]);

        $simulation = app(SimulationParcoursService::class)->simuler($etudiant, 'compensation', [], $inscription, $this->admin->id);

        $this->assertContains('Compensation désactivée par le règlement', $simulation->resultat['conditions_non_satisfaites']);
    }

    public function test_credits_centralises(): void
    {
        $etudiant = $this->creerEtudiant('PAR-CRE');
        $inscription = $this->inscrire($etudiant, 'L1');
        $this->noter($etudiant, $inscription, ['ALG' => 12.0, 'ANA' => 8.0]);

        $credits = app(CreditParcoursService::class)->creditsEtudiant($etudiant);

        $this->assertSame(180, $credits['requis']);
        $this->assertGreaterThanOrEqual(0, $credits['obtenus']);
        $this->assertSame($credits['requis'] - $credits['obtenus'], $credits['restants']);
        $this->assertNotEmpty($credits['par_semestre']);
        $this->assertNotEmpty($credits['historique']);
    }

    public function test_credits_rattrapage_non_double_compte(): void
    {
        $etudiant = $this->creerEtudiant('PAR-CRE2');
        $inscription = $this->inscrire($etudiant, 'L1');
        $semestre = Semestre::where('inscription_academique_id', $inscription->id)->where('numero', 1)->firstOrFail();
        $matiere = Matiere::firstOrCreate(
            ['code' => 'ALG2'],
            ['nom' => 'ALG2', 'coefficient' => 2, 'credits' => 5, 'niveau' => 'L1', 'semestre' => 1]
        );
        foreach ([['normale', 8.0], ['rattrapage', 12.0]] as [$session, $note]) {
            Note::create([
                'etudiant_id' => $etudiant->id, 'inscription_academique_id' => $inscription->id,
                'semestre_id' => $semestre->id, 'matiere_id' => $matiere->id,
                'note' => $note, 'coefficient' => 2, 'credits' => 5, 'session' => $session,
            ]);
        }

        $credits = app(CreditParcoursService::class)->creditsEtudiant($etudiant);

        // Le rattrapage remplace la normale : 5 crédits, jamais 10.
        $this->assertSame(5, $credits['obtenus']);
    }

    public function test_credits_conserves_apres_reorientation(): void
    {
        $etudiant = $this->creerEtudiant('PAR-CRER');
        $inscription = $this->inscrire($etudiant, 'L1');
        $this->noter($etudiant, $inscription, ['ALG' => 12.0]);

        $avant = app(CreditParcoursService::class)->creditsEtudiant($etudiant);

        app(AmenagementParcoursService::class)->proposerReorientation($etudiant, [
            'nouveau_programme_id' => $this->master->id,
            'annee_universitaire_id' => $this->anneeSuivante->id,
        ], $this->admin->id);

        $apres = app(CreditParcoursService::class)->creditsEtudiant($etudiant);

        $this->assertSame($avant['obtenus'], $apres['obtenus']);
    }

    public function test_equivalence_workflow_audit(): void
    {
        $etudiant = $this->creerEtudiant('PAR-EQ');
        $service = app(AmenagementParcoursService::class);

        $equivalence = $service->proposerEquivalence($etudiant, [
            'origine_libelle' => 'Algorithmique (Univ. X)',
            'origine_credits' => 6,
            'cible_libelle' => 'Algorithmique L1',
            'etablissement' => 'Univ. X',
        ], $this->admin->id);

        $this->assertSame('proposee', $equivalence->statut);

        $decidee = $service->deciderEquivalence($equivalence, 'accordee', 'Programme équivalent.', $this->admin->id);

        $this->assertSame('validee', $decidee->statut);
        $this->assertDatabaseHas('historique_academique', [
            'etudiant_id' => $etudiant->id, 'action' => 'parcours.equivalence',
        ]);
    }

    public function test_dispense_ne_modifie_pas_historique(): void
    {
        $etudiant = $this->creerEtudiant('PAR-DIS');
        $inscription = $this->inscrire($etudiant, 'L1');
        $this->noter($etudiant, $inscription, ['ALG' => 5.0, 'ANA' => 14.0]);
        $notesAvant = Note::where('etudiant_id', $etudiant->id)->count();

        $service = app(AmenagementParcoursService::class);
        $dispense = $service->proposerDispense($etudiant, [
            'cible_libelle' => 'Algorithmique L1', 'motif' => 'Acquis professionnels',
        ], $this->admin->id);
        $service->deciderDispense($dispense, 'validee', 'Dispensé.', $this->admin->id);

        $this->assertSame($notesAvant, Note::where('etudiant_id', $etudiant->id)->count());

        $dettes = app(DetteAcademiqueService::class)->lister($etudiant);
        $this->assertNotEmpty($dettes); // dette d'origine conservée et liée
    }

    public function test_reorientation_conserve_historique(): void
    {
        $etudiant = $this->creerEtudiant('PAR-REO');
        $this->inscrire($etudiant, 'L1');

        $reorientation = app(AmenagementParcoursService::class)->proposerReorientation($etudiant, [
            'nouveau_programme_id' => $this->master->id,
            'nouveau_niveau_id' => $this->niveaux['M1']->id,
            'annee_universitaire_id' => $this->anneeSuivante->id,
            'justification' => 'Projet professionnel',
        ], $this->admin->id);

        $this->assertSame('proposee', $reorientation->statut);
        $this->assertSame($this->master->id, $reorientation->nouveau_programme_id);
        $this->assertSame(1, $etudiant->inscriptionsAcademiques()->count()); // historique intact
    }

    public function test_passerelle_decision_admission(): void
    {
        $etudiant = $this->creerEtudiant('PAR-PAS');
        $service = app(AmenagementParcoursService::class);

        $passerelle = $service->proposerPasserelle($etudiant, [
            'programme_origine_id' => $this->licence->id,
            'niveau_origine_id' => $this->niveaux['L3']->id,
            'programme_cible_id' => $this->master->id,
            'niveau_cible_id' => $this->niveaux['M1']->id,
        ], $this->admin->id);

        $decidee = $service->deciderPasserelle($passerelle, 'admise', 'Commission du 12/06.', $this->admin->id);

        $this->assertSame('admise', $decidee->statut);
        $this->assertSame('Commission du 12/06.', $decidee->decision_admission);
    }

    public function test_risque_explique_sans_decision_auto(): void
    {
        $etudiant = $this->creerEtudiant('PAR-RIS');
        $inscription = $this->inscrire($etudiant, 'L1');
        $this->noter($etudiant, $inscription, ['ALG' => 4.0, 'ANA' => 5.0, 'PHY' => 3.0, 'CHI' => 6.0, 'ANG' => 5.0, 'INF' => 4.0]);

        $evaluation = app(RisqueAcademiqueService::class)->evaluer($etudiant, $inscription);

        $this->assertContains($evaluation['niveau'], ['moyen', 'eleve', 'critique']);
        $this->assertNotEmpty($evaluation['motifs']);
        $this->assertSame('en_cours', $inscription->fresh()->statut); // aucune décision prise
    }

    public function test_risque_profil_normal_faible(): void
    {
        $etudiant = $this->creerEtudiant('PAR-RISOK');
        $inscription = $this->inscrire($etudiant, 'L1');
        $this->noter($etudiant, $inscription, ['ALG' => 14.0, 'ANA' => 15.0]);

        $evaluation = app(RisqueAcademiqueService::class)->evaluer($etudiant, $inscription);

        $this->assertSame('faible', $evaluation['niveau']);
        $this->assertSame('en_cours', $inscription->fresh()->statut);
    }

    public function test_tableau_de_bord_scope_faculte(): void
    {
        $adminFaculte = User::creerCompteBackOffice([
            'name' => 'Fac', 'email' => 'fac.risque@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->autreFaculte->id,
        ], 'admin_faculte');

        // Alerte critique dans la faculté courante : invisible hors faculté.
        $etudiant = $this->creerEtudiant('PAR-RISBD');
        $inscription = $this->inscrire($etudiant, 'L1');
        $this->noter($etudiant, $inscription, ['ALG' => 3.0, 'ANA' => 4.0, 'PHY' => 3.0, 'CHI' => 4.0, 'ANG' => 3.0, 'INF' => 4.0]);
        app(RisqueAcademiqueService::class)->enregistrer($etudiant, $inscription);

        $reponse = $this->actingAs($adminFaculte)->get(route('admin.parcours.index'));
        $reponse->assertOk();
        $reponse->assertDontSee('PAR-RISBD');
    }

    public function test_simulation_semestre_contenu(): void
    {
        $etudiant = $this->creerEtudiant('PAR-SEM');
        $inscription = $this->inscrire($etudiant, 'L1');
        $this->noter($etudiant, $inscription, ['ALG' => 12.0, 'ANA' => 14.0]);

        $simulation = app(SimulationParcoursService::class)->simuler($etudiant, 'validation_semestre', ['semestre' => 1], $inscription, $this->admin->id);

        $this->assertSame('Validé', $simulation->resultat['decision_possible']);
        $this->assertEquals(13.0, $simulation->resultat['moyenne']);
        $this->assertSame(2, $simulation->resultat['ecue_validees']);
        $this->assertSame(2, $simulation->resultat['ecue_total']);
        $this->assertSame(10, $simulation->resultat['credits_obtenus_semestre']);
        $this->assertSame(1, $etudiant->inscriptionsAcademiques()->count());
    }

    public function test_simulation_semestre_introuvable(): void
    {
        $etudiant = $this->creerEtudiant('PAR-SEMKO');
        $inscription = $this->inscrire($etudiant, 'L1');
        Semestre::where('inscription_academique_id', $inscription->id)->where('numero', 2)->delete();

        $simulation = app(SimulationParcoursService::class)->simuler($etudiant, 'validation_semestre', ['semestre' => 2], $inscription->fresh(), $this->admin->id);

        $this->assertSame('impossible', $simulation->resultat['resultat_final']);
    }

    public function test_simulation_ne_modifie_rien_officiel(): void
    {
        $etudiant = $this->creerEtudiant('PAR-SIM');
        $inscription = $this->inscrire($etudiant, 'L1');
        $this->decider($etudiant, $inscription, 'admis');
        $this->noter($etudiant, $inscription, ['ALG' => 12.0]);

        $notesAvant = Note::where('etudiant_id', $etudiant->id)->count();
        $decisionsAvant = DecisionDeliberation::where('etudiant_id', $etudiant->id)->count();

        $simulation = app(SimulationParcoursService::class)->simuler($etudiant, 'validation_matiere', [
            'matiere_id' => Matiere::where('code', 'ALG')->first()->id, 'note' => 18.0,
        ], $inscription, $this->admin->id);

        $this->assertSame($notesAvant, Note::where('etudiant_id', $etudiant->id)->count());
        $this->assertSame($decisionsAvant, DecisionDeliberation::where('etudiant_id', $etudiant->id)->count());
        $this->assertSame(1, $etudiant->inscriptionsAcademiques()->count());
        // Audit : la simulation elle-même est tracée (sans PII).
        $this->assertDatabaseHas('historique_academique', [
            'etudiant_id' => $etudiant->id, 'action' => 'parcours.simulation',
            'entite_id' => $simulation->id,
        ]);
    }

    public function test_historique_chronologique(): void
    {
        $etudiant = $this->creerEtudiant('PAR-HIS');
        $inscription = $this->inscrire($etudiant, 'L1');
        $this->decider($etudiant, $inscription, 'admis');
        app(SimulationParcoursService::class)->simuler($etudiant, 'passage', [], $inscription, $this->admin->id);

        $chronologie = app(HistoriqueParcoursService::class)->chronologie($etudiant);
        $types = collect($chronologie)->pluck('type')->all();

        $this->assertContains('inscription', $types);
        $this->assertContains('deliberation', $types);
        $this->assertContains('simulation', $types);

        // Filtrage par type.
        $seulInscriptions = app(HistoriqueParcoursService::class)->chronologie($etudiant, 'inscription');
        $this->assertNotEmpty($seulInscriptions);
        $this->assertTrue(collect($seulInscriptions)->every(fn ($e) => $e['type'] === 'inscription'));

        // Aucune PII (CIN, e-mail) dans la chronologie.
        $texte = json_encode($chronologie);
        $this->assertStringNotContainsString('PAR-HIS', (string) $texte);
        $this->assertStringNotContainsString('@parcours.test', (string) $texte);
    }

    public function test_comparaison_deux_etudiants(): void
    {
        $premier = $this->creerEtudiant('PAR-CMP1');
        $second = $this->creerEtudiant('PAR-CMP2');
        $this->inscrire($premier, 'L1');
        $this->inscrire($second, 'L2');

        $lignes = app(ComparaisonParcoursService::class)->comparerEtudiants([$premier->id, $second->id]);

        $this->assertCount(2, $lignes);
        $this->assertArrayHasKey('credits_obtenus', $lignes[0]);
        $this->assertArrayHasKey('risque', $lignes[1]);
    }

    public function test_comparaison_route_et_scope(): void
    {
        $premier = $this->creerEtudiant('PAR-CMPR1');
        $second = $this->creerEtudiant('PAR-CMPR2');
        $this->inscrire($premier, 'L1');
        $this->inscrire($second, 'L1');

        // Sans paramètres : sélecteur, pas d'erreur.
        $this->actingAs($this->admin)->get(route('admin.parcours.comparer'))
            ->assertOk()
            ->assertSee('Choisir 2 à 4 étudiants', false);

        $this->actingAs($this->admin)
            ->get(route('admin.parcours.comparer', ['etudiants' => [$premier->id, $second->id]]))
            ->assertOk();

        // Un étudiant hors faculté : 404, aucune donnée exposée.
        $intrus = Etudiant::create([
            'cin' => 'PAR-CMPRX', 'nom' => 'X', 'prenom' => 'Y',
            'email' => 'x@parcours.test', 'date_naissance' => '2004-01-01', 'sexe' => 'M',
            'faculte_id' => $this->autreFaculte->id, 'niveau' => 'L1',
            'annee_academique' => '2025-2026', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
        $adminFaculte = User::creerCompteBackOffice([
            'name' => 'Fac', 'email' => 'fac.cmp@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->autreFaculte->id,
        ], 'admin_faculte');

        $this->actingAs($adminFaculte)
            ->get(route('admin.parcours.comparer', ['etudiants' => [$premier->id, $intrus->id]]))
            ->assertNotFound();
    }

    public function test_plan_analyse_l1(): void
    {
        $etudiant = $this->creerEtudiant('PAR-PLN');
        $inscription = $this->inscrire($etudiant, 'L1');
        $this->decider($etudiant, $inscription, 'admis');

        $analyse = app(PlanParcoursService::class)->analyser($etudiant, $inscription);

        $this->assertSame('L1', $analyse['niveau_actuel']);
        $this->assertNotEmpty($analyse['propositions']);
        $this->assertNotEmpty($analyse['regles']);
        $this->assertArrayHasKey('amenagements', $analyse);
        $this->assertSame(0, $analyse['amenagements']['equivalences_validees']);
    }

    public function test_idor_autre_faculte_404(): void
    {
        $etudiant = $this->creerEtudiant('PAR-IDOR');
        $adminFaculte = User::creerCompteBackOffice([
            'name' => 'Fac', 'email' => 'fac.parcours@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->autreFaculte->id,
        ], 'admin_faculte');

        $this->actingAs($adminFaculte)->get(route('admin.parcours.fiche', $etudiant))->assertNotFound();
        $this->actingAs($adminFaculte)->post(route('admin.parcours.simulations.store'), [
            'etudiant_id' => $etudiant->id, 'scenario' => 'passage',
        ])->assertNotFound();
    }

    public function test_sans_permission_simuler_403(): void
    {
        $lecteur = User::creerCompteBackOffice([
            'name' => 'Lect', 'email' => 'lect.parcours@universite.test', 'password' => 'Motdepasse@123',
        ], 'consultation');

        $etudiant = $this->creerEtudiant('PAR-403');

        $this->actingAs($lecteur)->post(route('admin.parcours.simulations.store'), [
            'etudiant_id' => $etudiant->id, 'scenario' => 'passage',
        ])->assertForbidden();
        // Lecture seule conservée pour consultation (parcours.view).
        $this->actingAs($lecteur)->get(route('admin.parcours.fiche', $etudiant))->assertOk();
    }

    public function test_concurrence_double_decision_409(): void
    {
        $etudiant = $this->creerEtudiant('PAR-CCR');
        $service = app(AmenagementParcoursService::class);
        $equivalence = $service->proposerEquivalence($etudiant, [
            'origine_libelle' => 'X', 'cible_libelle' => 'Y',
        ], $this->admin->id);

        $service->deciderEquivalence($equivalence, 'accordee', null, $this->admin->id);

        $this->expectExceptionMessage('déjà traitée');
        $service->deciderEquivalence($equivalence, 'accordee', null, $this->admin->id);
    }

    public function test_routes_fiche_et_simulation(): void
    {
        $etudiant = $this->creerEtudiant('PAR-RTE');
        $inscription = $this->inscrire($etudiant, 'L1');
        $this->decider($etudiant, $inscription, 'admis');

        $this->actingAs($this->admin)->get(route('admin.parcours.index'))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.parcours.fiche', $etudiant))->assertOk();
        $this->actingAs($this->admin)->post(route('admin.parcours.simulations.store'), [
            'etudiant_id' => $etudiant->id, 'scenario' => 'passage',
        ])->assertRedirect();

        $simulation = ParcoursSimulation::where('etudiant_id', $etudiant->id)->firstOrFail();
        $this->actingAs($this->admin)->get(route('admin.parcours.simulations.show', $simulation))->assertOk();
    }

    public function test_equivalence_routes_http_et_idor(): void
    {
        $etudiant = $this->creerEtudiant('PAR-EQHTTP');

        $this->actingAs($this->admin)->post(route('admin.parcours.amenagements.store', 'equivalences'), [
            'etudiant_id' => $etudiant->id,
            'origine_libelle' => 'Physique (Univ. X)',
            'cible_libelle' => 'Physique L1',
        ])->assertRedirect();

        $equivalence = ParcoursEquivalence::where('etudiant_id', $etudiant->id)->firstOrFail();
        $this->assertSame('proposee', $equivalence->statut);

        $this->actingAs($this->admin)->post(
            route('admin.parcours.amenagements.decider', ['categorie' => 'equivalences', 'id' => $equivalence->id]),
            ['statut' => 'validee', 'decision' => 'accordee', 'justification' => 'OK']
        )->assertRedirect();
        $this->assertSame('validee', $equivalence->fresh()->statut);

        $adminFaculte = User::creerCompteBackOffice([
            'name' => 'Fac', 'email' => 'fac.eq@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->autreFaculte->id,
        ], 'admin_faculte');

        $this->actingAs($adminFaculte)->post(
            route('admin.parcours.amenagements.decider', ['categorie' => 'equivalences', 'id' => $equivalence->id]),
            ['statut' => 'rejetee', 'decision' => 'refusee']
        )->assertNotFound();
    }

    public function test_dispense_revoquee_reversible(): void
    {
        $etudiant = $this->creerEtudiant('PAR-DISRV');
        $service = app(AmenagementParcoursService::class);
        $dispense = $service->proposerDispense($etudiant, [
            'cible_libelle' => 'Anglais L1', 'motif' => 'Certification',
        ], $this->admin->id);
        $service->deciderDispense($dispense, 'validee', 'Dispensé.', $this->admin->id);

        $revoquee = $service->deciderDispense($dispense->fresh(), 'revoquee', 'Révoqué.', $this->admin->id);

        $this->assertSame('revoquee', $revoquee->statut);
        $this->assertDatabaseHas('historique_academique', [
            'etudiant_id' => $etudiant->id, 'action' => 'parcours.dispense',
        ]);
    }

    public function test_reorientation_simulation_transferable(): void
    {
        $etudiant = $this->creerEtudiant('PAR-REOSIM');
        $inscription = $this->inscrire($etudiant, 'L1');
        $this->noter($etudiant, $inscription, ['ALG' => 13.0]);

        $simulation = app(SimulationParcoursService::class)->simuler($etudiant, 'reorientation', [], $inscription, $this->admin->id);

        $this->assertSame('orientation_envisageable', $simulation->resultat['resultat_final']);
        $this->assertSame(5, $simulation->resultat['credits_transferables']);
        $this->assertSame(1, $etudiant->inscriptionsAcademiques()->count());
    }

    public function test_passerelle_cible_incoherente(): void
    {
        $etudiant = $this->creerEtudiant('PAR-PASIN');
        $inscription = $this->inscrire($etudiant, 'L3');
        $this->decider($etudiant, $inscription, 'admis');

        // Niveau M1 rattaché au Master, programme cible = Licence : incohérent.
        $simulation = app(SimulationParcoursService::class)->simuler($etudiant, 'passerelle_master', [
            'programme_cible_id' => $this->licence->id,
            'niveau_cible_id' => $this->niveaux['M1']->id,
        ], $inscription, $this->admin->id);

        $this->assertContains('Niveau cible hors du programme cible.', $simulation->resultat['conditions_non_satisfaites']);
        $this->assertSame('non_eligible', $simulation->resultat['resultat_final']);
    }

    public function test_dette_revalidee_disparait(): void
    {
        $etudiant = $this->creerEtudiant('PAR-DETRV');
        $inscription = $this->inscrire($etudiant, 'L1');
        $semestre = Semestre::where('inscription_academique_id', $inscription->id)->where('numero', 1)->firstOrFail();
        $matiere = Matiere::firstOrCreate(
            ['code' => 'DETREV'],
            ['nom' => 'DETREV', 'coefficient' => 2, 'credits' => 5, 'niveau' => 'L1', 'semestre' => 1]
        );
        Note::create([
            'etudiant_id' => $etudiant->id, 'inscription_academique_id' => $inscription->id,
            'semestre_id' => $semestre->id, 'matiere_id' => $matiere->id,
            'note' => 5.0, 'coefficient' => 2, 'credits' => 5, 'session' => 'normale',
        ]);

        $this->assertCount(1, app(DetteAcademiqueService::class)->lister($etudiant));

        Note::create([
            'etudiant_id' => $etudiant->id, 'inscription_academique_id' => $inscription->id,
            'semestre_id' => $semestre->id, 'matiere_id' => $matiere->id,
            'note' => 12.0, 'coefficient' => 2, 'credits' => 5, 'session' => 'rattrapage',
        ]);

        $this->assertCount(0, app(DetteAcademiqueService::class)->lister($etudiant));
    }

    public function test_dette_conserve_annee_origine(): void
    {
        $etudiant = $this->creerEtudiant('PAR-DETOR');
        $inscription = $this->inscrire($etudiant, 'L1');
        $this->noter($etudiant, $inscription, ['ALG' => 6.0]);

        $dettes = app(DetteAcademiqueService::class)->lister($etudiant);

        $this->assertSame('2025-2026', $dettes[0]['annee']);
        $this->assertSame(1, $dettes[0]['semestre_origine']);
    }

    public function test_scenarios_independants_et_compares(): void
    {
        $etudiant = $this->creerEtudiant('PAR-MULT');
        $inscription = $this->inscrire($etudiant, 'L1');
        $this->decider($etudiant, $inscription, 'admis');
        $service = app(SimulationParcoursService::class);

        $passage = $service->simuler($etudiant, 'passage', [], $inscription, $this->admin->id);
        $redoublement = $service->simuler($etudiant, 'redoublement', [], $inscription, $this->admin->id);

        $this->assertNotSame($passage->id, $redoublement->id);
        $this->assertNotSame(
            $passage->resultat['scenario_propose'],
            $redoublement->resultat['scenario_propose']
        );

        $comparaison = app(ComparaisonParcoursService::class)
            ->comparerScenarios(collect([$passage, $redoublement]));

        $this->assertCount(2, $comparaison['scenarios']);
        // Décision officielle inchangée malgré 2 simulations.
        $this->assertSame(1, DecisionDeliberation::where('etudiant_id', $etudiant->id)->count());
    }

    public function test_regles_versionnees_et_formulaire(): void
    {
        $service = app(ParcoursRegleService::class);

        $socle = $service->resoudre('PASSAGE_L1_L2');
        $this->assertSame('PASSAGE_L1_L2@socle', $socle['reference']);

        $service->versionner('PASSAGE_L1_L2', 'Passage L1-L2 v1', ['credits_requis' => 60], null, null);
        $service->versionner('PASSAGE_L1_L2', 'Passage L1-L2 v2', ['credits_requis' => 55], null, null);

        $resolue = $service->resoudre('PASSAGE_L1_L2');
        $this->assertSame('PASSAGE_L1_L2@v2', $resolue['reference']);
        $this->assertSame(55, $resolue['criteres']['credits_requis']);

        // Formulaire HTTP avec critères JSON.
        $this->actingAs($this->admin)->post(route('admin.parcours.regles.store'), [
            'code' => 'RISQUE', 'libelle' => 'Risque v1', 'criteres_json' => '{"seuils": {"moyen": 3}}',
        ])->assertRedirect();
        $this->assertDatabaseHas('parcours_regles', ['code' => 'RISQUE', 'version' => 1]);

        // Clonage : formulaire pré-rempli depuis la dernière version.
        $this->actingAs($this->admin)->get(route('admin.parcours.regles.index', ['code' => 'RISQUE']))
            ->assertOk()
            ->assertSee('Risque v1', false);
    }

    public function test_explicabilite_regle_correspond_au_calcul(): void
    {
        $etudiant = $this->creerEtudiant('PAR-EXP');
        $inscription = $this->inscrire($etudiant, 'L1');
        $this->decider($etudiant, $inscription, 'admis');

        $simulation = app(SimulationParcoursService::class)->simuler($etudiant, 'passage', [], $inscription, $this->admin->id);

        $this->assertNotEmpty($simulation->resultat['regles']);
        $this->assertNotEmpty($simulation->resultat['justification']);
        $this->assertArrayHasKey('conditions_satisfaites', $simulation->resultat);
        $this->assertArrayHasKey('conditions_non_satisfaites', $simulation->resultat);
        // Référence persistée = début des règles du résultat.
        $this->assertStringStartsWith(
            explode(', ', (string) $simulation->regle_reference)[0],
            implode(', ', $simulation->resultat['regles'])
        );

        $this->actingAs($this->admin)
            ->get(route('admin.parcours.simulations.show', $simulation))
            ->assertOk()
            ->assertSee($simulation->resultat['resultat_final']);
    }

    public function test_tableau_de_bord_indicateurs_scopes(): void
    {
        $etudiant = $this->creerEtudiant('PAR-TDB');
        $inscription = $this->inscrire($etudiant, 'L1');
        $this->decider($etudiant, $inscription, 'admis');
        app(SimulationParcoursService::class)->simuler($etudiant, 'passage', [], $inscription, $this->admin->id);

        $this->actingAs($this->admin)->get(route('admin.parcours.index'))
            ->assertOk()
            ->assertSee('Accès rapide', false)
            ->assertSee('Passerelles admises', false);
    }

    public function test_prospective_sans_ecriture(): void
    {
        $l1 = $this->creerEtudiant('PAR-PRO1');
        $l2 = $this->creerEtudiant('PAR-PRO2');
        $i1 = $this->inscrire($l1, 'L1');
        $i2 = $this->inscrire($l2, 'L2');
        $this->decider($l1, $i1, 'admis');
        $this->decider($l2, $i2, 'ajourne');
        $inscriptionsAvant = InscriptionAcademique::count();

        $projection = app(ProspectiveParcoursService::class)->projeter($this->licence, $this->annee);

        $this->assertSame($inscriptionsAvant, InscriptionAcademique::count());
        $this->assertSame('2026-2027', $projection['annee_cible']);
        $lignes = collect($projection['niveaux']);
        $this->assertSame(1, $lignes->firstWhere('niveau', 'L1')['progresser']);
        $this->assertSame(2, $lignes->firstWhere('niveau', 'L2')['projetes']); // passage L1 + redoublement L2

        $this->actingAs($this->admin)->get(route('admin.parcours.prospective', [
            'programme_id' => $this->licence->id, 'annee_universitaire_id' => $this->annee->id,
        ]))->assertOk();

        // Sans paramètres : défauts (programme + année active), pas d'erreur.
        $this->actingAs($this->admin)->get(route('admin.parcours.prospective'))->assertOk();

        // Déterminisme : deux projections identiques (même source).
        $bis = app(ProspectiveParcoursService::class)->projeter($this->licence, $this->annee);
        $this->assertSame($projection['totaux'], $bis['totaux']);
    }

    public function test_assistant_explique_sans_decider(): void
    {
        $etudiant = $this->creerEtudiant('PAR-AST');
        $inscription = $this->inscrire($etudiant, 'L1');
        $this->decider($etudiant, $inscription, 'admis');
        $inscriptionsAvant = InscriptionAcademique::count();

        $reponse = app(AssistantParcoursService::class)->expliquer($etudiant, $inscription);

        $this->assertNotEmpty($reponse['points']);
        $this->assertNotEmpty($reponse['sources']);
        $this->assertStringContainsString('ne décide rien', $reponse['avertissement']);
        $this->assertSame($inscriptionsAvant, InscriptionAcademique::count());
        $this->assertSame(0, DecisionDeliberation::where('etudiant_id', $etudiant->id)->where('publie', false)->count());
    }

    public function test_anomalies_detectees_et_classees(): void
    {
        $etudiant = $this->creerEtudiant('PAR-ANO');
        $i1 = $this->inscrire($etudiant, 'L1');
        $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->anneeSuivante->id,
            'programme_id' => $this->licence->id,
            'niveau_id' => $this->niveaux['L2']->id,
            'niveau' => 'L2', 'statut' => 'en_cours',
        ]);

        $anomalies = app(AnomalieParcoursService::class)->detecter($etudiant);
        $codes = collect($anomalies)->pluck('code')->all();

        $this->assertContains('PROGRESSION_IMPOSSIBLE', $codes);

        $sain = $this->creerEtudiant('PAR-ANOOK');
        $inscription = $this->inscrire($sain, 'L1');
        $this->decider($sain, $inscription, 'admis');
        $this->assertSame([], app(AnomalieParcoursService::class)->detecter($sain));
    }

    public function test_fiche_renvoie_vers_ged_existante(): void
    {
        $etudiant = $this->creerEtudiant('PAR-GED');
        $this->inscrire($etudiant, 'L1');

        $this->actingAs($this->admin)->get(route('admin.parcours.fiche', $etudiant))
            ->assertOk()
            ->assertSee('Centre documentaire')
            ->assertSee('produit depuis une simulation', false);
    }

    public function test_fiche_onglets_et_simulation_1_clic(): void
    {
        $etudiant = $this->creerEtudiant('PAR-1CLIC');
        $inscription = $this->inscrire($etudiant, 'L1');
        $this->decider($etudiant, $inscription, 'admis');

        $this->actingAs($this->admin)->get(route('admin.parcours.fiche', $etudiant))
            ->assertOk()
            ->assertSee('role="tablist"', false)
            ->assertSee('Simuler en 1 clic', false);

        // 1 clic : etudiant + scenario suffisent, sans hypothèses.
        $this->actingAs($this->admin)->post(route('admin.parcours.simulations.store'), [
            'etudiant_id' => $etudiant->id, 'scenario' => 'compensation',
        ])->assertRedirect();

        $this->assertDatabaseHas('parcours_simulations', [
            'etudiant_id' => $etudiant->id, 'scenario' => 'compensation',
        ]);
    }

    public function test_releve_officiel_depuis_donnees_parcours(): void
    {
        $etudiant = $this->creerEtudiant('PAR-REL');
        $inscription = $this->inscrire($etudiant, 'L1');
        $this->decider($etudiant, $inscription, 'admis');

        $document = app(OfficialDocumentService::class)->generer($etudiant, 'attestation_reussite', $inscription->id, $this->admin->id);

        $this->assertSame('valide', $document->statut);
        $this->assertNotEmpty($document->code_verification);

        $refuse = $this->creerEtudiant('PAR-RELKO');
        $inscriptionRefusee = $this->inscrire($refuse, 'L1');
        $this->decider($refuse, $inscriptionRefusee, 'ajourne');

        $this->expectException(DomainException::class);
        app(OfficialDocumentService::class)->generer($refuse, 'attestation_reussite', $inscriptionRefusee->id, $this->admin->id);
    }

    public function test_decision_notifie_sans_bloquer(): void
    {
        $etudiant = $this->creerEtudiant('PAR-NOT');
        $service = app(AmenagementParcoursService::class);
        $equivalence = $service->proposerEquivalence($etudiant, [
            'origine_libelle' => 'X', 'cible_libelle' => 'Y',
        ], $this->admin->id);

        // Sans gabarit = no-op, décision enregistrée quand même.
        $decidee = $service->deciderEquivalence($equivalence, 'accordee', null, $this->admin->id);

        $this->assertSame('validee', $decidee->statut);
    }

    public function test_api_parcours_lecture_et_bola(): void
    {
        $etudiant = $this->creerEtudiant('PAR-API');
        $inscription = $this->inscrire($etudiant, 'L1');
        $this->decider($etudiant, $inscription, 'admis');

        [, $token] = $this->clientApi(['archives:read']);

        $this->getApi('/api/v1/students/'.$etudiant->id.'/parcours', $token)
            ->assertOk()->assertJsonStructure(['data' => ['niveau_actuel', 'propositions', 'regles']]);
        $this->getApi('/api/v1/students/'.$etudiant->id.'/parcours-credits', $token)
            ->assertOk()->assertJsonStructure(['data' => ['obtenus', 'requis', 'restants']]);
        $this->getApi('/api/v1/students/'.$etudiant->id.'/parcours-risques', $token)
            ->assertOk()->assertJsonStructure(['data' => ['niveau', 'motifs']]);
        $this->getApi('/api/v1/students/'.$etudiant->id.'/parcours-simulations', $token)->assertOk();

        // Sans le scope : 403.
        [, $limite] = $this->clientApi(['students:read']);
        $this->resetAuthGuards();
        $this->getApi('/api/v1/students/'.$etudiant->id.'/parcours', $limite)->assertForbidden();

        // BOLA : étudiant d'une autre faculté = 404.
        $intrus = $this->creerEtudiant('PAR-APIX');
        $intrus->update(['faculte_id' => $this->autreFaculte->id]);
        $restreint = User::creerCompteBackOffice([
            'name' => 'Scope', 'email' => 'scope.api@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculte->id,
        ], 'admin_faculte');
        $jeton = $restreint->createToken('t', ['archives:read'])->plainTextToken;
        $this->resetAuthGuards();
        $this->getApi('/api/v1/students/'.$intrus->id.'/parcours', $jeton)->assertNotFound();
    }

    public function test_recherche_sans_fuite_parcours(): void
    {
        $etudiant = $this->creerEtudiant('PAR-SRCH');
        $inscription = $this->inscrire($etudiant, 'L1');
        app(SimulationParcoursService::class)->simuler(
            $etudiant, 'validation_matiere', ['cible' => 'HypotheseSecreteZZZ9'],
            $inscription, $this->admin->id
        );

        // Saut direct CIN exacte → fiche (comportement existant, scopé), terme conservé.
        $this->actingAs($this->admin)->get(route('admin.recherche', ['q' => 'PAR-SRCH']))
            ->assertRedirect(route('admin.etudiants.show', $etudiant).'?q=PAR-SRCH');

        // Le contenu des simulations n'est jamais indexé ni exposé.
        $this->actingAs($this->admin)->get(route('admin.recherche', ['q' => 'HypotheseSecreteZZZ9']))
            ->assertOk()
            ->assertSee('Aucun résultat', false);
    }

    private function creerEtudiant(string $cin): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin, 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => strtolower($cin).'@parcours.test',
            'date_naissance' => '2004-01-01', 'sexe' => 'F', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2025-2026', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
        $etudiant->definirStatut('valide');

        return $etudiant;
    }

    private function inscrire(Etudiant $etudiant, string $niveau, ?int $programmeId = null): InscriptionAcademique
    {
        $inscription = $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $programmeId ?? $this->licence->id,
            'niveau_id' => $this->niveaux[$niveau]->id,
            'niveau' => $niveau,
            'statut' => 'en_cours',
        ]);
        $inscription->semestres()->create(['numero' => 1]);
        $inscription->semestres()->create(['numero' => 2]);

        return $inscription;
    }

    private function decider(Etudiant $etudiant, InscriptionAcademique $inscription, string $decision): void
    {
        $deliberation = Deliberation::create([
            'annee_universitaire_id' => $this->annee->id,
            'statut' => 'published',
            'created_by' => $this->admin->id,
            'published_at' => now(),
        ]);
        DecisionDeliberation::create([
            'deliberation_id' => $deliberation->id,
            'etudiant_id' => $etudiant->id,
            'inscription_academique_id' => $inscription->id,
            'moyenne' => 12.0,
            'credits_obtenus' => 30,
            'credits_total' => 30,
            'decision' => $decision,
            'publie' => true,
        ]);
    }

    /** @param array<string, float> $notes */
    private function noter(Etudiant $etudiant, InscriptionAcademique $inscription, array $notes): void
    {
        $semestre = Semestre::where('inscription_academique_id', $inscription->id)->where('numero', 1)->firstOrFail();

        foreach ($notes as $code => $valeur) {
            $matiere = Matiere::firstOrCreate(
                ['code' => $code],
                ['nom' => $code, 'coefficient' => 2, 'credits' => 5, 'niveau' => 'L1', 'semestre' => 1]
            );
            Note::create([
                'etudiant_id' => $etudiant->id,
                'inscription_academique_id' => $inscription->id,
                'semestre_id' => $semestre->id,
                'matiere_id' => $matiere->id,
                'note' => $valeur,
                'coefficient' => $matiere->coefficient,
                'credits' => $matiere->credits,
                'session' => 'normale',
            ]);
        }

        app(CreditParcoursService::class)->creditsEtudiant($etudiant);
    }
}
