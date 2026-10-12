<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Enseignement\Models\AffectationEnseignement;
use App\Modules\Enseignement\Models\Enseignement;
use App\Modules\Enseignement\Models\TypeEnseignement;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Models\Surveillance;
use App\Modules\Evaluation\Services\ContraintesSurveillance;
use App\Modules\Evaluation\Services\SurveillanceEquilibrageService;
use App\Modules\Referentiel\Models\Departement;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests d'intégration et d'optimisation de la charge d'enseignement
 * dans le moteur d'affectation automatique des surveillances.
 * Couvre les 10 exigences du cahier des charges LOOP.
 */
class SurveillanceChargeEnseignementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private AnneeUniversitaire $annee;

    private AnneeUniversitaire $autreAnnee;

    private SessionExamen $session;

    private Programme $programmeA;

    private Programme $programmeB;

    private Group $groupe;

    private Room $salle;

    private Matiere $matiere;

    private Examen $examen;

    private TypeEnseignement $typeCm;

    private TypeEnseignement $typeTd;

    private TypeEnseignement $typeTp;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.evaluation.active' => true]);
        config(['modules.enseignement.active' => true]);

        $this->faculteA = Faculte::create(['nom' => 'Faculté des Sciences', 'code' => 'FS', 'active' => true]);
        $this->faculteB = Faculte::create(['nom' => 'Faculté de Droit', 'code' => 'FD', 'active' => true]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Admin Scolarité', 'email' => 'admin.surv@univ.test', 'password' => 'Password@123',
        ], 'admin_scolarite');

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->autreAnnee = AnneeUniversitaire::create([
            'libelle' => '2025-2026', 'date_debut' => '2025-09-01', 'date_fin' => '2026-06-30', 'active' => false,
        ]);

        $this->session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'SESS-2027-P1',
            'nom' => 'Session Principale S1',
            'type' => 'principale',
            'statut' => 'open',
            'date_debut' => '2027-01-10',
            'date_fin' => '2027-01-20',
        ]);

        $this->programmeA = Programme::create([
            'code' => 'PRG-INFO', 'nom' => 'Informatique', 'faculte_id' => $this->faculteA->id,
            'cycle' => 'licence', 'duree_annees' => 3, 'actif' => true,
        ]);
        $this->programmeB = Programme::create([
            'code' => 'PRG-DROIT', 'nom' => 'Droit Privé', 'faculte_id' => $this->faculteB->id,
            'cycle' => 'licence', 'duree_annees' => 3, 'actif' => true,
        ]);

        $this->groupe = Group::create([
            'faculte_id' => $this->faculteA->id, 'programme_id' => $this->programmeA->id,
            'code' => 'L1-INFO-G1', 'nom' => 'Groupe 1', 'niveau' => 'L1', 'actif' => true,
        ]);

        $this->salle = Room::create([
            'code' => 'AMPHI-A', 'nom' => 'Grand Amphi', 'capacite' => 150,
            'faculte_id' => $this->faculteA->id, 'actif' => true,
        ]);

        $this->matiere = Matiere::create([
            'code' => 'MAT-ALGO', 'nom' => 'Algorithmique', 'niveau' => 'L1', 'semestre' => 1,
            'coefficient' => 2, 'credits' => 4, 'volume_cm' => 20,
        ]);

        $this->examen = Examen::create([
            'annee_universitaire_id' => $this->annee->id, 'session_id' => $this->session->id,
            'code' => 'EXAM-ALGO', 'libelle' => 'Examen Algo', 'type' => 'ecrit', 'statut' => 'publie',
        ]);

        $this->typeCm = TypeEnseignement::create(['code' => 'CM', 'nom' => 'Cours Magistral', 'actif' => true]);
        $this->typeTd = TypeEnseignement::create(['code' => 'TD', 'nom' => 'Travaux Dirigés', 'actif' => true]);
        $this->typeTp = TypeEnseignement::create(['code' => 'TP', 'nom' => 'Travaux Pratiques', 'actif' => true]);
    }

    private function creerEnseignant(string $nom, string $email, ?Faculte $faculte = null): Teacher
    {
        $faculte ??= $this->faculteA;
        $dep = Departement::firstOrCreate(
            ['code' => 'DEP-'.$faculte->code],
            ['nom' => 'Département '.$faculte->nom, 'faculte_id' => $faculte->id, 'actif' => true]
        );

        return Teacher::create([
            'departement_id' => $dep->id,
            'nom' => $nom,
            'prenom' => 'Prof',
            'email' => $email,
            'matricule' => 'ENS-'.uniqid(),
            'statut' => 'titulaire',
            'actif' => true,
        ]);
    }

    private function creerEpreuve(string $date = '2027-01-12', string $debut = '08:30:00', string $fin = '10:30:00'): Epreuve
    {
        return Epreuve::create([
            'annee_universitaire_id' => $this->annee->id,
            'session_id' => $this->session->id,
            'examen_id' => $this->examen->id,
            'matiere_id' => $this->matiere->id,
            'groupe_id' => $this->groupe->id,
            'salle_id' => $this->salle->id,
            'code' => 'EPR-'.uniqid(),
            'date' => $date,
            'heure_debut' => $debut,
            'heure_fin' => $fin,
            'duree_minutes' => 120,
            'type_evaluation' => 'examen',
            'coefficient' => 2,
            'bareme' => 20,
            'note_max' => 20,
            'capacite_necessaire' => 20,
            'statut' => 'scheduled',
        ]);
    }

    private function affecterCours(Teacher $teacher, float $volume, TypeEnseignement $type, ?AnneeUniversitaire $annee = null, ?Programme $prog = null): AffectationEnseignement
    {
        $annee ??= $this->annee;
        $prog ??= $this->programmeA;

        $matiere = Matiere::create([
            'code' => 'MAT-'.uniqid(),
            'nom' => 'Matière '.uniqid(),
            'niveau' => 'L1',
            'semestre' => 1,
            'coefficient' => 2,
            'credits' => 4,
            'volume_cm' => 20,
        ]);

        $enseignement = Enseignement::create([
            'code' => 'ENS-'.uniqid(),
            'matiere_id' => $matiere->id,
            'type_id' => $type->id,
            'annee_universitaire_id' => $annee->id,
            'programme_id' => $prog->id,
            'statut' => 'active',
            'cree_par' => $this->admin->id,
        ]);

        return AffectationEnseignement::create([
            'enseignement_id' => $enseignement->id,
            'teacher_id' => $teacher->id,
            'role' => 'enseignant',
            'volume_heures' => $volume,
            'statut' => 'active',
            'auteur_id' => $this->admin->id,
        ]);
    }

    /**
     * 1. Le critère désactivé préserve le comportement antérieur (zéro impact).
     */
    public function test_01_critere_desactive_preserve_comportement_anterieur(): void
    {
        $ens1 = $this->creerEnseignant('Alpha', 'alpha@univ.test');
        $ens2 = $this->creerEnseignant('Beta', 'beta@univ.test');
        $this->creerEpreuve();

        // Alpha a 200h de cours, Beta a 0h
        $this->affecterCours($ens1, 200.0, $this->typeCm);

        $service = app(SurveillanceEquilibrageService::class);

        // Simulation sans le critère actif
        $contraintesSans = new ContraintesSurveillance(
            minSurveillants: 1,
            ratioEtudiants: 50,
            nbGardes: 0,
            actifChargeEnseignement: false,
            poidsChargeEnseignement: 10.0
        );

        $simSans = $service->simulerSession($this->session, [
            'faculte_id' => $this->faculteA->id,
            'contraintes' => $contraintesSans,
        ]);

        $this->assertSame('REALISABLE', $simSans['statut']);
        $this->assertFalse($simSans['statistiques_enseignement']['actif']);
    }

    /**
     * 2. Le critère activé influence réellement l'optimisation et la répartition.
     */
    public function test_02_critere_active_influence_optimisation_et_repartition(): void
    {
        // 3 enseignants : Charge (200h), Moyen (50h), Léger (0h)
        $ensLourd = $this->creerEnseignant('Lourd', 'lourd@univ.test');
        $ensMoyen = $this->creerEnseignant('Moyen', 'moyen@univ.test');
        $ensLeger = $this->creerEnseignant('Leger', 'leger@univ.test');

        $this->affecterCours($ensLourd, 200.0, $this->typeCm);
        $this->affecterCours($ensMoyen, 50.0, $this->typeTd);

        // 1 épreuve nécessitant 1 principal + 1 garde
        $this->creerEpreuve('2027-01-12', '08:30:00', '10:30:00');

        $service = app(SurveillanceEquilibrageService::class);

        $contraintesActives = new ContraintesSurveillance(
            minSurveillants: 1,
            ratioEtudiants: 50,
            nbGardes: 1,
            actifChargeEnseignement: true,
            poidsChargeEnseignement: 15.0
        );

        $simulation = $service->simulerSession($this->session, [
            'faculte_id' => $this->faculteA->id,
            'contraintes' => $contraintesActives,
        ]);

        $this->assertSame('REALISABLE', $simulation['statut']);
        $this->assertTrue($simulation['statistiques_enseignement']['actif']);

        $bilan = collect($simulation['bilan_enseignants'])->keyBy('teacher_id');

        // L'enseignant le plus léger doit être privilégié
        $this->assertGreaterThan(
            $bilan[$ensLourd->id]['charge'],
            $bilan[$ensLeger->id]['charge'] + 0.01
        );
        $this->assertSame(0, $bilan[$ensLourd->id]['examens']);
    }

    /**
     * 3. Une charge élevée modifie le classement de deux enseignants autrement comparables.
     */
    public function test_03_charge_elevee_modifie_classement_deux_enseignants_comparables(): void
    {
        $ensA = $this->creerEnseignant('ProfA', 'profa@univ.test');
        $ensB = $this->creerEnseignant('ProfB', 'profb@univ.test');

        // ProfA a 150h, ProfB a 10h
        $this->affecterCours($ensA, 150.0, $this->typeCm);
        $this->affecterCours($ensB, 10.0, $this->typeTd);

        // Une seule surveillance à pourvoir (1 actif, 0 garde)
        $this->creerEpreuve('2027-01-14', '09:00:00', '11:00:00');

        $service = app(SurveillanceEquilibrageService::class);

        $contraintes = new ContraintesSurveillance(
            minSurveillants: 1,
            ratioEtudiants: 50,
            nbGardes: 0,
            actifChargeEnseignement: true,
            poidsChargeEnseignement: 10.0
        );

        $sim = $service->simulerSession($this->session, [
            'faculte_id' => $this->faculteA->id,
            'contraintes' => $contraintes,
        ]);

        $bilan = collect($sim['bilan_enseignants'])->keyBy('teacher_id');

        // ProfB avec une charge d'enseignement nettement plus faible doit être affecté
        $this->assertSame(1, $bilan[$ensB->id]['examens']);
        $this->assertSame(0, $bilan[$ensA->id]['examens']);
    }

    /**
     * 4. Les quotas d'affectation partagée évitent le double comptage.
     */
    public function test_04_quotas_affectation_partagee_evitent_double_comptage(): void
    {
        $ens1 = $this->creerEnseignant('Binome1', 'binome1@univ.test');
        $ens2 = $this->creerEnseignant('Binome2', 'binome2@univ.test');

        // Un enseignement partagé de 60h au total : 20h pour Binome1, 40h pour Binome2
        $enseignement = Enseignement::create([
            'code' => 'ENS-PARTAGE-1',
            'matiere_id' => $this->matiere->id,
            'type_id' => $this->typeCm->id,
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programmeA->id,
            'volume_cm' => 60,
            'statut' => 'active',
            'cree_par' => $this->admin->id,
        ]);

        AffectationEnseignement::create([
            'enseignement_id' => $enseignement->id,
            'teacher_id' => $ens1->id,
            'role' => 'enseignant',
            'volume_heures' => 20.0,
            'statut' => 'active',
            'auteur_id' => $this->admin->id,
        ]);

        AffectationEnseignement::create([
            'enseignement_id' => $enseignement->id,
            'teacher_id' => $ens2->id,
            'role' => 'intervenant',
            'volume_heures' => 40.0,
            'statut' => 'active',
            'auteur_id' => $this->admin->id,
        ]);

        $service = app(SurveillanceEquilibrageService::class);
        $charges = $service->chargesEnseignement([$ens1->id, $ens2->id], $this->annee->id, $this->faculteA->id);

        $this->assertSame(20.0, $charges['heures'][$ens1->id]);
        $this->assertSame(40.0, $charges['heures'][$ens2->id]);
        $this->assertSame(1.0, $charges['couverture']);
        $this->assertSame(0, $charges['sans_donnees']);
    }

    /**
     * 5. Les enseignements d'une autre année ou d'une autre faculté ne contaminent pas le calcul.
     */
    public function test_05_enseignements_autre_annee_ou_faculte_ne_contaminent_pas_le_calcul(): void
    {
        $ens = $this->creerEnseignant('MultiFac', 'multifac@univ.test', $this->faculteA);

        // Affectation dans l'année courante et la faculté A : 30h
        $this->affecterCours($ens, 30.0, $this->typeTd, $this->annee, $this->programmeA);

        // Affectation dans l'année précédente (2025-2026) : 100h (doit être ignorée)
        $this->affecterCours($ens, 100.0, $this->typeTd, $this->autreAnnee, $this->programmeA);

        // Affectation dans une autre faculté (Faculté B) : 80h (doit être ignorée sous scope faculteA)
        $this->affecterCours($ens, 80.0, $this->typeTd, $this->annee, $this->programmeB);

        $service = app(SurveillanceEquilibrageService::class);
        $charges = $service->chargesEnseignement([$ens->id], $this->annee->id, $this->faculteA->id);

        $this->assertSame(30.0, $charges['heures'][$ens->id]);
    }

    /**
     * 6. Les données manquantes sont traitées explicitement sans crash ni extrapolation arbitraire.
     */
    public function test_06_donnees_manquantes_traitees_explicitement(): void
    {
        $ensAvec = $this->creerEnseignant('AvecCours', 'aveccours@univ.test');
        $ensSans = $this->creerEnseignant('SansCours', 'sanscours@univ.test');

        $this->affecterCours($ensAvec, 50.0, $this->typeCm);

        $service = app(SurveillanceEquilibrageService::class);
        $charges = $service->chargesEnseignement([$ensAvec->id, $ensSans->id], $this->annee->id, $this->faculteA->id);

        $this->assertSame(50.0, $charges['heures'][$ensAvec->id]);
        $this->assertSame(0.0, $charges['heures'][$ensSans->id]);
        $this->assertSame(1, $charges['sans_donnees']);
        $this->assertSame(0.5, $charges['couverture']);

        $this->creerEpreuve();

        $contraintes = new ContraintesSurveillance(
            actifChargeEnseignement: true,
            poidsChargeEnseignement: 5.0
        );

        $sim = $service->simulerSession($this->session, [
            'faculte_id' => $this->faculteA->id,
            'contraintes' => $contraintes,
        ]);

        // Avertissement de données partielles dans les dégradations contrôlées
        $degradations = implode(' ', $sim['contraintes_souples_degradees']);
        $this->assertStringContainsString("Données de charge d'enseignement partielles", $degradations);
    }

    /**
     * 7. Les contraintes HARD restent strictement prioritaires.
     */
    public function test_07_contraintes_hard_restent_strictement_prioritaires(): void
    {
        // EnsDispo a une charge d'enseignement ÉNORME (300h)
        $ensDispo = $this->creerEnseignant('ProfDispo', 'dispo@univ.test');
        $this->affecterCours($ensDispo, 300.0, $this->typeCm);

        // EnsConflit a 0h de charge d'enseignement mais est en conflit horaire de cours (ScheduleSlot)
        $ensConflit = $this->creerEnseignant('ProfConflit', 'conflit@univ.test');

        $dateEpreuve = '2027-01-12'; // un mardi
        $this->creerEpreuve($dateEpreuve, '08:30:00', '10:30:00');

        // Créer un cours régulier pour ensConflit sur ce créneau (Tuesday)
        $schedule = Schedule::create([
            'annee_universitaire_id' => $this->annee->id,
            'semestre' => 1,
            'groupe_id' => $this->groupe->id,
            'nom' => 'EDT L1',
            'statut' => 'publie',
        ]);

        ScheduleSlot::create([
            'schedule_id' => $schedule->id,
            'matiere_id' => $this->matiere->id,
            'teacher_id' => $ensConflit->id,
            'day_of_week' => 'tuesday',
            'start_time' => '08:00:00',
            'end_time' => '10:00:00',
        ]);

        $service = app(SurveillanceEquilibrageService::class);
        $contraintes = new ContraintesSurveillance(
            minSurveillants: 1,
            ratioEtudiants: 50,
            nbGardes: 0,
            actifChargeEnseignement: true,
            poidsChargeEnseignement: 20.0
        );

        $sim = $service->simulerSession($this->session, [
            'faculte_id' => $this->faculteA->id,
            'contraintes' => $contraintes,
        ]);

        $bilan = collect($sim['bilan_enseignants'])->keyBy('teacher_id');

        // EnsDispo DOIT être affecté malgré sa charge énorme car EnsConflit est bloqué par contrainte HARD
        $this->assertSame(1, $bilan[$ensDispo->id]['examens']);
        $this->assertSame(0, $bilan[$ensConflit->id]['examens']);
    }

    /**
     * 8. Même jeu de données = même résultat (déterminisme et reproductibilité).
     */
    public function test_08_determinisme_et_snapshot_reproductible(): void
    {
        $ens1 = $this->creerEnseignant('Ens1', 'ens1@univ.test');
        $ens2 = $this->creerEnseignant('Ens2', 'ens2@univ.test');
        $ens3 = $this->creerEnseignant('Ens3', 'ens3@univ.test');

        $this->affecterCours($ens1, 100.0, $this->typeCm);
        $this->affecterCours($ens2, 40.0, $this->typeTd);
        $this->affecterCours($ens3, 0.0, $this->typeTp);

        $this->creerEpreuve('2027-01-12');
        $this->creerEpreuve('2027-01-13');

        $service = app(SurveillanceEquilibrageService::class);
        $contraintes = new ContraintesSurveillance(
            actifChargeEnseignement: true,
            poidsChargeEnseignement: 8.0
        );

        $simul1 = $service->simulerSession($this->session, ['faculte_id' => $this->faculteA->id, 'contraintes' => $contraintes]);
        $simul2 = $service->simulerSession($this->session, ['faculte_id' => $this->faculteA->id, 'contraintes' => $contraintes]);

        $this->assertSame(
            collect($simul1['bilan_enseignants'])->pluck('charge', 'teacher_id')->all(),
            collect($simul2['bilan_enseignants'])->pluck('charge', 'teacher_id')->all()
        );
        $this->assertSame(
            collect($simul1['propositions_epreuves'])->pluck('surveillants')->all(),
            collect($simul2['propositions_epreuves'])->pluck('surveillants')->all()
        );
    }

    /**
     * 9. L'intégration technique ne provoque aucun N+1 (1 seule requête d'agrégation).
     */
    public function test_09_absence_de_n_plus_un_sur_les_charges_enseignement(): void
    {
        $ids = [];
        for ($i = 1; $i <= 10; $i++) {
            $t = $this->creerEnseignant("Enseignant{$i}", "t{$i}@univ.test");
            $this->affecterCours($t, 10.0 * $i, $this->typeCm);
            $ids[] = $t->id;
        }

        $service = app(SurveillanceEquilibrageService::class);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $charges = $service->chargesEnseignement($ids, $this->annee->id, $this->faculteA->id);

        $nbQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Exactement 1 seule requête SQL exécutée pour agréger les 10 enseignants
        $this->assertSame(1, $nbQueries);
        $this->assertCount(10, $charges['heures']);
    }

    /**
     * 10. Le mode HETD distingue CM, TD, TP et applique le seuil de surcharge.
     */
    public function test_10_mode_hetd_distingue_cm_td_tp_et_seuil_surcharge(): void
    {
        $profCm = $this->creerEnseignant('ProfCm', 'cm@univ.test');
        $profTp = $this->creerEnseignant('ProfTp', 'tp@univ.test');

        // Les deux ont 100h brutes : ProfCm a 100h CM (150 HETD), ProfTp a 100h TP (75 HETD)
        $this->affecterCours($profCm, 100.0, $this->typeCm);
        $this->affecterCours($profTp, 100.0, $this->typeTp);

        $service = app(SurveillanceEquilibrageService::class);

        // Test en mode heures_brutes : charges identiques (100h vs 100h)
        $contraintesBrutes = new ContraintesSurveillance(
            modeCalculChargeEnseignement: 'heures_brutes'
        );
        $resBrut = $service->chargesEnseignement([$profCm->id, $profTp->id], $this->annee->id, $this->faculteA->id, $contraintesBrutes);
        $this->assertSame(100.0, $resBrut['valeurs_retenues'][$profCm->id]);
        $this->assertSame(100.0, $resBrut['valeurs_retenues'][$profTp->id]);

        // Test en mode hetd : 150h vs 75h
        $contraintesHetd = new ContraintesSurveillance(
            modeCalculChargeEnseignement: 'hetd',
            seuilSurchargeEnseignement: 120.0
        );
        $resHetd = $service->chargesEnseignement([$profCm->id, $profTp->id], $this->annee->id, $this->faculteA->id, $contraintesHetd);

        $this->assertSame(150.0, $resHetd['valeurs_retenues'][$profCm->id]);
        $this->assertSame(75.0, $resHetd['valeurs_retenues'][$profTp->id]);

        // ProfCm dépasse le seuil de surcharge (150 > 120), mais pas ProfTp (75 <= 120)
        $this->assertTrue($resHetd['surcharges'][$profCm->id]);
        $this->assertFalse($resHetd['surcharges'][$profTp->id]);
    }

    /**
     * 11. Le plafond maximal de surveillances par enseignant est strictement respecté.
     */
    public function test_11_plafond_max_surveillances_enseignant_respecte(): void
    {
        $ens1 = $this->creerEnseignant('Prof1', 'p1@univ.test');
        $ens2 = $this->creerEnseignant('Prof2', 'p2@univ.test');

        // Créer 4 épreuves sur 4 dates différentes
        $this->creerEpreuve('2027-01-11');
        $this->creerEpreuve('2027-01-12');
        $this->creerEpreuve('2027-01-13');
        $this->creerEpreuve('2027-01-14');

        $service = app(SurveillanceEquilibrageService::class);

        // Plafond max de 2 surveillances par enseignant
        $contraintes = new ContraintesSurveillance(
            minSurveillants: 1,
            ratioEtudiants: 50,
            nbGardes: 0,
            maxJours: 5,
            maxSurveillancesEnseignant: 2
        );

        $sim = $service->simulerSession($this->session, [
            'faculte_id' => $this->faculteA->id,
            'contraintes' => $contraintes,
        ]);

        $bilan = collect($sim['bilan_enseignants'])->keyBy('teacher_id');

        // Aucun enseignant ne doit dépasser 2 surveillances
        $this->assertLessThanOrEqual(2, $bilan[$ens1->id]['examens']);
        $this->assertLessThanOrEqual(2, $bilan[$ens2->id]['examens']);
        $this->assertSame(2, $bilan[$ens1->id]['examens']);
        $this->assertSame(2, $bilan[$ens2->id]['examens']);
    }

    /**
     * 12. L'optimiseur de l'existant (« Optimiser ») ne contourne JAMAIS
     * le plafond maximal : un transfert vers un enseignant déjà au plafond
     * est refusé par peutReprendreGarde (miroir de la règle 4c).
     */
    public function test_12_optimiseur_ne_contourne_pas_le_plafond_maximal(): void
    {
        $ens1 = $this->creerEnseignant('CapSurf1', 'capsurf1@univ.test');
        $ens2 = $this->creerEnseignant('CapSurf2', 'capsurf2@univ.test');

        $e1 = $this->creerEpreuve('2027-01-11');
        $e2 = $this->creerEpreuve('2027-01-12');
        $e3 = $this->creerEpreuve('2027-01-13');

        // ens1 : 3 postes (3.0 de charge) ; ens2 : 1 poste en RÉSERVE
        // (0.5 de charge pondérée), DÉJÀ au plafond de 1. Cas
        // discriminateur : une garde fondée sur la charge pondérée
        // (0.5 < 1) laisserait passer le transfert — seule la
        // comparaison en NOMBRE DE POSTES (miroir de la règle 4c) refuse.
        Surveillance::create(['epreuve_id' => $e1->id, 'teacher_id' => $ens1->id, 'role' => 'adjoint', 'statut' => 'confirme']);
        Surveillance::create(['epreuve_id' => $e1->id, 'teacher_id' => $ens2->id, 'role' => 'reserve', 'statut' => 'confirme']);
        Surveillance::create(['epreuve_id' => $e2->id, 'teacher_id' => $ens1->id, 'role' => 'adjoint', 'statut' => 'confirme']);
        Surveillance::create(['epreuve_id' => $e3->id, 'teacher_id' => $ens1->id, 'role' => 'adjoint', 'statut' => 'confirme']);

        $service = app(SurveillanceEquilibrageService::class);

        $resultat = $service->optimiserSession($this->session, [
            'faculte_id' => $this->faculteA->id,
            'contraintes' => new ContraintesSurveillance(maxSurveillancesEnseignant: 1),
        ], $this->admin->id);

        // Aucun transfert possible : le receveur (ens2) est au plafond.
        $this->assertSame(0, $resultat['ameliorees']);
        $this->assertSame(3, Surveillance::where('teacher_id', $ens1->id)->where('statut', '!=', 'annule')->count());
        $this->assertSame(1, Surveillance::where('teacher_id', $ens2->id)->where('statut', '!=', 'annule')->count());
    }

    /**
     * 13. Le critère activé pilote AUSSI l'optimiseur de l'existant :
     * un enseignant en surcharge d'enseignement cède ses gardes aux
     * moins chargés (transfert réel, coûts totaux et non charges brutes).
     */
    public function test_13_critere_actif_pousse_l_optimiseur_a_transferer_les_gardes_du_surcharge(): void
    {
        $tSurcharge = $this->creerEnseignant('Surcharge', 'surcharge@univ.test');
        $tNeutre = $this->creerEnseignant('Neutre', 'neutre@univ.test');
        $tLibre = $this->creerEnseignant('Libre', 'libre@univ.test');

        // 190h pour le surchargé (seuil 150), rien pour les deux autres.
        $this->affecterCours($tSurcharge, 190.0, $this->typeCm);

        $e1 = $this->creerEpreuve('2027-01-11');
        $e2 = $this->creerEpreuve('2027-01-12');

        Surveillance::create(['epreuve_id' => $e1->id, 'teacher_id' => $tSurcharge->id, 'role' => 'adjoint', 'statut' => 'confirme']);
        Surveillance::create(['epreuve_id' => $e2->id, 'teacher_id' => $tNeutre->id, 'role' => 'adjoint', 'statut' => 'confirme']);

        $service = app(SurveillanceEquilibrageService::class);

        // Critère désactivé : charges 1.0 / 1.0 / 0.0 → écart 1.0 ≤ 1.5 → rien ne bouge.
        $resultatInactif = $service->optimiserSession($this->session, [
            'faculte_id' => $this->faculteA->id,
            'contraintes' => new ContraintesSurveillance,
        ], $this->admin->id);
        $this->assertSame(0, $resultatInactif['ameliorees']);

        // Critère activé : coût total du surchargé (1.0 + 5.0 de norme + 7.5 de
        // malus de surcharge) domine → sa garde est transférée au libre.
        $resultatActif = $service->optimiserSession($this->session, [
            'faculte_id' => $this->faculteA->id,
            'contraintes' => new ContraintesSurveillance(
                actifChargeEnseignement: true,
                poidsChargeEnseignement: 5.0,
                seuilSurchargeEnseignement: 150.0
            ),
        ], $this->admin->id);

        $this->assertSame(1, $resultatActif['ameliorees']);
        $this->assertSame(0, Surveillance::where('teacher_id', $tSurcharge->id)->where('statut', '!=', 'annule')->count());
        $this->assertSame(1, Surveillance::where('teacher_id', $tNeutre->id)->where('statut', '!=', 'annule')->count());
        $this->assertSame(1, Surveillance::where('teacher_id', $tLibre->id)->where('statut', '!=', 'annule')->count());
    }

    /**
     * 15. Le statut « données de charge » est PAR ENSEIGNANT, jamais global :
     * un enseignant avec des heures enregistrées ne doit pas être étiqueté
     * « Sans affectation » parce que D'AUTRES enseignants n'ont pas de
     * données (diagnostic §6 : dire qui a des données, précisément).
     */
    public function test_15_statut_donnees_charge_est_par_enseignant_jamais_global(): void
    {
        $ensAvec = $this->creerEnseignant('AvecDonnees', 'avecdonnees@univ.test');
        $ensSans = $this->creerEnseignant('SansDonnees', 'sansdonnees@univ.test');

        // Couverture globale 0.5 (< 0.8) : l'un a 50h, l'autre rien.
        $this->affecterCours($ensAvec, 50.0, $this->typeCm);
        $this->creerEpreuve();

        $service = app(SurveillanceEquilibrageService::class);

        $sim = $service->simulerSession($this->session, [
            'faculte_id' => $this->faculteA->id,
            'contraintes' => new ContraintesSurveillance(
                actifChargeEnseignement: true,
                poidsChargeEnseignement: 5.0
            ),
        ]);

        $bilan = collect($sim['bilan_enseignants'])->keyBy('teacher_id');

        // Couverture globale dégradée mais l'enseignant AVEC données doit
        // être reconnu comme documenté, l'autre comme non documenté.
        $this->assertSame(0.5, $sim['statistiques_enseignement']['couverture']);
        $this->assertTrue($bilan[$ensAvec->id]['donnees_charge_completes']);
        $this->assertFalse($bilan[$ensSans->id]['donnees_charge_completes']);
    }

    /**
     * 14. Reproductibilité asynchrone : le snapshot de contraintes du Job
     * (versParams → config_snapshot → depuisParams) produit exactement le
     * même plan que les contraintes d'origine (§5 du cahier des charges).
     */
    public function test_14_snapshot_config_job_reproductible(): void
    {
        $ens1 = $this->creerEnseignant('Snap1', 'snap1@univ.test');
        $ens2 = $this->creerEnseignant('Snap2', 'snap2@univ.test');
        $ens3 = $this->creerEnseignant('Snap3', 'snap3@univ.test');

        $this->affecterCours($ens1, 200.0, $this->typeCm);
        $this->affecterCours($ens2, 50.0, $this->typeTd);
        $this->affecterCours($ens3, 0.0, $this->typeTp);

        $this->creerEpreuve('2027-01-12');
        $this->creerEpreuve('2027-01-13');

        $service = app(SurveillanceEquilibrageService::class);

        $contraintes = new ContraintesSurveillance(
            actifChargeEnseignement: true,
            poidsChargeEnseignement: 8.0,
            modeCalculChargeEnseignement: 'hetd',
            seuilSurchargeEnseignement: 180.0
        );

        // Round-trip exact du mécanisme de snapshot du Job.
        $snapshot = ContraintesSurveillance::depuisParams($contraintes->versParams());
        $this->assertEquals($contraintes, $snapshot);

        $simRef = $service->simulerSession($this->session, [
            'faculte_id' => $this->faculteA->id,
            'contraintes' => $contraintes,
        ]);
        $simSnapshot = $service->simulerSession($this->session, [
            'faculte_id' => $this->faculteA->id,
            'contraintes' => $snapshot,
        ]);

        $this->assertEquals($simRef['propositions_epreuves'], $simSnapshot['propositions_epreuves']);
        $this->assertEquals($simRef['bilan_enseignants'], $simSnapshot['bilan_enseignants']);
    }
}
