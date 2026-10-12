<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\AppNotification;
use App\Models\DossierCorrection;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\HistoriqueAcademique;
use App\Models\MensReference;
use App\Models\RegistrationPeriod;
use App\Models\User;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use App\Services\StudentStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOOP 21 — Tests de parcours complet et non-régression de bout en bout.
 *
 * Valide les cycles de vie réels du candidat et du dossier d'inscription :
 * 1. Cycle nominal A à Z : Découverte -> MENS -> Formulaire -> Dépôt -> Suivi -> Récépissé -> Instruction -> Validation -> Affectation.
 * 2. Cycle avec demande de complément : Dépôt -> Anomalie -> Complément demandé -> Rectification étudiant -> Re-score.
 * 3. Cycle de blocage période fermée : Tentative de soumission hors période -> Rejet clair.
 * 4. Cycle d'ambiguïté identité : Divergence bloquante MENS -> Rejet fail-closed.
 * 5. Cycle anti-doublon : Tentative de réinscription avec le même CIN -> Invitation à se connecter.
 * 6. Cycle de rejet administratif : Décision de rejet motivée -> Journalisation d'audit -> Notification haute priorité.
 * 7. Cycle de cloisonnement multi-facultés : Contrôle d'accès inviolable sur toute la chaîne.
 */
class InscriptionParcoursCompletBoutEnBoutTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculteSciences;

    private Faculte $faculteMedecine;

    private AnneeUniversitaire $annee;

    private RegistrationPeriod $periodeNormale;

    private Programme $programmeLicence;

    private Niveau $niveauL1;

    private Group $groupeG1;

    private User $scolariteSciences;

    private User $scolariteMedecine;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.student-dossiers.active' => true]);

        // 1. Facultés
        $this->faculteSciences = Faculte::create([
            'nom' => 'Faculté des Sciences de Tunis',
            'code' => 'FST',
            'active' => true,
        ]);

        $this->faculteMedecine = Faculte::create([
            'nom' => 'Faculté de Médecine de Tunis',
            'code' => 'FMT',
            'active' => true,
        ]);

        // 2. Année universitaire et période d'inscription ouverte
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->periodeNormale = RegistrationPeriod::create([
            'annee_universitaire_id' => $this->annee->id,
            'type_periode' => 'normale',
            'opened_at' => now()->subDays(2),
            'closed_at' => now()->addDays(10),
            'statut' => 'active',
        ]);

        // 3. Référentiel académique (Programme, Niveau, Groupe)
        $this->programmeLicence = Programme::create([
            'faculte_id' => $this->faculteSciences->id,
            'code' => 'LIC-INFO',
            'nom' => 'Licence Sciences Informatiques',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
            'statut' => Programme::STATUT_ACTIF,
        ]);

        $this->niveauL1 = Niveau::create([
            'programme_id' => $this->programmeLicence->id,
            'code' => 'L1',
            'nom' => 'Licence 1ère Année Informatique',
            'ordre' => 1,
        ]);

        $this->groupeG1 = Group::create([
            'nom' => 'TD-G1',
            'code' => 'L1-INFO-G1',
            'niveau' => 'L1',
            'programme_id' => $this->programmeLicence->id,
            'niveau_id' => $this->niveauL1->id,
            'faculte_id' => $this->faculteSciences->id,
            'annee_universitaire_id' => $this->annee->id,
            'capacite' => 30,
            'actif' => true,
        ]);

        // 4. Comptes de gestion de scolarité par faculté
        $this->scolariteSciences = User::creerCompteBackOffice([
            'name' => 'Agent Scolarité Sciences',
            'email' => 'agent.fst@universite.tn',
            'password' => 'SecretPass@123',
            'faculte_id' => $this->faculteSciences->id,
        ], 'admin_faculte');

        $this->scolariteMedecine = User::creerCompteBackOffice([
            'name' => 'Agent Scolarité Médecine',
            'email' => 'agent.fmt@universite.tn',
            'password' => 'SecretPass@123',
            'faculte_id' => $this->faculteMedecine->id,
        ], 'admin_faculte');
    }

    /**
     * SCÉNARIO 1 : Parcours nominal complet de bout en bout (A à Z).
     */
    public function test_parcours_nominal_complet_de_a_a_z(): void
    {
        // Étape 1 : Référentiel national MENS présent pour le candidat
        MensReference::create([
            'cin_normalise' => '07123456',
            'cin_original' => '07123456',
            'date_naissance' => '2004-05-12',
            'nom' => 'Ben Ammar',
            'prenom' => 'Youssef',
            'sexe' => 'M',
            'nationalite' => 'Tunisienne',
            'annee' => '2026-2027',
            'source_hash' => str_repeat('1', 64),
        ]);

        // Étape 2 : Consultation publique du formulaire d'inscription
        $respCreate = $this->get(route('inscription.create'));
        $respCreate->assertOk();
        $respCreate->assertSee('Faculté des Sciences de Tunis');

        // Étape 3 : Identification intelligente (pré-remplissage)
        $respIdentifier = $this->postJson(route('inscription.identifier'), [
            'cin' => '07123456',
            'date_naissance' => '2004-05-12',
        ]);
        $respIdentifier->assertOk();
        $respIdentifier->assertJsonPath('status', 'MENS_FOUND');
        $respIdentifier->assertJsonPath('prefill.nom', 'Ben Ammar');

        // Étape 4 : Options d'orientation
        $respOrientation = $this->getJson(route('inscription.orientation-options', [
            'faculte_id' => $this->faculteSciences->id,
            'type_inscription' => 'premiere',
            'annee_bac' => '2024',
            'cin' => '07123456',
            'annee_academique' => '2026-2027',
        ]));
        $respOrientation->assertOk();

        // Étape 5 : Soumission finale du formulaire d'inscription
        $payload = [
            'cin' => '07123456',
            'date_naissance' => '2004-05-12',
            'nom' => 'Ben Ammar',
            'prenom' => 'Youssef',
            'email' => 'youssef.benammar@etudiant.tn',
            'sexe' => 'M',
            'faculte_id' => $this->faculteSciences->id,
            'programme_id' => $this->programmeLicence->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => '15 Rue de Carthage',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2024',
            'password' => 'Etudiant@1234',
            'password_confirmation' => 'Etudiant@1234',
            'confirmation' => '1',
            'idempotency_token' => 'idemp-parcours-nominal-001',
        ];

        $respStore = $this->post('/inscription', $payload);
        $respStore->assertRedirect(route('inscription.success'));

        // Vérification de la création en base avec numéro officiel
        $etudiant = Etudiant::where('cin', '07123456')->first();
        $this->assertNotNull($etudiant);
        $this->assertNotEmpty($etudiant->num_inscription);
        $this->assertSame('en_attente', $etudiant->statut);
        $this->assertSame($this->niveauL1->id, $etudiant->niveau_id);

        // Étape 6 : Suivi public par référence et second facteur
        $respSuiviPublic = $this->post(route('inscription.suivi.rechercher'), [
            'reference' => $etudiant->num_inscription,
            'secondaire' => '2004-05-12',
        ]);
        $respSuiviPublic->assertOk();
        $respSuiviPublic->assertSee('En attente de validation académique');

        // Étape 7 : Authentification dans l'espace étudiant
        $respLogin = $this->post(route('etudiant.connexion.login'), [
            'identifiant' => '07123456',
            'password' => 'Etudiant@1234',
        ]);
        $respLogin->assertRedirect(route('etudiant.profil.show'));

        // Étape 8 : Consultation du suivi authentifié (anti-IDOR)
        $respSuiviEtu = $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->get(route('etudiant.suivi'));
        $respSuiviEtu->assertOk();
        $respSuiviEtu->assertSee('Ben Ammar');

        // Étape 9 : Téléchargement du récépissé PDF officiel
        $respRecepisse = $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->get(route('etudiant.recepisse'));
        $respRecepisse->assertOk();
        $this->assertSame('application/pdf', $respRecepisse->headers->get('Content-Type'));

        // Étape 10 : L'agent de scolarité examine la file administrative
        $respFileAdmin = $this->actingAs($this->scolariteSciences)
            ->get(route('admin.inscriptions.file-traitement'));
        $respFileAdmin->assertOk();
        $respFileAdmin->assertSee('Ben Ammar');

        // Ajout d'une note d'instruction administrative
        $respNote = $this->actingAs($this->scolariteSciences)
            ->post(route('admin.inscriptions.file-traitement.commenter', $etudiant), [
                'commentaire' => 'Vérification état civil effectuée avec succès.',
                'motif_code' => 'DOSSIER_COMPLET_VALABLE',
            ]);
        $respNote->assertRedirect();

        // Étape 11 : Décision de validation académique
        app(StudentStatusService::class)->changer(
            $etudiant,
            'valide',
            $this->scolariteSciences->id,
            'Dossier complet et conforme aux prérequis.'
        );

        $etudiant->refresh();
        $this->assertSame('valide', $etudiant->statut);

        // Affectation de groupe pédagogique
        $etudiant->groupe_id = $this->groupeG1->id;
        $etudiant->save();

        // Étape 12 : L'étudiant reçoit la notification officielle de validation
        $notif = AppNotification::where('etudiant_id', $etudiant->id)
            ->where('type', 'dossier.statut')
            ->first();
        $this->assertNotNull($notif);
        $this->assertStringContainsString('Validé', $notif->message);

        // Audit historique vérifié
        $trace = HistoriqueAcademique::where('etudiant_id', $etudiant->id)->first();
        $this->assertNotNull($trace);
    }

    /**
     * SCÉNARIO 2 : Parcours avec demande de complément et rectification étudiante.
     */
    public function test_parcours_avec_demande_de_complement_et_rectification(): void
    {
        MensReference::create([
            'cin_normalise' => '07999888',
            'cin_original' => '07999888',
            'date_naissance' => '2004-07-20',
            'nom' => 'Trabelsi',
            'prenom' => 'Salma',
            'sexe' => 'F',
            'nationalite' => 'Tunisienne',
            'annee' => '2026-2027',
            'source_hash' => str_repeat('2', 64),
        ]);

        $payload = [
            'cin' => '07999888',
            'date_naissance' => '2004-07-20',
            'nom' => 'Trabelsi',
            'prenom' => 'Salma',
            'email' => 'salma.trabelsi@etudiant.tn',
            'sexe' => 'F',
            'faculte_id' => $this->faculteSciences->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => '10 Avenue Habib Bourguiba',
            'ville' => 'Tunis',
            'code_postal' => '1001',
            'annee_bac' => '2024',
            'password' => 'Etudiant@1234',
            'password_confirmation' => 'Etudiant@1234',
            'confirmation' => '1',
        ];

        $this->post('/inscription', $payload)->assertRedirect(route('inscription.success'));
        $etudiant = Etudiant::where('cin', '07999888')->firstOrFail();

        // 1. L'agent signale une anomalie et demande un complément (orthographe du prénom)
        DossierCorrection::create([
            'etudiant_id' => $etudiant->id,
            'champ' => 'prenom',
            'ancienne_valeur' => 'Salma',
            'nouvelle_valeur' => 'Selma',
            'statut' => 'proposee',
            'motif' => 'Conformité avec l\'extrait d\'acte de naissance',
            'propose_par' => $this->scolariteSciences->id,
        ]);

        // 2. L'étudiant consulte son espace de correction
        $respCorrections = $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->get(route('etudiant.corrections.index'));
        $respCorrections->assertOk();
        $respCorrections->assertSee('prenom');

        // 3. L'étudiant soumet la correction requise
        $respSoumettre = $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->post(route('etudiant.corrections.store'), [
                'champs' => [
                    'prenom' => 'Selma',
                ],
            ]);
        $respSoumettre->assertRedirect(route('etudiant.suivi'));

        // 4. Vérification que la correction est validée et le prénom mis à jour
        $correction = DossierCorrection::where('etudiant_id', $etudiant->id)->first();
        $this->assertSame('appliquee', $correction->statut);
        $this->assertSame('Selma', $etudiant->fresh()->prenom);
    }

    /**
     * SCÉNARIO 3 : Parcours hors période d'inscription (fermeture administrative).
     */
    public function test_parcours_rejete_si_periode_fermee(): void
    {
        // Clôturer la période
        $this->periodeNormale->update([
            'closed_at' => now()->subMinute(),
            'statut' => 'revoquee',
        ]);

        MensReference::create([
            'cin_normalise' => '07111222',
            'cin_original' => '07111222',
            'date_naissance' => '2004-01-01',
            'nom' => 'HorsPeriode',
            'prenom' => 'Candidat',
            'sexe' => 'M',
            'nationalite' => 'Tunisienne',
            'annee' => '2026-2027',
            'source_hash' => str_repeat('3', 64),
        ]);

        $payload = [
            'cin' => '07111222',
            'date_naissance' => '2004-01-01',
            'nom' => 'HorsPeriode',
            'prenom' => 'Candidat',
            'email' => 'hors.periode@etudiant.tn',
            'sexe' => 'M',
            'faculte_id' => $this->faculteSciences->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => 'Rue test',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2024',
            'password' => 'Secret123!',
            'password_confirmation' => 'Secret123!',
            'confirmation' => '1',
        ];

        // La soumission doit être rejetée avec erreur de période
        $response = $this->post('/inscription', $payload);
        $response->assertSessionHasErrors('periode');
        $this->assertSame(0, Etudiant::where('cin', '07111222')->count());
    }

    /**
     * SCÉNARIO 4 : Rejet fail-closed en cas de divergence d'identité MENS bloquante.
     */
    public function test_parcours_rejete_si_divergence_mens_bloquante(): void
    {
        MensReference::create([
            'cin_normalise' => '07555444',
            'cin_original' => '07555444',
            'date_naissance' => '2004-02-14',
            'nom' => 'NomOfficiel',
            'prenom' => 'PrenomOfficiel',
            'sexe' => 'M',
            'nationalite' => 'Tunisienne',
            'annee' => '2026-2027',
            'source_hash' => str_repeat('4', 64),
        ]);

        // Soumission avec mauvaise date de naissance
        $payload = [
            'cin' => '07555444',
            'date_naissance' => '1995-10-10', // Date incorrecte
            'nom' => 'NomOfficiel',
            'prenom' => 'PrenomOfficiel',
            'email' => 'divergence@etudiant.tn',
            'sexe' => 'M',
            'faculte_id' => $this->faculteSciences->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => 'Rue test',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2024',
            'password' => 'Secret123!',
            'password_confirmation' => 'Secret123!',
            'confirmation' => '1',
        ];

        $response = $this->post('/inscription', $payload);
        $response->assertSessionHasErrors('identification');
        $this->assertSame(0, Etudiant::where('cin', '07555444')->count());
    }

    /**
     * SCÉNARIO 5 : Tentative de doublon CIN rejetée avec redirection claire.
     */
    public function test_parcours_rejete_si_doublon_cin(): void
    {
        MensReference::create([
            'cin_normalise' => '07000001',
            'cin_original' => '07000001',
            'date_naissance' => '2004-03-01',
            'nom' => 'Premier',
            'prenom' => 'Etudiant',
            'sexe' => 'M',
            'nationalite' => 'Tunisienne',
            'annee' => '2026-2027',
            'source_hash' => str_repeat('5', 64),
        ]);

        $payload = [
            'cin' => '07000001',
            'date_naissance' => '2004-03-01',
            'nom' => 'Premier',
            'prenom' => 'Etudiant',
            'email' => 'premier@etudiant.tn',
            'sexe' => 'M',
            'faculte_id' => $this->faculteSciences->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => 'Rue test',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2024',
            'password' => 'Secret123!',
            'password_confirmation' => 'Secret123!',
            'confirmation' => '1',
        ];

        // 1. Première inscription réussie
        $this->post('/inscription', $payload)->assertRedirect(route('inscription.success'));
        $this->assertSame(1, Etudiant::where('cin', '07000001')->count());

        // 2. Deuxième inscription avec un email différent mais même CIN
        $payload2 = array_merge($payload, ['email' => 'autre.email@etudiant.tn']);
        $respDoublon = $this->post('/inscription', $payload2);
        $respDoublon->assertSessionHasErrors('cin');
        $this->assertSame(1, Etudiant::where('cin', '07000001')->count());
    }

    /**
     * SCÉNARIO 6 : Rejet administratif motivé avec traçabilité d'audit et notification.
     */
    public function test_parcours_rejet_administratif_motive(): void
    {
        $etudiant = Etudiant::create([
            'cin' => '07666777',
            'nom' => 'Rejete',
            'prenom' => 'Candidat',
            'email' => 'candidat.rejete@etudiant.tn',
            'date_naissance' => '2004-08-15',
            'sexe' => 'M',
            'faculte_id' => $this->faculteSciences->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => 'Rue test',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2024',
            'password' => 'Secret123!',
        ]);
        $etudiant->definirStatut('en_attente');

        // Décision administrative de rejet
        app(StudentStatusService::class)->changer(
            $etudiant,
            'rejete',
            $this->scolariteSciences->id,
            'Dossier non recevable : prérequis académiques non atteints.'
        );

        $etudiant->refresh();
        $this->assertSame('rejete', $etudiant->statut);

        // Notification haute priorité envoyée au candidat
        $notif = AppNotification::where('etudiant_id', $etudiant->id)
            ->where('type', 'dossier.statut')
            ->first();
        $this->assertNotNull($notif);
        $this->assertSame(2, $notif->priorite); // Priorité haute sur rejet
        $this->assertStringContainsString('prérequis académiques', $notif->message);

        // Suivi public affiche le statut de rejet
        $respSuivi = $this->post(route('inscription.suivi.rechercher'), [
            'reference' => $etudiant->num_inscription,
            'secondaire' => '2004-08-15',
        ]);
        $respSuivi->assertOk();
        $respSuivi->assertSee('Dossier non retenu');
    }

    /**
     * SCÉNARIO 7 : Cloisonnement strict multi-facultés sur toute la chaîne opérationnelle.
     */
    public function test_cloisonnement_multi_facultes_sur_toute_la_chaine(): void
    {
        // Étudiant de Médecine
        $etudiantMedecine = Etudiant::create([
            'cin' => '07888999',
            'nom' => 'Medecin',
            'prenom' => 'Futur',
            'email' => 'futur.medecin@fmt.tn',
            'date_naissance' => '2004-09-09',
            'sexe' => 'M',
            'faculte_id' => $this->faculteMedecine->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => 'Bab Saadoun',
            'ville' => 'Tunis',
            'code_postal' => '1006',
            'annee_bac' => '2024',
            'password' => 'Secret123!',
        ]);
        $etudiantMedecine->definirStatut('en_attente');

        // 1. L'agent de Sciences consulte sa file de traitement : l'étudiant de Médecine est absent
        $respFileSciences = $this->actingAs($this->scolariteSciences)
            ->get(route('admin.inscriptions.file-traitement'));
        $respFileSciences->assertOk();
        $respFileSciences->assertDontSee('futur.medecin@fmt.tn');

        // 2. L'agent de Sciences tente d'ajouter une note sur l'étudiant de Médecine -> 403 Forbidden
        $respCommentaireIllegitime = $this->actingAs($this->scolariteSciences)
            ->post(route('admin.inscriptions.file-traitement.commenter', $etudiantMedecine), [
                'commentaire' => 'Tentative d\'intervention cross-faculty',
            ]);
        $respCommentaireIllegitime->assertForbidden();

        // 3. L'agent de Médecine consulte sa propre file : l'étudiant de Médecine est bien visible
        $respFileMedecine = $this->actingAs($this->scolariteMedecine)
            ->get(route('admin.inscriptions.file-traitement'));
        $respFileMedecine->assertOk();
        $respFileMedecine->assertSee('Medecin');
    }
}
