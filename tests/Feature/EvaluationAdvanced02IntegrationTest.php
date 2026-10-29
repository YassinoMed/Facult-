<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\Note;
use App\Models\User;
use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\Evaluation\Models\Deliberation;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\JuryMembre;
use App\Modules\Evaluation\Models\NoteEpreuve;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Services\CompostageService;
use App\Modules\Evaluation\Services\EvaluationDocumentBatchService;
use App\Modules\Evaluation\Services\EvaluationImportService;
use App\Modules\Evaluation\Services\EvaluationQualityService;
use App\Modules\Evaluation\Services\EvaluationWorkflowService;
use App\Modules\Evaluation\Services\SaisieNoteService;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class EvaluationAdvanced02IntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Programme $programme;

    private Matiere $matiere;

    private Group $groupe;

    private Room $salle;

    private SessionExamen $session;

    private Epreuve $epreuve;

    private int $compteur = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.evaluation.active' => true]);

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences Test E2E',
            'code' => 'FST-E2E',
            'description' => 'Test E2E',
            'active' => true,
        ]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Admin Intégration',
            'email' => 'admin.integration@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->programme = Programme::create([
            'nom' => 'Licence Informatique E2E',
            'code' => 'L-INFO-E2E',
            'cycle' => 'licence',
            'faculte_id' => $this->faculte->id,
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
            'actif' => true,
        ]);

        $niveau = Niveau::create([
            'code' => 'L1-E2E',
            'nom' => 'Licence 1 E2E',
            'libelle' => 'Licence 1 E2E',
            'programme_id' => $this->programme->id,
            'ordre' => 1,
            'actif' => true,
        ]);

        $semestre = SemestreLmd::create([
            'code' => 'S1-E2E',
            'nom' => 'Semestre 1 E2E',
            'libelle' => 'Semestre 1 E2E',
            'numero' => 1,
            'niveau_id' => $niveau->id,
            'actif' => true,
        ]);

        $ue = UniteEnseignement::create([
            'code' => 'UE-INFO-E2E',
            'nom' => 'UE Informatique E2E',
            'programme_id' => $this->programme->id,
            'semestre_id' => $semestre->id,
            'credits' => 6,
            'coefficient' => 2,
            'type' => 'fondamentale',
            'actif' => true,
        ]);

        $this->matiere = Matiere::create([
            'code' => 'ALGO-E2E',
            'nom' => 'Algorithmique E2E',
            'ue_id' => $ue->id,
            'niveau' => 'L1',
            'semestre' => 1,
            'credits' => 3,
            'coefficient' => 1.5,
            'heures_cm' => 20,
            'heures_td' => 20,
            'heures_tp' => 10,
            'active' => true,
        ]);

        $this->groupe = Group::create([
            'nom' => 'Groupe E2E G1',
            'code' => 'GRP-E2E-G1',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'actif' => true,
        ]);

        $this->salle = Room::create([
            'code' => 'AMPHI-E2E',
            'nom' => 'Amphi E2E',
            'capacity' => 100,
            'exam_capacity' => 50,
            'active' => true,
        ]);

        $this->session = SessionExamen::create([
            'code' => 'SESS-E2E-01',
            'nom' => 'Session Principale E2E 2026',
            'libelle' => 'Session Principale E2E 2026',
            'annee_universitaire_id' => $this->annee->id,
            'faculte_id' => $this->faculte->id,
            'type' => 'normale',
            'statut' => 'open',
            'date_debut' => '2026-11-01',
            'date_fin' => '2026-11-30',
            'created_by' => $this->admin->id,
        ]);

        $examen = Examen::create([
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programme->id,
            'libelle' => 'Examen Algorithmique E2E',
            'type' => 'examen_final',
            'statut' => 'scheduled',
            'titre' => 'Examen Algorithmique E2E',
        ]);

        $this->epreuve = Epreuve::create([
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'examen_id' => $examen->id,
            'matiere_id' => $this->matiere->id,
            'groupe_id' => $this->groupe->id,
            'salle_id' => $this->salle->id,
            'date' => '2026-11-10',
            'heure_debut' => '09:00:00',
            'heure_fin' => '11:00:00',
            'note_max' => 20.00,
            'coefficient' => 1.5,
            'type_evaluation' => 'ecrit',
            'statut' => 'draft',
        ]);
    }

    private function creerEtudiant(): Etudiant
    {
        static $compteurEtudiant = 700000;
        $suffixe = ++$compteurEtudiant;

        $etudiant = Etudiant::create([
            'cin' => 'NW'.$suffixe,
            'nom' => 'Mansouri',
            'prenom' => 'Karim',
            'email' => 'karim.'.$suffixe.'@test.ma',
            'date_naissance' => '2004-01-01',
            'sexe' => 'M',
            'faculte_id' => $this->faculte->id,
            'groupe_id' => $this->groupe->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => '12 rue des Tests',
            'ville' => 'Casablanca',
            'code_postal' => '20000',
            'annee_bac' => '2024',
            'password' => 'Etudiant@123',
        ]);
        $etudiant->forceFill(['num_inscription' => 'INS-2026-'.$suffixe])->save();
        $etudiant->definirStatut('valide');

        $inscription = $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id,
            'niveau' => 'L1',
            'statut' => 'en_cours',
        ]);
        $inscription->semestres()->create(['numero' => 1]);

        return $etudiant;
    }

    /**
     * Parcours A : Saisie -> Soumission -> Validation -> Délibération -> Publication
     */
    public function test_parcours_a_saisie_validation_deliberation_et_publication(): void
    {
        $etudiant = $this->creerEtudiant();
        $candidat = Candidat::create(['epreuve_id' => $this->epreuve->id, 'etudiant_id' => $etudiant->id, 'statut' => 'present']);

        /** @var SaisieNoteService $saisieService */
        $saisieService = app(SaisieNoteService::class);
        $saisieService->saisirLot($this->epreuve, [[
            'etudiant_id' => $etudiant->id,
            'candidat_id' => $candidat->id,
            'valeur' => 15.50,
            'motif' => 'Saisie parcours A',
        ]], (int) $this->admin->id);

        /** @var EvaluationWorkflowService $workflow */
        $workflow = app(EvaluationWorkflowService::class);

        // 1. Soumission
        $resSoumission = $workflow->soumettreNotesEpreuve($this->epreuve, $this->admin);
        $this->assertSame(1, $resSoumission['soumises']);
        $this->assertSame('submitted', NoteEpreuve::where('epreuve_id', $this->epreuve->id)->first()->statut);

        // 2. Validation
        $resValidation = $workflow->validerNotesEpreuve($this->epreuve, $this->admin);
        $this->assertSame(1, $resValidation['validees']);

        $note = NoteEpreuve::where('epreuve_id', $this->epreuve->id)->first();
        $this->assertSame('validated', $note->statut);

        // Synchronisation vers table centrale notes
        $noteCentrale = Note::find($note->note_id);
        $this->assertNotNull($noteCentrale);
        $this->assertEquals(15.50, (float) $noteCentrale->note);

        // 3. Délibération
        $deliberation = Deliberation::create([
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programme->id,
            'groupe_id' => $this->groupe->id,
            'statut' => 'prepared',
            'regles_appliquees' => ['version' => 1],
        ]);

        // Membres de jury présents pour quorum (président + 2 membres)
        JuryMembre::create([
            'deliberation_id' => $deliberation->id,
            'user_id' => $this->admin->id,
            'fonction' => 'president',
            'present' => true,
        ]);
        JuryMembre::create([
            'deliberation_id' => $deliberation->id,
            'user_id' => $this->admin->id,
            'fonction' => 'rapporteur',
            'present' => true,
        ]);
        JuryMembre::create([
            'deliberation_id' => $deliberation->id,
            'user_id' => $this->admin->id,
            'fonction' => 'membre',
            'present' => true,
        ]);

        DecisionDeliberation::create([
            'deliberation_id' => $deliberation->id,
            'etudiant_id' => $etudiant->id,
            'inscription_academique_id' => $etudiant->inscriptionsAcademiques()->first()->id,
            'moyenne' => 15.50,
            'credits_obtenus' => 30,
            'credits_total' => 30,
            'decision' => 'admis',
            'publie' => false,
        ]);

        // Validation délibération
        $workflow->validerDeliberation($deliberation, $this->admin);
        $this->assertSame('validated', $deliberation->fresh()->statut);

        // Publication officielle
        $workflow->publierDeliberation($deliberation, $this->admin);
        $this->assertSame('published', $deliberation->fresh()->statut);

        $decision = DecisionDeliberation::where('deliberation_id', $deliberation->id)->first();
        $this->assertTrue((bool) $decision->publie);
    }

    /**
     * Parcours B : Import -> Prévisualisation sans écriture -> Confirmation et traçabilité
     */
    public function test_parcours_b_import_prevalidation_confirmation_et_tracabilite(): void
    {
        $etudiant = $this->creerEtudiant();
        Candidat::create(['epreuve_id' => $this->epreuve->id, 'etudiant_id' => $etudiant->id, 'statut' => 'present']);

        $compostage = app(CompostageService::class)->getOrCreate($this->epreuve, $etudiant)->numero;
        $csvContent = "compostage,note\n{$compostage},14.00\n";
        $fichier = UploadedFile::fake()->createWithContent('import-test.csv', $csvContent);

        /** @var EvaluationImportService $importService */
        $importService = app(EvaluationImportService::class);

        // 1. Prévisualisation (zéro écriture)
        $preview = $importService->analyser($fichier, $this->epreuve, $this->admin);
        $this->assertNotEmpty($preview['resultat']->valides);
        $this->assertSame(1, $preview['resume']['valides']);
        $this->assertDatabaseMissing('evaluation_notes', ['etudiant_id' => $etudiant->id]);

        // 2. Confirmation et exécution
        $res = $importService->confirmer($preview['token'], $this->epreuve, $this->admin);
        $this->assertSame('completed', $res['rapport']->status);
        $this->assertSame(1, $res['rapport']->valid_rows);

        $note = NoteEpreuve::where('epreuve_id', $this->epreuve->id)->where('etudiant_id', $etudiant->id)->first();
        $this->assertNotNull($note);
        $this->assertEquals(14.00, (float) $note->valeur);
    }

    /**
     * Parcours C : Détection d'anomalie -> Résolution / Resynchronisation
     */
    public function test_parcours_c_detection_anomalie_correction_et_resynchronisation(): void
    {
        $etudiant = $this->creerEtudiant();
        $candidat = Candidat::create(['epreuve_id' => $this->epreuve->id, 'etudiant_id' => $etudiant->id, 'statut' => 'present']);

        // Création directe d'une note en attente
        $note = NoteEpreuve::create([
            'epreuve_id' => $this->epreuve->id,
            'etudiant_id' => $etudiant->id,
            'candidat_id' => $candidat->id,
            'valeur' => 12.00,
            'note_max' => 20.00,
            'coefficient' => 1.5,
            'statut' => 'submitted',
        ]);

        /** @var EvaluationQualityService $quality */
        $quality = app(EvaluationQualityService::class);

        // Détection de l'anomalie 'note_en_attente'
        $anomalies = $quality->detecterAnomalies($this->faculte->id, ['type' => 'note_en_attente']);
        $this->assertNotEmpty($anomalies);
        $this->assertSame('note_en_attente', $anomalies[0]['type']);

        // Résolution groupée
        $bilan = $quality->resoudreGroupee([$anomalies[0]['id']], $this->admin, 'valider');
        $this->assertSame(1, $bilan['resolus']);

        $note->refresh();
        $this->assertSame('validated', $note->statut);
    }

    /**
     * Parcours D : Génération documentaire groupée et intégrité des archives
     */
    public function test_parcours_d_generation_documentaire_groupee_et_protection_pv(): void
    {
        $etudiant = $this->creerEtudiant();
        Candidat::create(['epreuve_id' => $this->epreuve->id, 'etudiant_id' => $etudiant->id, 'statut' => 'present']);

        /** @var EvaluationDocumentBatchService $batchService */
        $batchService = app(EvaluationDocumentBatchService::class);

        $res = $batchService->genererLot([
            'session_id' => $this->session->id,
            'types' => ['emargement', 'feuille_notes'],
        ], $this->admin);

        $this->assertSame(2, $res['reussis']);
        $this->assertFileExists($res['chemin_zip']);

        // Téléchargement sécurisé
        $response = $batchService->telechargerLot($res['lot_id'], $this->admin);
        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * Parcours E : Concurrence et intégrité transactionnelle
     */
    public function test_parcours_e_concurrence_verrou_optimiste_ou_transactionnel(): void
    {
        $etudiant = $this->creerEtudiant();
        $candidat = Candidat::create(['epreuve_id' => $this->epreuve->id, 'etudiant_id' => $etudiant->id, 'statut' => 'present']);

        /** @var SaisieNoteService $saisieService */
        $saisieService = app(SaisieNoteService::class);

        // Saisie première note
        $saisieService->saisirLot($this->epreuve, [[
            'etudiant_id' => $etudiant->id,
            'candidat_id' => $candidat->id,
            'valeur' => 12.00,
            'motif' => 'Premier auteur',
        ]], (int) $this->admin->id);

        $note = NoteEpreuve::where('epreuve_id', $this->epreuve->id)->where('etudiant_id', $etudiant->id)->first();
        $this->assertEquals(12.00, (float) $note->valeur);

        // Saisie deuxième auteur
        $autreUser = User::creerCompteBackOffice([
            'name' => 'Autre Enseignant',
            'email' => 'enseignant2@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $saisieService->saisirLot($this->epreuve, [[
            'etudiant_id' => $etudiant->id,
            'candidat_id' => $candidat->id,
            'valeur' => 14.00,
            'motif' => 'Second auteur concurrent',
        ]], (int) $autreUser->id);

        $note->refresh();
        $this->assertEquals(14.00, (float) $note->valeur);

        // Vérification de la double trace d'historique
        $historique = $note->historique()->orderBy('id')->get();
        $this->assertCount(2, $historique);
        $this->assertEquals($this->admin->id, $historique[0]->auteur_id);
        $this->assertEquals($autreUser->id, $historique[1]->auteur_id);
    }
}
