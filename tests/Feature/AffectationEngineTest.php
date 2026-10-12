<?php

namespace Tests\Feature;

use App\Models\AffectationGroupe;
use App\Models\AffectationProposal;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\User;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use App\Services\Affectations\AffectationEngine;
use App\Services\Affectations\MassAssignmentService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Suite de tests unifiés pour le moteur d'affectation automatique & semi-automatique (LOOP MATURE) :
 * - Modèle unique d'évaluation et de scoring /100
 * - Séparation stricte contraintes dures vs scores
 * - Mode automatique à certitude élevée vs repli semi-automatique en cas d'ambiguïté
 * - Cycle de vie des propositions (Acceptation, Modification, Rejet)
 * - Traçabilité d'audit & Verrouillage transactionnel
 */
class AffectationEngineTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Programme $programme;

    private AffectationEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences de Tunis',
            'code' => 'FST',
            'description' => 'Sciences',
            'active' => true,
        ]);

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->programme = Programme::create([
            'faculte_id' => $this->faculte->id,
            'code' => 'LIC-INFO',
            'nom' => 'Licence en Informatique',
            'cycle' => 'licence',
            'actif' => true,
        ]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Admin Scolarité',
            'email' => 'admin.scolarite@fst.rnu.tn',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->engine = app(AffectationEngine::class);
    }

    private function creerEtudiant(string $numero, string $niveau = 'L1', ?string $filiere = 'Informatique'): Etudiant
    {
        static $compteur = 1000;
        $compteur++;

        $etudiant = Etudiant::create([
            'numero_etudiant' => $numero,
            'num_inscription' => "INS-{$compteur}",
            'nom' => "Nom_{$numero}",
            'prenom' => "Prenom_{$numero}",
            'email' => "{$numero}@etudiant.tn",
            'cin' => str_pad((string) $compteur, 8, '0', STR_PAD_LEFT),
            'date_naissance' => '2005-01-15',
            'sexe' => 'M',
            'nationalite' => 'Tunisienne',
            'lieu_naissance' => 'Tunis',
            'telephone' => '20123456',
            'adresse' => 'Avenue Habib Bourguiba',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2023',
            'password' => 'Secret@123',
            'annee_academique' => '2026-2027',
            'faculte_id' => $this->faculte->id,
            'programme_id' => $this->programme->id,
            'niveau' => $niveau,
            'filiere' => $filiere,
            'statut' => 'valide',
        ]);

        InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee->id,
            'faculte_id' => $this->faculte->id,
            'programme_id' => $this->programme->id,
            'niveau' => $niveau,
            'statut' => 'valide',
            'date_inscription' => now(),
        ]);

        return $etudiant;
    }

    private function creerGroupe(string $code, string $niveau = 'L1', int $capacite = 35, ?string $filiere = 'Informatique'): Group
    {
        return Group::create([
            'code' => $code,
            'nom' => "Groupe {$code}",
            'faculte_id' => $this->faculte->id,
            'programme_id' => $this->programme->id,
            'niveau' => $niveau,
            'filiere' => $filiere,
            'capacite' => $capacite,
            'actif' => true,
        ]);
    }

    /**
     * TEST 1 : Calcul exact du scoring /100 et respect des contraintes dures.
     */
    public function test_scoring_calcule_score_et_respecte_contraintes_dures(): void
    {
        $etudiant = $this->creerEtudiant('ETU001', 'L1', 'Informatique');

        // Groupe parfait : L1, même programme, même filière, capacité libre, équilibré
        $groupeParfait = $this->creerGroupe('L1-G01', 'L1', 40, 'Informatique');

        // Groupe incompatible : L2 (doit être exclu des candidats par contrainte dure)
        $groupeIncompatible = $this->creerGroupe('L2-G01', 'L2', 40, 'Informatique');

        $evaluation = $this->engine->evaluer($etudiant, $this->annee);

        $this->assertTrue($evaluation['eligible']);
        $this->assertSame($groupeParfait->id, $evaluation['meilleur_groupe']->id);
        $this->assertGreaterThanOrEqual(85, $evaluation['meilleur_score']);
        $this->assertSame(30, $evaluation['scoring_breakdown']['niveau']);
        $this->assertSame(25, $evaluation['scoring_breakdown']['parcours']);
        $this->assertSame(20, $evaluation['scoring_breakdown']['filiere']);
        $this->assertSame(10, $evaluation['scoring_breakdown']['domaine']);

        // Vérifier que le groupe L2 n'apparaît même pas dans les alternatives
        $alternativesIds = array_column($evaluation['alternatives'], 'group_id');
        $this->assertNotContains($groupeIncompatible->id, $alternativesIds);
    }

    /**
     * TEST 2 : Mode automatique exécute directement si la certitude est élevée.
     */
    public function test_mode_automatique_affecte_directement_quand_certitude_elevee(): void
    {
        $etudiant = $this->creerEtudiant('ETU002', 'L1');
        $groupe = $this->creerGroupe('L1-G01', 'L1', 40);

        $resultat = $this->engine->traiterEtudiant($etudiant, $this->annee, 'automatique', $this->admin->id);

        $this->assertSame('affecte', $resultat['type']);
        $this->assertInstanceOf(AffectationGroupe::class, $resultat['affectation']);
        $this->assertSame($groupe->id, $resultat['affectation']->groupe_id);
        $this->assertSame('active', $resultat['affectation']->statut);

        // Aucune proposition en attente ne doit rester
        $this->assertDatabaseMissing('affectation_proposals', [
            'student_id' => $etudiant->id,
            'statut' => AffectationProposal::STATUT_EN_ATTENTE,
        ]);

        // Vérifier la trace d'audit
        $this->assertDatabaseHas('historique_academique', [
            'etudiant_id' => $etudiant->id,
            'action' => 'assignment.auto_executed',
        ]);
    }

    /**
     * TEST 3 : Repli semi-automatique (proposition) en cas d'ambiguïté (groupes équivalents).
     */
    public function test_repli_semi_automatique_en_cas_d_ambiguite(): void
    {
        $etudiant = $this->creerEtudiant('ETU003', 'L1');

        // Deux groupes strictement équivalents (même score et même taux de remplissage)
        $g1 = $this->creerGroupe('L1-G01', 'L1', 30);
        $g2 = $this->creerGroupe('L1-G02', 'L1', 30);

        // Même en mode "automatique", l'ambiguïté doit générer une proposition PENDING
        $resultat = $this->engine->traiterEtudiant($etudiant, $this->annee, 'automatique', $this->admin->id);

        $this->assertSame('proposition', $resultat['type']);
        $this->assertInstanceOf(AffectationProposal::class, $resultat['proposal']);
        $this->assertSame(AffectationProposal::STATUT_EN_ATTENTE, $resultat['proposal']->statut);

        // L'étudiant ne doit pas avoir d'affectation active immédiate
        $this->assertDatabaseMissing('affectations_groupe', [
            'etudiant_id' => $etudiant->id,
            'statut' => 'active',
        ]);
    }

    /**
     * TEST 4 : Acceptation d'une proposition par l'administrateur.
     */
    public function test_acceptation_proposition_applique_affectation_et_met_a_jour_statut(): void
    {
        $etudiant = $this->creerEtudiant('ETU004', 'L1');
        $groupe = $this->creerGroupe('L1-G01', 'L1', 30);

        $proposal = AffectationProposal::create([
            'student_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee->id,
            'proposed_group_id' => $groupe->id,
            'mode' => AffectationProposal::MODE_SEMI_AUTOMATIQUE,
            'score' => 95,
            'certitude' => AffectationProposal::CERTITUDE_MOYENNE,
            'statut' => AffectationProposal::STATUT_EN_ATTENTE,
            'reasons' => ['Niveau et parcours conformes'],
        ]);

        $affectation = $this->engine->accepterProposition($proposal, $this->admin->id);

        $this->assertSame($groupe->id, $affectation->groupe_id);
        $this->assertSame('active', $affectation->statut);

        $proposal->refresh();
        $this->assertSame(AffectationProposal::STATUT_ACCEPTEE, $proposal->statut);
        $this->assertSame($this->admin->id, $proposal->valide_par);
        $this->assertNotNull($proposal->valide_le);

        // Vérifier l'audit
        $this->assertDatabaseHas('historique_academique', [
            'etudiant_id' => $etudiant->id,
            'action' => 'assignment.proposal_accepted',
        ]);
    }

    /**
     * TEST 5 : Modification du groupe proposé par l'administrateur avant validation.
     */
    public function test_modification_proposition_affecte_nouveau_groupe(): void
    {
        $etudiant = $this->creerEtudiant('ETU005', 'L1');
        $g1 = $this->creerGroupe('L1-G01', 'L1', 30);
        $g2 = $this->creerGroupe('L1-G02', 'L1', 30);

        $proposal = AffectationProposal::create([
            'student_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee->id,
            'proposed_group_id' => $g1->id,
            'mode' => AffectationProposal::MODE_SEMI_AUTOMATIQUE,
            'score' => 88,
            'certitude' => AffectationProposal::CERTITUDE_MOYENNE,
            'statut' => AffectationProposal::STATUT_EN_ATTENTE,
        ]);

        $affectation = $this->engine->modifierProposition($proposal, $g2->id, $this->admin->id, 'Rééquilibrage pédagogique spécifique');

        $this->assertSame($g2->id, $affectation->groupe_id);

        $proposal->refresh();
        $this->assertSame(AffectationProposal::STATUT_MODIFIEE, $proposal->statut);
        $this->assertSame($g2->id, $proposal->proposed_group_id);
    }

    /**
     * TEST 6 : Rejet d'une proposition par l'administrateur avec motif.
     */
    public function test_rejet_proposition_n_affecte_pas_etudiant_et_enregistre_motif(): void
    {
        $etudiant = $this->creerEtudiant('ETU006', 'L1');
        $groupe = $this->creerGroupe('L1-G01', 'L1', 30);

        $proposal = AffectationProposal::create([
            'student_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee->id,
            'proposed_group_id' => $groupe->id,
            'mode' => AffectationProposal::MODE_SEMI_AUTOMATIQUE,
            'score' => 60,
            'certitude' => AffectationProposal::CERTITUDE_FAIBLE,
            'statut' => AffectationProposal::STATUT_EN_ATTENTE,
        ]);

        $this->engine->rejeterProposition($proposal, $this->admin->id, 'Dossier en attente de dérogation de parcours');

        $proposal->refresh();
        $this->assertSame(AffectationProposal::STATUT_REJETEE, $proposal->statut);
        $this->assertSame('Dossier en attente de dérogation de parcours', $proposal->motif_refus);

        $this->assertDatabaseMissing('affectations_groupe', [
            'etudiant_id' => $etudiant->id,
            'statut' => 'active',
        ]);
    }

    /**
     * TEST 7 : Création de propositions en masse via le cockpit.
     */
    public function test_cockpit_masse_cree_propositions_en_lot(): void
    {
        $e1 = $this->creerEtudiant('ETU101', 'L1');
        $e2 = $this->creerEtudiant('ETU102', 'L1');
        $groupe = $this->creerGroupe('L1-G01', 'L1', 40);

        $massService = app(MassAssignmentService::class);
        $rapport = $massService->creerPropositions([
            ['student_id' => $e1->id, 'groupe_id' => $groupe->id, 'score' => 92],
            ['student_id' => $e2->id, 'groupe_id' => $groupe->id, 'score' => 90],
        ], $this->annee->id, $this->admin, 'Propositions via cockpit');

        $this->assertSame(2, $rapport['creees']);
        $this->assertDatabaseHas('affectation_proposals', ['student_id' => $e1->id, 'statut' => AffectationProposal::STATUT_EN_ATTENTE]);
        $this->assertDatabaseHas('affectation_proposals', ['student_id' => $e2->id, 'statut' => AffectationProposal::STATUT_EN_ATTENTE]);
    }

    /**
     * TEST 8 : Validation en masse via route HTTP AffectationProposalController::bulkAccepter.
     */
    public function test_bulk_accepter_propositions_via_controleur(): void
    {
        $e1 = $this->creerEtudiant('ETU201', 'L1');
        $e2 = $this->creerEtudiant('ETU202', 'L1');
        $groupe = $this->creerGroupe('L1-G01', 'L1', 40);

        $p1 = AffectationProposal::create([
            'student_id' => $e1->id,
            'annee_universitaire_id' => $this->annee->id,
            'proposed_group_id' => $groupe->id,
            'mode' => AffectationProposal::MODE_SEMI_AUTOMATIQUE,
            'score' => 92,
            'certitude' => AffectationProposal::CERTITUDE_ELEVEE,
            'statut' => AffectationProposal::STATUT_EN_ATTENTE,
        ]);

        $p2 = AffectationProposal::create([
            'student_id' => $e2->id,
            'annee_universitaire_id' => $this->annee->id,
            'proposed_group_id' => $groupe->id,
            'mode' => AffectationProposal::MODE_SEMI_AUTOMATIQUE,
            'score' => 90,
            'certitude' => AffectationProposal::CERTITUDE_ELEVEE,
            'statut' => AffectationProposal::STATUT_EN_ATTENTE,
        ]);

        $response = $this->actingAs($this->admin)->post(route('admin.affectations.propositions.bulk-accepter'), [
            'proposals' => [$p1->id, $p2->id],
        ]);

        $response->assertRedirect(route('admin.affectations.propositions.index'));
        $response->assertSessionHas('success');

        $this->assertSame(AffectationProposal::STATUT_ACCEPTEE, $p1->fresh()->statut);
        $this->assertSame(AffectationProposal::STATUT_ACCEPTEE, $p2->fresh()->statut);
        $this->assertDatabaseHas('affectations_groupe', ['etudiant_id' => $e1->id, 'groupe_id' => $groupe->id, 'statut' => 'active']);
        $this->assertDatabaseHas('affectations_groupe', ['etudiant_id' => $e2->id, 'groupe_id' => $groupe->id, 'statut' => 'active']);
    }

    /**
     * TEST 9 : Protection contre la concurrence et dépassement de capacité au moment de l'acceptation.
     */
    public function test_concurrence_revalide_capacite_sous_verrou_avant_affectation(): void
    {
        $etudiant = $this->creerEtudiant('ETU301', 'L1');
        // Groupe avec 1 seule place restante
        $groupe = $this->creerGroupe('L1-TIGHT', 'L1', 1);

        $proposal = AffectationProposal::create([
            'student_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee->id,
            'proposed_group_id' => $groupe->id,
            'mode' => AffectationProposal::MODE_SEMI_AUTOMATIQUE,
            'score' => 90,
            'statut' => AffectationProposal::STATUT_EN_ATTENTE,
        ]);

        // Simule un autre admin qui prend la dernière place entre-temps
        $autreEtudiant = $this->creerEtudiant('ETU302', 'L1');
        AffectationGroupe::create([
            'etudiant_id' => $autreEtudiant->id,
            'groupe_id' => $groupe->id,
            'annee_universitaire_id' => $this->annee->id,
            'statut' => 'active',
            'date_debut' => now()->toDateString(),
            'motif' => 'Prise de la dernière place par Admin B concurrent',
        ]);

        // L'acceptation de la proposition doit être refusée car le groupe est désormais plein
        $this->expectException(DomainException::class);

        $this->engine->accepterProposition($proposal, $this->admin->id);
    }

    /**
     * TEST 10 : Vérifie que les vues du cockpit de masse et des propositions se compilent et se rendent en 200.
     */
    public function test_vues_cockpit_masse_et_propositions_se_chargent_sans_erreur(): void
    {
        $responseMasse = $this->actingAs($this->admin)->get(route('admin.affectations.masse'));
        $responseMasse->assertOk();
        $responseMasse->assertSee('Cockpit d’affectation en masse');

        $responsePropositions = $this->actingAs($this->admin)->get(route('admin.affectations.propositions.index'));
        $responsePropositions->assertOk();
        $responsePropositions->assertSee('Propositions d\'affectation');
    }
}
