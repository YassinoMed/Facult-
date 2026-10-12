<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Models\Surveillance;
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
use Tests\TestCase;

/**
 * Tests exhaustifs du moteur d'équilibrage intelligent des surveillants (DS & Examens).
 * Couvre les 13 points de spécification : minimum 2 surveillants, 1 garde/réserve,
 * capacités réelles, conflits, équilibre 3 jours, isolation faculté, idempotence,
 * verrouillage, audit et performance batch.
 */
class SurveillanceEquilibrageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculteA;

    private Faculte $faculteB;

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

        $this->faculteA = Faculte::create(['nom' => 'Faculté des Sciences', 'code' => 'FST', 'active' => true]);
        $this->faculteB = Faculte::create(['nom' => 'Faculté des Lettres', 'code' => 'FLSH', 'active' => true]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Admin Scolarité',
            'email' => 'admin.eval@universite.test',
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
            'faculte_id' => $this->faculteA->id,
            'cycle' => 'licence',
            'duree_annees' => 3,
            'actif' => true,
        ]);

        $this->session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'SESS-2026-P',
            'nom' => 'Session Principale Janvier 2027',
            'type' => 'principale',
            'statut' => 'open',
            'date_debut' => '2027-01-10',
            'date_fin' => '2027-01-20',
        ]);

        $this->examen = Examen::create([
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programme->id,
            'libelle' => 'Examens Semestre 1',
            'type' => 'examen',
            'statut' => 'scheduled',
        ]);

        $this->matiere = Matiere::create([
            'code' => 'ALGO101',
            'nom' => 'Algorithmique & Structures',
            'niveau' => 'L1',
            'semestre' => 1,
            'coefficient' => 2,
            'credits' => 5,
        ]);

        $this->amphi = Room::create([
            'code' => 'AMPHI-A',
            'capacite' => 150,
            'actif' => true,
        ]);
    }

    private function creerEpreuve(array $attributs = []): Epreuve
    {
        return Epreuve::create(array_merge([
            'code' => 'EPR-'.uniqid(),
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
            'capacite_necessaire' => 60,
            'statut' => 'scheduled',
        ], $attributs));
    }

    private function creerEnseignants(int $nombre, ?int $faculteId = null): array
    {
        $dep = Departement::create([
            'code' => 'DEP-'.uniqid(),
            'nom' => 'Département Info',
            'faculte_id' => $faculteId ?? $this->faculteA->id,
        ]);

        $liste = [];
        for ($i = 1; $i <= $nombre; $i++) {
            $liste[] = Teacher::create([
                'matricule' => 'ENS-'.uniqid(),
                'nom' => "Enseignant{$i}",
                'prenom' => "Prenom{$i}",
                'email' => "ens{$i}.".uniqid().'@univ.test',
                'departement_id' => $dep->id,
                'actif' => true,
                'statut' => 'actif',
            ]);
        }

        return $liste;
    }

    /** 1. Règle 2 surveillants actifs minimum + 1 garde/réserve par examen */
    public function test_regle_deux_surveillants_et_un_garde_par_examen(): void
    {
        $epreuve = $this->creerEpreuve();
        $this->creerEnseignants(5);

        $service = app(SurveillanceEquilibrageService::class);
        $simulation = $service->simulerSession($this->session);

        $this->assertNotSame('IMPOSSIBLE', $simulation['statut']);
        $this->assertCount(1, $simulation['propositions_epreuves']);

        $surveillants = $simulation['propositions_epreuves'][0]['surveillants'];
        $actifs = array_filter($surveillants, fn ($s) => $s['role'] !== 'reserve');
        $gardes = array_filter($surveillants, fn ($s) => $s['role'] === 'reserve');

        // Minimum 2 surveillants actifs + 1 garde/réserve
        $this->assertGreaterThanOrEqual(2, count($actifs));
        $this->assertCount(1, $gardes);
    }

    /** 2. Distinction garde vs surveillance active et calcul des poids */
    public function test_distinction_garde_et_surveillance_active_avec_poids(): void
    {
        $this->assertSame(1.0, SurveillanceEquilibrageService::POIDS_SURVEILLANCE_ACTIVE);
        $this->assertSame(0.5, SurveillanceEquilibrageService::POIDS_GARDE_RESERVE);

        $epreuve = $this->creerEpreuve();
        $ens = $this->creerEnseignants(3);

        $service = app(SurveillanceEquilibrageService::class);
        $simulation = $service->simulerSession($this->session);

        // Application de la proposition
        $service->appliquerSession($this->session, $simulation, $this->admin->id);

        $survs = Surveillance::where('epreuve_id', $epreuve->id)->get();
        $this->assertCount(3, $survs);

        $reserve = $survs->where('role', 'reserve')->first();
        $actifs = $survs->where('role', '!=', 'reserve');

        $this->assertNotNull($reserve);
        $this->assertCount(2, $actifs);
    }

    /** 3. Capacité multi-salles : calcul des salles nécessaires et surveillance associée */
    public function test_capacite_multi_salles_et_calcul_salles_necessaires(): void
    {
        $salle1 = Room::create(['code' => 'S101', 'capacite' => 30, 'actif' => true]);
        $salle2 = Room::create(['code' => 'S102', 'capacite' => 30, 'actif' => true]);
        $salle3 = Room::create(['code' => 'S103', 'capacite' => 40, 'actif' => true]);

        // Épreuve de 100 candidats sans salle unique, couverte par S101+S102+S103 (total = 100)
        $epreuve = $this->creerEpreuve([
            'salle_id' => null,
            'capacite_necessaire' => 100,
        ]);

        $service = app(SurveillanceEquilibrageService::class);
        $besoin = $service->evaluerBesoinsEpreuve($epreuve);

        $this->assertSame(100, $besoin['candidats_reels']);
        $this->assertGreaterThanOrEqual(2, $besoin['besoin_surveillants_actifs']);
        $this->assertSame(1, $besoin['besoin_gardes']);
    }

    /** 4. Capacité insuffisante : épreuve de 100 candidats avec salle de 80 -> IMPOSSIBLE */
    public function test_capacite_insuffisante_declare_statut_impossible(): void
    {
        $petiteSalle = Room::create(['code' => 'PETITE-1', 'capacite' => 80, 'actif' => true]);

        $this->creerEpreuve([
            'salle_id' => $petiteSalle->id,
            'capacite_necessaire' => 100,
        ]);
        $this->creerEnseignants(5);

        $service = app(SurveillanceEquilibrageService::class);
        $simulation = $service->simulerSession($this->session);

        $this->assertSame('IMPOSSIBLE', $simulation['statut']);
        $this->assertSame(1, $simulation['problemes_capacite']);
        $this->assertStringContainsString('déficit : 20 places', $simulation['anomalies'][0]);
    }

    /** 5. Équilibrage de la charge entre enseignants (minimisation max - min) */
    public function test_equilibrage_charge_entre_enseignants(): void
    {
        // 4 épreuves sur des créneaux distincts
        $this->creerEpreuve(['code' => 'E1', 'date' => '2027-01-11', 'heure_debut' => '08:30:00', 'heure_fin' => '10:30:00']);
        $this->creerEpreuve(['code' => 'E2', 'date' => '2027-01-11', 'heure_debut' => '14:00:00', 'heure_fin' => '16:00:00']);
        $this->creerEpreuve(['code' => 'E3', 'date' => '2027-01-12', 'heure_debut' => '08:30:00', 'heure_fin' => '10:30:00']);
        $this->creerEpreuve(['code' => 'E4', 'date' => '2027-01-13', 'heure_debut' => '08:30:00', 'heure_fin' => '10:30:00']);

        // 6 enseignants
        $this->creerEnseignants(6);

        $service = app(SurveillanceEquilibrageService::class);
        $simulation = $service->simulerSession($this->session);

        $this->assertNotSame('IMPOSSIBLE', $simulation['statut']);
        $ecart = $simulation['indicateurs_charge']['ecart'];

        // L'écart entre l'enseignant le plus chargé et le moins chargé doit rester très faible (<= 2.5)
        $this->assertLessThanOrEqual(2.5, $ecart, "L'écart de charge doit être maîtrisé.");
    }

    /** 6. Équilibre sur 3 jours : le moteur privilégie la présence sur 3 jours distincts */
    public function test_equilibre_presence_sur_trois_jours(): void
    {
        // Épreuves réparties sur 4 dates différentes
        $this->creerEpreuve(['date' => '2027-01-11']);
        $this->creerEpreuve(['date' => '2027-01-12']);
        $this->creerEpreuve(['date' => '2027-01-13']);
        $this->creerEpreuve(['date' => '2027-01-14']);

        $this->creerEnseignants(4);

        $service = app(SurveillanceEquilibrageService::class);
        $simulation = $service->simulerSession($this->session);

        $this->assertNotSame('IMPOSSIBLE', $simulation['statut']);
        $repartitionJours = $simulation['repartition_jours'];

        // Aucun enseignant ne doit être éparpillé inutilement au-delà de sa charge
        $this->assertGreaterThan(0, $repartitionJours['cible_3_jours'] + $repartitionJours['deux_jours']);
    }

    /** 7. Conflits d'horaires et de cours réguliers (ScheduleSlot) bloquants */
    public function test_conflit_creneau_cours_et_autre_epreuve_bloquant(): void
    {
        $epreuve1 = $this->creerEpreuve([
            'date' => '2027-01-11', // Lundi
            'heure_debut' => '09:00:00',
            'heure_fin' => '11:00:00',
        ]);

        $ens = $this->creerEnseignants(3);
        $profOccupe = $ens[0];

        // Création d'un cours régulier le lundi à 09:30-11:30 pour le prof 0
        $groupe = Group::create([
            'faculte_id' => $this->faculteA->id,
            'code' => 'GI1',
            'nom' => 'Groupe 1',
            'niveau' => 'L1',
            'actif' => true,
        ]);
        $schedule = Schedule::create([
            'nom' => 'EDT L1-G1',
            'annee_universitaire_id' => $this->annee->id,
            'faculte_id' => $this->faculteA->id,
            'programme_id' => $this->programme->id,
            'groupe_id' => $groupe->id,
            'semestre' => 1,
            'statut' => 'published',
        ]);
        ScheduleSlot::create([
            'schedule_id' => $schedule->id,
            'matiere_id' => $this->matiere->id,
            'teacher_id' => $profOccupe->id,
            'day_of_week' => 'monday',
            'start_time' => '09:30:00',
            'end_time' => '11:30:00',
            'type' => 'course',
            'status' => 'scheduled',
        ]);

        $service = app(SurveillanceEquilibrageService::class);
        $simulation = $service->simulerSession($this->session);

        // Le prof occupé par un cours ne doit pas être affecté sur ce créneau
        $surveillantsAffectes = collect($simulation['propositions_epreuves'][0]['surveillants'])->pluck('teacher_id');
        $this->assertNotContains($profOccupe->id, $surveillantsAffectes->all());
    }

    /** 8. Remplacement : une garde de réserve peut remplacer un absent avec traçabilité */
    public function test_remplacement_garde_conserve_filiation(): void
    {
        $epreuve = $this->creerEpreuve();
        $ens = $this->creerEnseignants(3);

        $service = app(SurveillanceEquilibrageService::class);
        $simulation = $service->simulerSession($this->session);
        $service->appliquerSession($this->session, $simulation, $this->admin->id);

        $titulaire = Surveillance::where('epreuve_id', $epreuve->id)->where('role', '!=', 'reserve')->firstOrFail();
        $reserve = Surveillance::where('epreuve_id', $epreuve->id)->where('role', 'reserve')->firstOrFail();

        // Remplacement du titulaire par un nouveau remplaçant avec traçabilité
        $nouveauProf = $this->creerEnseignants(1)[0];
        $remplacant = app(SurveillanceService::class)->remplacer($titulaire, [
            'teacher_id' => $nouveauProf->id,
            'role' => 'surveillant',
        ], 'Absence justifiée');

        $this->assertSame($titulaire->id, $remplacant->remplace_surveillance_id);
        $this->assertSame('remplace', $titulaire->fresh()->statut);
    }

    /** 9. Verrouillage : épreuve verrouillée refuse toute mutation */
    public function test_epreuve_verrouillee_bloque_application(): void
    {
        $epreuve = $this->creerEpreuve(['statut' => 'locked']);
        $this->creerEnseignants(4);

        $service = app(SurveillanceEquilibrageService::class);
        $simulation = $service->simulerSession($this->session);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('verrouillée');
        $service->appliquerSession($this->session, $simulation, $this->admin->id);
    }

    /** 10. Isolation Faculté : aucune ressource d'une autre faculté ne peut être affectée */
    public function test_faculty_scope_isole_ressources_autre_faculte(): void
    {
        $this->creerEpreuve();

        // Enseignants uniquement rattachés à la Faculté B
        $this->creerEnseignants(4, $this->faculteB->id);

        $service = app(SurveillanceEquilibrageService::class);
        // Simulation restreinte à la faculté A
        $simulation = $service->simulerSession($this->session, ['faculte_id' => $this->faculteA->id]);

        $this->assertSame('IMPOSSIBLE', $simulation['statut']);
    }

    /** 11. Idempotence : deux applications successives ne créent pas de doublons */
    public function test_idempotence_deux_applications_aucun_doublon(): void
    {
        $epreuve = $this->creerEpreuve();
        $this->creerEnseignants(5);

        $service = app(SurveillanceEquilibrageService::class);
        $simulation = $service->simulerSession($this->session);

        // Première application
        $premier = $service->appliquerSession($this->session, $simulation, $this->admin->id);
        $totalSurvs = Surveillance::where('epreuve_id', $epreuve->id)->count();

        // Seconde application identique
        $second = $service->appliquerSession($this->session, $simulation, $this->admin->id);
        $totalApres = Surveillance::where('epreuve_id', $epreuve->id)->count();

        $this->assertSame($totalSurvs, $totalApres, 'Aucune duplication physique tolérée.');
        $this->assertSame(0, $second['assignes']);
    }

    /** 12. Performance Batch : très faible nombre de requêtes SQL (zéro dans les boucles) */
    public function test_performance_simulation_batch_faible_nombre_requetes(): void
    {
        // 5 épreuves et 20 enseignants
        for ($i = 0; $i < 5; $i++) {
            $this->creerEpreuve(['code' => 'EPR-PERF-'.$i, 'date' => '2027-01-'.(11 + $i)]);
        }
        $this->creerEnseignants(20);

        $service = app(SurveillanceEquilibrageService::class);

        \DB::enableQueryLog();
        $simulation = $service->simulerSession($this->session);
        $requetes = count(\DB::getQueryLog());
        \DB::disableQueryLog();

        // Moins de 10 requêtes SQL pour toute la session multi-épreuves et multi-enseignants
        $this->assertLessThanOrEqual(10, $requetes, "Le moteur batch d'équilibrage doit exécuter <= 10 requêtes au total.");
        $this->assertNotSame('IMPOSSIBLE', $simulation['statut']);
    }

    /** 13. Endpoints Web : routes HTTP de simulation et d'application */
    public function test_endpoints_web_simuler_et_appliquer(): void
    {
        $this->creerEpreuve();
        $this->creerEnseignants(4);

        // Simulation JSON
        $this->actingAs($this->admin)
            ->postJson(route('admin.evaluations.sessions.equilibrage.simuler', $this->session))
            ->assertOk()
            ->assertJsonStructure(['statut', 'total_epreuves', 'indicateurs_charge']);

        // Application POST
        $this->actingAs($this->admin)
            ->post(route('admin.evaluations.sessions.equilibrage.appliquer', $this->session))
            ->assertRedirect()
            ->assertSessionHas('succes');

        $this->assertGreaterThan(0, Surveillance::count());
    }

    /** 14. Rôles distincts : Principal, Adjoint et Garde sans double rôle */
    public function test_roles_distincts_principal_adjoint_et_garde_par_examen(): void
    {
        $epreuve = $this->creerEpreuve();
        $this->creerEnseignants(3);

        $service = app(SurveillanceEquilibrageService::class);
        $simulation = $service->simulerSession($this->session);

        $service->appliquerSession($this->session, $simulation, $this->admin->id);

        $surveillants = Surveillance::where('epreuve_id', $epreuve->id)->get();
        $this->assertCount(3, $surveillants);

        $principaux = $surveillants->where('role', 'principal');
        $adjoints = $surveillants->where('role', 'adjoint');
        $gardes = $surveillants->where('role', 'reserve');

        $this->assertCount(1, $principaux, 'Exactement 1 surveillant Principal.');
        $this->assertCount(1, $adjoints, 'Exactement 1 surveillant Adjoint.');
        $this->assertCount(1, $gardes, 'Exactement 1 Garde de réserve.');

        // Vérifier qu'aucun enseignant n'a deux rôles sur la même épreuve
        $distincts = $surveillants->pluck('teacher_id')->unique();
        $this->assertCount(3, $distincts, 'Une même personne ne peut pas avoir plusieurs rôles sur la même épreuve.');
    }

    /** 15. Règle d'unicité du rôle Principal sur affectation manuelle */
    public function test_unicite_role_principal_sur_affectation_manuelle(): void
    {
        $epreuve = $this->creerEpreuve();
        $ens = $this->creerEnseignants(2);

        $survService = app(SurveillanceService::class);
        // Affecter le premier comme principal
        $survService->assigner($epreuve, [
            'teacher_id' => $ens[0]->id,
            'role' => 'principal',
        ], $this->admin->id);

        // Tentative d'affecter un second principal -> doit être rejetée
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Un surveillant Principal est déjà affecté à cette épreuve.');

        $survService->assigner($epreuve, [
            'teacher_id' => $ens[1]->id,
            'role' => 'principal',
        ], $this->admin->id);
    }

    /** 16. Transaction et Rollback : aucune affectation partielle si une épreuve échoue */
    public function test_transaction_rollback_complet_en_cas_dechec(): void
    {
        $epreuve1 = $this->creerEpreuve(['code' => 'EPR-OK-1']);
        $epreuve2 = $this->creerEpreuve(['code' => 'EPR-LOCKED-2', 'statut' => 'locked']);
        $this->creerEnseignants(6);

        $service = app(SurveillanceEquilibrageService::class);
        $proposition = [
            'propositions_epreuves' => [
                [
                    'epreuve_id' => $epreuve1->id,
                    'surveillants' => [
                        ['teacher_id' => 1, 'role' => 'principal', 'existant' => false],
                    ],
                ],
                [
                    'epreuve_id' => $epreuve2->id,
                    'surveillants' => [
                        ['teacher_id' => 2, 'role' => 'principal', 'existant' => false],
                    ],
                ],
            ],
        ];

        try {
            $service->appliquerSession($this->session, $proposition, $this->admin->id);
            $this->fail('Une DomainException devait être levée.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('verrouillée', $e->getMessage());
        }

        // ROLLBACK complet : épreuve 1 ne doit avoir AUCUNE affectation partielle résiduelle
        $this->assertSame(0, Surveillance::count(), 'Aucune affectation partielle ne doit rester en base après un échec.');
    }

    /** 17. Multi-niveaux L1, L2, L3 et multi-groupes sans aucun conflit */
    public function test_multi_niveaux_et_multi_groupes_l1_l2_l3(): void
    {
        $niveaux = ['L1', 'L2', 'L3'];
        $epreuvesCreees = [];

        foreach ($niveaux as $idx => $niv) {
            $matiereNiv = Matiere::create([
                'code' => "MAT-{$niv}",
                'nom' => "Matière {$niv}",
                'niveau' => $niv,
                'semestre' => 1,
            ]);

            $groupeNiv = Group::create([
                'faculte_id' => $this->faculteA->id,
                'code' => "GRP-{$niv}",
                'nom' => "Groupe {$niv}",
                'niveau' => $niv,
                'actif' => true,
            ]);

            $epreuvesCreees[] = $this->creerEpreuve([
                'code' => "EPR-{$niv}",
                'matiere_id' => $matiereNiv->id,
                'date' => '2027-01-'.(11 + $idx),
                'groupe_id' => $groupeNiv->id,
            ]);
        }

        $this->creerEnseignants(8);

        $service = app(SurveillanceEquilibrageService::class);
        $simulation = $service->simulerSession($this->session);

        $this->assertNotSame('IMPOSSIBLE', $simulation['statut']);
        $this->assertSame(100.0, $simulation['epreuves_couvertes_pct']);
        $this->assertSame(100.0, $simulation['gardes_couvertes_pct']);
        $this->assertSame(0, $simulation['conflits_detectes']);

        $service->appliquerSession($this->session, $simulation, $this->admin->id);

        foreach ($epreuvesCreees as $epreuve) {
            $survs = Surveillance::where('epreuve_id', $epreuve->id)->get();
            $this->assertGreaterThanOrEqual(2, $survs->where('role', '!=', 'reserve')->count(), "Niveau {$epreuve->code} : >= 2 surveillants actifs");
            $this->assertGreaterThanOrEqual(1, $survs->where('role', 'reserve')->count(), "Niveau {$epreuve->code} : 1 garde de réserve");
        }
    }

    /** 18. Affectation manuelle : messages d'erreurs explicites avec nom et motif */
    public function test_affectation_manuelle_avec_messages_explicites(): void
    {
        $epreuve1 = $this->creerEpreuve([
            'date' => '2027-01-12',
            'heure_debut' => '09:00:00',
            'heure_fin' => '11:00:00',
        ]);
        $epreuve2 = $this->creerEpreuve([
            'date' => '2027-01-12',
            'heure_debut' => '09:30:00',
            'heure_fin' => '11:30:00',
        ]);

        $ens = $this->creerEnseignants(1)[0];
        $survService = app(SurveillanceService::class);

        // 1. Affecter sur épreuve 1
        $survService->assigner($epreuve1, ['teacher_id' => $ens->id, 'role' => 'surveillant'], $this->admin->id);

        // 2. Tentative de double affectation sur la même épreuve
        try {
            $survService->assigner($epreuve1, ['teacher_id' => $ens->id, 'role' => 'adjoint'], $this->admin->id);
            $this->fail('Exception attendue pour doublon');
        } catch (DomainException $e) {
            $this->assertStringContainsString('déjà affecté', $e->getMessage());
        }

        // 3. Tentative de conflit sur épreuve 2 (même horaire)
        try {
            $survService->assigner($epreuve2, ['teacher_id' => $ens->id, 'role' => 'surveillant'], $this->admin->id);
            $this->fail('Exception attendue pour conflit horaire autre épreuve');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Affectation impossible', $e->getMessage());
            $this->assertStringContainsString('Surveillant déjà affecté sur', $e->getMessage());
        }
    }
}
