<?php

namespace Tests\Feature;

use App\Models\AffectationProposal;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use App\Services\Affectations\MassAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOOP 2 (P0) — Protection des CIN dans les exports et vues d'affectation
 * de masse : sans `exports.personal`, la CIN complète ne doit jamais
 * sortir (CSV, JSON, Blade) ; avec, le comportement historique est conservé.
 */
class AffectationMasseCinProtectionTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    private User $privilegie;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Programme $programme;

    private Group $groupe;

    private Etudiant $etudiant;

    private const CIN = '12345678';

    private const CIN_MASQUEE = '12***678';

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences de Tunis',
            'code' => 'FST',
            'description' => 'Sciences',
            'active' => true,
        ]);

        // Agent scolarité : data.export SANS exports.personal (profil victime P0).
        $this->agent = User::creerCompteBackOffice([
            'name' => 'Agent Scolarité',
            'email' => 'agent.cin@universite.test',
            'password' => 'Motdepasse@123',
        ], 'agent_scolarite');

        // Scolarité privilégiée : data.export AVEC exports.personal.
        $this->privilegie = User::creerCompteBackOffice([
            'name' => 'Admin Scolarité',
            'email' => 'scolarite.cin@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->assertFalse($this->agent->hasPermission('exports.personal'));
        $this->assertTrue($this->agent->hasPermission('data.export'));
        $this->assertTrue($this->privilegie->hasPermission('exports.personal'));

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->programme = Programme::create([
            'faculte_id' => $this->faculte->id,
            'code' => 'LIC-INFO',
            'nom' => 'Licence Informatique',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
        ]);

        $this->groupe = Group::create([
            'code' => 'L1-INFO-G1',
            'nom' => 'Groupe 1 Informatique L1',
            'faculte_id' => $this->faculte->id,
            'programme_id' => $this->programme->id,
            'niveau' => 'L1',
            'capacite' => 20,
            'actif' => true,
        ]);

        $this->etudiant = Etudiant::create([
            'cin' => self::CIN,
            'num_inscription' => 'MAT-90001',
            'nom' => 'Ben Salah',
            'prenom' => 'Amine',
            'email' => 'amine.cin@universite.test',
            'date_naissance' => '2004-03-10',
            'sexe' => 'M',
            'telephone' => '98000901',
            'faculte_id' => $this->faculte->id,
            'programme_id' => $this->programme->id,
            'niveau' => 'L1',
            'annee_academique' => $this->annee->libelle,
            'adresse' => 'Rue des Jasmins',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2023',
            'password' => 'Secret@123',
        ]);
        $this->etudiant->definirStatut('valide');
        $this->etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id,
            'niveau' => 'L1',
            'statut' => 'valide',
        ]);
    }

    public function test_export_masse_sans_permission_personnelle_masque_la_cin(): void
    {
        $reponse = $this->actingAs($this->agent)->post(route('admin.affectations.masse.exporter'), [
            'annee_universitaire_id' => $this->annee->id,
            'faculte_id' => $this->faculte->id,
        ]);

        $reponse->assertOk();
        $contenu = (string) $reponse->streamedContent();

        // En-têtes fixes conservées, structure intacte.
        $this->assertStringContainsString('num_inscription;nom;prenom;cin;', $contenu);
        $this->assertStringContainsString('Ben Salah', $contenu);
        // CIN complète absente, forme masquée présente.
        $this->assertStringNotContainsString(self::CIN, $contenu);
        $this->assertStringContainsString(self::CIN_MASQUEE, $contenu);
    }

    public function test_export_masse_avec_permission_personnelle_conserve_la_cin(): void
    {
        $reponse = $this->actingAs($this->privilegie)->post(route('admin.affectations.masse.exporter'), [
            'annee_universitaire_id' => $this->annee->id,
            'faculte_id' => $this->faculte->id,
        ]);

        $reponse->assertOk();
        $contenu = (string) $reponse->streamedContent();

        $this->assertStringContainsString(self::CIN, $contenu);
    }

    public function test_export_masse_invite_est_redirige(): void
    {
        $this->post(route('admin.affectations.masse.exporter'), [
            'annee_universitaire_id' => $this->annee->id,
        ])->assertRedirect();
    }

    public function test_analyse_json_sans_permission_personnelle_masque_la_cin(): void
    {
        $reponse = $this->actingAs($this->agent)->postJson(route('admin.affectations.masse.analyser'), [
            'annee_universitaire_id' => $this->annee->id,
            'faculte_id' => $this->faculte->id,
        ]);

        $reponse->assertOk();
        $this->assertStringNotContainsString(self::CIN, $reponse->getContent());
    }

    public function test_export_masse_colonnes_fixes_non_manipulables(): void
    {
        // Tentative d'injection d'une sélection de colonnes : ignorée.
        $reponse = $this->actingAs($this->agent)->post(route('admin.affectations.masse.exporter'), [
            'annee_universitaire_id' => $this->annee->id,
            'faculte_id' => $this->faculte->id,
            'colonnes' => ['cin', 'password'],
            'cin' => self::CIN,
        ]);

        $reponse->assertOk();
        $contenu = (string) $reponse->streamedContent();

        $lignes = array_values(array_filter(explode("\n", trim($contenu))));
        $this->assertSame('num_inscription;nom;prenom;cin;niveau;programme;groupe_actuel;groupe_propose;score;certitude;statut;motif', trim($lignes[0], "\xEF\xBB\xBF\r"));
        $this->assertStringNotContainsString(self::CIN, $contenu);
        $this->assertStringNotContainsString('Secret@123', $contenu);
    }

    public function test_service_analyser_masque_la_cin_selon_utilisateur(): void
    {
        $criteres = [
            'annee_universitaire_id' => $this->annee->id,
            'faculte_id' => $this->faculte->id,
        ];

        $analyseAgent = app(MassAssignmentService::class)->analyser($criteres, $this->agent);
        $this->assertNotEmpty($analyseAgent['propositions']);
        $this->assertSame(self::CIN_MASQUEE, $analyseAgent['propositions'][0]['student_cin']);

        $analysePrivilegie = app(MassAssignmentService::class)->analyser($criteres, $this->privilegie);
        $this->assertSame(self::CIN, $analysePrivilegie['propositions'][0]['student_cin']);
    }

    public function test_vue_propositions_sans_permission_personnelle_masque_la_cin(): void
    {
        AffectationProposal::create([
            'student_id' => $this->etudiant->id,
            'annee_universitaire_id' => $this->annee->id,
            'proposed_group_id' => $this->groupe->id,
            'mode' => AffectationProposal::MODE_SEMI_AUTOMATIQUE,
            'score' => 90,
            'certitude' => AffectationProposal::CERTITUDE_ELEVEE,
            'statut' => AffectationProposal::STATUT_EN_ATTENTE,
        ]);

        $vueAgent = $this->actingAs($this->agent)->get(route('admin.affectations.propositions.index'));
        $vueAgent->assertOk();
        $vueAgent->assertDontSee(self::CIN, false);

        $vuePrivilegie = $this->actingAs($this->privilegie)->get(route('admin.affectations.propositions.index'));
        $vuePrivilegie->assertOk();
        $vuePrivilegie->assertSee(self::CIN, false);
    }
}
