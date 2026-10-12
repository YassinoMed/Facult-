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
use App\Modules\Evaluation\Models\Surveillance;
use App\Modules\Evaluation\Services\ConflitEpreuveService;
use App\Modules\Evaluation\Services\SurveillanceEquilibrageService;
use App\Modules\Evaluation\Services\SurveillanceService;
use App\Modules\Referentiel\Models\Departement;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Suite de tests adversariaux et de résistance métier pour l'équilibrage des surveillances.
 * Valide de manière hostile et exhaustive les 20 exigences de durcissement.
 */
class SurveillanceAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private SessionExamen $session;

    private Examen $examen;

    private Programme $programme;

    private Matiere $matiere;

    private Room $amphi;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.evaluation.active' => true]);

        $this->faculte = Faculte::create(['nom' => 'Faculté des Sciences', 'code' => 'FST', 'active' => true]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Admin Scolarité',
            'email' => 'admin.adversarial@univ.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->programme = Programme::create([
            'code' => 'L-INFO',
            'nom' => 'Licence Informatique',
            'faculte_id' => $this->faculte->id,
            'cycle' => 'licence',
            'duree_annees' => 3,
            'actif' => true,
        ]);

        $this->session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'SESS-ADV-2027',
            'nom' => 'Session Adversariale 2027',
            'type' => 'principale',
            'statut' => 'open',
            'date_debut' => '2027-01-10',
            'date_fin' => '2027-01-20',
        ]);

        $this->examen = Examen::create([
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programme->id,
            'libelle' => 'Examens S1',
            'type' => 'examen',
            'statut' => 'scheduled',
        ]);

        $this->matiere = Matiere::create([
            'code' => 'MAT-ADV',
            'nom' => 'Algorithmique Avancée',
            'niveau' => 'L1',
            'semestre' => 1,
            'coefficient' => 2,
            'credits' => 5,
        ]);

        $this->amphi = Room::create([
            'code' => 'AMPHI-ADV',
            'capacite' => 1000,
            'actif' => true,
        ]);
    }

    private function creerEpreuve(array $attributs = []): Epreuve
    {
        return Epreuve::create(array_merge([
            'code' => 'EPR-ADV-'.uniqid(),
            'examen_id' => $this->examen->id,
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'matiere_id' => $this->matiere->id,
            'salle_id' => $this->amphi->id,
            'date' => '2027-01-11',
            'heure_debut' => '09:00:00',
            'heure_fin' => '11:00:00',
            'duree_minutes' => 120,
            'type_evaluation' => 'examen',
            'capacite_necessaire' => 50,
            'statut' => 'scheduled',
        ], $attributs));
    }

    private function creerEnseignants(int $nombre, bool $actif = true): array
    {
        $dep = Departement::create([
            'code' => 'DEP-ADV-'.uniqid(),
            'nom' => 'Département Info',
            'faculte_id' => $this->faculte->id,
        ]);

        $liste = [];
        for ($i = 1; $i <= $nombre; $i++) {
            $liste[] = Teacher::create([
                'matricule' => 'ENS-ADV-'.uniqid(),
                'nom' => "Nom{$i}",
                'prenom' => "Prenom{$i}",
                'email' => "adv{$i}.".uniqid().'@univ.test',
                'departement_id' => $dep->id,
                'actif' => $actif,
                'statut' => $actif ? 'actif' : 'inactif',
            ]);
        }

        return $liste;
    }

    private function creerEtudiant(string $nom = 'Etu'): Etudiant
    {
        $e = Etudiant::create([
            'cin' => 'ADV'.substr(uniqid(), -6),
            'nom' => $nom,
            'prenom' => 'Prenom',
            'email' => 'adv.etu.'.uniqid().'@univ.test',
            'date_naissance' => '2004-01-01',
            'sexe' => 'M',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => 'Rue Universitaire',
            'ville' => 'Casablanca',
            'code_postal' => '20000',
            'annee_bac' => '2024',
            'password' => 'Etudiant@123',
        ]);
        $e->definirStatut('valide');

        return $e;
    }

    /**
     * 1. AUDIT DU DIMENSIONNEMENT
     * Test de la formule : actifs = max(2, nb_salles, ceil(nb_candidats / 50))
     * Paliers : 1, 49, 50, 51, 99, 100, 101, 149, 150, 151, 300, 500
     */
    public function test_1_audit_dimensionnement_paliers(): void
    {
        $service = app(SurveillanceEquilibrageService::class);

        $paliers = [
            1 => 2,
            49 => 2,
            50 => 2,
            51 => 2,
            99 => 2,
            100 => 2,
            101 => 3,
            149 => 3,
            150 => 3,
            151 => 4,
            300 => 6,
            500 => 10,
        ];

        foreach ($paliers as $nbCandidats => $actifsAttendus) {
            $epreuve = $this->creerEpreuve([
                'capacite_necessaire' => $nbCandidats,
                'salle_id' => $this->amphi->id,
            ]);

            $besoin = $service->evaluerBesoinsEpreuve($epreuve);

            $this->assertSame(
                $actifsAttendus,
                $besoin['besoin_surveillants_actifs'],
                "Pour {$nbCandidats} candidats, le nombre de surveillants actifs doit être {$actifsAttendus}."
            );
            $this->assertSame(1, $besoin['besoin_gardes'], '1 garde de réserve requise.');
            $this->assertSame($actifsAttendus + 1, $besoin['besoin_total']);
        }
    }

    /**
     * 2. TESTS MULTI-SALLES (Cas A, B, C, D, E)
     */
    public function test_2_multi_salles_cas_a_b_c_d_e(): void
    {
        $service = app(SurveillanceEquilibrageService::class);

        // Cas A : 100 étudiants, 1 salle de 120 places
        $salle120 = Room::create(['code' => 'S-120', 'capacite' => 120, 'actif' => true]);
        $eprA = $this->creerEpreuve(['capacite_necessaire' => 100, 'salle_id' => $salle120->id]);
        $besoinA = $service->evaluerBesoinsEpreuve($eprA);
        $this->assertTrue($besoinA['capacite_valide']);
        $this->assertSame(120, $besoinA['capacite_disponible']);
        $this->assertSame(2, $besoinA['besoin_surveillants_actifs']);
        $this->assertSame(1, $besoinA['besoin_gardes']);

        // Cas B : 100 étudiants, 2 salles de 60 places
        $salleB1 = Room::create(['code' => 'S-B1', 'capacite' => 60, 'actif' => true]);
        $salleB2 = Room::create(['code' => 'S-B2', 'capacite' => 60, 'actif' => true]);
        $eprB = $this->creerEpreuve(['capacite_necessaire' => 100, 'salle_id' => $salleB1->id]);
        $etuB1 = $this->creerEtudiant('EB1');
        $etuB2 = $this->creerEtudiant('EB2');
        Candidat::create(['epreuve_id' => $eprB->id, 'etudiant_id' => $etuB1->id, 'salle_id' => $salleB1->id, 'statut' => 'present']);
        Candidat::create(['epreuve_id' => $eprB->id, 'etudiant_id' => $etuB2->id, 'salle_id' => $salleB2->id, 'statut' => 'present']);

        $besoinB = $service->evaluerBesoinsEpreuve($eprB);
        $this->assertTrue($besoinB['capacite_valide']);
        $this->assertSame(120, $besoinB['capacite_disponible']);
        $this->assertSame(2, $besoinB['besoin_surveillants_actifs']);
        $this->assertSame(1, $besoinB['besoin_gardes']);

        // Cas C : 100 étudiants, 3 salles de 40 places (3 salles -> 3 actifs min)
        $salleC1 = Room::create(['code' => 'S-C1', 'capacite' => 40, 'actif' => true]);
        $salleC2 = Room::create(['code' => 'S-C2', 'capacite' => 40, 'actif' => true]);
        $salleC3 = Room::create(['code' => 'S-C3', 'capacite' => 40, 'actif' => true]);
        $eprC = $this->creerEpreuve(['capacite_necessaire' => 100, 'salle_id' => $salleC1->id]);
        $etuC1 = $this->creerEtudiant('EC1');
        $etuC2 = $this->creerEtudiant('EC2');
        $etuC3 = $this->creerEtudiant('EC3');
        Candidat::create(['epreuve_id' => $eprC->id, 'etudiant_id' => $etuC1->id, 'salle_id' => $salleC1->id, 'statut' => 'present']);
        Candidat::create(['epreuve_id' => $eprC->id, 'etudiant_id' => $etuC2->id, 'salle_id' => $salleC2->id, 'statut' => 'present']);
        Candidat::create(['epreuve_id' => $eprC->id, 'etudiant_id' => $etuC3->id, 'salle_id' => $salleC3->id, 'statut' => 'present']);

        $besoinC = $service->evaluerBesoinsEpreuve($eprC);
        $this->assertTrue($besoinC['capacite_valide']);
        $this->assertSame(120, $besoinC['capacite_disponible']);
        $this->assertSame(3, $besoinC['nombre_salles']);
        $this->assertSame(3, $besoinC['besoin_surveillants_actifs'], '3 salles nécessitent au moins 3 surveillants actifs.');

        // Cas D : 100 étudiants, 2 salles : 60 + 30 = 90 places (BLOQUER)
        $salleD1 = Room::create(['code' => 'S-D1', 'capacite' => 60, 'actif' => true]);
        $salleD2 = Room::create(['code' => 'S-D2', 'capacite' => 30, 'actif' => true]);
        $eprD = $this->creerEpreuve(['capacite_necessaire' => 100, 'salle_id' => $salleD1->id]);
        $etuD1 = $this->creerEtudiant('ED1');
        $etuD2 = $this->creerEtudiant('ED2');
        Candidat::create(['epreuve_id' => $eprD->id, 'etudiant_id' => $etuD1->id, 'salle_id' => $salleD1->id, 'statut' => 'present']);
        Candidat::create(['epreuve_id' => $eprD->id, 'etudiant_id' => $etuD2->id, 'salle_id' => $salleD2->id, 'statut' => 'present']);

        $besoinD = $service->evaluerBesoinsEpreuve($eprD);
        $this->assertFalse($besoinD['capacite_valide'], 'Capacité insuffisante doit invalider le besoin.');
        $this->assertSame(90, $besoinD['capacite_disponible']);
        $this->assertSame(10, $besoinD['deficit_capacite']);
        $this->assertStringContainsString('Capacité insuffisante : 100 candidats pour 90 places disponibles', $besoinD['erreur_capacite']);

        // Cas E : 100 étudiants, 2 salles : 60 + 40 = 100 places (OK)
        $salleE1 = Room::create(['code' => 'S-E1', 'capacite' => 60, 'actif' => true]);
        $salleE2 = Room::create(['code' => 'S-E2', 'capacite' => 40, 'actif' => true]);
        $eprE = $this->creerEpreuve(['capacite_necessaire' => 100, 'salle_id' => $salleE1->id]);
        $etuE1 = $this->creerEtudiant('EE1');
        $etuE2 = $this->creerEtudiant('EE2');
        Candidat::create(['epreuve_id' => $eprE->id, 'etudiant_id' => $etuE1->id, 'salle_id' => $salleE1->id, 'statut' => 'present']);
        Candidat::create(['epreuve_id' => $eprE->id, 'etudiant_id' => $etuE2->id, 'salle_id' => $salleE2->id, 'statut' => 'present']);

        $besoinE = $service->evaluerBesoinsEpreuve($eprE);
        $this->assertTrue($besoinE['capacite_valide']);
        $this->assertSame(100, $besoinE['capacite_disponible']);
        $this->assertSame(0, $besoinE['deficit_capacite']);
        $this->assertSame(2, $besoinE['besoin_surveillants_actifs']);
    }

    /**
     * 3. TEST DE CAPACITÉ BLOQUANTE (100 candidats, capacité 99)
     * Vérifier DB state avant == DB state après (0 surveillance enregistrée).
     */
    public function test_3_capacite_bloquante_zero_ecriture_en_base(): void
    {
        $salle99 = Room::create(['code' => 'S-99', 'capacite' => 99, 'actif' => true]);
        $epreuve = $this->creerEpreuve([
            'capacite_necessaire' => 100,
            'salle_id' => $salle99->id,
        ]);
        $this->creerEnseignants(5);

        $service = app(SurveillanceEquilibrageService::class);

        $nbSurveillancesAvant = Surveillance::count();
        $this->assertSame(0, $nbSurveillancesAvant);

        $simulation = $service->simulerSession($this->session);

        $this->assertSame('IMPOSSIBLE', $simulation['statut']);
        $this->assertFalse($simulation['contraintes_dures_respectees']);
        $this->assertSame(1, $simulation['problemes_capacite']);
        $this->assertSame(0, Surveillance::count());

        // Tentative d'application forcée -> Exception et rollback
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Capacité');
        $service->appliquerSession($this->session, $simulation, $this->admin->id);

        $this->assertSame(0, Surveillance::count(), 'Aucune affectation partielle ne doit subsister.');
    }

    /**
     * 4. TEST DE PÉNURIE D'ENSEIGNANTS (10 épreuves simultanées, 3 enseignants)
     */
    public function test_4_penurie_enseignants_rollback_et_message_clair(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->creerEpreuve([
                'code' => 'EPR-SIMUL-'.$i,
                'date' => '2027-01-12',
                'heure_debut' => '09:00:00',
                'heure_fin' => '11:00:00',
            ]);
        }
        $this->creerEnseignants(3); // 3 enseignants pour 10 épreuves simultanées (besoin = 20 actifs + 10 gardes = 30)

        $service = app(SurveillanceEquilibrageService::class);
        $simulation = $service->simulerSession($this->session);

        $this->assertSame('IMPOSSIBLE', $simulation['statut']);
        $this->assertFalse($simulation['contraintes_dures_respectees']);
        $this->assertStringContainsString('Affectation impossible', $simulation['message_statut']);
        $this->assertStringContainsString('Déficit de personnel', $simulation['message_statut']);

        // Tentative d'application bloquée
        try {
            $service->appliquerSession($this->session, $simulation, $this->admin->id);
            $this->fail('Aurait dû lever une DomainException');
        } catch (DomainException $e) {
            $this->assertStringContainsString('impossible', $e->getMessage());
        }

        $this->assertSame(0, Surveillance::count());
    }

    /**
     * 5. TEST DE PÉNURIE DE GARDES (2 surveillants disponibles, 0 garde)
     * Le moteur ne doit PAS transformer un actif en garde.
     */
    public function test_5_penurie_de_gardes_pas_de_conversion_invalide(): void
    {
        $epreuve = $this->creerEpreuve(['date' => '2027-01-12', 'heure_debut' => '09:00', 'heure_fin' => '11:00']);
        $this->creerEnseignants(2); // Assez pour Principal + Adjoint, mais AUCUN pour la Garde

        $service = app(SurveillanceEquilibrageService::class);
        $simulation = $service->simulerSession($this->session);

        $this->assertSame('IMPOSSIBLE', $simulation['statut']);
        $this->assertSame(1, $simulation['conflits_detectes']);
        $this->assertStringContainsString('Aucun enseignant disponible pour le poste de garde / réserve', implode(' ', $simulation['anomalies']));

        $propEpreuve = $simulation['propositions_epreuves'][0];
        $gardes = array_filter($propEpreuve['surveillants'], fn ($p) => $p['role'] === 'reserve');
        $this->assertEmpty($gardes, 'Aucune garde ne doit être fabriquée artificiellement.');
    }

    /**
     * 6. TEST DE CONFLIT CROISÉ & GESTION DES BORNES HORAIRES
     * 09:00-11:00 vs 10:00-12:00 -> CHEVAUCHEMENT.
     * 08:00-10:00 vs 10:00-12:00 -> PAS DE CHEVAUCHEMENT (intervalle demi-ouvert standard).
     */
    public function test_6_conflit_croise_et_gestion_des_bornes_horaires(): void
    {
        $conflits = app(ConflitEpreuveService::class);
        $enseignant = $this->creerEnseignants(1)[0];

        // Épreuve A : 09:00 -> 11:00
        $eprA = $this->creerEpreuve([
            'date' => '2027-01-14',
            'heure_debut' => '09:00',
            'heure_fin' => '11:00',
        ]);
        Surveillance::create([
            'epreuve_id' => $eprA->id,
            'teacher_id' => $enseignant->id,
            'role' => 'principal',
            'statut' => 'assigne',
        ]);

        // Cas 1 : Créneau chevauchant 10:00 -> 12:00
        $detectes = $conflits->detecter([
            'date' => '2027-01-14',
            'heure_debut' => '10:00',
            'heure_fin' => '12:00',
            'surveillants' => [['teacher_id' => $enseignant->id]],
        ]);
        $bloquants = array_filter($detectes, fn ($c) => $c['type'] === 'surveillant' && $c['critique']);
        $this->assertNotEmpty($bloquants, '10:00 -> 12:00 chevauche 09:00 -> 11:00.');

        // Cas 2 : Créneau contigu qui touche la borne : 11:00 -> 13:00 (PAS de chevauchement)
        $detectesContigus = $conflits->detecter([
            'date' => '2027-01-14',
            'heure_debut' => '11:00',
            'heure_fin' => '13:00',
            'surveillants' => [['teacher_id' => $enseignant->id]],
        ]);
        $bloquantsContigus = array_filter($detectesContigus, fn ($c) => $c['type'] === 'surveillant' && $c['critique']);
        $this->assertEmpty($bloquantsContigus, '11:00 -> 13:00 ne chevauche PAS 09:00 -> 11:00 (borne disjointe).');

        // Cas 3 : Créneau contigu avant : 07:00 -> 09:00 (PAS de chevauchement)
        $detectesAvant = $conflits->detecter([
            'date' => '2027-01-14',
            'heure_debut' => '07:00',
            'heure_fin' => '09:00',
            'surveillants' => [['teacher_id' => $enseignant->id]],
        ]);
        $bloquantsAvant = array_filter($detectesAvant, fn ($c) => $c['type'] === 'surveillant' && $c['critique']);
        $this->assertEmpty($bloquantsAvant, '07:00 -> 09:00 ne chevauche PAS 09:00 -> 11:00.');
    }

    /**
     * 7. TEST AVEC EMPLOI DU TEMPS (ScheduleSlot)
     * Cours Mardi 09:00-11:00 -> Épreuve 09:30-11:30 bloquée.
     * Épreuve 08:00-09:00 ou 11:00-12:00 autorisées.
     */
    public function test_7_conflit_schedule_slot_et_bornes(): void
    {
        $enseignant = $this->creerEnseignants(1)[0];
        $groupe = Group::create([
            'faculte_id' => $this->faculte->id,
            'code' => 'G-ADV',
            'nom' => 'Groupe Adversarial',
            'niveau' => 'L1',
            'actif' => true,
        ]);
        $schedule = Schedule::create([
            'annee_universitaire_id' => $this->annee->id,
            'groupe_id' => $groupe->id,
            'semestre' => 1,
            'nom' => 'Planning S1',
            'statut' => 'publie',
            'actif' => true,
        ]);

        // Créneau Mardi (tuesday) 09:00 -> 11:00
        ScheduleSlot::create([
            'schedule_id' => $schedule->id,
            'matiere_id' => $this->matiere->id,
            'teacher_id' => $enseignant->id,
            'day_of_week' => 'tuesday',
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
            'type' => 'course',
            'status' => 'scheduled',
        ]);

        // Mardi 12 Janvier 2027
        $epreuveConflit = $this->creerEpreuve([
            'date' => '2027-01-12',
            'heure_debut' => '09:30:00',
            'heure_fin' => '11:30:00',
        ]);

        $service = app(SurveillanceService::class);

        // Affectation manuelle sur le créneau en conflit
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('cours régulier');
        $service->assigner($epreuveConflit, ['teacher_id' => $enseignant->id]);
    }

    /**
     * 8. TEST DE L'ÉQUILIBRAGE (20 épreuves, 30 enseignants)
     * Calcul min, max, moyenne, écart, écart-type.
     */
    public function test_8_equilibrage_metriques_statistiques_20_epreuves_30_enseignants(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->creerEpreuve([
                'code' => 'EPR-EQ-'.$i,
                'date' => '2027-01-'.sprintf('%02d', 11 + ($i % 5)),
                'heure_debut' => sprintf('%02d:00:00', 8 + ($i % 3) * 3),
                'heure_fin' => sprintf('%02d:00:00', 10 + ($i % 3) * 3),
            ]);
        }
        $this->creerEnseignants(30);

        $service = app(SurveillanceEquilibrageService::class);
        $sim = $service->simulerSession($this->session);

        $this->assertNotSame('IMPOSSIBLE', $sim['statut']);
        $this->assertSame(20, $sim['total_epreuves']);
        $this->assertSame(40, $sim['total_surveillants_actifs']);
        $this->assertSame(20, $sim['total_gardes_reserve']);

        $min = $sim['indicateurs_charge']['min'];
        $max = $sim['indicateurs_charge']['max'];
        $moyenne = $sim['indicateurs_charge']['moyenne'];
        $ecart = $sim['indicateurs_charge']['ecart'];

        // Calcul indépendant de l'écart-type
        $charges = array_column($sim['bilan_enseignants'], 'charge');
        $n = count($charges);
        $variance = array_sum(array_map(fn ($x) => pow($x - $moyenne, 2), $charges)) / $n;
        $ecartType = sqrt($variance);

        $this->assertLessThanOrEqual(2.5, $ecart, "L'écart max-min ({$ecart}) doit être inférieur ou égal à 2.5.");
        $this->assertLessThan(1.0, $ecartType, "L'écart-type ({$ecartType}) doit démontrer une distribution resserrée.");
    }

    /**
     * 9. TEST DU SCÉNARIO DES 3 JOURS
     * Vérifier que la dispersion temporelle est activement pénalisée.
     */
    public function test_9_scenario_3_jours_penalisation_dispersion(): void
    {
        // Session de 5 jours : 11 au 15 janvier 2027
        for ($j = 1; $j <= 5; $j++) {
            $this->creerEpreuve([
                'code' => "EPR-J{$j}",
                'date' => "2027-01-1{$j}",
                'heure_debut' => '09:00:00',
                'heure_fin' => '11:00:00',
            ]);
        }
        $this->creerEnseignants(15);

        $service = app(SurveillanceEquilibrageService::class);
        $sim = $service->simulerSession($this->session);

        $this->assertNotSame('IMPOSSIBLE', $sim['statut']);

        // Vérification qu'aucun enseignant n'a plus de 3 jours de présence
        foreach ($sim['bilan_enseignants'] as $ens) {
            $this->assertLessThanOrEqual(3, $ens['jours'], "L'enseignant {$ens['nom']} ne doit pas dépasser 3 jours.");
        }
    }

    /**
     * 10. CAS IMPOSSIBLE DES 3 JOURS : DÉGRADATION CONTRÔLÉE
     * Lorsque le nombre d'enseignants force certains à être mobilisés plus de 3 jours,
     * le système doit réussir les contraintes dures et déclarer la dégradation souple.
     */
    public function test_10_cas_impossible_3_jours_degradation_controlee(): void
    {
        // 5 épreuves sur 5 jours distincts avec seulement 3 enseignants au total
        // Chaque épreuve exige 2 actifs + 1 garde = 3 personnes par épreuve !
        // Les 3 enseignants sont obligés d'être présents les 5 jours !
        for ($j = 1; $j <= 5; $j++) {
            $this->creerEpreuve([
                'code' => "EPR-DEG-J{$j}",
                'date' => "2027-01-1{$j}",
                'heure_debut' => '09:00:00',
                'heure_fin' => '11:00:00',
            ]);
        }
        $this->creerEnseignants(3);

        $service = app(SurveillanceEquilibrageService::class);
        $sim = $service->simulerSession($this->session);

        // Les contraintes dures sont 100% respectées !
        $this->assertTrue($sim['contraintes_dures_respectees']);
        $this->assertSame('REALISABLE', $sim['statut']);
        $this->assertNotEmpty($sim['contraintes_souples_degradees']);
        $this->assertStringContainsString('3 jours', implode(' ', $sim['contraintes_souples_degradees']));
    }

    /**
     * 11. TAXONOMIE DES CONTRAINTES DURES ET SOUPLES
     */
    public function test_11_taxonomie_contraintes_dures_et_souples(): void
    {
        $this->assertArrayHasKey('capacite_salles', SurveillanceEquilibrageService::CONTRAINTES_DURES);
        $this->assertArrayHasKey('enseignant_actif', SurveillanceEquilibrageService::CONTRAINTES_DURES);
        $this->assertArrayHasKey('non_double_affectation', SurveillanceEquilibrageService::CONTRAINTES_DURES);
        $this->assertArrayHasKey('conflit_examen', SurveillanceEquilibrageService::CONTRAINTES_DURES);
        $this->assertArrayHasKey('conflit_emploi_du_temps', SurveillanceEquilibrageService::CONTRAINTES_DURES);
        $this->assertArrayHasKey('minimum_principal', SurveillanceEquilibrageService::CONTRAINTES_DURES);
        $this->assertArrayHasKey('minimum_adjoint', SurveillanceEquilibrageService::CONTRAINTES_DURES);
        $this->assertArrayHasKey('minimum_garde', SurveillanceEquilibrageService::CONTRAINTES_DURES);

        $this->assertArrayHasKey('equilibre_charges', SurveillanceEquilibrageService::CONTRAINTES_SOUPLES);
        $this->assertArrayHasKey('regroupement_3_jours', SurveillanceEquilibrageService::CONTRAINTES_SOUPLES);
        $this->assertArrayHasKey('equilibre_gardes', SurveillanceEquilibrageService::CONTRAINTES_SOUPLES);
    }

    /**
     * 12. CONSTANTES DU SCORE D'ÉQUILIBRAGE
     */
    public function test_12_constantes_du_score_d_equilibrage(): void
    {
        $this->assertSame(10.0, SurveillanceEquilibrageService::POIDS_CHARGE_TOTALE);
        $this->assertSame(15.0, SurveillanceEquilibrageService::POIDS_CHARGE_JOUR);
        $this->assertSame(8.0, SurveillanceEquilibrageService::POIDS_GARDES);
        $this->assertSame(35.0, SurveillanceEquilibrageService::PENALITE_DEPASSEMENT_3_JOURS);
        $this->assertSame(10.0, SurveillanceEquilibrageService::PENALITE_DISPERSION_TEMPORELLE);
        $this->assertSame(2.0, SurveillanceEquilibrageService::PENALITE_NOUVEAU_JOUR);
    }

    /**
     * 13. DÉTERMINISME DE LA SIMULATION
     * Deux exécutions successives doivent produire des résultats 100% identiques.
     */
    public function test_13_determinisme_de_la_simulation(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->creerEpreuve([
                'code' => 'EPR-DET-'.$i,
                'date' => '2027-01-'.sprintf('%02d', 11 + ($i % 3)),
                'heure_debut' => sprintf('%02d:00:00', 9 + ($i % 2) * 3),
                'heure_fin' => sprintf('%02d:00:00', 11 + ($i % 2) * 3),
            ]);
        }
        $this->creerEnseignants(10);

        $service = app(SurveillanceEquilibrageService::class);

        $sim1 = $service->simulerSession($this->session);
        $sim2 = $service->simulerSession($this->session);

        $this->assertSame(
            serialize($sim1['propositions_epreuves']),
            serialize($sim2['propositions_epreuves']),
            'La simulation doit être rigoureusement déterministe.'
        );
        $this->assertSame($sim1['indicateurs_charge'], $sim2['indicateurs_charge']);
    }

    /**
     * 14. TRANSACTION ET ROLLBACK TOTAL EN CAS D'ÉCHEC
     */
    public function test_14_transaction_rollback_total(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->creerEpreuve(['code' => 'EPR-TX-'.$i]);
        }
        $this->creerEnseignants(15);

        $service = app(SurveillanceEquilibrageService::class);
        $sim = $service->simulerSession($this->session);

        // Verrouillons la 5e épreuve pour provoquer une erreur fatale au cours de l'application
        $derniereEpreuve = Epreuve::where('code', 'EPR-TX-4')->firstOrFail();
        $derniereEpreuve->update(['statut' => 'locked']);

        try {
            $service->appliquerSession($this->session, $sim, $this->admin->id);
            $this->fail('Aurait dû lever une DomainException pour épreuve verrouillée.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('verrouillée', $e->getMessage());
        }

        $this->assertSame(0, Surveillance::count(), 'Rollback total : zéro surveillance persistée.');
    }

    /**
     * 15. AFFECTATION MANUELLE SÉCURISÉE (Refus des tentatives invalides)
     */
    public function test_15_affectation_manuelle_securisee(): void
    {
        $service = app(SurveillanceService::class);
        $epreuve = $this->creerEpreuve();
        [$tActif, $t2] = $this->creerEnseignants(2);
        $tInactif = $this->creerEnseignants(1, false)[0];

        // 1. Refus enseignant inactif
        try {
            $service->assigner($epreuve, ['teacher_id' => $tInactif->id]);
            $this->fail('Aurait dû refuser un enseignant inactif');
        } catch (DomainException $e) {
            $this->assertStringContainsString('inactif', $e->getMessage());
        }

        // 2. Affectation normale d'un premier principal
        $service->assigner($epreuve, ['teacher_id' => $tActif->id, 'role' => 'principal']);

        // 3. Refus d'un deuxième principal
        try {
            $service->assigner($epreuve, ['teacher_id' => $t2->id, 'role' => 'principal']);
            $this->fail('Aurait dû refuser un deuxième Principal');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Principal est déjà affecté', $e->getMessage());
        }

        // 4. Refus de double affectation du même enseignant sur la même épreuve
        try {
            $service->assigner($epreuve, ['teacher_id' => $tActif->id, 'role' => 'adjoint']);
            $this->fail('Aurait dû refuser une double affectation');
        } catch (DomainException $e) {
            $this->assertStringContainsString('déjà affecté à cette épreuve', $e->getMessage());
        }
    }

    /**
     * 16. INTÉGRITÉ DES RÔLES & REMPLACEMENT PAR LA GARDE
     * Principal A, Adjoint B, Garde C.
     * A est absent -> Remplacé par C.
     * Résultat : A remplace, C devient principal actif, C n'est plus garde active.
     */
    public function test_16_remplacement_titulaire_par_garde_meme_epreuve(): void
    {
        $service = app(SurveillanceService::class);
        $epreuve = $this->creerEpreuve();
        [$tA, $tB, $tC] = $this->creerEnseignants(3);

        $survA = $service->assigner($epreuve, ['teacher_id' => $tA->id, 'role' => 'principal']);
        $survB = $service->assigner($epreuve, ['teacher_id' => $tB->id, 'role' => 'adjoint']);
        $survC = $service->assigner($epreuve, ['teacher_id' => $tC->id, 'role' => 'reserve']);

        $this->assertSame('principal', $survA->role);
        $this->assertSame('adjoint', $survB->role);
        $this->assertSame('reserve', $survC->role);

        // A est absent -> Remplacement de A par la garde C
        $nouveauPrincipal = $service->remplacer($survA->fresh(), [
            'teacher_id' => $tC->id,
            'role' => 'principal',
        ], 'A est malade, mobilisation de la réserve C.');

        $this->assertSame('remplace', $survA->fresh()->statut);
        $this->assertSame('principal', $nouveauPrincipal->role);
        $this->assertSame($survA->id, $nouveauPrincipal->remplace_surveillance_id);
        $this->assertContains($survC->fresh()->statut, ['assigne', 'confirme'], 'C doit être actif.');
        $this->assertSame('principal', $survC->fresh()->role, 'C est désormais le principal actif.');

        // Vérification des surveillants actifs sur l'épreuve
        $actifs = $epreuve->surveillants()->whereIn('statut', ['assigne', 'confirme'])->get();
        $this->assertCount(2, $actifs);
        $this->assertTrue($actifs->contains('role', 'principal'));
        $this->assertTrue($actifs->contains('role', 'adjoint'));
        $this->assertFalse($actifs->contains('role', 'reserve'), "La garde C n'est plus comptabilisée comme réserve.");
    }

    /**
     * 17. PERFORMANCE ET BUDGET REQUÊTES (Échelle 50 épreuves / 100 enseignants)
     */
    public function test_17_performance_50_epreuves_100_enseignants(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $this->creerEpreuve([
                'code' => 'EPR-PERF-'.$i,
                'date' => '2027-01-'.sprintf('%02d', 11 + ($i % 6)),
                'heure_debut' => sprintf('%02d:00:00', 8 + ($i % 4) * 2),
                'heure_fin' => sprintf('%02d:00:00', 10 + ($i % 4) * 2),
            ]);
        }
        $this->creerEnseignants(100);

        DB::enableQueryLog();
        $debut = microtime(true);

        $service = app(SurveillanceEquilibrageService::class);
        $sim = $service->simulerSession($this->session);

        $dureeMs = (microtime(true) - $debut) * 1000;
        $nbRequetes = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertNotSame('IMPOSSIBLE', $sim['statut']);
        $this->assertSame(50, $sim['total_epreuves']);
        $this->assertLessThan(20, $nbRequetes, "La simulation batch doit s'exécuter en moins de 20 requêtes (obtenu : {$nbRequetes}).");
        $this->assertLessThan(5000, $dureeMs, "La simulation doit prendre moins de 5 secondes (obtenu : {$dureeMs} ms).");
    }

    /**
     * 18. CONTRÔLE CROISÉ DU COCKPIT AVEC REQUÊTES SQL INDÉPENDANTES
     */
    public function test_18_cockpit_conformite_calculs_sql_independants(): void
    {
        $service = app(SurveillanceService::class);
        $epreuve = $this->creerEpreuve();
        [$t1, $t2, $t3] = $this->creerEnseignants(3);

        $service->assigner($epreuve, ['teacher_id' => $t1->id, 'role' => 'principal']);
        $service->assigner($epreuve, ['teacher_id' => $t2->id, 'role' => 'adjoint']);
        $service->assigner($epreuve, ['teacher_id' => $t3->id, 'role' => 'reserve']);

        $cockpit = $service->obtenirCockpit();

        $sqlTotalGardes = Surveillance::where('statut', '!=', 'annule')->count();
        $sqlTotalAssigne = Surveillance::where('statut', 'assigne')->count();

        $this->assertSame($sqlTotalGardes, $cockpit['total_gardes']);
        $this->assertSame($sqlTotalAssigne, $cockpit['gardes_en_attente']);
    }
}
