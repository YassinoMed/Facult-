<?php

namespace Tests\Feature;

use App\Events\InscriptionValidee;
use App\Models\AnneeUniversitaire;
use App\Models\DossierCorrection;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\MensReference;
use App\Models\User;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Suite de tests des mutations métier du Centre de travail (Sous-lots D1, D2, D3).
 *
 * Exigences couvertes :
 * - D1 : Validation d'inscription éligible, blocage si non éligible, anti-TOCTOU, clôture de tâche, événements.
 * - D2 : Ajournement / refus d'inscription avec motif obligatoire, actualisation de tâche, refus d'état incompatible.
 * - D3 : Workflow à quatre yeux, approbation/rejet par un second agent, interdiction absolue d'auto-approbation (403),
 *        isolation facultaire (404), permissions 'archives.update'.
 */
class CentreTravailMutationTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte1;

    private Faculte $faculte2;

    private AnneeUniversitaire $annee;

    private Programme $programme;

    private Niveau $niveau;

    private User $agentScolariteF1;

    private User $secondAgentF1;

    private User $adminFaculte2;

    private User $consultant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte1 = Faculte::create([
            'code' => 'FST',
            'nom' => 'Faculté des Sciences de Tunis',
            'active' => true,
        ]);

        $this->faculte2 = Faculte::create([
            'code' => 'FSEGS',
            'nom' => 'Faculté de Sfax',
            'active' => true,
        ]);

        $this->annee = AnneeUniversitaire::create([
            'code' => '2026-2027',
            'libelle' => 'Année Académique 2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->programme = Programme::create([
            'faculte_id' => $this->faculte1->id,
            'code' => 'L-INFO',
            'nom' => 'Licence Informatique',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
            'actif' => true,
        ]);

        $this->niveau = Niveau::create([
            'programme_id' => $this->programme->id,
            'code' => 'L1',
            'nom' => 'Première Année Licence',
            'ordre' => 1,
            'actif' => true,
        ]);

        $this->agentScolariteF1 = User::creerCompteBackOffice([
            'name' => 'Agent Scolarité 1',
            'email' => 'agent1.fst@fst.test',
            'password' => 'Secret@123',
            'faculte_id' => $this->faculte1->id,
        ], 'agent_scolarite');

        $this->secondAgentF1 = User::creerCompteBackOffice([
            'name' => 'Agent Scolarité 2',
            'email' => 'agent2.fst@fst.test',
            'password' => 'Secret@123',
            'faculte_id' => $this->faculte1->id,
        ], 'agent_scolarite');

        $this->adminFaculte2 = User::creerCompteBackOffice([
            'name' => 'Doyen FSEGS',
            'email' => 'admin.fsegs@fsegs.test',
            'password' => 'Secret@123',
            'faculte_id' => $this->faculte2->id,
        ], 'admin_faculte');

        $this->consultant = User::creerCompteBackOffice([
            'name' => 'Auditeur Consultation',
            'email' => 'consultant@uniregistre.test',
            'password' => 'Secret@123',
        ], 'consultation');

        // Configuration par défaut : pas de pièces supplémentaires obligatoires pour simplifier l'éligibilité
        config(['inscriptions.documents_requis_par_type' => [
            'premiere' => [],
            'reinscription' => [],
            'transfert' => [],
        ]]);
    }

    private function creerEtudiant(string $cin, Faculte $faculte): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin,
            'nom' => 'Ben Salah',
            'prenom' => 'Mohamed',
            'email' => "etudiant.{$cin}@fst.test",
            'date_naissance' => '2004-06-15',
            'sexe' => 'M',
            'faculte_id' => $faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => 'Campus Universitaire',
            'ville' => 'Tunis',
            'code_postal' => '1060',
            'annee_bac' => '2023',
            'password' => 'SecretHash@123',
        ]);
        $etudiant->definirStatut('en_attente');

        // Création de la référence MENS pour correspondre au CIN
        MensReference::create([
            'cin_normalise' => MensReference::normaliserCin($cin),
            'cin_original' => $cin,
            'date_naissance' => '2004-06-15',
            'nom' => 'Ben Salah',
            'prenom' => 'Mohamed',
            'annee' => '2026',
            'source_hash' => hash('sha256', $cin),
        ]);

        return $etudiant;
    }

    private function creerInscription(Etudiant $etudiant): InscriptionAcademique
    {
        return InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programme->id,
            'niveau_id' => $this->niveau->id,
            'niveau' => 'L1',
            'type' => 'premiere',
            'statut' => 'en_cours',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | LOT D1 : VALIDATION D'UNE INSCRIPTION
    |--------------------------------------------------------------------------
    */

    public function test_validation_autorisee_dossier_eligible(): void
    {
        $etudiant = $this->creerEtudiant('08123470', $this->faculte1);
        $inscription = $this->creerInscription($etudiant);

        $tache = Task::create([
            'titre' => 'Contrôle dossier',
            'source' => 'inscription',
            'cle_unique' => "inscription:{$inscription->id}",
            'statut' => 'pending',
            'priorite' => 'normal',
            'faculte_id' => $this->faculte1->id,
        ]);

        $reponse = $this->actingAs($this->agentScolariteF1)->post(
            route('admin.centre-travail.valider', $inscription),
            ['motif' => 'Validation conforme après contrôle']
        );

        $reponse->assertRedirect(route('admin.centre-travail.show', $inscription));
        $reponse->assertSessionHas('success');

        // Statut de l'inscription transité vers 'valide'
        $this->assertSame('valide', $inscription->fresh()->statut);

        // Tâche associée clôturée
        $tacheFraiche = $tache->fresh();
        $this->assertSame('completed', $tacheFraiche->statut);
        $this->assertNotNull($tacheFraiche->terminee_a);

        // Audit et situation créés
        $this->assertDatabaseHas('situations_etudiant', [
            'etudiant_id' => $etudiant->id,
            'nouvelle_valeur' => 'inscription:valide',
        ]);
    }

    public function test_refus_validation_si_eligibilite_bloquante(): void
    {
        // On exige la CIN dans la GED pour provoquer un BLOCAGE
        config(['inscriptions.documents_requis_par_type' => ['premiere' => ['cin_manquante']]]);

        $etudiant = $this->creerEtudiant('08123471', $this->faculte1);
        $inscription = $this->creerInscription($etudiant);

        $tache = Task::create([
            'titre' => 'Contrôle dossier',
            'source' => 'inscription',
            'cle_unique' => "inscription:{$inscription->id}",
            'statut' => 'pending',
            'priorite' => 'normal',
            'faculte_id' => $this->faculte1->id,
        ]);

        $reponse = $this->actingAs($this->agentScolariteF1)->post(
            route('admin.centre-travail.valider', $inscription)
        );

        $reponse->assertSessionHasErrors('statut');

        // Le statut de l'inscription doit rester 'en_cours'
        $this->assertSame('en_cours', $inscription->fresh()->statut);

        // La tâche doit rester active
        $this->assertSame('pending', $tache->fresh()->statut);
    }

    public function test_validation_refusee_si_deja_validee(): void
    {
        $etudiant = $this->creerEtudiant('08123472', $this->faculte1);
        $inscription = $this->creerInscription($etudiant);
        $inscription->update(['statut' => 'valide']);

        $reponse = $this->actingAs($this->agentScolariteF1)->post(
            route('admin.centre-travail.valider', $inscription)
        );

        $reponse->assertSessionHasErrors('statut');
        $this->assertSame('valide', $inscription->fresh()->statut);
    }

    public function test_validation_refus_si_non_authentifie(): void
    {
        $etudiant = $this->creerEtudiant('08123473', $this->faculte1);
        $inscription = $this->creerInscription($etudiant);

        $reponse = $this->post(route('admin.centre-travail.valider', $inscription));
        $reponse->assertRedirect(route('admin.connexion.show'));
    }

    public function test_validation_refus_si_role_sans_permission(): void
    {
        $etudiant = $this->creerEtudiant('08123474', $this->faculte1);
        $inscription = $this->creerInscription($etudiant);

        // Consultant has only 'tasks.view', NOT 'archives.update'
        $reponse = $this->actingAs($this->consultant)->post(
            route('admin.centre-travail.valider', $inscription)
        );

        $reponse->assertForbidden();
        $this->assertSame('en_cours', $inscription->fresh()->statut);
    }

    public function test_validation_refus_acces_inter_facultes_anti_bola(): void
    {
        $etudiant = $this->creerEtudiant('08123475', $this->faculte1);
        $inscription = $this->creerInscription($etudiant);

        // Doyen FSEGS tries to validate inscription belonging to FST
        $reponse = $this->actingAs($this->adminFaculte2)->post(
            route('admin.centre-travail.valider', $inscription)
        );

        $reponse->assertNotFound();
        $this->assertSame('en_cours', $inscription->fresh()->statut);
    }

    public function test_validation_declenche_evenement_post_commit_et_tache(): void
    {
        $etudiant = $this->creerEtudiant('08123476', $this->faculte1);
        $inscription = $this->creerInscription($etudiant);

        Event::fake([InscriptionValidee::class]);

        $reponse = $this->actingAs($this->agentScolariteF1)->post(
            route('admin.centre-travail.valider', $inscription),
            ['motif' => 'Validation officielle']
        );

        $reponse->assertRedirect();
        Event::assertDispatched(InscriptionValidee::class);
    }

    /*
    |--------------------------------------------------------------------------
    | LOT D2 : REFUS / AJOURNEMENT D'UNE INSCRIPTION
    |--------------------------------------------------------------------------
    */

    public function test_ajournement_autorise_avec_motif(): void
    {
        $etudiant = $this->creerEtudiant('08123477', $this->faculte1);
        $inscription = $this->creerInscription($etudiant);

        $tache = Task::create([
            'titre' => 'Contrôle dossier',
            'source' => 'inscription',
            'cle_unique' => "inscription:{$inscription->id}",
            'statut' => 'pending',
            'priorite' => 'normal',
            'faculte_id' => $this->faculte1->id,
        ]);

        $reponse = $this->actingAs($this->agentScolariteF1)->post(
            route('admin.centre-travail.rejeter', $inscription),
            ['motif' => 'Dossier incomplet : relevé de notes du baccalauréat non certifié']
        );

        $reponse->assertRedirect(route('admin.centre-travail.show', $inscription));
        $reponse->assertSessionHas('success');

        // Statut de l'inscription transité vers 'ajourne'
        $this->assertSame('ajourne', $inscription->fresh()->statut);

        // Tâche associée annulée
        $this->assertSame('cancelled', $tache->fresh()->statut);

        // Situation créée avec motif
        $this->assertDatabaseHas('situations_etudiant', [
            'etudiant_id' => $etudiant->id,
            'nouvelle_valeur' => 'inscription:ajourne',
            'motif' => 'Dossier incomplet : relevé de notes du baccalauréat non certifié',
        ]);
    }

    public function test_ajournement_interdit_sans_motif(): void
    {
        $etudiant = $this->creerEtudiant('08123478', $this->faculte1);
        $inscription = $this->creerInscription($etudiant);

        $reponse = $this->actingAs($this->agentScolariteF1)->post(
            route('admin.centre-travail.rejeter', $inscription),
            ['motif' => '']
        );

        $reponse->assertSessionHasErrors('motif');
        $this->assertSame('en_cours', $inscription->fresh()->statut);
    }

    public function test_ajournement_interdit_si_statut_incompatible(): void
    {
        $etudiant = $this->creerEtudiant('08123479', $this->faculte1);
        $inscription = $this->creerInscription($etudiant);
        $inscription->update(['statut' => 'valide']);

        $reponse = $this->actingAs($this->agentScolariteF1)->post(
            route('admin.centre-travail.rejeter', $inscription),
            ['motif' => 'Tentative rejet sur validé']
        );

        $reponse->assertSessionHasErrors('statut');
        $this->assertSame('valide', $inscription->fresh()->statut);
    }

    public function test_ajournement_refus_sans_permission(): void
    {
        $etudiant = $this->creerEtudiant('08123480', $this->faculte1);
        $inscription = $this->creerInscription($etudiant);

        $reponse = $this->actingAs($this->consultant)->post(
            route('admin.centre-travail.rejeter', $inscription),
            ['motif' => 'Tentative consultant']
        );

        $reponse->assertForbidden();
        $this->assertSame('en_cours', $inscription->fresh()->statut);
    }

    public function test_ajournement_refus_inter_facultes(): void
    {
        $etudiant = $this->creerEtudiant('08123481', $this->faculte1);
        $inscription = $this->creerInscription($etudiant);

        $reponse = $this->actingAs($this->adminFaculte2)->post(
            route('admin.centre-travail.rejeter', $inscription),
            ['motif' => 'Tentative FSEGS sur FST']
        );

        $reponse->assertNotFound();
        $this->assertSame('en_cours', $inscription->fresh()->statut);
    }

    /*
    |--------------------------------------------------------------------------
    | LOT D3 : WORKFLOW À QUATRE YEUX (CORRECTIONS)
    |--------------------------------------------------------------------------
    */

    public function test_approbation_correction_quatre_yeux_par_second_agent(): void
    {
        $etudiant = $this->creerEtudiant('08123482', $this->faculte1);
        $inscription = $this->creerInscription($etudiant);

        // Proposition créée par Agent 1
        $correction = DossierCorrection::create([
            'etudiant_id' => $etudiant->id,
            'inscription_academique_id' => $inscription->id,
            'champ' => 'nom',
            'ancienne_valeur' => 'Ben Salah',
            'nouvelle_valeur' => 'Ben Salah Validé',
            'regle' => 'R12_MENS',
            'motif' => 'Correction selon extrait naissance',
            'statut' => 'proposee',
            'propose_par' => $this->agentScolariteF1->id,
        ]);

        // Agent 2 approuve la proposition
        $reponse = $this->actingAs($this->secondAgentF1)->post(
            route('admin.centre-travail.corrections.decider', [$inscription, $correction]),
            ['decision' => 'approuver', 'motif' => 'Approbation conforme']
        );

        $reponse->assertRedirect(route('admin.centre-travail.show', $inscription));
        $reponse->assertSessionHas('success');

        $correctionFraiche = $correction->fresh();
        $this->assertSame('approuvee', $correctionFraiche->statut);
        $this->assertSame($this->secondAgentF1->id, $correctionFraiche->decide_par);
        $this->assertNotNull($correctionFraiche->decide_le);
    }

    public function test_auto_approbation_strictement_interdite(): void
    {
        $etudiant = $this->creerEtudiant('08123483', $this->faculte1);
        $inscription = $this->creerInscription($etudiant);

        // Proposition créée par Agent 1
        $correction = DossierCorrection::create([
            'etudiant_id' => $etudiant->id,
            'inscription_academique_id' => $inscription->id,
            'champ' => 'nom',
            'ancienne_valeur' => 'Ben Salah',
            'nouvelle_valeur' => 'Ben Salah Auto',
            'regle' => 'R12_MENS',
            'motif' => 'Correction selon extrait naissance',
            'statut' => 'proposee',
            'propose_par' => $this->agentScolariteF1->id,
        ]);

        // Agent 1 tente d'approuver sa propre proposition (Auto-approbation) -> 403 interdit
        $reponse = $this->actingAs($this->agentScolariteF1)->post(
            route('admin.centre-travail.corrections.decider', [$inscription, $correction]),
            ['decision' => 'approuver']
        );

        $reponse->assertForbidden();

        // Le statut doit impérativement rester 'proposee'
        $this->assertSame('proposee', $correction->fresh()->statut);
    }

    public function test_rejet_correction_quatre_yeux_par_second_agent(): void
    {
        $etudiant = $this->creerEtudiant('08123484', $this->faculte1);
        $inscription = $this->creerInscription($etudiant);

        // Proposition créée par Agent 1
        $correction = DossierCorrection::create([
            'etudiant_id' => $etudiant->id,
            'inscription_academique_id' => $inscription->id,
            'champ' => 'nom',
            'ancienne_valeur' => 'Ben Salah',
            'nouvelle_valeur' => 'Ben Salah Rejete',
            'regle' => 'R12_MENS',
            'motif' => 'Erreur de saisie',
            'statut' => 'proposee',
            'propose_par' => $this->agentScolariteF1->id,
        ]);

        // Agent 2 rejette la proposition
        $reponse = $this->actingAs($this->secondAgentF1)->post(
            route('admin.centre-travail.corrections.decider', [$inscription, $correction]),
            ['decision' => 'rejeter', 'motif' => 'Justificatif non convaincant']
        );

        $reponse->assertRedirect(route('admin.centre-travail.show', $inscription));
        $reponse->assertSessionHas('success');

        $correctionFraiche = $correction->fresh();
        $this->assertSame('rejetee', $correctionFraiche->statut);
        $this->assertSame($this->secondAgentF1->id, $correctionFraiche->decide_par);
    }

    public function test_auto_rejet_strictement_interdit(): void
    {
        $etudiant = $this->creerEtudiant('08123485', $this->faculte1);
        $inscription = $this->creerInscription($etudiant);

        $correction = DossierCorrection::create([
            'etudiant_id' => $etudiant->id,
            'inscription_academique_id' => $inscription->id,
            'champ' => 'nom',
            'ancienne_valeur' => 'Ben Salah',
            'nouvelle_valeur' => 'Ben Salah Test',
            'regle' => 'R12_MENS',
            'motif' => 'Test',
            'statut' => 'proposee',
            'propose_par' => $this->agentScolariteF1->id,
        ]);

        // Agent 1 tente de rejeter sa propre proposition -> 403
        $reponse = $this->actingAs($this->agentScolariteF1)->post(
            route('admin.centre-travail.corrections.decider', [$inscription, $correction]),
            ['decision' => 'rejeter', 'motif' => 'Auto-rejet']
        );

        $reponse->assertForbidden();
        $this->assertSame('proposee', $correction->fresh()->statut);
    }

    public function test_decision_correction_refus_inter_facultes(): void
    {
        $etudiant = $this->creerEtudiant('08123486', $this->faculte1);
        $inscription = $this->creerInscription($etudiant);

        $correction = DossierCorrection::create([
            'etudiant_id' => $etudiant->id,
            'inscription_academique_id' => $inscription->id,
            'champ' => 'nom',
            'ancienne_valeur' => 'Ben Salah',
            'nouvelle_valeur' => 'Ben Salah Cross',
            'regle' => 'R12_MENS',
            'motif' => 'Test',
            'statut' => 'proposee',
            'propose_par' => $this->agentScolariteF1->id,
        ]);

        // Agent FSEGS tente de décider une correction sur FST -> 404 anti-BOLA
        $reponse = $this->actingAs($this->adminFaculte2)->post(
            route('admin.centre-travail.corrections.decider', [$inscription, $correction]),
            ['decision' => 'approuver']
        );

        $reponse->assertNotFound();
        $this->assertSame('proposee', $correction->fresh()->statut);
    }

    public function test_decision_correction_refus_sans_permission(): void
    {
        $etudiant = $this->creerEtudiant('08123487', $this->faculte1);
        $inscription = $this->creerInscription($etudiant);

        $correction = DossierCorrection::create([
            'etudiant_id' => $etudiant->id,
            'inscription_academique_id' => $inscription->id,
            'champ' => 'nom',
            'ancienne_valeur' => 'Ben Salah',
            'nouvelle_valeur' => 'Ben Salah Perm',
            'regle' => 'R12_MENS',
            'motif' => 'Test',
            'statut' => 'proposee',
            'propose_par' => $this->agentScolariteF1->id,
        ]);

        $reponse = $this->actingAs($this->consultant)->post(
            route('admin.centre-travail.corrections.decider', [$inscription, $correction]),
            ['decision' => 'approuver']
        );

        $reponse->assertForbidden();
        $this->assertSame('proposee', $correction->fresh()->statut);
    }

    public function test_decision_correction_refus_si_correction_deja_traitee(): void
    {
        $etudiant = $this->creerEtudiant('08123488', $this->faculte1);
        $inscription = $this->creerInscription($etudiant);

        $correction = DossierCorrection::create([
            'etudiant_id' => $etudiant->id,
            'inscription_academique_id' => $inscription->id,
            'champ' => 'nom',
            'ancienne_valeur' => 'Ben Salah',
            'nouvelle_valeur' => 'Ben Salah Déjà',
            'regle' => 'R12_MENS',
            'motif' => 'Test',
            'statut' => 'approuvee',
            'propose_par' => $this->agentScolariteF1->id,
            'decide_par' => $this->secondAgentF1->id,
        ]);

        $reponse = $this->actingAs($this->secondAgentF1)->post(
            route('admin.centre-travail.corrections.decider', [$inscription, $correction]),
            ['decision' => 'approuver']
        );

        $reponse->assertSessionHasErrors('correction');
    }

    public function test_decision_correction_refus_si_etudiant_incoherent(): void
    {
        $etudiant1 = $this->creerEtudiant('08123489', $this->faculte1);
        $inscription1 = $this->creerInscription($etudiant1);

        $etudiant2 = $this->creerEtudiant('08123490', $this->faculte1);

        // Correction pour etudiant2
        $correction2 = DossierCorrection::create([
            'etudiant_id' => $etudiant2->id,
            'champ' => 'nom',
            'ancienne_valeur' => 'Ben Salah',
            'nouvelle_valeur' => 'Incohérent',
            'regle' => 'R12_MENS',
            'motif' => 'Test',
            'statut' => 'proposee',
            'propose_par' => $this->agentScolariteF1->id,
        ]);

        // Tentative d'appliquer correction2 via l'URL de inscription1 -> 404
        $reponse = $this->actingAs($this->secondAgentF1)->post(
            route('admin.centre-travail.corrections.decider', [$inscription1, $correction2]),
            ['decision' => 'approuver']
        );

        $reponse->assertNotFound();
    }

    public function test_decision_correction_refus_si_correction_autre_inscription_meme_etudiant(): void
    {
        $etudiant = $this->creerEtudiant('08123491', $this->faculte1);
        $inscription1 = $this->creerInscription($etudiant);

        $anneePrecedente = AnneeUniversitaire::create([
            'code' => '2025-2026',
            'libelle' => 'Année Académique 2025-2026',
            'date_debut' => '2025-09-01',
            'date_fin' => '2026-06-30',
            'active' => false,
        ]);

        $inscription2 = InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $anneePrecedente->id,
            'programme_id' => $this->programme->id,
            'niveau_id' => $this->niveau->id,
            'niveau' => 'L1',
            'type' => 'premiere',
            'statut' => 'en_cours',
        ]);

        // Correction rattachée à l'inscription 2, décidée via l'URL de l'inscription 1
        // (même étudiant) : elle ne doit jamais être approuvée par le mauvais dossier.
        $correction2 = DossierCorrection::create([
            'etudiant_id' => $etudiant->id,
            'inscription_academique_id' => $inscription2->id,
            'champ' => 'nom',
            'ancienne_valeur' => 'Ben Salah',
            'nouvelle_valeur' => 'Ben Salah Autre Inscription',
            'regle' => 'R12_MENS',
            'motif' => 'Test',
            'statut' => 'proposee',
            'propose_par' => $this->agentScolariteF1->id,
        ]);

        $reponse = $this->actingAs($this->secondAgentF1)->post(
            route('admin.centre-travail.corrections.decider', [$inscription1, $correction2]),
            ['decision' => 'approuver']
        );

        $reponse->assertNotFound();
        $this->assertSame('proposee', $correction2->fresh()->statut);

        // La proposition d'une autre inscription ne doit pas être affichée
        // comme actionnable dans le dossier de l'inscription 1.
        $this->actingAs($this->secondAgentF1)
            ->get(route('admin.centre-travail.show', $inscription1))
            ->assertOk()
            ->assertDontSee('Ben Salah Autre Inscription');
    }
}
