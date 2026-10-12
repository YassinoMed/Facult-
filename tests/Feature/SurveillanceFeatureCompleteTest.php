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
use App\Modules\Evaluation\Services\SurveillanceService;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
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
 * Suite de tests de bout en bout pour la Surveillance et Garde des DS et Examens (§24).
 */
class SurveillanceFeatureCompleteTest extends TestCase
{
    use RefreshDatabase;

    private User $adminScolarite;

    private User $adminFaculteA;

    private User $adminFaculteB;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private AnneeUniversitaire $annee;

    private Programme $programmeA;

    private UniteEnseignement $ueA;

    private Matiere $ecueA;

    private Group $groupeA;

    private Room $salle1;

    private Room $salle2;

    private Teacher $enseignant1;

    private Teacher $enseignant2;

    private Teacher $enseignant3;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.evaluation.active' => true]);

        $this->faculteA = Faculte::create(['nom' => 'Faculté des Sciences', 'code' => 'FS', 'active' => true]);
        $this->faculteB = Faculte::create(['nom' => 'Faculté de Médecine', 'code' => 'FM', 'active' => true]);

        $this->adminScolarite = User::factory()->create([
            'email' => 'admin.scolarite@univ.test',
            'role' => 'admin_scolarite',
            'faculte_id' => null,
            'actif' => true,
        ]);

        $this->adminFaculteA = User::factory()->create([
            'email' => 'admin.fsa@univ.test',
            'role' => 'admin_faculte',
            'faculte_id' => $this->faculteA->id,
            'actif' => true,
        ]);

        $this->adminFaculteB = User::factory()->create([
            'email' => 'admin.fmb@univ.test',
            'role' => 'admin_faculte',
            'faculte_id' => $this->faculteB->id,
            'actif' => true,
        ]);

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->programmeA = Programme::create([
            'faculte_id' => $this->faculteA->id,
            'code' => 'LGL',
            'nom' => 'Licence Génie Logiciel',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
        ]);

        $niveau = Niveau::create(['programme_id' => $this->programmeA->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1]);
        $semestre = SemestreLmd::create(['niveau_id' => $niveau->id, 'code' => 'S1', 'nom' => 'S1', 'numero' => 1, 'ordre' => 1]);

        $this->ueA = UniteEnseignement::create([
            'semestre_id' => $semestre->id,
            'programme_id' => $this->programmeA->id,
            'code' => 'UE-INFO',
            'nom' => 'Informatique Fondamentale',
            'credits' => 6,
            'coefficient' => 2,
        ]);

        $this->ecueA = Matiere::create([
            'code' => 'ALGO101',
            'nom' => 'Algorithmes et Programmation',
            'ue_id' => $this->ueA->id,
            'niveau' => 'L1',
            'semestre' => 1,
            'credits' => 6,
            'coefficient' => 2,
            'actif' => true,
        ]);

        $this->groupeA = Group::create([
            'code' => 'GL-G1',
            'nom' => 'Groupe 1 GL',
            'faculte_id' => $this->faculteA->id,
            'programme_id' => $this->programmeA->id,
            'niveau' => 'L1',
            'actif' => true,
        ]);

        $this->salle1 = Room::create(['code' => 'AMPHI-A', 'nom' => 'Amphi A', 'capacite' => 120, 'actif' => true]);
        $this->salle2 = Room::create(['code' => 'SALLE-101', 'nom' => 'Salle TD 101', 'capacite' => 40, 'actif' => true]);

        $this->enseignant1 = Teacher::create([
            'matricule' => 'ENS-001',
            'nom' => 'Ben Amor',
            'prenom' => 'Tarek',
            'email' => 'tarek@univ.test',
            'actif' => true,
        ]);

        $this->enseignant2 = Teacher::create([
            'matricule' => 'ENS-002',
            'nom' => 'Trabelsi',
            'prenom' => 'Sonia',
            'email' => 'sonia@univ.test',
            'actif' => true,
        ]);

