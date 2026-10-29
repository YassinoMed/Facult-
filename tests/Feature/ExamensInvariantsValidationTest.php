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
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Services\ConflitEpreuveService;
use App\Modules\Evaluation\Services\EpreuveService;
use App\Modules\Evaluation\Services\ExamRoomAssignmentService;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleAvailability;
use App\Modules\Schedule\Models\ScheduleSlot;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExamensInvariantsValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private AnneeUniversitaire $annee;

    private Matiere $matiere;

    private Group $groupe;

    private SessionExamen $session;

    private Examen $examen;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'modules.evaluation.active' => true,
            'modules.schedule.active' => true,
        ]);

        $this->faculteA = Faculte::create(['nom' => 'Faculté des Sciences', 'code' => 'FS_INV', 'active' => true]);
        $this->faculteB = Faculte::create(['nom' => 'Faculté de Droit', 'code' => 'FD_INV', 'active' => true]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Admin Test Examens',
            'email' => 'admin.examens@univ.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $programme = Programme::create([
            'faculte_id' => $this->faculteA->id,
            'code' => 'LGL_INV',
            'nom' => 'Licence GL',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
        ]);

        $niveau = Niveau::create(['programme_id' => $programme->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1]);
        $semestre = SemestreLmd::create(['niveau_id' => $niveau->id, 'code' => 'S1', 'nom' => 'S1', 'numero' => 1, 'ordre' => 1]);
        $ue = UniteEnseignement::create([
            'semestre_id' => $semestre->id,
            'programme_id' => $programme->id,
            'code' => 'UE_ALGO',
            'nom' => 'Algo',
            'credits' => 6,
            'coefficient' => 2,
        ]);

        $this->matiere = Matiere::create([
            'code' => 'ALGO_101',
            'nom' => 'Algorithmique 1',
            'ue_id' => $ue->id,
            'niveau' => 'L1',
            'semestre' => 1,
            'credits' => 6,
            'coefficient' => 2,
            'actif' => true,
        ]);

        $this->groupe = Group::create([
            'code' => 'GRP_INV',
            'nom' => 'Groupe GL',
            'faculte_id' => $this->faculteA->id,
            'programme_id' => $programme->id,
            'niveau' => 'L1',
            'actif' => true,
        ]);

        $this->session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'S_INV',
            'nom' => 'Session Janvier',
            'type' => 'principale',
            'date_debut' => '2027-01-05',
            'date_fin' => '2027-01-25',
            'statut' => 'draft',
        ]);

        $this->examen = Examen::create([
            'session_id' => $this->session->id,
            'programme_id' => $programme->id,
            'annee_universitaire_id' => $this->annee->id,
            'libelle' => 'Examen S1',
            'type' => 'examen',
            'statut' => 'scheduled',
        ]);
    }

    private function creerEpreuve(array $overrides = []): Epreuve
    {
        return Epreuve::create(array_merge([
            'examen_id' => $this->examen->id,
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'matiere_id' => $this->matiere->id,
            'groupe_id' => $this->groupe->id,
            'date' => '2027-01-12',
            'heure_debut' => '09:00',
            'heure_fin' => '11:00',
            'statut' => 'scheduled',
        ], $overrides));
    }

    private function creerCandidat(Epreuve $epreuve, string $nom, string $matricule): Candidat
    {
        $etudiant = Etudiant::create([
            'nom' => $nom,
            'prenom' => 'Test',
            'cin' => 'CIN_'.substr(md5($matricule), 0, 8),
            'num_inscription' => $matricule,
            'email' => strtolower($matricule).'@univ.test',
            'date_naissance' => '2004-01-01',
            'sexe' => 'M',
            'faculte_id' => $this->faculteA->id,
            'groupe_id' => $this->groupe->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => 'Rue de Test',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2024',
            'password' => 'Etudiant@123',
        ]);
        $etudiant->definirStatut('valide');

        return Candidat::create([
            'epreuve_id' => $epreuve->id,
            'etudiant_id' => $etudiant->id,
            'statut' => Candidat::STATUT_NON_POINTE,
        ]);
    }

    /** 1. Capacité null : affectation refusée (DM-01, EX-01) */
    public function test_affectation_salle_capacite_null_rejetee(): void
    {
        $epreuve = $this->creerEpreuve();
        $salle = Room::create(['code' => 'S_NULL', 'nom' => 'Salle Sans Capacité', 'capacite' => null, 'actif' => true]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('inconnue');

        app(ExamRoomAssignmentService::class)->affecterSalle($epreuve, $salle->id, $this->admin->id);
    }

    /** 2. Capacité nulle ou négative : affectation refusée (DM-01, EX-01) */
    public function test_affectation_salle_capacite_zero_ou_negative_rejetee(): void
    {
        $epreuve = $this->creerEpreuve();
        $salle = Room::create(['code' => 'S_ZERO', 'nom' => 'Salle Capa Zero', 'capacite' => 0, 'actif' => true]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('inconnue');

        app(ExamRoomAssignmentService::class)->affecterSalle($epreuve, $salle->id, $this->admin->id);
    }

    /** 3. Salle inactive : affectation refusée (DM-01, EX-02) */
    public function test_affectation_salle_inactive_rejetee(): void
    {
        $epreuve = $this->creerEpreuve();
        $salle = Room::create(['code' => 'S_INACT', 'nom' => 'Salle Inactive', 'capacite' => 40, 'actif' => false]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('inactive');

        app(ExamRoomAssignmentService::class)->affecterSalle($epreuve, $salle->id, $this->admin->id);
    }

    /** 4. Répartition incomplète : application refusée (DM-06, EX-03) */
    public function test_appliquer_repartition_refuse_affectation_partielle(): void
    {
        $epreuve = $this->creerEpreuve();
        $c1 = $this->creerCandidat($epreuve, 'Dupont', 'MAT_001');
        $c2 = $this->creerCandidat($epreuve, 'Martin', 'MAT_002');
        $salle = Room::create(['code' => 'S_OK', 'nom' => 'Salle Valide', 'capacite' => 50, 'actif' => true]);

        // Un seul candidat dans la répartition alors que 2 sont convoqués
        $repartition = [
            ['candidat_id' => $c1->id, 'salle_id' => $salle->id, 'place' => 'S_OK-01'],
        ];

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('incomplète');

        app(ExamRoomAssignmentService::class)->appliquerRepartition($epreuve, $repartition, $this->admin->id);
    }

    /** 5. Candidat hors épreuve ou dupliqué : application refusée (EX-03) */
    public function test_appliquer_repartition_refuse_candidat_duplique(): void
    {
        $epreuve = $this->creerEpreuve();
        $c1 = $this->creerCandidat($epreuve, 'Dupont', 'MAT_003');
        $salle = Room::create(['code' => 'S_OK2', 'nom' => 'Salle 2', 'capacite' => 50, 'actif' => true]);

        // Même candidat deux fois
        $repartition = [
            ['candidat_id' => $c1->id, 'salle_id' => $salle->id, 'place' => 'S_OK2-01'],
            ['candidat_id' => $c1->id, 'salle_id' => $salle->id, 'place' => 'S_OK2-02'],
        ];

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('plusieurs fois');

        app(ExamRoomAssignmentService::class)->appliquerRepartition($epreuve, $repartition, $this->admin->id);
    }

    /** 6. Place dupliquée dans la même salle : application refusée (EX-04) */
    public function test_appliquer_repartition_refuse_doublon_de_places(): void
    {
        $epreuve = $this->creerEpreuve();
        $c1 = $this->creerCandidat($epreuve, 'Dupont', 'MAT_004');
        $c2 = $this->creerCandidat($epreuve, 'Martin', 'MAT_005');
        $salle = Room::create(['code' => 'S_OK3', 'nom' => 'Salle 3', 'capacite' => 50, 'actif' => true]);

        // Même numéro de place pour deux candidats
        $repartition = [
            ['candidat_id' => $c1->id, 'salle_id' => $salle->id, 'place' => 'S_OK3-01'],
            ['candidat_id' => $c2->id, 'salle_id' => $salle->id, 'place' => 'S_OK3-01'],
        ];

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Place « S_OK3-01 » attribuée deux fois');

        app(ExamRoomAssignmentService::class)->appliquerRepartition($epreuve, $repartition, $this->admin->id);
    }

    /** 7. Dépassement de capacité par salle : application refusée (EX-04) */
    public function test_appliquer_repartition_refuse_depassement_capacite(): void
    {
        $epreuve = $this->creerEpreuve();
        $c1 = $this->creerCandidat($epreuve, 'Dupont', 'MAT_006');
        $c2 = $this->creerCandidat($epreuve, 'Martin', 'MAT_007');
        // Salle avec capacité 1 place
        $salle = Room::create(['code' => 'S_PETITE', 'nom' => 'Petite Salle', 'capacite' => 1, 'actif' => true]);

        $repartition = [
            ['candidat_id' => $c1->id, 'salle_id' => $salle->id, 'place' => 'S_PETITE-01'],
            ['candidat_id' => $c2->id, 'salle_id' => $salle->id, 'place' => 'S_PETITE-02'],
        ];

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Capacité dépassée');

        app(ExamRoomAssignmentService::class)->appliquerRepartition($epreuve, $repartition, $this->admin->id);
    }

    /** 8. Salle fermée sur le créneau : refusée (DM-04, EX-05) */
    public function test_affectation_hors_horaires_ouverture_salle_rejetee(): void
    {
        $epreuve = $this->creerEpreuve(['heure_debut' => '07:00', 'heure_fin' => '09:00']);
        // Salle ouvrant à 08:00
        $salle = Room::create([
            'code' => 'S_FERMEE', 'nom' => 'Salle Tardive',
            'capacite' => 40, 'actif' => true,
            'heure_ouverture' => '08:00', 'heure_fermeture' => '18:00',
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('fermée');

        app(ExamRoomAssignmentService::class)->affecterSalle($epreuve, $salle->id, $this->admin->id);
    }

    /** 9. Indisponibilité déclarée (ScheduleAvailability) : conflit détecté (DM-04, EX-06) */
    public function test_affectation_salle_indisponible_detecte_conflit(): void
    {
        $salle = Room::create(['code' => 'S_INDISPO', 'nom' => 'Salle Travaux', 'capacite' => 40, 'actif' => true]);

        // Mardi = tuesday (2027-01-12 est un mardi)
        ScheduleAvailability::create([
            'room_id' => $salle->id,
            'day_of_week' => 'tuesday',
            'start_time' => '08:00:00',
            'end_time' => '12:00:00',
            'type' => 'indisponible',
            'reason' => 'Maintenance électrique',
        ]);

        $conflits = app(ConflitEpreuveService::class)->detecter([
            'date' => '2027-01-12',
            'heure_debut' => '09:00',
            'heure_fin' => '11:00',
            'salle_id' => $salle->id,
        ]);

        $bloquants = app(ConflitEpreuveService::class)->bloquants($conflits);
        $this->assertNotEmpty($bloquants);
        $this->assertSame('salle_indisponible', $bloquants[0]['type']);
        $this->assertStringContainsString('Maintenance électrique', $bloquants[0]['message']);
    }

    /** 10. Chevauchement avec une autre épreuve : conflit détecté (DM-03) */
    public function test_conflit_chevauchement_deux_epreuves(): void
    {
        $salle = Room::create(['code' => 'S_COMMUNE', 'nom' => 'Salle Commune', 'capacite' => 50, 'actif' => true]);
        $epreuve1 = $this->creerEpreuve(['salle_id' => $salle->id, 'heure_debut' => '09:00', 'heure_fin' => '11:00']);

        $conflits = app(ConflitEpreuveService::class)->detecter([
            'date' => '2027-01-12',
            'heure_debut' => '10:00',
            'heure_fin' => '12:00',
            'salle_id' => $salle->id,
        ]);

        $bloquants = app(ConflitEpreuveService::class)->bloquants($conflits);
        $this->assertNotEmpty($bloquants);
        $this->assertSame('salle', $bloquants[0]['type']);
        $this->assertStringContainsString('déjà réservée', $bloquants[0]['message']);
    }

    /** 11. Chevauchement avec un cours planifié (ScheduleSlot) : conflit détecté (DM-03, EX-07) */
    public function test_conflit_avec_cours_planning_detecte(): void
    {
        $salle = Room::create(['code' => 'S_COURS', 'nom' => 'Salle Cours', 'capacite' => 50, 'actif' => true]);

        $planning = Schedule::create([
            'annee_universitaire_id' => $this->annee->id,
            'groupe_id' => $this->groupe->id,
            'semestre' => 1,
            'nom' => 'Planning L1',
            'actif' => true,
        ]);

        ScheduleSlot::create([
            'schedule_id' => $planning->id,
            'matiere_id' => $this->matiere->id,
            'room_id' => $salle->id,
            'day_of_week' => 'tuesday',
            'start_time' => '08:30:00',
            'end_time' => '10:30:00',
            'type' => 'course',
            'status' => 'scheduled',
        ]);

        $conflits = app(ConflitEpreuveService::class)->detecter([
            'date' => '2027-01-12', // mardi
            'heure_debut' => '09:00',
            'heure_fin' => '11:00',
            'salle_id' => $salle->id,
        ]);

        $bloquants = app(ConflitEpreuveService::class)->bloquants($conflits);
        $this->assertNotEmpty($bloquants);
        $this->assertSame('salle_planning', $bloquants[0]['type']);
        $this->assertStringContainsString('cours planifié', $bloquants[0]['message']);
    }

    /** 12. Deux facultés utilisant une même salle : conflit global (DM-03, EX-07) */
    public function test_salles_partagees_inter_facultes_conflit_global(): void
    {
        // Salle appartenant à la Faculté A
        $sallePartagee = Room::create([
            'code' => 'AMPHI_CENTRAL', 'nom' => 'Amphi Central',
            'faculte_id' => $this->faculteA->id,
            'capacite' => 120, 'actif' => true,
        ]);

        // Épreuve Faculté A
        $epreuveFacA = $this->creerEpreuve([
            'salle_id' => $sallePartagee->id,
            'date' => '2027-01-14',
            'heure_debut' => '14:00',
            'heure_fin' => '16:00',
        ]);

        // Tentative d'affectation par la Faculté B sur la même salle au même horaire
        $conflits = app(ConflitEpreuveService::class)->detecter([
            'date' => '2027-01-14',
            'heure_debut' => '14:00',
            'heure_fin' => '16:00',
            'salle_id' => $sallePartagee->id,
        ]);

        $bloquants = app(ConflitEpreuveService::class)->bloquants($conflits);
        $this->assertNotEmpty($bloquants);
        $this->assertSame('salle', $bloquants[0]['type']);
        $this->assertStringContainsString('déjà réservée', $bloquants[0]['message']);
    }

    /** 13. Créneaux strictement adjacents sans chevauchement : aucun faux conflit (DM-04) */
    public function test_creneaux_adjacents_sans_conflit(): void
    {
        $salle = Room::create(['code' => 'S_ADJ', 'nom' => 'Salle Adjacente', 'capacite' => 50, 'actif' => true]);
        $epreuve1 = $this->creerEpreuve(['salle_id' => $salle->id, 'heure_debut' => '08:00', 'heure_fin' => '10:00']);

        // Créneau débutant exactement à la fin du précédent (10:00)
        $conflits = app(ConflitEpreuveService::class)->detecter([
            'date' => '2027-01-12',
            'heure_debut' => '10:00',
            'heure_fin' => '12:00',
            'salle_id' => $salle->id,
        ]);

        $bloquants = app(ConflitEpreuveService::class)->bloquants($conflits);
        $this->assertEmpty($bloquants);
    }

    /** 14. Candidat nouvellement généré : statut non_pointe (DM-02, EX-08) */
    public function test_candidat_initialise_au_statut_non_pointe(): void
    {
        $epreuve = $this->creerEpreuve();
        $etudiant = Etudiant::create([
            'nom' => 'Ben Salem', 'prenom' => 'Karim',
            'cin' => 'CIN_KARIM', 'num_inscription' => 'MAT_KARIM',
            'email' => 'karim@univ.test',
            'date_naissance' => '2004-01-01', 'sexe' => 'M',
            'faculte_id' => $this->faculteA->id,
            'groupe_id' => $this->groupe->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027',
            'adresse' => 'Avenue Bourguiba', 'ville' => 'Tunis',
            'code_postal' => '1000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
        $etudiant->definirStatut('valide');
        $inscription = $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id, 'niveau' => 'L1', 'statut' => 'en_cours',
        ]);
        $inscription->pedagogiques()->create([
            'semestre_id' => $this->matiere->ue->semestre_id,
            'ue_id' => $this->matiere->ue_id,
            'matiere_id' => $this->matiere->id,
            'statut' => 'inscrit',
        ]);

        $service = app(EpreuveService::class);
        $crees = $service->genererCandidats($epreuve);

        $this->assertSame(1, $crees);
        $candidat = Candidat::where('epreuve_id', $epreuve->id)->where('etudiant_id', $etudiant->id)->firstOrFail();
        $this->assertSame(Candidat::STATUT_NON_POINTE, $candidat->statut);
        $this->assertFalse($candidat->estPointe());
        $this->assertFalse($candidat->estAbsent());
    }

    /** 15. Échec d'une affectation : rollback complet, aucune écriture partielle (DM-06, DM-08) */
    public function test_echec_repartition_provoque_rollback_sans_ecriture_partielle(): void
    {
        $epreuve = $this->creerEpreuve();
        $c1 = $this->creerCandidat($epreuve, 'Dupont', 'MAT_010');
        $c2 = $this->creerCandidat($epreuve, 'Martin', 'MAT_011');
        $salle = Room::create(['code' => 'S_ROLLBACK', 'nom' => 'Salle Rollback', 'capacite' => 50, 'actif' => true]);

        // Place dupliquée sur le 2e candidat provoquant l'exception
        $repartition = [
            ['candidat_id' => $c1->id, 'salle_id' => $salle->id, 'place' => 'S_ROLLBACK-01'],
            ['candidat_id' => $c2->id, 'salle_id' => $salle->id, 'place' => 'S_ROLLBACK-01'],
        ];

        try {
            app(ExamRoomAssignmentService::class)->appliquerRepartition($epreuve, $repartition, $this->admin->id);
            $this->fail('Une DomainException aurait dû être levée.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('attribuée deux fois', $e->getMessage());
        }

        // Vérification de l'intégrité : aucun des deux candidats n'a été affecté
        $this->assertNull($c1->fresh()->salle_id);
        $this->assertNull($c1->fresh()->place);
        $this->assertNull($c2->fresh()->salle_id);
        $this->assertNull($c2->fresh()->place);
    }

    /** 16. Revalidation complète côté serveur lors de l'application (DM-08, EX-08) */
    public function test_revalidation_serveur_rejette_si_salle_desactivee_apres_simulation(): void
    {
        $epreuve = $this->creerEpreuve();
        $c1 = $this->creerCandidat($epreuve, 'Dupont', 'MAT_020');
        $salle = Room::create(['code' => 'S_DESACT', 'nom' => 'Salle Validée', 'capacite' => 50, 'actif' => true]);

        $service = app(ExamRoomAssignmentService::class);
        $simulation = $service->simulerRepartition($epreuve, [$salle->id]);
        $this->assertTrue($simulation['valide']);

        // Modification concurrente : la salle est désactivée juste avant l'application
        $salle->update(['actif' => false]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('inactive');

        $service->appliquerRepartition($epreuve, $simulation['repartition'], $this->admin->id);
    }
}
