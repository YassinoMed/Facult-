<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Evaluation\Models\Deliberation;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Services\EvaluationDocumentBatchService;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

class EvaluationDocumentBatchTest extends TestCase
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
            'nom' => 'Faculté des Sciences Test Batch',
            'code' => 'FST-BATCH',
            'description' => 'Test Batch',
            'active' => true,
        ]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Admin Batch',
            'email' => 'admin.batch@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->programme = Programme::create([
            'nom' => 'Licence Informatique Batch',
            'code' => 'L-INFO-BATCH',
            'cycle' => 'licence',
            'faculte_id' => $this->faculte->id,
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
            'actif' => true,
        ]);

        $niveau = Niveau::create([
            'code' => 'L1-BATCH',
            'nom' => 'Licence 1 Batch',
            'libelle' => 'Licence 1 Batch',
            'programme_id' => $this->programme->id,
            'ordre' => 1,
            'actif' => true,
        ]);

        $semestre = SemestreLmd::create([
            'code' => 'S1-BATCH',
            'nom' => 'Semestre 1 Batch',
            'libelle' => 'Semestre 1 Batch',
            'numero' => 1,
            'niveau_id' => $niveau->id,
            'actif' => true,
        ]);

        $ue = UniteEnseignement::create([
            'code' => 'UE-INFO-BATCH',
            'nom' => 'UE Informatique Batch',
            'programme_id' => $this->programme->id,
            'semestre_id' => $semestre->id,
            'credits' => 6,
            'coefficient' => 2,
            'type' => 'fondamentale',
            'actif' => true,
        ]);

        $this->matiere = Matiere::create([
            'code' => 'ALGO-BATCH',
            'nom' => 'Algorithmique Batch',
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
            'nom' => 'Groupe Batch G1',
            'code' => 'GRP-BATCH-G1',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'actif' => true,
        ]);

        $this->salle = Room::create([
            'code' => 'AMPHI-BATCH',
            'nom' => 'Amphi Batch',
            'capacity' => 100,
            'exam_capacity' => 50,
            'active' => true,
        ]);

        $this->session = SessionExamen::create([
            'code' => 'SESS-BATCH-01',
            'nom' => 'Session Principale Batch 2026',
            'libelle' => 'Session Principale Batch 2026',
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
            'libelle' => 'Examen Algorithmique Batch',
            'type' => 'examen_final',
            'statut' => 'scheduled',
            'titre' => 'Examen Algorithmique Batch',
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
        static $compteurEtudiant = 500000;
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

        return $etudiant;
    }

    public function test_previsualisation_lot_documentaire_compte_correctement(): void
    {
        $e1 = $this->creerEtudiant();
        $e2 = $this->creerEtudiant();

        Candidat::create(['epreuve_id' => $this->epreuve->id, 'etudiant_id' => $e1->id, 'statut' => 'inscrit']);
        Candidat::create(['epreuve_id' => $this->epreuve->id, 'etudiant_id' => $e2->id, 'statut' => 'inscrit']);

        /** @var EvaluationDocumentBatchService $service */
        $service = app(EvaluationDocumentBatchService::class);

        $preview = $service->previsualiser([
            'session_id' => $this->session->id,
            'types' => ['emargement', 'feuille_notes'],
        ], $this->admin);

        $this->assertSame(1, $preview['nombre_epreuves']);
        $this->assertSame(2, $preview['nombre_candidats']);
        $this->assertSame(2, $preview['nombre_documents_estimes']); // 1 emargement + 1 feuille_notes
        $this->assertEmpty($preview['avertissements']);
    }

    public function test_generation_lot_emargements_et_feuilles_produit_un_zip_valide(): void
    {
        $e1 = $this->creerEtudiant();
        Candidat::create(['epreuve_id' => $this->epreuve->id, 'etudiant_id' => $e1->id, 'statut' => 'inscrit']);

        /** @var EvaluationDocumentBatchService $service */
        $service = app(EvaluationDocumentBatchService::class);

        $resultat = $service->genererLot([
            'session_id' => $this->session->id,
            'types' => ['emargement', 'feuille_notes'],
        ], $this->admin);

        $this->assertNotEmpty($resultat['lot_id']);
        $this->assertSame(2, $resultat['reussis']);
        $this->assertEmpty($resultat['erreurs']);
        $this->assertFileExists($resultat['chemin_zip']);

        // Vérification de l'archive ZIP
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($resultat['chemin_zip']));
        $this->assertSame(2, $zip->numFiles);
        $zip->close();
    }

    public function test_generation_pv_deliberation_rejette_si_deliberation_non_validee(): void
    {
        $delib = Deliberation::create([
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programme->id,
            'statut' => 'draft', // non validé
            'regles_appliquees' => ['version' => 1],
        ]);

        /** @var EvaluationDocumentBatchService $service */
        $service = app(EvaluationDocumentBatchService::class);

        $preview = $service->previsualiser([
            'session_id' => $this->session->id,
            'deliberation_id' => $delib->id,
            'types' => ['pv_deliberation'],
        ], $this->admin);

        $this->assertNotEmpty($preview['avertissements']);
        $this->assertStringContainsString('PV bloqué', $preview['avertissements'][0]);

        $resultat = $service->genererLot([
            'session_id' => $this->session->id,
            'deliberation_id' => $delib->id,
            'types' => ['pv_deliberation'],
        ], $this->admin);

        $this->assertSame(0, $resultat['reussis']);
        $this->assertNotEmpty($resultat['erreurs']);
    }

    public function test_telechargement_securise_empeche_acces_hors_perimetre_faculte(): void
    {
        $e1 = $this->creerEtudiant();
        Candidat::create(['epreuve_id' => $this->epreuve->id, 'etudiant_id' => $e1->id, 'statut' => 'inscrit']);

        /** @var EvaluationDocumentBatchService $service */
        $service = app(EvaluationDocumentBatchService::class);

        $resultat = $service->genererLot([
            'session_id' => $this->session->id,
            'types' => ['emargement'],
        ], $this->admin);

        // Création d'un second administrateur rattaché à une autre faculté
        $autreFaculte = Faculte::create([
            'nom' => 'Autre Faculté',
            'code' => 'AUTRE-FAC',
            'active' => true,
        ]);
        $autreAdmin = User::creerCompteBackOffice([
            'name' => 'Autre Admin',
            'email' => 'autre.admin@universite.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $autreFaculte->id,
        ], 'admin_faculte');

        // L'admin de l'autre faculté ne doit pas pouvoir télécharger l'archive
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Accès refusé');

        $service->telechargerLot($resultat['lot_id'], $autreAdmin);
    }

    public function test_interface_http_documents_groupes(): void
    {
        $this->actingAs($this->admin);

        $resIndex = $this->get(route('admin.evaluations.documents-batch.index'));
        $resIndex->assertOk();
        $resIndex->assertSee('Génération documentaire groupée');

        $resPreview = $this->postJson(route('admin.evaluations.documents-batch.previsualiser'), [
            'session_id' => $this->session->id,
            'types' => ['emargement', 'feuille_notes'],
        ]);
        $resPreview->assertOk();
        $resPreview->assertJsonPath('succes', true);
    }
}