        $this->enseignant3 = Teacher::create([
            'matricule' => 'ENS-003',
            'nom' => 'Gharbi',
            'prenom' => 'Mounir',
            'email' => 'mounir@univ.test',
            'actif' => true,
        ]);
    }

    private function creerSession(string $type = 'principale'): SessionExamen
    {
        return SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'SESS-'.strtoupper($type).'-'.random_int(1000, 9999),
            'nom' => 'Session '.ucfirst($type),
            'type' => $type,
            'date_debut' => '2027-01-10',
            'date_fin' => '2027-01-25',
            'statut' => 'draft',
        ]);
    }

    private function creerEpreuve(SessionExamen $session, string $typeEval = 'examen', string $date = '2027-01-12', string $heureDebut = '08:30', string $heureFin = '10:30'): Epreuve
    {
        $examen = Examen::create([
            'session_id' => $session->id,
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programmeA->id,
            'groupe_id' => $this->groupeA->id,
            'libelle' => 'Examen '.$this->ecueA->code,
            'type' => $typeEval,
            'statut' => 'scheduled',
            'responsable_id' => $this->adminScolarite->id,
        ]);

        return Epreuve::create([
            'examen_id' => $examen->id,
            'session_id' => $session->id,
            'annee_universitaire_id' => $this->annee->id,
            'matiere_id' => $this->ecueA->id,
            'ue_id' => $this->ueA->id,
            'groupe_id' => $this->groupeA->id,
            'salle_id' => $this->salle1->id,
            'date' => $date,
            'heure_debut' => $heureDebut,
            'heure_fin' => $heureFin,
            'duree_minutes' => 120,
            'type_evaluation' => $typeEval,
            'code' => strtoupper($typeEval).'-'.random_int(1000, 9999),
            'statut' => 'scheduled',
        ]);
    }

    /**
     * 1. Affectation unitaire & Règle anti-doublon direct sur la même épreuve (§7).
     */
    public function test_affectation_unitaire_et_anti_doublon(): void
    {
        $session = $this->creerSession();
        $epreuve = $this->creerEpreuve($session);
        $service = app(SurveillanceService::class);

        // Affectation réussie
        $surveillance = $service->assigner($epreuve, [
            'teacher_id' => $this->enseignant1->id,
            'role' => 'principal',
            'salle_id' => $this->salle1->id,
        ], $this->adminScolarite->id);

        $this->assertSame('assigne', $surveillance->statut);
        $this->assertSame('principal', $surveillance->role);
        $this->assertSame($this->enseignant1->id, $surveillance->teacher_id);
        $this->assertSame($this->salle1->id, $surveillance->salle_id);

        // Deuxième affectation du même enseignant sur la même épreuve : refusée (Règle 2)
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Ce surveillant est déjà affecté à cette épreuve.');

        $service->assigner($epreuve, [
            'teacher_id' => $this->enseignant1->id,
            'role' => 'surveillant',
        ]);
    }

    /**
     * 2. Détection des conflits horaires entre épreuves simultanées (§7).
     */
    public function test_conflit_horaire_sur_autre_epreuve(): void
    {
        $session = $this->creerSession();
        $epreuve1 = $this->creerEpreuve($session, 'examen', '2027-01-15', '09:00', '11:00');
        $epreuve2 = $this->creerEpreuve($session, 'examen', '2027-01-15', '10:00', '12:00'); // Chevauchement 10:00 - 11:00
        $service = app(SurveillanceService::class);

        $service->assigner($epreuve1, ['teacher_id' => $this->enseignant1->id]);

        // Tentative sur la deuxième épreuve se chevauchant
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Surveillant déjà affecté sur');

        $service->assigner($epreuve2, ['teacher_id' => $this->enseignant1->id]);
    }

    /**
     * 3. Détection de conflit avec créneau de cours régulier de l'emploi du temps (ScheduleSlot) (§7 Règle 4).
     */
    public function test_conflit_creneau_cours_emploi_du_temps(): void
    {
        $session = $this->creerSession();
        // Le 15 janvier 2027 est un vendredi ('friday')
        $epreuve = $this->creerEpreuve($session, 'ds', '2027-01-15', '14:00', '16:00');

        $schedule = Schedule::create([
            'nom' => 'Emploi du temps GL-G1',
            'annee_universitaire_id' => $this->annee->id,
            'faculte_id' => $this->faculteA->id,
            'programme_id' => $this->programmeA->id,
            'groupe_id' => $this->groupeA->id,
            'semestre' => 1,
            'statut' => 'published',
        ]);

        ScheduleSlot::create([
            'schedule_id' => $schedule->id,
            'matiere_id' => $this->ecueA->id,
            'teacher_id' => $this->enseignant2->id,
            'room_id' => $this->salle2->id,
            'day_of_week' => 'friday',
            'start_time' => '14:30',
            'end_time' => '16:30',
            'type' => 'course',
            'status' => 'scheduled',
        ]);

        $service = app(SurveillanceService::class);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('L\'enseignant a déjà un cours régulier à ce créneau');

        $service->assigner($epreuve, ['teacher_id' => $this->enseignant2->id]);
    }

    /**
     * 4. Multi-salles : affectation de surveillants distincts par salle (§5).
     */
    public function test_multi_salles_surveillants_distincts(): void
    {
        $session = $this->creerSession();
        $epreuve = $this->creerEpreuve($session);
        $service = app(SurveillanceService::class);

        $surv1 = $service->assigner($epreuve, [
            'teacher_id' => $this->enseignant1->id,
            'salle_id' => $this->salle1->id,
            'role' => 'principal',
        ]);

        $surv2 = $service->assigner($epreuve, [
            'teacher_id' => $this->enseignant2->id,
            'salle_id' => $this->salle2->id,
            'role' => 'surveillant',
        ]);

        $this->assertSame($this->salle1->id, $surv1->salle_id);
        $this->assertSame($this->salle2->id, $surv2->salle_id);
        $this->assertCount(2, $epreuve->fresh()->surveillants);
    }

    /**
     * 5. Désaffectation d'un surveillant (§7).
     */
    public function test_desaffectation_surveillant(): void
    {
        $session = $this->creerSession();
        $epreuve = $this->creerEpreuve($session);
        $service = app(SurveillanceService::class);

        $surv = $service->assigner($epreuve, ['teacher_id' => $this->enseignant1->id]);
        $this->assertSame('assigne', $surv->statut);

        $response = $this->actingAs($this->adminScolarite)->post(
            route('admin.evaluations.surveillants.desaffecter', $surv),
            ['motif' => 'Erreur de saisie']
        );

        $response->assertRedirect()->assertSessionHas('succes');
        $this->assertSame('annule', $surv->fresh()->statut);
        $this->assertStringContainsString('Erreur de saisie', $surv->fresh()->observations);
    }

    /**
     * 6. Remplacement avec conservation de l'historique et traçabilité (§11).
     */
    public function test_remplacement_surveillant_avec_historique(): void
    {
        $session = $this->creerSession();
        $epreuve = $this->creerEpreuve($session);
        $service = app(SurveillanceService::class);

        $initial = $service->assigner($epreuve, ['teacher_id' => $this->enseignant1->id]);

        $response = $this->actingAs($this->adminScolarite)->post(
            route('admin.evaluations.surveillants.remplacer', $initial),
            [
                'teacher_id' => $this->enseignant2->id,
                'motif' => 'Congé maladie imprévu',
            ]
        );

        $response->assertRedirect()->assertSessionHas('succes');

        $initial->refresh();
        $this->assertSame('remplace', $initial->statut);

        $remplacant = Surveillance::where('remplace_surveillance_id', $initial->id)->first();
        $this->assertNotNull($remplacant);
        $this->assertSame($this->enseignant2->id, $remplacant->teacher_id);
        $this->assertSame('Congé maladie imprévu', $remplacant->observations);
        $this->assertSame($initial->id, $remplacant->remplaceSurveillance->id);

        $this->assertDatabaseHas('integration_logs', [
            'integration' => 'surveillance',
            'status' => 'completed',
        ]);
    }

    /**
     * 7. Émargement surveillant : présent (heure), retard, absence (§13).
     */
    public function test_emargement_surveillant(): void
    {
        $session = $this->creerSession();
        $epreuve = $this->creerEpreuve($session);
        $service = app(SurveillanceService::class);

        $surv1 = $service->assigner($epreuve, ['teacher_id' => $this->enseignant1->id]);
        $surv2 = $service->assigner($epreuve, ['teacher_id' => $this->enseignant2->id]);

        // Pointage présent avec heure d'arrivée
        $this->actingAs($this->adminScolarite)->post(
            route('admin.evaluations.surveillants.emargement', $surv1),
            [
                'presence' => 'present',
                'heure_arrivee' => '08:20',
            ]
        )->assertRedirect()->assertSessionHas('succes');

        $this->assertSame('confirme', $surv1->fresh()->statut);
        $this->assertSame('present', $surv1->fresh()->presence);
        $this->assertStringStartsWith('08:20', (string) $surv1->fresh()->heure_arrivee);

        // Pointage en retard
        $this->actingAs($this->adminScolarite)->post(
            route('admin.evaluations.surveillants.emargement', $surv2),
            [
                'presence' => 'retard',
                'heure_arrivee' => '08:50',
                'motif' => 'Embouteillages',
            ]
        )->assertRedirect();

        $this->assertSame('confirme', $surv2->fresh()->statut);
        $this->assertSame('retard', $surv2->fresh()->presence);
        $this->assertSame('Embouteillages', $surv2->fresh()->motif_absence);
    }

    /**
     * 8. Gestion identique des Devoirs Surveillés (DS) et des Examens sans duplication (§2, §6).
     */
    public function test_surveillance_unifiee_ds_et_examens(): void
    {
        $session = $this->creerSession();
        $ds = $this->creerEpreuve($session, 'ds', '2027-01-18', '08:30', '10:30');
        $examen = $this->creerEpreuve($session, 'examen', '2027-01-19', '08:30', '10:30');

        $service = app(SurveillanceService::class);

        $survDs = $service->assigner($ds, ['teacher_id' => $this->enseignant1->id]);
        $survExamen = $service->assigner($examen, ['teacher_id' => $this->enseignant2->id]);

        $this->assertSame('ds', $survDs->epreuve->type_evaluation);
        $this->assertSame('examen', $survExamen->epreuve->type_evaluation);

        // Vérification du filtrage par type sur la vue index
        $resDs = $this->actingAs($this->adminScolarite)->get(
            route('admin.evaluations.surveillances.index', ['type_evaluation' => 'ds'])
        )->assertOk();

        $surveillancesFiltrees = $resDs->viewData('surveillances');
        $this->assertCount(1, $surveillancesFiltrees);
        $this->assertSame($this->enseignant1->id, $surveillancesFiltrees->first()->teacher_id);
    }

    /**
     * 9. Simulation pure sans écriture DB (§8).
     */
    public function test_simulation_pure_sans_ecriture(): void
    {
        $session = $this->creerSession();
        $epreuve = $this->creerEpreuve($session);
        $service = app(SurveillanceService::class);

        $initialCount = Surveillance::count();

        $resultat = $service->simuler($epreuve, [
            $this->enseignant1->id,
            $this->enseignant2->id,
            $this->enseignant3->id,
        ]);

        $this->assertSame(3, $resultat['total_propositions']);
        $this->assertSame(3, $resultat['eligibles']);
        $this->assertSame(0, $resultat['conflits']);
        $this->assertSame($initialCount, Surveillance::count(), 'La simulation ne doit écrire aucune ligne en base.');
    }

    /**
     * 10. Application transactionnelle en lot (§9).
     */
    public function test_application_transactionnelle_en_lot(): void
    {
        $session = $this->creerSession();
        $epreuve = $this->creerEpreuve($session);
        $service = app(SurveillanceService::class);

        $bilan = $service->appliquer($epreuve, [
            ['teacher_id' => $this->enseignant1->id, 'role' => 'principal', 'salle_id' => $this->salle1->id],
            ['teacher_id' => $this->enseignant2->id, 'role' => 'surveillant', 'salle_id' => $this->salle1->id],
        ], $this->adminScolarite->id);

        $this->assertSame(2, $bilan['assignes']);
        $this->assertCount(2, $epreuve->fresh()->surveillants);
    }

    /**
     * 11. Cockpit et calculs de charge par enseignant (§10, §15).
     */
    public function test_cockpit_indicateurs_et_charges(): void
    {
        $session = $this->creerSession();
        $epreuve1 = $this->creerEpreuve($session, 'examen', '2027-01-20', '08:00', '10:00');
        $epreuve2 = $this->creerEpreuve($session, 'ds', '2027-01-21', '08:00', '10:00');

        $service = app(SurveillanceService::class);
        $service->assigner($epreuve1, ['teacher_id' => $this->enseignant1->id]);
        $service->assigner($epreuve2, ['teacher_id' => $this->enseignant1->id]);

        $cockpit = $service->obtenirCockpit();
        $this->assertSame(2, $cockpit['total_gardes']);
        $this->assertSame(2, $cockpit['gardes_en_attente']);

        $charges = $service->calculerChargeEnseignants();
        $chargeTarek = $charges->firstWhere('id', $this->enseignant1->id);

        $this->assertNotNull($chargeTarek);
        $this->assertSame(2, $chargeTarek['total_gardes']);
        $this->assertSame(1, $chargeTarek['gardes_ds']);
        $this->assertSame(1, $chargeTarek['gardes_examen']);
        $this->assertEquals(4.0, $chargeTarek['heures_totales']);

        $this->actingAs($this->adminScolarite)
            ->get(route('admin.evaluations.surveillances.cockpit'))
            ->assertOk()
            ->assertSee('Répartition des charges de surveillance par enseignant');
    }

    /**
     * 12. Espace personnel surveillant (« Mes gardes ») avec protection anti-IDOR/BOLA (§16).
     */
    public function test_espace_surveillant_mes_gardes(): void
    {
        $userEnseignant = User::factory()->create([
            'email' => $this->enseignant1->email,
            'role' => 'consultation',
        ]);

        $session = $this->creerSession();
        $epreuve1 = $this->creerEpreuve($session);
        $epreuve2 = $this->creerEpreuve($session, 'examen', '2027-01-22');

        $service = app(SurveillanceService::class);
        $service->assigner($epreuve1, ['teacher_id' => $this->enseignant1->id]);
        $service->assigner($epreuve2, ['teacher_id' => $this->enseignant2->id]); // Pour l'enseignant 2

        $response = $this->actingAs($userEnseignant)->get(route('admin.evaluations.surveillances.mes-gardes'));
        $response->assertOk();
        $response->assertSee($epreuve1->code);
        $response->assertDontSee($epreuve2->code); // N'affiche que ses propres gardes
    }

    /**
     * 13. Verrouillage d'épreuve : interdiction absolue de modification des gardes (§18).
     */
    public function test_verrouillage_epreuve_interdit_modifications(): void
    {
        $session = $this->creerSession();
        $epreuve = $this->creerEpreuve($session);
        $service = app(SurveillanceService::class);

        $surv = $service->assigner($epreuve, ['teacher_id' => $this->enseignant1->id]);

        // Verrouillage de l'épreuve
        $epreuve->update(['statut' => 'locked']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Épreuve verrouillée.');

        $service->assigner($epreuve, ['teacher_id' => $this->enseignant2->id]);
    }

    /**
     * 14. Sécurité : RBAC et isolation de Faculté (§17).
     */
    public function test_securite_rbac_et_isolation_faculte(): void
    {
        $session = $this->creerSession();
        $epreuveA = $this->creerEpreuve($session); // Faculté A

        // Admin Faculté B tente d'affecter sur l'épreuve de Faculté A : 404
        $this->actingAs($this->adminFaculteB)->post(
            route('admin.evaluations.surveillants.store', $epreuveA),
            ['teacher_id' => $this->enseignant1->id]
        )->assertNotFound();
    }

    /**
     * 15. Concurrence : deux transactions concurrentes ne créent aucun doublon (§9).
     */
    public function test_concurrence_affectation_surveillants(): void
    {
        $session = $this->creerSession();
        $epreuve = $this->creerEpreuve($session);
        $service = app(SurveillanceService::class);

        // Simulation de 2 tentatives simultanées sur le même enseignant
        $succes = 0;
        $echecs = 0;

        foreach ([$this->enseignant1, $this->enseignant1] as $candidat) {
            try {
                $service->assigner($epreuve, ['teacher_id' => $candidat->id]);
                $succes++;
            } catch (DomainException) {
                $echecs++;
            }
        }

        $this->assertSame(1, $succes);
        $this->assertSame(1, $echecs);
        $this->assertSame(1, $epreuve->fresh()->surveillants()->where('statut', '!=', 'annule')->count());
    }

    /**
     * 16. Export CSV des surveillances (§20).
     */
    public function test_export_csv_surveillances(): void
    {
        $session = $this->creerSession();
        $epreuve = $this->creerEpreuve($session);
        $service = app(SurveillanceService::class);

        $service->assigner($epreuve, ['teacher_id' => $this->enseignant1->id, 'role' => 'principal']);

        $response = $this->actingAs($this->adminScolarite)->get(
            route('admin.evaluations.surveillances.export')
        );

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $csv = $response->streamedContent();

        $this->assertStringContainsString('Date', $csv);
        $this->assertStringContainsString($this->enseignant1->nom, $csv);
        $this->assertStringContainsString('Principal', $csv);
    }

    /**
     * 17. Performance & Volume : simulation et calcul de charge sur volume élevé (§22).
     */
    public function test_performance_simulation_et_calcul_charge_volume(): void
    {
        $session = $this->creerSession();
        $epreuve = $this->creerEpreuve($session);
        $service = app(SurveillanceService::class);

        $now = now();
        $enseignantsBulk = [];
        for ($i = 10; $i < 110; $i++) {
            $enseignantsBulk[] = [
                'matricule' => "ENS-VOL-{$i}",
                'nom' => "Nom{$i}",
                'prenom' => "Prenom{$i}",
                'email' => "prof{$i}@univ.test",
                'actif' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        Teacher::insert($enseignantsBulk);
        $teacherIds = Teacher::where('matricule', 'like', 'ENS-VOL-%')->pluck('id')->all();

        $debut = microtime(true);
        $resultat = $service->simuler($epreuve, $teacherIds);
        $dureeSimulation = microtime(true) - $debut;

        $this->assertCount(100, $resultat['propositions']);
        $this->assertSame(100, $resultat['eligibles']);
        $this->assertLessThan(1.0, $dureeSimulation, 'La simulation de 100 surveillants doit prendre moins de 1 seconde.');

        $debutCharge = microtime(true);
        $charges = $service->calculerChargeEnseignants($session->id);
        $dureeCharge = microtime(true) - $debutCharge;

        $this->assertGreaterThanOrEqual(100, $charges->count());
        $this->assertLessThan(1.0, $dureeCharge, 'Le calcul des charges sur volume élevé doit prendre moins de 1 seconde.');
    }
}
