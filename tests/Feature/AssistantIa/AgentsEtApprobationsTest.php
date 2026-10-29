<?php

namespace Tests\Feature\AssistantIa;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\User;
use App\Modules\AssistantIa\Exceptions\SecuriteIaException;
use App\Modules\AssistantIa\Services\WorkflowApprobationService;
use App\Modules\AssistantIa\Tools\Developpeur\InspecterFichiersCodeTool;
use App\Modules\AssistantIa\Tools\Developpeur\InspecterRoutesTool;
use App\Modules\AssistantIa\Tools\Developpeur\ProposerPatchTool;
use App\Modules\AssistantIa\Tools\Metier\DiagnostiquerBlocageDossierTool;
use App\Modules\AssistantIa\Tools\Metier\ExpliquerCycleInscriptionTool;
use App\Modules\AssistantIa\Tools\Metier\VerifierPiecesManquantesTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentsEtApprobationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_outil_dev_rechercher_fichiers_code_respecte_les_perimetres_et_exclusions(): void
    {
        $outil = new InspecterFichiersCodeTool;
        $admin = User::factory()->create();
        $admin->definirRole('super_admin');

        // Recherche valide dans app
        $res = $outil->executer(['motif' => 'User', 'dossier' => 'app'], $admin);
        $this->assertTrue($res->succes);
        $this->assertNotEmpty($res->donnees['fichiers']);

        // Tentative d'accès hors périmètre ou path traversal
        $this->expectException(SecuriteIaException::class);
        $outil->executer(['motif' => 'env', 'dossier' => '../'], $admin);
    }

    public function test_outil_dev_consulter_routes_liste_les_endpoints_autorises(): void
    {
        $outil = new InspecterRoutesTool;
        $admin = User::factory()->create();
        $admin->definirRole('super_admin');

        $res = $outil->executer(['filtre' => 'assistant-ia'], $admin);
        $this->assertTrue($res->succes);
        $this->assertGreaterThan(0, $res->donnees['total']);
    }

    public function test_outil_dev_proposer_patch_genere_diff_sans_ecrire_sur_le_disque(): void
    {
        $outil = new ProposerPatchTool;
        $admin = User::factory()->create();
        $admin->definirRole('super_admin');

        $diffExemple = "--- a/app/Example.php\n+++ b/app/Example.php\n@@ -1,2 +1,2 @@\n-old\n+new";

        $res = $outil->executer([
            'fichier' => 'app/Services/ExempleService.php',
            'justification' => 'Optimisation de performance',
            'diff_unifie' => $diffExemple,
            'analyse_risques' => 'Risque faible',
            'tests_proposes' => 'Test unitaire spécifique',
        ], $admin);

        $this->assertTrue($res->succes);
        $this->assertSame('proposition_affichee_sans_application', $res->donnees['statut']);
        $this->assertSame($diffExemple, $res->donnees['diff']);
    }

    public function test_outil_metier_expliquer_cycle_inscription(): void
    {
        $outil = new ExpliquerCycleInscriptionTool;
        $agent = User::factory()->create();
        $agent->definirRole('agent_scolarite');

        $res = $outil->executer([], $agent);
        $this->assertTrue($res->succes);
        $this->assertCount(5, $res->donnees['etapes_cycle']);
    }

    public function test_outil_metier_verifier_pieces_manquantes(): void
    {
        $outil = new VerifierPiecesManquantesTool;
        $agent = User::factory()->create();
        $agent->definirRole('agent_scolarite');

        $res = $outil->executer(['categorie' => 'identity'], $agent);
        $this->assertTrue($res->succes);
        $this->assertGreaterThan(0, $res->donnees['total']);
    }

    public function test_outil_metier_diagnostiquer_blocage_dossier_et_isolation_facultaire(): void
    {
        $faculte1 = Faculte::create(['nom' => 'Faculté des Sciences', 'code' => 'FST', 'active' => true]);
        $faculte2 = Faculte::create(['nom' => 'Faculté de Droit', 'code' => 'FD', 'active' => true]);

        $etudiant = Etudiant::create([
            'cin' => '08765432',
            'nom' => 'Ben Salah',
            'prenom' => 'Mohamed',
            'email' => 'etudiant.08765432@fst.test',
            'date_naissance' => '2004-06-15',
            'sexe' => 'M',
            'faculte_id' => $faculte1->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => 'Campus Universitaire',
            'ville' => 'Tunis',
            'code_postal' => '1060',
            'annee_bac' => '2023',
            'password' => 'SecretHash@123',
        ]);

        $annee = AnneeUniversitaire::create([
            'code' => '2026-2027',
            'libelle' => 'Année Académique 2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $inscription = InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $annee->id,
            'faculte_id' => $faculte1->id,
            'statut' => 'en_attente',
            'type' => 'premiere',
            'niveau' => 'L1',
            'filiere' => 'Informatique',
        ]);

        $outil = app(DiagnostiquerBlocageDossierTool::class);

        // 1. Agent rattaché à la Faculté 1 : autorisé
        $agentFaculte1 = User::factory()->create(['faculte_id' => $faculte1->id]);
        $agentFaculte1->definirRole('admin_faculte');

        $res = $outil->executer(['inscription_id' => $inscription->id], $agentFaculte1);
        $this->assertTrue($res->succes);
        $this->assertArrayHasKey('verdict', $res->donnees);

        // 2. Agent rattaché à la Faculté 2 : bloqué par isolation facultaire
        $agentFaculte2 = User::factory()->create(['faculte_id' => $faculte2->id]);
        $agentFaculte2->definirRole('admin_faculte');

        $this->expectException(SecuriteIaException::class);
        $outil->executer(['inscription_id' => $inscription->id], $agentFaculte2);
    }

    public function test_workflow_approbation_sensible_a_quatre_yeux_empeche_auto_approbation(): void
    {
        $workflow = new WorkflowApprobationService;

        // Le demandeur a la permission d'approuver (admin_scolarite), mais ne peut PAS s'auto-approuver
        $demandeur = User::factory()->create();
        $demandeur->definirRole('admin_scolarite');

        // Création de proposition sensible
        $proposition = $workflow->proposerAction(
            demandeur: $demandeur,
            typeAction: 'debloquer_dossier',
            ressourceType: 'InscriptionAcademique',
            ressourceId: 999,
            description: 'Déblocage pour inscription tardive',
            parametres: ['motif' => 'Certificat médical reçu'],
            resumeImpact: 'Autorise l\'étudiant à finaliser son inscription',
            niveauRisque: 'eleve'
        );

        $this->assertSame('pending_approval', $proposition->statut);
        $this->assertTrue($proposition->estEnAttente());
        $this->assertTrue($proposition->verifierIntegrite());

        // RÈGLE DES 4 YEUX : Le demandeur tente d'approuver sa propre proposition -> REJETÉ
        $this->expectException(SecuriteIaException::class);
        $this->expectExceptionMessage('Règle des 4 yeux');
        $workflow->approuverAction($proposition, $demandeur);
    }

    public function test_workflow_approbation_reussie_par_un_second_utilisateur_autorise(): void
    {
        $workflow = new WorkflowApprobationService;

        $demandeur = User::factory()->create();
        $demandeur->definirRole('agent_scolarite');

        $approbateur = User::factory()->create();
        $approbateur->definirRole('admin_scolarite'); // A la permission action_approuver

        $proposition = $workflow->proposerAction(
            demandeur: $demandeur,
            typeAction: 'relancer_etudiant',
            ressourceType: 'InscriptionAcademique',
            ressourceId: 888,
            description: 'Relance pour pièces manquantes',
            parametres: ['delai_jours' => 5],
            resumeImpact: 'Envoi d\'une notification de rappel'
        );

        $res = $workflow->approuverAction($proposition, $approbateur, 'Validé conforme');
        $this->assertTrue($res['succes']);

        $proposition->refresh();
        $this->assertSame('completed', $proposition->statut);
        $this->assertSame($approbateur->id, $proposition->approbateur_id);
        $this->assertNotNull($proposition->execute_a);

        // Anti-rejeu : impossible d'approuver une seconde fois
        $this->expectException(SecuriteIaException::class);
        $workflow->approuverAction($proposition, $approbateur);
    }

    public function test_workflow_detecte_alteration_du_payload_sha256(): void
    {
        $workflow = new WorkflowApprobationService;

        $demandeur = User::factory()->create();
        $demandeur->definirRole('agent_scolarite');

        $approbateur = User::factory()->create();
        $approbateur->definirRole('admin_scolarite');

        $proposition = $workflow->proposerAction(
            demandeur: $demandeur,
            typeAction: 'rembourser_frais',
            ressourceType: 'InscriptionAcademique',
            ressourceId: 777,
            description: 'Remboursement de 50 DT',
            parametres: ['montant' => 50],
            resumeImpact: 'Remboursement bancaire'
        );

        // Falsification malveillante directe du montant en base (50 -> 500)
        $proposition->parametres = ['montant' => 500];
        $proposition->saveQuietly();

        $this->assertFalse($proposition->verifierIntegrite());

        // L'approbation doit être immédiatement bloquée pour falsification
        $this->expectException(SecuriteIaException::class);
        $this->expectExceptionMessage('Échec de vérification d\'intégrité');
        $workflow->approuverAction($proposition, $approbateur);
    }
}
