<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Batiment;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Teacher;
use App\Modules\Schedule\Services\ExamScheduleEngineService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchedulePhase12ExamensExtensionTest extends TestCase
{
    use RefreshDatabase;

    protected Faculte $faculte;

    protected Group $groupe;

    protected AnneeUniversitaire $annee;

    protected Batiment $batiment;

    protected Matiere $matiereAlgo;

    protected Matiere $matiereWeb;

    protected Teacher $prof1;

    protected Teacher $prof2;

    protected Room $amphi;

    protected Room $petiteSalle;

    protected Examen $examenSession;

    protected ExamScheduleEngineService $examService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences Test P12',
            'code' => 'FST_P12_'.uniqid(),
            'actif' => true,
        ]);

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027-P12-'.uniqid(),
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->batiment = Batiment::create([
            'nom' => 'Bâtiment Principal P12',
            'code' => 'BAT_P12_'.uniqid(),
            'faculte_id' => $this->faculte->id,
            'actif' => true,
        ]);

        $this->groupe = Group::create([
            'nom' => 'Groupe L3 Info P12',
            'code' => 'L3_P12_'.uniqid(),
            'niveau' => 'L3',
            'capacite' => 30, // 30 candidats
            'faculte_id' => $this->faculte->id,
            'actif' => true,
        ]);

        $codeAlgo = 'ALG_P12_'.substr(uniqid(), -6);
        $this->matiereAlgo = Matiere::create([
            'code' => $codeAlgo,
            'nom' => 'Algorithmique P12',
            'niveau' => 'L3',
            'semestre' => 1,
            'faculte_id' => $this->faculte->id,
        ]);

        $codeWeb = 'WEB_P12_'.substr(uniqid(), -6);
        $this->matiereWeb = Matiere::create([
            'code' => $codeWeb,
            'nom' => 'Web P12',
            'niveau' => 'L3',
            'semestre' => 1,
            'faculte_id' => $this->faculte->id,
        ]);

        $this->prof1 = Teacher::create([
            'matricule' => 'ENS-P12-01-'.substr(uniqid(), -6),
            'nom' => 'Kacem',
            'prenom' => 'Imed',
            'email' => 'imed.kacem@test.tn',
            'charge_statutaire' => 192.0,
            'actif' => true,
        ]);

        $this->prof2 = Teacher::create([
            'matricule' => 'ENS-P12-02-'.substr(uniqid(), -6),
            'nom' => 'Mrad',
            'prenom' => 'Fatma',
            'email' => 'fatma.mrad@test.tn',
            'charge_statutaire' => 192.0,
            'actif' => true,
        ]);

        // Amphi : capacité 80 -> capacité d'examen à 50% = 40 places (suffisant pour groupe de 30)
        $this->amphi = Room::create([
            'code' => 'AMPHI_P12_'.uniqid(),
            'nom' => 'Amphithéâtre A P12',
            'capacite' => 80,
            'min_surveillants' => 2,
            'batiment_id' => $this->batiment->id,
            'faculte_id' => $this->faculte->id,
            'actif' => true,
        ]);

        // Petite salle : capacité 40 -> capacité d'examen à 50% = 20 places (insuffisant pour groupe de 30 !)
        $this->petiteSalle = Room::create([
            'code' => 'SALLE_P12_'.uniqid(),
            'nom' => 'Salle TD 10 P12',
            'capacite' => 40,
            'min_surveillants' => 1,
            'batiment_id' => $this->batiment->id,
            'faculte_id' => $this->faculte->id,
            'actif' => true,
        ]);

        $session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'SESS_P12_'.substr(uniqid(), -6),
            'nom' => 'Session Principale P12',
            'type' => 'principale',
            'date_debut' => '2027-01-15',
            'date_fin' => '2027-01-31',
            'statut' => 'open',
        ]);

        $admin = User::factory()->create();
        $this->examenSession = Examen::create([
            'session_id' => $session->id,
            'code' => 'EXAM_S1_P12_'.uniqid(),
            'annee_universitaire_id' => $this->annee->id,
            'libelle' => 'Session Principale S1 P12',
            'type' => 'examen',
            'statut' => 'planned',
            'responsable_id' => $admin->id,
        ]);

        $this->examService = app(ExamScheduleEngineService::class);
    }

    public function test_calcul_capacite_utile_examen_avec_ratio_espacement(): void
    {
        // 80 places nominales * 0.5 = 40 places examen
        $this->assertSame(40, $this->examService->capaciteUtileExamen($this->amphi, 0.5));

        // 40 places nominales * 0.5 = 20 places examen
        $this->assertSame(20, $this->examService->capaciteUtileExamen($this->petiteSalle, 0.5));
    }

    public function test_planification_epreuve_reussie_avec_salle_et_horaires(): void
    {
        $epreuve = $this->examService->planifierEpreuve([
            'examen_id' => $this->examenSession->id,
            'matiere_id' => $this->matiereAlgo->id,
            'groupe_id' => $this->groupe->id,
            'salle_id' => $this->amphi->id,
            'date' => '2027-01-18',
            'heure_debut' => '08:30:00',
            'heure_fin' => '10:30:00',
            'duree_minutes' => 120,
        ]);

        $this->assertInstanceOf(Epreuve::class, $epreuve);
        $this->assertSame('scheduled', $epreuve->statut);
        $this->assertSame('2027-01-18', $epreuve->date->format('Y-m-d'));
        $this->assertSame('08:30:00', $epreuve->heure_debut);
    }

    public function test_planification_epreuve_rejetee_si_salle_sous_capacitaire_avec_espacement(): void
    {
        // Groupe de 30 candidats dans une salle de 40 places (utile 20 en examen)
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage("Capacité d'examen insuffisante");

        $this->examService->planifierEpreuve([
            'examen_id' => $this->examenSession->id,
            'matiere_id' => $this->matiereAlgo->id,
            'groupe_id' => $this->groupe->id,
            'salle_id' => $this->petiteSalle->id,
            'date' => '2027-01-18',
            'heure_debut' => '08:30:00',
            'heure_fin' => '10:30:00',
        ]);
    }

    public function test_planification_epreuve_rejetee_si_conflit_etudiant_ou_salle_occupee(): void
    {
        // Première épreuve planifiée
        $this->examService->planifierEpreuve([
            'examen_id' => $this->examenSession->id,
            'matiere_id' => $this->matiereAlgo->id,
            'groupe_id' => $this->groupe->id,
            'salle_id' => $this->amphi->id,
            'date' => '2027-01-18',
            'heure_debut' => '08:30:00',
            'heure_fin' => '10:30:00',
        ]);

        // Tentative de programmer un autre examen pour le même groupe au même moment (dans une autre salle ou sans salle)
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('déjà une épreuve programmée');

        $this->examService->planifierEpreuve([
            'examen_id' => $this->examenSession->id,
            'matiere_id' => $this->matiereWeb->id,
            'groupe_id' => $this->groupe->id,
            'salle_id' => null,
            'date' => '2027-01-18',
            'heure_debut' => '09:00:00',
            'heure_fin' => '11:00:00',
        ]);
    }

    public function test_affectation_surveillants_valide_quotas_et_rejette_conflits(): void
    {
        $epreuve1 = $this->examService->planifierEpreuve([
            'examen_id' => $this->examenSession->id,
            'matiere_id' => $this->matiereAlgo->id,
            'groupe_id' => $this->groupe->id,
            'salle_id' => $this->amphi->id, // min_surveillants = 2
            'date' => '2027-01-18',
            'heure_debut' => '08:30:00',
            'heure_fin' => '10:30:00',
        ]);

        // Affectation réussie des 2 surveillants requis
        $surveillances = $this->examService->affecterSurveillants($epreuve1, [
            $this->prof1->id,
            $this->prof2->id,
        ]);

        $this->assertCount(2, $surveillances);
        $this->assertSame('principal', $surveillances[0]->role);
        $this->assertSame('surveillant', $surveillances[1]->role);

        // Seconde épreuve dans une autre salle sur la même plage
        $autreGroupe = Group::create([
            'nom' => 'Groupe L3 Math P12',
            'code' => 'L3_MATH_'.uniqid(),
            'niveau' => 'L3',
            'capacite' => 15,
            'faculte_id' => $this->faculte->id,
            'actif' => true,
        ]);
        $epreuve2 = $this->examService->planifierEpreuve([
            'examen_id' => $this->examenSession->id,
            'matiere_id' => $this->matiereWeb->id,
            'groupe_id' => $autreGroupe->id,
            'salle_id' => $this->petiteSalle->id,
            'date' => '2027-01-18',
            'heure_debut' => '08:30:00',
            'heure_fin' => '10:30:00',
            'capacite_necessaire' => 15,
        ]);

        // Tentative d'affecter prof1 à la seconde épreuve alors qu'il surveille déjà epreuve1
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('déjà affecté à la surveillance');

        $this->examService->affecterSurveillants($epreuve2, [$this->prof1->id]);
    }

    public function test_suggestion_creneaux_examens_propose_plages_sans_conflit(): void
    {
        $suggestions = $this->examService->suggererCreneauxExamens(
            $this->groupe,
            $this->matiereAlgo,
            '2027-01-18',
            '2027-01-25',
            120
        );

        $this->assertNotEmpty($suggestions);
        $this->assertSame($this->amphi->id, $suggestions[0]['salle_recommandee']->id);
        $this->assertGreaterThanOrEqual(80, $suggestions[0]['score_confort']);
    }

    public function test_audit_conformite_session_examens_detecte_manquements(): void
    {
        // 1. Épreuve sans salle
        Epreuve::create([
            'code' => 'EPR_SANS_SALLE',
            'examen_id' => $this->examenSession->id,
            'session_id' => $this->examenSession->session_id,
            'annee_universitaire_id' => $this->annee->id,
            'matiere_id' => $this->matiereAlgo->id,
            'groupe_id' => $this->groupe->id,
            'salle_id' => null,
            'date' => '2027-01-18',
            'heure_debut' => '08:30:00',
            'heure_fin' => '10:30:00',
            'statut' => 'scheduled',
        ]);

        $audit = $this->examService->auditerSessionExamens($this->examenSession);

        $this->assertFalse($audit['conforme']);
        $this->assertSame(1, $audit['epreuves_sans_salle']);
        $this->assertNotEmpty($audit['conflits_bloquants']);
    }
}
