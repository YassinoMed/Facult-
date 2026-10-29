<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\DossierCorrection;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\HistoriqueAcademique;
use App\Models\InscriptionAcademique;
use App\Models\RegistrationPeriod;
use App\Models\User;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Suite de tests du Sous-lot C : Contrôle des inscriptions et explicabilité décisionnelle.
 *
 * Exigences couvertes :
 * 1. Accès autorisé au détail de l'inscription.
 * 2. Refus pour utilisateur non authentifié (302).
 * 3. Refus pour utilisateur/rôle sans permission (403).
 * 4. Isolation facultaire étanche (404 anti-BOLA/IDOR).
 * 5. Affichage des verdicts canoniques (ELIGIBLE, AVERTISSEMENT, BLOCAGE).
 * 6. Présentation structurée des motifs de blocage et avertissements.
 * 7. Matrice des pièces requises, manquantes ou rejetées.
 * 8. Propositions de correction 4-eyes en attente du second agent.
 * 9. Immutabilité absolue : aucune mutation de l'inscription, tâche ou correction.
 * 10. Absence totale d'envoi de courriel ou de transition métier.
 * 11. Absence de fuite de données personnelles sensibles (mots de passe, tokens).
 * 12. Non-régression et affichage de la tâche et de l'historique d'audit.
 */
class CentreTravailDetailTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte1;

    private Faculte $faculte2;

    private AnneeUniversitaire $annee;

    private Programme $programme;

    private Niveau $niveau;

    private User $agentScolariteF1;

    private User $autreAgent;

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
            'name' => 'Agent Scolarité FST',
            'email' => 'agent.fst@fst.test',
            'password' => 'Secret@123',
            'faculte_id' => $this->faculte1->id,
        ], 'agent_scolarite');

        $this->autreAgent = User::creerCompteBackOffice([
            'name' => 'Autre Agent FST',
            'email' => 'autre.agent@fst.test',
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
            'filiere' => 'Informatique',
            'statut' => 'en_cours',
            'type' => 'premiere',
        ]);
    }

    /** 1. Accès autorisé au détail de l'inscription pour un agent de scolarité. */
    public function test_acces_autorise_au_detail_inscription(): void
    {
        $etudiant = $this->creerEtudiant('08123456', $this->faculte1);
        $inscription = $this->creerInscription($etudiant);

        $reponse = $this->actingAs($this->agentScolariteF1)->get(route('admin.centre-travail.show', $inscription));

        $reponse->assertOk();
        $reponse->assertViewIs('admin.centre-travail.show');
        $reponse->assertSee('Contrôle inscription — Mohamed Ben Salah');
        $reponse->assertSee('08123456');
        $reponse->assertSee('Photographie décisionnelle temps réel');
    }

    /** 2. Refus pour utilisateur non authentifié (redirection 302). */
    public function test_refus_acces_detail_non_authentifie(): void
    {
        $etudiant = $this->creerEtudiant('08123457', $this->faculte1);
        $inscription = $this->creerInscription($etudiant);

        $reponse = $this->get(route('admin.centre-travail.show', $inscription));

        $reponse->assertRedirect(route('admin.connexion.show'));
    }

    /** 3. Refus pour rôle sans permission (403 Forbidden). */
    public function test_refus_acces_detail_role_sans_permission(): void
    {
        $etudiant = $this->creerEtudiant('08123458', $this->faculte1);
        $inscription = $this->creerInscription($etudiant);

        $userSansDroit = User::create([
            'name' => 'Utilisateur Sans Droit',
            'email' => 'sansdroit@test.tn',
            'password' => bcrypt('Secret@123'),
            'role' => 'aucun_role',
            'actif' => true,
        ]);

        $reponse = $this->actingAs($userSansDroit)->get(route('admin.centre-travail.show', $inscription));

        $reponse->assertForbidden();
    }

    /** 4. Isolation facultaire étanche (anti-BOLA / IDOR : 404 Not Found si hors périmètre). */
    public function test_isolation_facultaire_detail_anti_bola(): void
    {
        // Inscription rattachée à la Faculté 1
        $etudiantF1 = $this->creerEtudiant('08123459', $this->faculte1);
        $inscriptionF1 = $this->creerInscription($etudiantF1);

        // L'administrateur de la Faculté 2 tente d'accéder au dossier de la Faculté 1
        $reponse = $this->actingAs($this->adminFaculte2)->get(route('admin.centre-travail.show', $inscriptionF1));

        $reponse->assertNotFound();
    }

    /** 5. Affichage des verdicts canoniques de l'évaluation d'éligibilité. */
    public function test_affichage_verdicts_canoniques(): void
    {
        $etudiant = $this->creerEtudiant('08123460', $this->faculte1);
        $inscription = $this->creerInscription($etudiant);

        // Période d'inscription ouverte
        RegistrationPeriod::create([
            'annee_universitaire_id' => $this->annee->id,
            'faculte_id' => $this->faculte1->id,
            'statut' => 'active',
            'type_periode' => 'normale',
            'opened_at' => now()->subDays(5),
            'closed_at' => now()->addDays(20),
        ]);

        $reponse = $this->actingAs($this->agentScolariteF1)->get(route('admin.centre-travail.show', $inscription));

        $reponse->assertOk();
        $reponse->assertViewHas('evaluation', function (array $eval) {
            $this->assertContains($eval['verdict'], ['ELIGIBLE', 'AVERTISSEMENT', 'BLOCAGE']);
            $this->assertArrayHasKey('controles', $eval);
            $this->assertArrayHasKey('blocages', $eval);
            $this->assertArrayHasKey('avertissements', $eval);
            $this->assertArrayHasKey('documents', $eval);

            return true;
        });

        // Le mot-clé Verdict doit être affiché dans le HTML
        $reponse->assertSee('Verdict :');
    }

    /** 6. Présentation structurée des motifs de blocage et avertissements. */
    public function test_presentation_motifs_blocage_et_avertissements(): void
    {
        $etudiant = $this->creerEtudiant('08123461', $this->faculte1);
        $inscription = $this->creerInscription($etudiant);

        $reponse = $this->actingAs($this->agentScolariteF1)->get(route('admin.centre-travail.show', $inscription));

        $reponse->assertOk();
        $reponse->assertSee('Anomalies bloquantes');
        $reponse->assertSee('Avertissements &amp; Vigilance', false);
        $reponse->assertSee('Diagnostic des 4 piliers de contrôle');
    }

    /** 7. Présentation de la matrice des pièces requises. */
    public function test_presentation_matrice_pieces_requises(): void
    {
        $etudiant = $this->creerEtudiant('08123462', $this->faculte1);
        $inscription = $this->creerInscription($etudiant);

        $reponse = $this->actingAs($this->agentScolariteF1)->get(route('admin.centre-travail.show', $inscription));

        $reponse->assertOk();
        $reponse->assertSee('Matrice des pièces justificatives obligatoires');
        $reponse->assertSee('État GED actuel');
    }

    /** 8. Propositions de correction 4-eyes en attente du second agent. */
    public function test_affichage_propositions_correction_quatre_yeux(): void
    {
        $etudiant = $this->creerEtudiant('08123463', $this->faculte1);
        $inscription = $this->creerInscription($etudiant);

        // Correction proposée par un autre agent
        DossierCorrection::create([
            'etudiant_id' => $etudiant->id,
            'inscription_academique_id' => $inscription->id,
            'champ' => 'nom',
            'ancienne_valeur' => 'Ben Salah',
            'nouvelle_valeur' => 'Ben Salah Corrigé',
            'regle' => 'R12_MENS',
            'motif' => 'Conformité avec extrait d\'acte de naissance MENS',
            'statut' => 'proposee',
            'propose_par' => $this->autreAgent->id, // Autre agent
        ]);

        $reponse = $this->actingAs($this->agentScolariteF1)->get(route('admin.centre-travail.show', $inscription));

        $reponse->assertOk();
        $reponse->assertSee('Workflow à quatre yeux — Propositions de correction');
        $reponse->assertSee('Ben Salah Corrigé');
        $reponse->assertSee('Conformité avec extrait d\'acte de naissance MENS');
        $reponse->assertSee('Décision autorisée'); // L'agent courant n'est pas le proposeur
    }

    /** 9. Immutabilité absolue : aucune mutation de l'inscription, tâche ou correction. */
    public function test_absence_de_mutation_lors_de_la_consultation_du_detail(): void
    {
        $etudiant = $this->creerEtudiant('08123464', $this->faculte1);
        $inscription = $this->creerInscription($etudiant);

        $tache = Task::create([
            'titre' => 'Contrôle dossier',
            'source' => 'inscription',
            'cle_unique' => "inscription:{$inscription->id}",
            'statut' => 'pending',
            'priorite' => 'normal',
            'faculte_id' => $this->faculte1->id,
        ]);

        $correction = DossierCorrection::create([
            'etudiant_id' => $etudiant->id,
            'inscription_academique_id' => $inscription->id,
            'champ' => 'cin',
            'ancienne_valeur' => '08123464',
            'nouvelle_valeur' => '08123499',
            'regle' => 'R12_MENS',
            'motif' => 'Correction CIN',
            'statut' => 'proposee',
            'propose_par' => $this->autreAgent->id,
        ]);

        $etatInscriptionAvant = $inscription->fresh()->toArray();
        $etatTacheAvant = $tache->fresh()->toArray();
        $etatCorrectionAvant = $correction->fresh()->toArray();

        // Appel GET sur l'écran de consultation
        $reponse = $this->actingAs($this->agentScolariteF1)->get(route('admin.centre-travail.show', $inscription));
        $reponse->assertOk();

        // Vérification de la stricte immutabilité
        $this->assertSame($etatInscriptionAvant, $inscription->fresh()->toArray());
        $this->assertSame($etatTacheAvant, $tache->fresh()->toArray());
        $this->assertSame($etatCorrectionAvant, $correction->fresh()->toArray());
    }

    /** 10. Absence totale d'envoi de courriel et de transition métier. */
    public function test_absence_envoi_courriel_et_transition_metier(): void
    {
        $etudiant = $this->creerEtudiant('08123465', $this->faculte1);
        $inscription = $this->creerInscription($etudiant);

        Mail::fake();
        Event::fake();

        $reponse = $this->actingAs($this->agentScolariteF1)->get(route('admin.centre-travail.show', $inscription));
        $reponse->assertOk();

        // Aucun mail ne doit avoir été envoyé
        Mail::assertNothingSent();

        // Le statut de l'inscription doit rester 'en_cours'
        $this->assertSame('en_cours', $inscription->fresh()->statut);
    }

    /** 11. Absence de fuite de données personnelles sensibles (mots de passe, tokens). */
    public function test_absence_fuite_donnees_sensibles(): void
    {
        $etudiant = $this->creerEtudiant('08123466', $this->faculte1);
        $inscription = $this->creerInscription($etudiant);

        $reponse = $this->actingAs($this->agentScolariteF1)->get(route('admin.centre-travail.show', $inscription));
        $reponse->assertOk();

        // Le hash du mot de passe ne doit JAMAIS apparaître dans le HTML
        $reponse->assertDontSee('SecretHash@123');
        $reponse->assertDontSee('$2y$');
    }

    /** 12. Affichage de la tâche administrative associée et de l'historique d'audit. */
    public function test_affichage_tache_et_historique_associes(): void
    {
        $etudiant = $this->creerEtudiant('08123467', $this->faculte1);
        $inscription = $this->creerInscription($etudiant);

        Task::create([
            'titre' => 'Tâche spécifique inscription',
            'source' => 'inscription',
            'cle_unique' => "inscription:{$inscription->id}",
            'statut' => 'pending',
            'priorite' => 'high',
            'faculte_id' => $this->faculte1->id,
        ]);

        HistoriqueAcademique::create([
            'etudiant_id' => $etudiant->id,
            'action' => 'CREATION_INSCRIPTION',
            'entite' => 'inscription_academique',
            'entite_id' => $inscription->id,
            'utilisateur_id' => $this->agentScolariteF1->id,
        ]);

        $reponse = $this->actingAs($this->agentScolariteF1)->get(route('admin.centre-travail.show', $inscription));

        $reponse->assertOk();
        $reponse->assertSee("inscription:{$inscription->id}");
        $reponse->assertSee("Journal d'audit académique récent", false);
    }
}
