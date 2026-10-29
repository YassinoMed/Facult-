<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\NoteEpreuve;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Services\EpreuveService;
use App\Modules\Evaluation\Services\EvaluationQualityService;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EvaluationQualityServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Programme $programme;

    private Matiere $matiere;

    private Group $groupe;

    private Room $salle;

    private int $compteur = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.evaluation.active' => true]);

        $this->faculte = Faculte::create([
            'nom' => 'FST Qualité',
            'code' => 'FST-Q',
            'description' => 'Test',
            'active' => true,
        ]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Admin Qualité',
            'email' => 'admin.qualite@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->programme = Programme::create([
            'faculte_id' => $this->faculte->id,
            'code' => 'LGL-Q',
            'nom' => 'Licence Qualité',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
            'type' => 'licence',
            'statut' => 'actif',
        ]);
        $niveau = Niveau::create([
            'programme_id' => $this->programme->id,
            'code' => 'L1',
            'nom' => 'L1',
            'ordre' => 1,
        ]);
        $semestre = SemestreLmd::create([
            'niveau_id' => $niveau->id,
            'code' => 'S1',
            'nom' => 'S1',
            'numero' => 1,
            'ordre' => 1,
        ]);
        $ue = UniteEnseignement::create([
            'semestre_id' => $semestre->id,
            'programme_id' => $this->programme->id,
            'code' => 'UE-Q',
            'nom' => 'Algo Qualité',
            'credits' => 6,
            'coefficient' => 2,
        ]);
        $this->matiere = Matiere::create([
            'code' => 'MAT-Q',
            'nom' => 'Algorithmique',
            'ue_id' => $ue->id,
            'niveau' => 'L1',
            'semestre' => 1,
            'credits' => 6,
            'coefficient' => 2,
        ]);
        $this->groupe = Group::create([
            'code' => 'G1',
            'nom' => 'Groupe 1',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'actif' => true,
        ]);
        $this->salle = Room::create([
            'code' => 'B12',
            'nom' => 'B12',
            'capacite' => 40,
            'actif' => true,
        ]);
    }

    public function test_detection_candidat_present_sans_note(): void
    {
        $epreuve = $this->creerEpreuve();
        $epreuve->update(['statut' => 'completed']);
        $etudiant = $this->creerEtudiant();
        $candidat = $this->creerCandidat($epreuve, $etudiant, 'present');

        $service = app(EvaluationQualityService::class);
        $anomalies = $service->detecterAnomalies($this->faculte->id, ['type' => 'candidat_sans_note']);

        $this->assertNotEmpty($anomalies);
        $this->assertEquals('candidat_sans_note', $anomalies[0]['type']);
        $this->assertEquals('bloquante', $anomalies[0]['gravite']);
        $this->assertEquals($etudiant->id, $anomalies[0]['etudiant_id']);
    }

    public function test_detection_note_hors_bornes(): void
    {
        $epreuve = $this->creerEpreuve();
        $epreuve->update(['bareme' => 10.00]); // Barème fixé à 10
        $etudiant = $this->creerEtudiant();
        $candidat = $this->creerCandidat($epreuve, $etudiant, 'present');

        NoteEpreuve::create([
            'epreuve_id' => $epreuve->id,
            'etudiant_id' => $etudiant->id,
            'candidat_id' => $candidat->id,
            'valeur' => 14.00, // Supérieur au barème de 10
            'note_max' => 20.00,
            'coefficient' => 1.0,
            'statut' => 'entered',
        ]);

        $service = app(EvaluationQualityService::class);
        $anomalies = $service->detecterAnomalies($this->faculte->id, ['type' => 'note_hors_bornes']);

        $this->assertNotEmpty($anomalies);
        $this->assertEquals('bloquante', $anomalies[0]['gravite']);
        $this->assertStringContainsString('14', $anomalies[0]['libelle']);
    }

    public function test_detection_note_en_attente_et_resolution_groupee(): void
    {
        $epreuve = $this->creerEpreuve();
        $etudiant = $this->creerEtudiant();
        $candidat = $this->creerCandidat($epreuve, $etudiant, 'present');

        $note = NoteEpreuve::create([
            'epreuve_id' => $epreuve->id,
            'etudiant_id' => $etudiant->id,
            'candidat_id' => $candidat->id,
            'valeur' => 14.50,
            'note_max' => 20.00,
            'coefficient' => 1.0,
            'statut' => 'submitted', // En attente
        ]);

        $service = app(EvaluationQualityService::class);
        $anomalies = $service->detecterAnomalies($this->faculte->id, ['type' => 'note_en_attente']);

        $this->assertCount(1, $anomalies);
        $this->assertTrue($anomalies[0]['resoluble_automatiquement']);

        $resultat = $service->resoudreGroupee([$anomalies[0]['id']], $this->admin, 'valider');
        $this->assertEquals(1, $resultat['resolus']);
        $this->assertEquals('validated', $note->fresh()->statut);
    }

    public function test_acces_page_anomalies_et_export_csv(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.evaluations.anomalies.index'));
        $response->assertOk();
        $response->assertSee('Centre de contrôle des anomalies');

        $responseCsv = $this->actingAs($this->admin)->get(route('admin.evaluations.anomalies.export'));
        $responseCsv->assertOk();
        $this->assertStringContainsString('text/csv', (string) $responseCsv->headers->get('content-type'));
    }

    private function creerEpreuve(): Epreuve
    {
        $this->compteur++;
        $session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'S-QUAL-'.$this->compteur.'-'.random_int(100, 999),
            'nom' => 'Principale',
            'type' => 'principale',
            'date_debut' => '2027-01-05',
            'date_fin' => '2027-01-20',
            'statut' => 'open',
            'created_by' => $this->admin->id,
        ]);
        $examen = Examen::create([
            'session_id' => $session->id,
            'annee_universitaire_id' => $this->annee->id,
            'libelle' => 'Examen S1',
            'type' => 'examen',
            'statut' => 'scheduled',
            'responsable_id' => $this->admin->id,
        ]);

        return app(EpreuveService::class)->creer($examen, [
            'session_id' => $session->id,
            'matiere_id' => $this->matiere->id,
            'groupe_id' => $this->groupe->id,
            'date' => '2027-01-'.sprintf('%02d', 10 + $this->compteur),
            'heure_debut' => '09:00',
            'heure_fin' => '11:00',
        ]);
    }

    private function creerEtudiant(): Etudiant
    {
        static $compteurEtudiant = 200000;
        $suffixe = ++$compteurEtudiant;

        $etudiant = Etudiant::create([
            'cin' => 'NQ'.$suffixe,
            'nom' => 'Benali',
            'prenom' => 'Sara',
            'email' => 'sara.'.$suffixe.'@test.ma',
            'date_naissance' => '2004-01-01',
            'sexe' => 'F',
            'faculte_id' => $this->faculte->id,
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
        $inscription->semestres()->create(['numero' => 2]);

        return $etudiant;
    }

    private function creerCandidat(Epreuve $epreuve, Etudiant $etudiant, string $statut): Candidat
    {
        return Candidat::create([
            'epreuve_id' => $epreuve->id,
            'etudiant_id' => $etudiant->id,
            'statut' => $statut,
        ]);
    }
}
