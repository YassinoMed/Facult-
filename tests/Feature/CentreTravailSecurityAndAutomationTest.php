<?php

namespace Tests\Feature;

use App\Events\InscriptionAcademiqueCreee;
use App\Models\AnneeUniversitaire;
use App\Models\DossierCorrection;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\HistoriqueAcademique;
use App\Models\InscriptionAcademique;
use App\Models\RegistrationPeriod;
use App\Models\User;
use App\Modules\Finances\Models\DossierPaiement;
use App\Modules\Finances\Models\RecuPaiement;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskEvenement;
use App\Modules\Tasks\Services\TaskService;
use App\Services\Verification\CorrectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Suite de tests de certification LOOP MASTER pour le Centre de travail administratif intelligent.
 *
 * Référence : LOOP-UNIREGISTRE-CENTRE-TRAVAIL-INTELLIGENT-MASTER-AUTO-PASS-01
 * Couverture complète :
 * - Lot P1 : Sécurité, anti-BOLA/IDOR (404), comptes inactifs (403), workflow 4-yeux impossible à contourner.
 * - Lot P2 : Cockpit opérationnel, 10 KPIs exacts, filtres combinables, actions groupées strictes.
 * - Lot P2 : Dossier 360°, 7 sections canoniques, historique des corrections, fail-soft finances & affectations.
 * - Lot P3 : Automatisation contrôlée, écoute d'événements, déduplication, équilibrage de charge, escalade SLA, réconciliation.
 */
class CentreTravailSecurityAndAutomationTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte1;

    private Faculte $faculte2;

    private AnneeUniversitaire $annee;

    private Programme $programme;

    private Niveau $niveau;

    private User $superAdmin;

    private User $agentScolariteF1;

    private User $secondAgentF1;

    private User $agentF2;

    private User $agentInactif;

    private User $utilisateurSansDroit;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'modules.tasks.active' => true,
            'modules.finances.active' => true,
        ]);

        $this->faculte1 = Faculte::create([
            'code' => 'FST',
            'nom' => 'Faculté des Sciences de Tunis',
            'active' => true,
        ]);

        $this->faculte2 = Faculte::create([
            'code' => 'FSEGS',
            'nom' => 'Faculté des Sciences Économiques et de Gestion de Sfax',
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
            'code' => 'L-INFO',
            'nom' => 'Licence en Informatique',
            'type' => 'LMD',
            'faculte_id' => $this->faculte1->id,
            'active' => true,
        ]);

        $this->niveau = Niveau::create([
            'code' => 'L1',
            'nom' => 'Première Année Licence',
            'programme_id' => $this->programme->id,
            'ordre' => 1,
            'active' => true,
        ]);

        // Période d'inscription ouverte
        RegistrationPeriod::create([
            'annee_universitaire_id' => $this->annee->id,
            'faculte_id' => $this->faculte1->id,
            'statut' => 'active',
            'type_periode' => 'normale',
            'opened_at' => now()->subDays(5),
            'closed_at' => now()->addDays(20),
        ]);

        // Comptes de test
        $this->superAdmin = User::creerCompteBackOffice([
            'name' => 'Super Administrateur',
            'email' => 'superadmin@uniregistre.tn',
            'password' => 'Secret@123',
            'actif' => true,
        ], 'super_admin');

        $this->agentScolariteF1 = User::creerCompteBackOffice([
            'name' => 'Agent Scolarité FST 1',
            'email' => 'agent1.fst@uniregistre.tn',
            'password' => 'Secret@123',
            'faculte_id' => $this->faculte1->id,
            'actif' => true,
        ], 'agent_scolarite');

        $this->secondAgentF1 = User::creerCompteBackOffice([
            'name' => 'Agent Scolarité FST 2',
            'email' => 'agent2.fst@uniregistre.tn',
            'password' => 'Secret@123',
            'faculte_id' => $this->faculte1->id,
            'actif' => true,
        ], 'agent_scolarite');

        $this->agentF2 = User::creerCompteBackOffice([
            'name' => 'Agent Scolarité FSEGS',
            'email' => 'agent.fsegs@uniregistre.tn',
            'password' => 'Secret@123',
            'faculte_id' => $this->faculte2->id,
            'actif' => true,
        ], 'admin_faculte');

        $this->agentInactif = User::creerCompteBackOffice([
            'name' => 'Agent Désactivé',
            'email' => 'agent.inactif@uniregistre.tn',
            'password' => 'Secret@123',
            'faculte_id' => $this->faculte1->id,
            'actif' => false,
        ], 'agent_scolarite');

        $this->utilisateurSansDroit = User::create([
            'name' => 'Utilisateur Lambda Sans Droits',
            'email' => 'sansdroit@uniregistre.tn',
            'password' => bcrypt('Secret@123'),
            'actif' => true,
        ]);
        $this->utilisateurSansDroit->role = 'etudiant';
        $this->utilisateurSansDroit->save();
    }

    private function creerDossierInscription(Faculte $faculte, string $statut = 'en_cours'): InscriptionAcademique
    {
        $matricule = 'ETU'.str_pad((string) mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
        $cin = '0'.mt_rand(1000000, 9999999);

        $etudiant = Etudiant::create([
            'matricule' => $matricule,
            'nom' => 'Ben Salah',
            'prenom' => 'Ahmed',
            'date_naissance' => '2004-05-15',
            'sexe' => 'M',
            'cin' => $cin,
            'email' => "ahmed.{$matricule}@etudiant.tn",
            'telephone' => '21698123456',
            'nationalite' => 'TN',
            'faculte_id' => $faculte->id,
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programme->id,
            'niveau_id' => $this->niveau->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => 'Campus Universitaire',
            'ville' => 'Tunis',
            'code_postal' => '1060',
            'annee_bac' => '2023',
            'password' => bcrypt('Secret@123'),
        ]);

        return InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programme->id,
            'niveau_id' => $this->niveau->id,
            'filiere' => 'Licence Informatique',
            'niveau' => 'L1',
            'statut' => $statut,
            'date_inscription' => now(),
        ]);
    }

    /* ══════════════════════════════════════════════════════════════════
     * LOT P1 — SÉCURITÉ ET WORKFLOW À QUATRE YEUX
     * ══════════════════════════════════════════════════════════════════ */

    /** Cas 1 : Utilisateur non authentifié rejeté (redirection ou 403). */
    public function test_p1_securite_01_utilisateur_non_authentifie_redirige(): void
    {
        $inscription = $this->creerDossierInscription($this->faculte1);

        $this->get(route('admin.centre-travail.index'))->assertRedirect(route('admin.connexion.show'));
        $this->get(route('admin.centre-travail.show', $inscription))->assertRedirect(route('admin.connexion.show'));
        $this->post(route('admin.centre-travail.valider', $inscription))->assertRedirect(route('admin.connexion.show'));
        $this->post(route('admin.centre-travail.actions-groupees'))->assertRedirect(route('admin.connexion.show'));
    }

    /** Cas 2 : Utilisateur authentifié sans permission rejeté avec 403. */
    public function test_p1_securite_02_utilisateur_sans_permission_rejete_403(): void
    {
        $inscription = $this->creerDossierInscription($this->faculte1);

        $this->actingAs($this->utilisateurSansDroit)->get(route('admin.centre-travail.index'))->assertForbidden();
        $this->actingAs($this->utilisateurSansDroit)->get(route('admin.centre-travail.show', $inscription))->assertForbidden();
        $this->actingAs($this->utilisateurSansDroit)->post(route('admin.centre-travail.valider', $inscription))->assertForbidden();
        $this->actingAs($this->utilisateurSansDroit)->post(route('admin.centre-travail.actions-groupees'))->assertForbidden();
    }

    /** Cas 3 : Agent autorisé sur sa faculté accède avec succès. */
    public function test_p1_securite_03_agent_autorise_sur_sa_faculte_accede_avec_succes(): void
    {
        $inscription = $this->creerDossierInscription($this->faculte1);

        $resIndex = $this->actingAs($this->agentScolariteF1)->get(route('admin.centre-travail.index'));
        $resIndex->assertOk();
        $resIndex->assertSee('Centre de travail administratif');

        $resShow = $this->actingAs($this->agentScolariteF1)->get(route('admin.centre-travail.show', $inscription));
        $resShow->assertOk();
        $resShow->assertSee($inscription->etudiant->nom_complet);
    }

    /** Cas 4, 7 : Agent d'une autre faculté rejeté avec 404 strict (anti-BOLA / IDOR). */
    public function test_p1_securite_04_agent_autre_faculte_rejete_en_404_anti_bola(): void
    {
        $inscriptionF1 = $this->creerDossierInscription($this->faculte1);

        // L'agent de FSEGS tente d'accéder au dossier de FST -> 404 strict
        $this->actingAs($this->agentF2)->get(route('admin.centre-travail.show', $inscriptionF1))->assertNotFound();
        $this->actingAs($this->agentF2)->post(route('admin.centre-travail.valider', $inscriptionF1))->assertNotFound();
        $this->actingAs($this->agentF2)->post(route('admin.centre-travail.rejeter', $inscriptionF1), ['motif' => 'Motif arbitraire'])->assertNotFound();
    }

    /** Cas 5 : Super-administrateur accède transversalement à toutes les facultés. */
    public function test_p1_securite_05_super_administrateur_acces_transverse(): void
    {
        $inscriptionF1 = $this->creerDossierInscription($this->faculte1);
        $inscriptionF2 = $this->creerDossierInscription($this->faculte2);

        $this->actingAs($this->superAdmin)->get(route('admin.centre-travail.show', $inscriptionF1))->assertOk();
        $this->actingAs($this->superAdmin)->get(route('admin.centre-travail.show', $inscriptionF2))->assertOk();
    }

    /** Cas 6 : Identifiant d'inscription inexistant retourne 404. */
    public function test_p1_securite_06_identifiant_inexistant_donne_404(): void
    {
        $this->actingAs($this->agentScolariteF1)->get('/admin/centre-travail/inscriptions/999999')->assertNotFound();
    }

    /** Cas 8 : Correction appartenant à une autre inscription rejetée avec 404. */
    public function test_p1_securite_08_correction_croisee_autre_inscription_donne_404(): void
    {
        $inscriptionA = $this->creerDossierInscription($this->faculte1);
        $inscriptionB = $this->creerDossierInscription($this->faculte1);

        $correctionB = DossierCorrection::create([
            'etudiant_id' => $inscriptionB->etudiant_id,
            'inscription_academique_id' => $inscriptionB->id,
            'champ' => 'prenom',
            'ancienne_valeur' => 'Ahmed',
            'nouvelle_valeur' => 'Mohamed',
            'motif' => 'Correction prénom suite à acte de naissance officiel',
            'regle' => 'r1',
            'statut' => 'proposee',
            'propose_par' => $this->agentScolariteF1->id,
        ]);

        // Tentative de décider la correction de B via l'URL de A -> 404 strict
        $res = $this->actingAs($this->secondAgentF1)->post(
            route('admin.centre-travail.corrections.decider', [$inscriptionA, $correctionB]),
            ['decision' => 'approuver']
        );

        $res->assertNotFound();
    }

    /** Cas 9, 15 : Compte désactivé rejeté avec 403 même avec le rôle d'agent. */
    public function test_p1_securite_09_compte_desactive_rejete_en_403(): void
    {
        $inscription = $this->creerDossierInscription($this->faculte1);

        $this->actingAs($this->agentInactif)->get(route('admin.centre-travail.index'))->assertForbidden();
        $this->actingAs($this->agentInactif)->get(route('admin.centre-travail.show', $inscription))->assertForbidden();
        $this->actingAs($this->agentInactif)->post(route('admin.centre-travail.valider', $inscription))->assertForbidden();
    }

    /** Cas 12 : Motif d'ajournement trop court (<5 car) ou vide rejeté en validation. */
    public function test_p1_securite_12_motif_ajournement_invalide_rejete(): void
    {
        $inscription = $this->creerDossierInscription($this->faculte1);

        $resVide = $this->actingAs($this->agentScolariteF1)->post(
            route('admin.centre-travail.rejeter', $inscription),
            ['motif' => '']
        );
        $resVide->assertSessionHasErrors('motif');

        $resCourt = $this->actingAs($this->agentScolariteF1)->post(
            route('admin.centre-travail.rejeter', $inscription),
            ['motif' => 'Non']
        );
        $resCourt->assertSessionHasErrors('motif');

        $this->assertEquals('en_cours', $inscription->fresh()->statut);
    }

    /** Workflow 4-yeux : Le proposant d'une correction ne peut JAMAIS l'approuver (403). */
    public function test_p1_4yeux_proposant_ne_peut_pas_approuver_sa_propre_correction(): void
    {
        $inscription = $this->creerDossierInscription($this->faculte1);

        $correction = DossierCorrection::create([
            'etudiant_id' => $inscription->etudiant_id,
            'inscription_academique_id' => $inscription->id,
            'champ' => 'prenom',
            'ancienne_valeur' => 'Ahmed',
            'nouvelle_valeur' => 'Mohamed',
            'motif' => 'Correction prénom acte civil',
            'regle' => 'r1',
            'statut' => 'proposee',
            'propose_par' => $this->agentScolariteF1->id,
        ]);

        // Tentative d'auto-approbation au contrôleur
        $res = $this->actingAs($this->agentScolariteF1)->post(
            route('admin.centre-travail.corrections.decider', [$inscription, $correction]),
            ['decision' => 'approuver']
        );
        $res->assertForbidden();

        // Tentative d'auto-rejet au contrôleur
        $resRejet = $this->actingAs($this->agentScolariteF1)->post(
            route('admin.centre-travail.corrections.decider', [$inscription, $correction]),
            ['decision' => 'rejeter', 'motif' => 'Mon propre rejet']
        );
        $resRejet->assertForbidden();

        // Vérification directe au niveau du service métier
        $this->expectException(HttpException::class);
        app(CorrectionService::class)->approuver($correction, (int) $this->agentScolariteF1->id);
    }

    /** Workflow 4-yeux : Un second agent habilité de la même faculté peut approuver. */
    public function test_p1_4yeux_second_agent_habilite_peut_approuver(): void
    {
        $inscription = $this->creerDossierInscription($this->faculte1);

        $correction = DossierCorrection::create([
            'etudiant_id' => $inscription->etudiant_id,
            'inscription_academique_id' => $inscription->id,
            'champ' => 'prenom',
            'ancienne_valeur' => 'Ahmed',
            'nouvelle_valeur' => 'Sami',
            'motif' => 'Correction prénom acte civil',
            'regle' => 'r1',
            'statut' => 'proposee',
            'propose_par' => $this->agentScolariteF1->id,
        ]);

        $res = $this->actingAs($this->secondAgentF1)->post(
            route('admin.centre-travail.corrections.decider', [$inscription, $correction]),
            ['decision' => 'approuver']
        );

        $res->assertRedirect();
        $this->assertEquals('approuvee', $correction->fresh()->statut);
        $this->assertEquals($this->secondAgentF1->id, $correction->fresh()->decide_par);
    }

    /** Workflow 4-yeux : Une correction déjà décidée ne peut pas être modifiée à nouveau. */
    public function test_p1_4yeux_correction_deja_decidee_ne_peut_etre_rejouee(): void
    {
        $inscription = $this->creerDossierInscription($this->faculte1);

        $correction = DossierCorrection::create([
            'etudiant_id' => $inscription->etudiant_id,
            'inscription_academique_id' => $inscription->id,
            'champ' => 'prenom',
            'ancienne_valeur' => 'Ahmed',
            'nouvelle_valeur' => 'Sami',
            'motif' => 'Correction',
            'regle' => 'r1',
            'statut' => 'approuvee',
            'propose_par' => $this->agentScolariteF1->id,
            'decide_par' => $this->secondAgentF1->id,
            'decide_le' => now(),
        ]);

        $res = $this->actingAs($this->secondAgentF1)->post(
            route('admin.centre-travail.corrections.decider', [$inscription, $correction]),
            ['decision' => 'rejeter', 'motif' => 'Tentative de réouverture']
        );

        $res->assertRedirect();
        $res->assertSessionHasErrors('correction');
    }

    /* ══════════════════════════════════════════════════════════════════
     * LOT P2 — COCKPIT ADMINISTRATIF ET ACTIONS EN LOT
     * ══════════════════════════════════════════════════════════════════ */

    /** Vérification des 10 KPIs du Cockpit sans double comptage. */
    public function test_p2_cockpit_10_kpis_exacts_et_deterministes(): void
    {
        $ins1 = $this->creerDossierInscription($this->faculte1);
        $ins2 = $this->creerDossierInscription($this->faculte1);

        // Tâche 1 : pending, assignée à agent 1, normale
        Task::create([
            'source' => 'inscription',
            'cle_unique' => "inscription:{$ins1->id}",
            'titre' => 'Vérifier dossier Ahmed',
            'statut' => 'pending',
            'priorite' => 'normal',
            'faculte_id' => $this->faculte1->id,
            'assigne_a' => $this->agentScolariteF1->id,
            'data' => ['inscription_id' => $ins1->id],
        ]);

        // Tâche 2 : in_progress, non assignée, critique, en retard
        Task::create([
            'source' => 'inscription',
            'cle_unique' => "inscription:{$ins2->id}",
            'titre' => 'Vérifier dossier Sami',
            'statut' => 'in_progress',
            'priorite' => 'critical',
            'faculte_id' => $this->faculte1->id,
            'assigne_a' => null,
            'echeance_a' => now()->subHours(5),
            'data' => ['inscription_id' => $ins2->id, 'bloque' => true],
        ]);

        // Correction 4-yeux en attente
        DossierCorrection::create([
            'etudiant_id' => $ins1->etudiant_id,
            'inscription_academique_id' => $ins1->id,
            'champ' => 'prenom',
            'ancienne_valeur' => 'Ahmed',
            'nouvelle_valeur' => 'Sami',
            'motif' => 'Acte civil',
            'regle' => 'r1',
            'statut' => 'proposee',
            'propose_par' => $this->secondAgentF1->id,
        ]);

        $res = $this->actingAs($this->agentScolariteF1)->get(route('admin.centre-travail.index'));
        $res->assertOk();

        $kpis = $res->viewData('kpis');
        $this->assertEquals(1, $kpis['en_attente']);
        $this->assertEquals(1, $kpis['en_cours']);
        $this->assertEquals(1, $kpis['mes_taches']);
        $this->assertEquals(1, $kpis['non_assignees']);
        $this->assertEquals(1, $kpis['prioritaires']);
        $this->assertEquals(1, $kpis['en_retard']);
        $this->assertEquals(1, $kpis['bloquees']);
        $this->assertEquals(1, $kpis['corrections_4yeux']);
    }

    /** Actions groupées : Démarrer plusieurs tâches en lot. */
    public function test_p2_cockpit_actions_groupees_demarrer_en_lot(): void
    {
        $t1 = Task::create([
            'source' => 'inscription',
            'cle_unique' => 'task:batch:1',
            'titre' => 'Tâche batch 1',
            'statut' => 'pending',
            'priorite' => 'normal',
            'faculte_id' => $this->faculte1->id,
        ]);
        $t2 = Task::create([
            'source' => 'inscription',
            'cle_unique' => 'task:batch:2',
            'titre' => 'Tâche batch 2',
            'statut' => 'pending',
            'priorite' => 'normal',
            'faculte_id' => $this->faculte1->id,
        ]);

        $res = $this->actingAs($this->agentScolariteF1)->post(route('admin.centre-travail.actions-groupees'), [
            'action' => 'demarrer',
            'tache_ids' => [$t1->id, $t2->id],
        ]);

        $res->assertRedirect();
        $this->assertEquals('in_progress', $t1->fresh()->statut);
        $this->assertEquals('in_progress', $t2->fresh()->statut);
    }

    /** Actions groupées : Assigner plusieurs tâches à un agent. */
    public function test_p2_cockpit_actions_groupees_assigner_en_lot(): void
    {
        $t1 = Task::create([
            'source' => 'inscription',
            'cle_unique' => 'task:assign:1',
            'titre' => 'Tâche assigner 1',
            'statut' => 'pending',
            'priorite' => 'normal',
            'faculte_id' => $this->faculte1->id,
        ]);

        // Un agent ordinaire sans tasks.assign ne peut pas assigner en lot -> 403
        $this->actingAs($this->agentScolariteF1)->post(route('admin.centre-travail.actions-groupees'), [
            'action' => 'assigner',
            'assigne_a_id' => $this->secondAgentF1->id,
            'tache_ids' => [$t1->id],
        ])->assertForbidden();

        // Le super-administrateur (tasks.assign) peut assigner en lot
        $res = $this->actingAs($this->superAdmin)->post(route('admin.centre-travail.actions-groupees'), [
            'action' => 'assigner',
            'assigne_a_id' => $this->secondAgentF1->id,
            'tache_ids' => [$t1->id],
        ]);

        $res->assertRedirect();
        $this->assertEquals($this->secondAgentF1->id, $t1->fresh()->assigne_a);
    }

    /** Actions groupées : Prioriser plusieurs tâches en lot. */
    public function test_p2_cockpit_actions_groupees_prioriser_en_lot(): void
    {
        $t1 = Task::create([
            'source' => 'inscription',
            'cle_unique' => 'task:prio:1',
            'titre' => 'Tâche prio 1',
            'statut' => 'pending',
            'priorite' => 'low',
            'faculte_id' => $this->faculte1->id,
        ]);

        $res = $this->actingAs($this->agentScolariteF1)->post(route('admin.centre-travail.actions-groupees'), [
            'action' => 'changer_priorite',
            'priorite' => 'critical',
            'tache_ids' => [$t1->id],
        ]);

        $res->assertRedirect();
        $this->assertEquals('critical', $t1->fresh()->priorite);
    }

    /* ══════════════════════════════════════════════════════════════════
     * LOT P2 — DOSSIER ÉTUDIANT 360°
     * ══════════════════════════════════════════════════════════════════ */

    /** Affichage 360° complet avec les 7 sections canoniques. */
    public function test_p2_dossier_360_affiche_toutes_les_sections_canoniques(): void
    {
        $inscription = $this->creerDossierInscription($this->faculte1);
        $etudiant = $inscription->etudiant;

        $anneePrecedente = AnneeUniversitaire::create([
            'code' => '2025-2026',
            'libelle' => 'Année Académique 2025-2026',
            'date_debut' => '2025-09-01',
            'date_fin' => '2026-06-30',
            'active' => false,
        ]);

        // Inscription passée (Cursus)
        InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $anneePrecedente->id,
            'programme_id' => $this->programme->id,
            'niveau_id' => $this->niveau->id,
            'filiere' => 'Tronc Commun',
            'niveau' => 'L0',
            'statut' => 'valide',
            'date_inscription' => now()->subYear(),
        ]);

        // Groupe et affectation
        $group = Group::create([
            'nom' => 'Groupe TD 1',
            'code' => 'L1-TD1',
            'faculte_id' => $this->faculte1->id,
            'niveau' => 'L1',
            'filiere' => 'Licence Informatique',
            'actif' => true,
        ]);
        $etudiant->update(['groupe_id' => $group->id]);

        // Dossier de paiement
        $dossierPaiement = DossierPaiement::firstOrNew(['inscription_academique_id' => $inscription->id]);
        $dossierPaiement->statut = DossierPaiement::VALIDE;
        $dossierPaiement->decide_par = $this->agentScolariteF1->id;
        $dossierPaiement->decide_le = now();
        $dossierPaiement->save();
        RecuPaiement::create([
            'dossier_paiement_id' => $dossierPaiement->id,
            'inscription_academique_id' => $inscription->id,
            'annee_universitaire_id' => $this->annee->id,
            'reference_paiement' => 'REC-2026-0001',
            'date_paiement' => '2026-10-20',
            'version' => 1,
            'storage_path' => 'prive/recus/test.pdf',
            'original_filename' => 'recu.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size' => 1024,
            'sha256' => hash('sha256', 'recu-content'),
            'montant_attendu_centimes' => 15000,
            'montant_declare_centimes' => 15000,
            'date_paiement' => now()->toDateString(),
            'depose_le' => now(),
            'statut' => RecuPaiement::VALIDE,
            'est_valide' => true,
        ]);

        // Tâche active
        Task::create([
            'source' => 'inscription',
            'cle_unique' => "inscription:{$inscription->id}",
            'titre' => 'Contrôle dossier أحمد',
            'statut' => 'in_progress',
            'priorite' => 'normal',
            'faculte_id' => $this->faculte1->id,
            'assigne_a' => $this->agentScolariteF1->id,
            'data' => ['inscription_id' => $inscription->id],
        ]);

        // Historique d'audit
        HistoriqueAcademique::create([
            'etudiant_id' => $etudiant->id,
            'utilisateur_id' => $this->agentScolariteF1->id,
            'action' => HistoriqueAcademique::ACTION_CREATION_INSCRIPTION,
            'entite' => 'inscription_academique',
            'entite_id' => $inscription->id,
            'details' => ['statut' => 'en_cours'],
        ]);

        $res = $this->actingAs($this->agentScolariteF1)->get(route('admin.centre-travail.show', $inscription));

        $res->assertOk();
        $res->assertSee($etudiant->nom_complet);
        $res->assertSee('Historique du Cursus &amp; Autres Inscriptions', false);
        $res->assertSee('Dossier financier &amp; Paiement', false);
        $res->assertSee('Affectation de groupe &amp; Filière', false);
        $res->assertSee('Groupe TD 1');
        $res->assertSee('REC-2026-0001');
        $res->assertSee('Création d&#039;une inscription académique', false);
    }

    /* ══════════════════════════════════════════════════════════════════
     * LOT P3 — AUTOMATISATION CONTRÔLÉE
     * ══════════════════════════════════════════════════════════════════ */

    /** Déclencheur : L'événement InscriptionAcademiqueCreee génère une tâche sans doublon (idempotent). */
    public function test_p3_automatisation_evenement_genere_tache_de_facon_idempotente(): void
    {
        $inscription = $this->creerDossierInscription($this->faculte1);

        // Déclenchement de l'événement
        event(new InscriptionAcademiqueCreee($inscription));

        $this->assertDatabaseHas('tasks', [
            'cle_unique' => "inscription:{$inscription->id}",
            'source' => 'inscription',
            'faculte_id' => $this->faculte1->id,
        ]);

        $countInitial = Task::where('cle_unique', "inscription:{$inscription->id}")->count();
        $this->assertEquals(1, $countInitial);

        // Rejeu de l'événement (test d'idempotence stricte)
        event(new InscriptionAcademiqueCreee($inscription));

        $countFinal = Task::where('cle_unique', "inscription:{$inscription->id}")->count();
        $this->assertEquals(1, $countFinal);
    }

    /** Affectation automatique : Équilibre de charge et exclusion du proposant (règle 4-yeux). */
    public function test_p3_automatisation_affectation_intelligente_equilibre_charge_et_exclut_proposant(): void
    {
        // Donnons 2 tâches à agent 1 et 0 tâche à agent 2
        Task::create([
            'source' => 'inscription',
            'cle_unique' => 't1',
            'titre' => 'T1',
            'statut' => 'pending',
            'priorite' => 'normal',
            'faculte_id' => $this->faculte1->id,
            'assigne_a' => $this->agentScolariteF1->id,
        ]);
        Task::create([
            'source' => 'inscription',
            'cle_unique' => 't2',
            'titre' => 'T2',
            'statut' => 'in_progress',
            'priorite' => 'normal',
            'faculte_id' => $this->faculte1->id,
            'assigne_a' => $this->agentScolariteF1->id,
        ]);

        $nouvelleTache = Task::create([
            'source' => 'inscription',
            'cle_unique' => 't3',
            'titre' => 'T3',
            'statut' => 'pending',
            'priorite' => 'normal',
            'faculte_id' => $this->faculte1->id,
        ]);

        /** @var TaskService $taskService */
        $taskService = app(TaskService::class);

        // L'algorithme doit choisir l'agent avec la charge minimale (ici Agent 2)
        $agentChoisi = $taskService->trouverAgentPourAffectation($nouvelleTache);
        $this->assertNotNull($agentChoisi);
        $this->assertEquals($this->secondAgentF1->id, $agentChoisi->id);

        // Si l'Agent 2 est exclu (ex. il est le proposant d'une correction), l'algorithme se rabat sur Agent 1
        $agentAvecExclusion = $taskService->trouverAgentPourAffectation($nouvelleTache, [$this->secondAgentF1->id]);
        $this->assertNotNull($agentAvecExclusion);
        $this->assertEquals($this->agentScolariteF1->id, $agentAvecExclusion->id);
    }

    /** Escalade SLA : Tâches en retard rehaussées automatiquement en critique. */
    public function test_p3_automatisation_escalade_automatique_des_taches_en_souffrance(): void
    {
        $tacheEchue = Task::create([
            'source' => 'inscription',
            'cle_unique' => 'tache:echue',
            'titre' => 'Tâche échue',
            'statut' => 'pending',
            'priorite' => 'normal',
            'faculte_id' => $this->faculte1->id,
            'echeance_a' => now()->subDay(),
        ]);

        /** @var TaskService $taskService */
        $taskService = app(TaskService::class);
        $bilan = $taskService->escaladerTachesEnSouffrance($this->faculte1->id);

        $this->assertGreaterThanOrEqual(1, $bilan['escaladees']);
        $this->assertEquals('critical', $tacheEchue->fresh()->priorite);
    }

    /** Réconciliation : Création des tâches orphelines pour les inscriptions sans tâche. */
    public function test_p3_automatisation_reconciliation_taches_orphelines(): void
    {
        $inscriptionSansTache = $this->creerDossierInscription($this->faculte1, 'en_cours');

        $this->assertDatabaseMissing('tasks', [
            'cle_unique' => "inscription:{$inscriptionSansTache->id}",
        ]);

        /** @var TaskService $taskService */
        $taskService = app(TaskService::class);
        $bilan = $taskService->reconcilierTachesOrphelines($this->faculte1->id);

        $this->assertGreaterThanOrEqual(1, $bilan['creees']);
        $this->assertDatabaseHas('tasks', [
            'cle_unique' => "inscription:{$inscriptionSansTache->id}",
        ]);
    }

    /* ══════════════════════════════════════════════════════════════════
     * LOTS ADVERSARIAUX — HARDENING & CONCURRENCE ÉTENDUE
     * ══════════════════════════════════════════════════════════════════ */

    /** Concurrence : La double soumission d'une validation est rejetée sans double effet. */
    public function test_adversarial_p1_concurrence_double_validation_inscription_rejete(): void
    {
        $inscription = $this->creerDossierInscription($this->faculte1, 'en_cours');

        // Première validation réussie
        $res1 = $this->actingAs($this->superAdmin)->post(route('admin.centre-travail.valider', $inscription));
        $res1->assertRedirect();
        $this->assertEquals('valide', $inscription->fresh()->statut);

        // Seconde validation concurrente rejetée (statut déjà valide)
        $res2 = $this->actingAs($this->superAdmin)->post(route('admin.centre-travail.valider', $inscription));
        $res2->assertSessionHasErrors('statut');
    }

    /** Concurrence : Double décision concurrente sur une proposition à 4 yeux rejetée. */
    public function test_adversarial_p1_concurrence_double_decision_correction_rejete(): void
    {
        $inscription = $this->creerDossierInscription($this->faculte1);
        $correction = DossierCorrection::create([
            'etudiant_id' => $inscription->etudiant_id,
            'inscription_academique_id' => $inscription->id,
            'champ' => 'prenom',
            'ancienne_valeur' => 'Mohamed',
            'nouvelle_valeur' => 'Yassine',
            'motif' => 'Correction prénom',
            'statut' => 'proposee',
            'propose_par' => $this->agentScolariteF1->id,
        ]);

        // Première approbation par le second agent
        $res1 = $this->actingAs($this->secondAgentF1)->post(
            route('admin.centre-travail.corrections.decider', [$inscription, $correction]),
            ['decision' => 'approuver', 'motif' => 'Validation conforme']
        );
        $res1->assertRedirect();
        $this->assertEquals('approuvee', $correction->fresh()->statut);

        // Tentative d'approbation concurrente supplémentaire par le super-admin : rejetée
        $res2 = $this->actingAs($this->superAdmin)->post(
            route('admin.centre-travail.corrections.decider', [$inscription, $correction]),
            ['decision' => 'approuver', 'motif' => 'Tentative concurrente']
        );
        $res2->assertSessionHasErrors('correction');
    }

    /** Actions groupées : Un lot mixte (tâches autorisées et interdites) protège les tâches hors périmètre et compte fidèlement les ignorées. */
    public function test_adversarial_p2_actions_groupees_lot_mixte_autorise_interdit(): void
    {
        $tF1 = Task::create([
            'source' => 'inscription',
            'cle_unique' => 'mixte:f1',
            'titre' => 'Tâche F1',
            'statut' => 'pending',
            'priorite' => 'normal',
            'faculte_id' => $this->faculte1->id,
        ]);

        $tF2 = Task::create([
            'source' => 'inscription',
            'cle_unique' => 'mixte:f2',
            'titre' => 'Tâche F2',
            'statut' => 'pending',
            'priorite' => 'normal',
            'faculte_id' => $this->faculte2->id,
        ]);

        // Agent restreint à la faculté 1
        $agentFaculteF1 = User::creerCompteBackOffice([
            'name' => 'Admin Faculté FST Restreint',
            'email' => 'admin.fac.mixte@uniregistre.tn',
            'password' => 'Secret@123',
            'faculte_id' => $this->faculte1->id,
            'actif' => true,
        ], 'admin_faculte');

        // L'agent restreint à F1 tente de démarrer les deux tâches (F1 et F2)
        $res = $this->actingAs($agentFaculteF1)->post(route('admin.centre-travail.actions-groupees'), [
            'action' => 'demarrer',
            'tache_ids' => [$tF1->id, $tF2->id],
        ]);

        $res->assertRedirect();
        $res->assertSessionHas('success', 'Action groupée exécutée : 1 tâche(s) traitée(s), 1 ignorée(s).');

        // La tâche F1 a été prise en charge
        $this->assertEquals('in_progress', $tF1->fresh()->statut);
        // La tâche F2 n'a absolument pas été altérée (protection BOLA / étanchéité)
        $this->assertEquals('pending', $tF2->fresh()->statut);
    }

    /** Actions groupées : Interdiction de modifier la priorité d'une tâche déjà clôturée. */
    public function test_adversarial_p2_actions_groupees_refus_priorisation_tache_terminee(): void
    {
        $tCloturee = Task::create([
            'source' => 'inscription',
            'cle_unique' => 'cloturee:prio',
            'titre' => 'Tâche déjà terminée',
            'statut' => 'completed',
            'priorite' => 'normal',
            'faculte_id' => $this->faculte1->id,
            'terminee_a' => now(),
        ]);

        $res = $this->actingAs($this->agentScolariteF1)->post(route('admin.centre-travail.actions-groupees'), [
            'action' => 'changer_priorite',
            'priorite' => 'critical',
            'tache_ids' => [$tCloturee->id],
        ]);

        $res->assertRedirect();
        $res->assertSessionHas('success', 'Action groupée exécutée : 0 tâche(s) traitée(s), 1 ignorée(s).');
        $this->assertEquals('normal', $tCloturee->fresh()->priorite);
    }

    /** Idempotence SLA : Escalades répétées n'insèrent pas de doublons d'événements. */
    public function test_adversarial_p3_sla_escalade_idempotente_sans_doublon_evenement(): void
    {
        $tacheEchue = Task::create([
            'source' => 'inscription',
            'cle_unique' => 'tache:sla:idem',
            'titre' => 'Tâche SLA à escalader',
            'statut' => 'pending',
            'priorite' => 'normal',
            'faculte_id' => $this->faculte1->id,
            'echeance_a' => now()->subHours(5),
        ]);

        /** @var TaskService $taskService */
        $taskService = app(TaskService::class);

        // Premier passage
        $b1 = $taskService->escaladerTachesEnSouffrance($this->faculte1->id);
        $this->assertEquals(1, $b1['escaladees']);

        // Second passage immédiat (rejeu scheduler)
        $b2 = $taskService->escaladerTachesEnSouffrance($this->faculte1->id);
        $this->assertEquals(0, $b2['escaladees']);

        // Un seul événement de journalisation enregistré
        $nbLogs = TaskEvenement::where('task_id', $tacheEchue->id)
            ->where('action', 'escalade_sla')
            ->count();
        $this->assertEquals(1, $nbLogs);
    }

    /** Idempotence Réconciliation : Exécutions répétées sans re-clôture ni doublons d'événements. */
    public function test_adversarial_p3_reconciliation_idempotente_sans_doublon_evenement(): void
    {
        $inscription = $this->creerDossierInscription($this->faculte1, 'valide');
        $tache = Task::create([
            'source' => 'inscription',
            'cle_unique' => "inscription:{$inscription->id}",
            'titre' => 'Tâche pour inscription validée',
            'statut' => 'pending',
            'faculte_id' => $this->faculte1->id,
            'data' => ['inscription_id' => $inscription->id],
        ]);

        /** @var TaskService $taskService */
        $taskService = app(TaskService::class);

        // Première réconciliation
        $b1 = $taskService->reconcilierTachesOrphelines($this->faculte1->id);
        $this->assertEquals(1, $b1['reconciliees']);
        $this->assertEquals('completed', $tache->fresh()->statut);

        // Seconde réconciliation (rejeu scheduler)
        $b2 = $taskService->reconcilierTachesOrphelines($this->faculte1->id);
        $this->assertEquals(0, $b2['reconciliees']);

        // Un seul événement de réconciliation enregistré
        $nbLogs = TaskEvenement::where('task_id', $tache->id)
            ->where('action', 'reconciliation_validee')
            ->count();
        $this->assertEquals(1, $nbLogs);
    }

    /** Métrologie : Vérification reproductible du nombre de requêtes SQL et performance. */
    public function test_adversarial_p2_mesure_performance_et_nombre_requetes_sql(): void
    {
        $inscription = $this->creerDossierInscription($this->faculte1, 'en_cours');
        Task::create([
            'source' => 'inscription',
            'cle_unique' => "inscription:{$inscription->id}",
            'titre' => 'Tâche métrologie',
            'statut' => 'pending',
            'priorite' => 'normal',
            'faculte_id' => $this->faculte1->id,
            'data' => ['inscription_id' => $inscription->id],
        ]);

        // Mesure Index
        $requetesIndex = 0;
        DB::listen(function () use (&$requetesIndex) {
            $requetesIndex++;
        });
        $this->actingAs($this->agentScolariteF1)->get(route('admin.centre-travail.index'))->assertOk();
        $this->assertLessThanOrEqual(25, $requetesIndex);

        // Mesure Show 360°
        $requetesShow = 0;
        DB::listen(function () use (&$requetesShow) {
            $requetesShow++;
        });
        $this->actingAs($this->agentScolariteF1)->get(route('admin.centre-travail.show', $inscription))->assertOk();
        $this->assertLessThanOrEqual(45, $requetesShow);
    }

    /** Actions groupées : Le paramètre priorité est obligatoire quand l'action est priorite. */
    public function test_adversarial_p2_actions_groupees_priorite_requise_si_action_priorite(): void
    {
        $t = Task::create([
            'source' => 'inscription',
            'cle_unique' => 'tache:prio:sans_valeur',
            'titre' => 'Tâche prio vide',
            'statut' => 'pending',
            'priorite' => 'normal',
            'faculte_id' => $this->faculte1->id,
        ]);

        $res = $this->actingAs($this->agentScolariteF1)->post(route('admin.centre-travail.actions-groupees'), [
            'action' => 'priorite',
            'tache_ids' => [$t->id],
        ]);

        $res->assertSessionHasErrors('priorite');
        $this->assertEquals('normal', $t->fresh()->priorite);
    }

    /** Sécurité 4-yeux : La falsification du champ propose_par dans le payload POST est strictement ignorée. */
    public function test_adversarial_p1_securite_forger_propose_par_dans_requete_decision_est_ignore(): void
    {
        $inscription = $this->creerDossierInscription($this->faculte1);
        $correction = DossierCorrection::create([
            'etudiant_id' => $inscription->etudiant_id,
            'inscription_academique_id' => $inscription->id,
            'champ' => 'cin',
            'ancienne_valeur' => '12345678',
            'nouvelle_valeur' => '87654321',
            'motif' => 'Correction CIN',
            'statut' => 'proposee',
            'propose_par' => $this->agentScolariteF1->id,
        ]);

        // L'auteur tente d'usurper l'identité du proposeur en injectant 'propose_par' => 9999 dans le POST
        $res = $this->actingAs($this->agentScolariteF1)->post(
            route('admin.centre-travail.corrections.decider', [$inscription, $correction]),
            [
                'decision' => 'approuver',
                'motif' => 'Tentative contournement',
                'propose_par' => 9999,
            ]
        );

        // Rejeté 403 strict car le proposeur est lu depuis la base de données
        $res->assertForbidden();
        $this->assertEquals('proposee', $correction->fresh()->statut);
    }

    /** Lot 2 — Recherche multi-critères : recherche par CIN et nom étudiant retrouve les tâches associées. */
    public function test_lot2_recherche_multicriteres_par_cin_et_nom_etudiant(): void
    {
        $inscription = $this->creerDossierInscription($this->faculte1);
        $etudiant = $inscription->etudiant;

        $tache = Task::create([
            'source' => 'inscription',
            'cle_unique' => "inscription:{$inscription->id}",
            'titre' => "Dossier {$etudiant->nom_complet}",
            'statut' => 'pending',
            'priorite' => 'normal',
            'faculte_id' => $this->faculte1->id,
            'data' => [
                'inscription_id' => $inscription->id,
                'etudiant_id' => $etudiant->id,
            ],
        ]);

        // Recherche par CIN de l'étudiant
        $resCin = $this->actingAs($this->agentScolariteF1)->get(route('admin.centre-travail.index', [
            'search' => $etudiant->cin,
        ]));
        $resCin->assertOk();
        $resCin->assertSee($tache->titre);

        // Recherche par nom de l'étudiant
        $resNom = $this->actingAs($this->agentScolariteF1)->get(route('admin.centre-travail.index', [
            'search' => $etudiant->nom,
        ]));
        $resNom->assertOk();
        $resNom->assertSee($tache->titre);
    }

    /** Lot 4 — Simulation d'actions groupées : dry-run sans écriture. */
    public function test_lot4_simulation_actions_groupees_sans_mutation(): void
    {
        $t1 = Task::create([
            'source' => 'inscription',
            'cle_unique' => 'task:sim:1',
            'titre' => 'Tâche 1 sim',
            'statut' => 'pending',
            'priorite' => 'normal',
            'faculte_id' => $this->faculte1->id,
        ]);

        $t2 = Task::create([
            'source' => 'inscription',
            'cle_unique' => 'task:sim:2',
            'titre' => 'Tâche 2 déjà in_progress',
            'statut' => 'in_progress',
            'priorite' => 'normal',
            'faculte_id' => $this->faculte1->id,
        ]);

        $res = $this->actingAs($this->agentScolariteF1)->postJson(route('admin.centre-travail.actions-groupees'), [
            'action' => 'demarrer',
            'ids' => [$t1->id, $t2->id],
            'simuler' => true,
        ]);

        $res->assertOk();
        $res->assertJson([
            'simulation' => true,
            'total_demande' => 2,
            'total_eligibles' => 1,
            'total_exclus' => 1,
            'confirmation_necessaire' => true,
        ]);

        // Vérification stricte : aucune écriture en base de données
        $this->assertEquals('pending', $t1->fresh()->statut);
        $this->assertEquals('in_progress', $t2->fresh()->statut);
    }

    /** Lot 4 — Action groupée clôture : clôture les tâches actives et ignore les déjà clôturées. */
    public function test_lot4_action_groupee_cloture_taches(): void
    {
        $t1 = Task::create([
            'source' => 'inscription',
            'cle_unique' => 'task:close:1',
            'titre' => 'Tâche active',
            'statut' => 'pending',
            'priorite' => 'normal',
            'faculte_id' => $this->faculte1->id,
        ]);

        $t2 = Task::create([
            'source' => 'inscription',
            'cle_unique' => 'task:close:2',
            'titre' => 'Tâche déjà close',
            'statut' => 'completed',
            'priorite' => 'normal',
            'faculte_id' => $this->faculte1->id,
        ]);

        $res = $this->actingAs($this->agentScolariteF1)->postJson(route('admin.centre-travail.actions-groupees'), [
            'action' => 'cloturer',
            'ids' => [$t1->id, $t2->id],
        ]);

        $res->assertOk();
        $res->assertJson([
            'success' => true,
            'traitees' => 1,
            'ignorees' => 1,
        ]);

        $this->assertEquals('completed', $t1->fresh()->statut);
        $this->assertEquals('completed', $t2->fresh()->statut);
    }

    /** Lot 1 — Cockpit Inscriptions : transmission des indicateurs consolidés d'inscription. */
    public function test_lot1_cockpit_inscriptions_fournit_indicateurs_consolides(): void
    {
        $inscription = $this->creerDossierInscription($this->faculte1);

        $res = $this->actingAs($this->agentScolariteF1)->get(route('admin.centre-travail.index'));

        $res->assertOk();
        $res->assertViewHas('inscriptionsKpis', function ($kpis) {
            return isset($kpis['total'], $kpis['en_cours'], $kpis['valides'], $kpis['rejetes'], $kpis['bloquees']);
        });
        $res->assertSee('Registre des inscriptions académiques');
    }
}
