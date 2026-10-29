<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\DossierCorrection;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\HistoriqueAcademique;
use App\Models\InscriptionAcademique;
use App\Models\User;
use App\Services\Workflow\MoteurWorkflowService;
use App\Services\Workflow\RegistreWorkflow;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Suite de tests pour LOT 01 — Workflow de validation configurable.
 *
 * Couvre :
 * - Contrôle strict des transitions côté serveur (interdiction des sauts d'états)
 * - Permissions requises pour chaque transition
 * - Séparation des fonctions (4 yeux : interdiction de l'auto-approbation)
 * - Conservation de la version de workflow pour les dossiers en cours
 * - Idempotence et prévention des doubles soumissions
 * - Isolation facultaire étanche (scoping serveur)
 * - Auditabilité complète des décisions (attribution, horodatage, métadonnées)
 */
class WorkflowValidationConfigurableTest extends TestCase
{
    use RefreshDatabase;

    private MoteurWorkflowService $moteur;

    private RegistreWorkflow $registre;

    private Faculte $faculte1;

    private Faculte $faculte2;

    private AnneeUniversitaire $annee;

    private Etudiant $etudiantF1;

    private InscriptionAcademique $inscriptionF1;

    private User $agentF1;

    private User $secondAgentF1;

    private User $agentF2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registre = app(RegistreWorkflow::class);
        $this->moteur = app(MoteurWorkflowService::class);

        $this->faculte1 = Faculte::create([
            'code' => 'FSB',
            'nom' => 'Faculté des Sciences de Bizerte',
            'active' => true,
        ]);

        $this->faculte2 = Faculte::create([
            'code' => 'FST',
            'nom' => 'Faculté des Sciences de Tunis',
            'active' => true,
        ]);

        $this->annee = AnneeUniversitaire::create([
            'code' => '2026-2027',
            'libelle' => 'Année 2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->etudiantF1 = Etudiant::create([
            'cin' => '12345678',
            'nom' => 'Ben Ali',
            'prenom' => 'Sami',
            'email' => 'sami.benali@uniregistre.tn',
            'sexe' => 'M',
            'faculte_id' => $this->faculte1->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => 'Avenue Habib Bourguiba',
            'ville' => 'Bizerte',
            'code_postal' => '7000',
            'annee_bac' => '2025',
            'statut' => 'valide',
        ]);

        $this->inscriptionF1 = InscriptionAcademique::create([
            'etudiant_id' => $this->etudiantF1->id,
            'annee_universitaire_id' => $this->annee->id,
            'niveau' => 'L1',
            'filiere' => 'Informatique',
            'statut' => 'en_attente',
        ]);

        // Agent F1 avec rôle agent_scolarite (possède archives.update)
        $this->agentF1 = User::creerCompteBackOffice([
            'name' => 'Agent F1',
            'email' => 'agent.f1@uniregistre.tn',
            'password' => 'MotDePasse123!',
            'faculte_id' => $this->faculte1->id,
            'actif' => true,
        ], 'agent_scolarite');

        // Second agent F1 pour la séparation 4 yeux
        $this->secondAgentF1 = User::creerCompteBackOffice([
            'name' => 'Second Agent F1',
            'email' => 'second.agent.f1@uniregistre.tn',
            'password' => 'MotDePasse123!',
            'faculte_id' => $this->faculte1->id,
            'actif' => true,
        ], 'agent_scolarite');

        // Agent restreint à la Faculté 2
        $this->agentF2 = User::creerCompteBackOffice([
            'name' => 'Admin Faculte 2',
            'email' => 'admin.f2@uniregistre.tn',
            'password' => 'MotDePasse123!',
            'faculte_id' => $this->faculte2->id,
            'actif' => true,
        ], 'admin_faculte');
    }

    public function test_transition_autorisee_avec_permission_met_a_jour_statut_et_audite(): void
    {
        $resultat = $this->moteur->transiter(
            'inscription',
            $this->inscriptionF1,
            'valide',
            $this->agentF1,
            'Dossier complet et vérifié conforme',
            ['source_validation' => 'guichet']
        );

        $this->assertEquals('valide', $resultat->statut);
        $this->assertDatabaseHas('inscriptions_academiques', [
            'id' => $this->inscriptionF1->id,
            'statut' => 'valide',
        ]);

        // Vérification de l'audit trail
        $this->assertDatabaseHas('historique_academique', [
            'etudiant_id' => $this->etudiantF1->id,
            'action' => HistoriqueAcademique::ACTION_MODIFICATION_INSCRIPTION,
            'entite' => 'inscription',
        ]);
    }

    public function test_transition_interdite_par_la_topologie_est_rejetee_avec_domain_exception(): void
    {
        // En V1 et V2, transition directe de brouillon vers valide est interdite
        $this->inscriptionF1->update(['statut' => 'brouillon']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Transition interdite');

        $this->moteur->transiter(
            'inscription',
            $this->inscriptionF1,
            'valide',
            $this->agentF1
        );
    }

    public function test_transition_refusee_si_l_acteur_n_a_pas_la_permission_requise(): void
    {
        $agentSansPerm = User::creerCompteBackOffice([
            'name' => 'Consultant Simple',
            'email' => 'consultant@uniregistre.tn',
            'password' => 'MotDePasse123!',
            'faculte_id' => $this->faculte1->id,
            'actif' => true,
        ], 'consultation'); // consultation n'a pas archives.update

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Permission « archives.update » requise');

        $this->moteur->transiter(
            'inscription',
            $this->inscriptionF1,
            'valide',
            $agentSansPerm
        );
    }

    public function test_separation_des_fonctions_quatre_yeux_interdit_l_auto_approbation(): void
    {
        // Création d'une correction proposée par l'agentF1
        $correction = DossierCorrection::create([
            'etudiant_id' => $this->etudiantF1->id,
            'champ' => 'nom',
            'ancienne_valeur' => 'Ben Ali',
            'nouvelle_valeur' => 'Ben Ali Correct',
            'regle' => 'rectification_etat_civil',
            'motif' => 'Erreur de saisie initiale',
            'statut' => 'proposee',
            'propose_par' => $this->agentF1->id,
        ]);

        // L'agentF1 essaie d'approuver sa propre proposition -> HTTP 403 attendu
        try {
            $this->moteur->transiter(
                'correction',
                $correction,
                'approuvee',
                $this->agentF1,
                'Auto-approbation illégale'
            );
            $this->fail('Une HttpException 403 aurait dû être levée pour violation du principe des 4 yeux.');
        } catch (HttpException $e) {
            $this->assertEquals(403, $e->getStatusCode());
            $this->assertStringContainsString('Séparation des fonctions requise', $e->getMessage());
        }

        // L'état en base doit être resté intact
        $this->assertDatabaseHas('dossier_corrections', [
            'id' => $correction->id,
            'statut' => 'proposee',
        ]);
    }

    public function test_second_agent_distinct_peut_approuver_la_correction_quatre_yeux(): void
    {
        $correction = DossierCorrection::create([
            'etudiant_id' => $this->etudiantF1->id,
            'champ' => 'nom',
            'ancienne_valeur' => 'Ben Ali',
            'nouvelle_valeur' => 'Ben Ali Correct',
            'regle' => 'rectification_etat_civil',
            'motif' => 'Erreur de frappe',
            'statut' => 'proposee',
            'propose_par' => $this->agentF1->id,
        ]);

        $resultat = $this->moteur->transiter(
            'correction',
            $correction,
            'approuvee',
            $this->secondAgentF1,
            'Approuvé après examen de l’acte de naissance'
        );

        $this->assertEquals('approuvee', $resultat->statut);
        $this->assertDatabaseHas('dossier_corrections', [
            'id' => $correction->id,
            'statut' => 'approuvee',
            'decide_par' => $this->secondAgentF1->id,
        ]);
    }

    public function test_dossier_en_cours_conserve_sa_version_de_workflow_applicable(): void
    {
        // Dossier initialisé explicitement sous la version 1 du workflow d'inscription
        $this->inscriptionF1->update([
            'statut' => 'valide',
        ]);
        $this->inscriptionF1->version_workflow = 1;

        // En V1, l'état 'valide' est terminal : la transition vers 'revoquee' est interdite
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('selon le workflow inscription v1');

        $this->moteur->transiter(
            'inscription',
            $this->inscriptionF1,
            'revoquee',
            $this->agentF1,
            'Tentative révocation en V1',
            [],
            1 // Version V1 imposée
        );
    }

    public function test_nouveau_dossier_v2_autorise_la_revocation_prevue_en_v2(): void
    {
        // En V2, la révocation post-validation est une nouvelle transition autorisée
        $this->inscriptionF1->update([
            'statut' => 'valide',
        ]);

        $agentAvecRevoke = User::creerCompteBackOffice([
            'name' => 'Super Admin',
            'email' => 'superadmin@uniregistre.tn',
            'password' => 'MotDePasse123!',
            'faculte_id' => $this->faculte1->id,
            'actif' => true,
        ], 'super_admin');

        $resultat = $this->moteur->transiter(
            'inscription',
            $this->inscriptionF1,
            'revoquee',
            $agentAvecRevoke,
            'Annulation sur ordre rectoral',
            [],
            2 // Version V2 applicable
        );

        $this->assertEquals('revoquee', $resultat->statut);
    }

    public function test_idempotence_si_deja_dans_l_etat_cible(): void
    {
        $this->inscriptionF1->update(['statut' => 'valide']);

        $resultat = $this->moteur->transiter(
            'inscription',
            $this->inscriptionF1,
            'valide',
            $this->agentF1
        );

        $this->assertEquals('valide', $resultat->statut);
    }

    public function test_isolation_facultaire_bloque_un_agent_d_une_autre_faculte(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('hors du périmètre de votre faculté');

        // L'agentF2 (Faculté 2) tente de valider l'inscription de l'étudiant de la Faculté 1
        $this->moteur->transiter(
            'inscription',
            $this->inscriptionF1,
            'valide',
            $this->agentF2
        );
    }
}
