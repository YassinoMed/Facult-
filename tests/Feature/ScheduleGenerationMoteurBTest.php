<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Enseignement\Models\Enseignement;
use App\Modules\Enseignement\Models\TypeEnseignement;
use App\Modules\Referentiel\Models\Departement;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleAvailability;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use App\Modules\Schedule\Models\TimeSlot;
use App\Modules\Schedule\Models\TimetableConstraint;
use App\Modules\Schedule\Models\TimetableGeneration;
use App\Modules\Schedule\Services\ScheduleConstraintChecker;
use App\Modules\Schedule\Services\ScheduleConstraintRegistry;
use App\Modules\Schedule\Services\ScheduleGenerationSettings;
use App\Modules\Schedule\Services\ScheduleGeneratorService;
use App\Modules\Schedule\Services\SeanceDemande;
use App\Services\ParametreService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PHASE B (Smart Timetables) — moteur de génération branché sur les
 * pondérations déclarées (registre + paramètres `planning.generation.*`),
 * plages `souhaitee` honorées, contraintes dures garanties, garde-fous
 * brouillon et exposition HTTP (simulation sans écriture / brouillon).
 */
class ScheduleGenerationMoteurBTest extends TestCase
{
    use RefreshDatabase;

    protected Faculte $faculte;

    protected User $admin;

    protected Group $groupe;

    protected AnneeUniversitaire $annee;

    protected Matiere $matiereAlgo;

    protected Matiere $matiereWeb;

    protected Teacher $enseignant;

    protected Room $salleA;

    protected Room $salleB;

    protected Schedule $planning;

    protected UniteEnseignement $ue;

    protected Programme $programme;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.schedule.active' => true]);

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences B',
            'code' => 'FST_B',
            'description' => 'Sciences exactes',
            'active' => true,
        ]);

        // Chaîne LMD réelle : programme → niveau → semestre → UE → matière.
        // C'est ce chemin que suit la préparation automatique des demandes.
        $this->programme = Programme::create([
            'faculte_id' => $this->faculte->id,
            'code' => 'LMI_B',
            'nom' => 'Licence Mathématiques-Informatique',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
        ]);
        $niveau = Niveau::create([
            'programme_id' => $this->programme->id,
            'code' => 'L2',
            'nom' => 'L2',
            'ordre' => 2,
        ]);
        $semestreLmd = SemestreLmd::create([
            'niveau_id' => $niveau->id,
            'code' => 'S1',
            'nom' => 'S1',
            'numero' => 1,
            'ordre' => 1,
        ]);
        $this->ue = UniteEnseignement::create([
            'semestre_id' => $semestreLmd->id,
            'programme_id' => $this->programme->id,
            'code' => 'UE_B1',
            'nom' => 'Bases de l’informatique',
            'credits' => 6,
            'coefficient' => 2,
        ]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité Phase B',
            'email' => 'scolarite.b@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->groupe = Group::create([
            'code' => 'L2-INFO-B',
            'nom' => 'L2 Informatique B',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L2',
            'filiere' => 'Informatique',
            'capacite' => 30,
            'actif' => true,
        ]);

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027-B',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->matiereAlgo = Matiere::create([
            'code' => 'ALGO_B',
            'nom' => 'Algorithmique avancée',
            'niveau' => 'L2',
            'semestre' => 1,
            'ue_id' => $this->ue->id,
        ]);

        $this->matiereWeb = Matiere::create([
            'code' => 'WEB_B',
            'nom' => 'Développement web',
            'niveau' => 'L2',
            'semestre' => 1,
        ]);

        $this->enseignant = Teacher::create([
            'matricule' => 'ENS-B-001',
            'nom' => 'Trabelsi',
            'prenom' => 'Nadia',
            'email' => 'nadia.trabelsi@universite.test',
            'qualifications' => ['ALGO_B', 'WEB_B'],
            'faculte_id' => $this->faculte->id,
            'actif' => true,
        ]);

        $this->salleA = Room::create([
            'code' => 'A101',
            'nom' => 'Salle A101',
            'capacite' => 60,
            'faculte_id' => $this->faculte->id,
            'type_salle' => 'td',
            'heure_ouverture' => '08:00:00',
            'heure_fermeture' => '19:00:00',
            'actif' => true,
        ]);

        $this->salleB = Room::create([
            'code' => 'B102',
            'nom' => 'Salle B102',
            'capacite' => 60,
            'faculte_id' => $this->faculte->id,
            'type_salle' => 'td',
            'heure_ouverture' => '08:00:00',
            'heure_fermeture' => '19:00:00',
            'actif' => true,
        ]);

        $this->planning = Schedule::create([
            'annee_universitaire_id' => $this->annee->id,
            'semestre' => 1,
            'groupe_id' => $this->groupe->id,
            'nom' => 'L2 Info B — S1',
            'statut' => 'brouillon',
            'actif' => true,
        ]);

        foreach ([
            'SLOT_LUN_1' => ['monday', '08:30:00', '10:00:00'],
            'SLOT_LUN_2' => ['monday', '14:00:00', '15:30:00'],
            'SLOT_MAR_1' => ['tuesday', '08:30:00', '10:00:00'],
            'SLOT_MAR_2' => ['tuesday', '10:15:00', '11:45:00'],
        ] as $code => [$jour, $debut, $fin]) {
            TimeSlot::create([
                'faculte_id' => $this->faculte->id,
                'code' => $code,
                'nom' => "Créneau {$code}",
                'day_of_week' => $jour,
                'start_time' => $debut,
                'end_time' => $fin,
                'type' => 'course',
                'is_mandatory_break' => false,
                'actif' => true,
            ]);
        }
    }

    /** Une instance neuve par appel : aucun cache de contexte hors génération. */
    protected function moteur(): ScheduleGeneratorService
    {
        return app(ScheduleGeneratorService::class);
    }

    protected function demande(string $id, string $matiere = 'algo', ?int $teacherId = null): SeanceDemande
    {
        return new SeanceDemande(
            id: $id,
            matiereId: $matiere === 'web' ? $this->matiereWeb->id : $this->matiereAlgo->id,
            type: 'course',
            titre: "Séance {$id}",
            dureeMinutes: 90,
            teacherId: $teacherId ?? $this->enseignant->id,
        );
    }

    /**
     * @param  array<string, mixed>  $surcharges
     */
    protected function souhait(array $surcharges): ScheduleAvailability
    {
        return ScheduleAvailability::create($surcharges + [
            'annee_universitaire_id' => $this->annee->id,
            'type' => 'souhaitee',
        ]);
    }

    protected function parametres(): ParametreService
    {
        return app(ParametreService::class);
    }

    // ---------------------------------------------------------------- moteur

    public function test_ponderations_effectives_tracees_dans_la_generation(): void
    {
        $generation = $this->moteur()->generer($this->planning, [
            'seances_demandes' => [$this->demande('TRACE_1')],
            'ecraser_brouillon' => true,
        ]);

        $this->assertTrue($generation->estSucces());
        $this->assertSame(5, $generation->parametres['ponderations']['poids_trous']);
        $this->assertSame(5, $generation->parametres['ponderations']['poids_equilibre_jours']);
        $this->assertSame(3, $generation->parametres['ponderations']['poids_continuite']);
        $this->assertSame(240, $generation->parametres['ponderations']['duree_max_sequence_minutes']);
        $this->assertSame(
            ['enseignants' => 0, 'salles' => 0, 'groupes' => 0],
            $generation->parametres['preferences_souhaitees']
        );
    }

    public function test_poids_trous_oriente_vers_une_journee_compacte(): void
    {
        // L'axe « équilibre des jours » est neutralisé pour isoler les trous.
        $this->parametres()->set('planning.generation.poids_equilibre_jours', '0');

        $slotFixe = ScheduleSlot::create([
            'schedule_id' => $this->planning->id,
            'matiere_id' => $this->matiereWeb->id,
            'teacher_id' => $this->enseignant->id,
            'room_id' => $this->salleA->id,
            'day_of_week' => 'monday',
            'start_time' => '08:30:00',
            'end_time' => '10:00:00',
            'type' => 'course',
            'status' => 'scheduled',
        ]);

        // 1) Pondération nulle : le premier candidat du domaine est retenu,
        //    même s'il laisse un trou de 4 h après le créneau déjà placé.
        $this->parametres()->set('planning.generation.poids_trous', '0');
        $generation = $this->moteur()->generer($this->planning, [
            'seances_demandes' => [$this->demande('TROUS_FAIBLE')],
        ]);
        $this->assertTrue($generation->estSucces());

        $slot = ScheduleSlot::where('schedule_id', $this->planning->id)
            ->where('id', '!=', $slotFixe->id)->firstOrFail();
        $this->assertSame('monday', $slot->day_of_week);
        $this->assertSame('14:00:00', $slot->start_time);

        // 2) Pondération forte : le trou est évité, la séance passe au mardi
        //    (preuve que le second passage relit bien les paramètres).
        $slot->delete();
        $this->parametres()->set('planning.generation.poids_trous', '10');

        $generation = $this->moteur()->generer($this->planning, [
            'seances_demandes' => [$this->demande('TROUS_FORT')],
        ]);

        $slot = ScheduleSlot::where('schedule_id', $this->planning->id)
            ->where('id', '!=', $slotFixe->id)->firstOrFail();
        $this->assertTrue($generation->estSucces());
        $this->assertSame('tuesday', $slot->day_of_week);
        $this->assertSame(10, $generation->parametres['ponderations']['poids_trous']);
    }

    public function test_preference_souhaitee_enseignant_oriente_le_creneau(): void
    {
        $this->souhait([
            'teacher_id' => $this->enseignant->id,
            'day_of_week' => 'monday',
            'start_time' => '14:00:00',
            'end_time' => '15:30:00',
        ]);

        $generation = $this->moteur()->generer($this->planning, [
            'seances_demandes' => [$this->demande('PREF_ENS_1')],
            'ecraser_brouillon' => true,
        ]);

        $slot = ScheduleSlot::firstOrFail();
        $this->assertTrue($generation->estSucces());
        $this->assertSame('monday', $slot->day_of_week);
        $this->assertSame('14:00:00', $slot->start_time);
        $this->assertSame(['enseignants' => 1, 'salles' => 0, 'groupes' => 0], $generation->parametres['preferences_souhaitees']);

        // Contre-épreuve : contrainte de préférence désactivée → plus aucun
        // biais, le premier candidat du domaine (lundi 08:30) l'emporte.
        ScheduleSlot::query()->delete();
        app(ScheduleConstraintRegistry::class)->desactiverContrainte(TimetableConstraint::SOFT_TEACHER_PREFERENCE);

        $this->moteur()->generer($this->planning, [
            'seances_demandes' => [$this->demande('PREF_ENS_2')],
            'ecraser_brouillon' => true,
        ]);

        $this->assertSame('08:30:00', ScheduleSlot::firstOrFail()->start_time);
    }

    public function test_preference_souhaitee_salle_oriente_le_choix_de_salle(): void
    {
        $this->souhait([
            'room_id' => $this->salleB->id,
            'day_of_week' => 'monday',
            'start_time' => '08:30:00',
            'end_time' => '10:00:00',
        ]);

        $generation = $this->moteur()->generer($this->planning, [
            'seances_demandes' => [$this->demande('PREF_SALLE_1')],
            'ecraser_brouillon' => true,
        ]);

        $slot = ScheduleSlot::firstOrFail();
        $this->assertTrue($generation->estSucces());
        $this->assertSame($this->salleB->id, $slot->room_id);
        $this->assertSame('08:30:00', $slot->start_time);
        $this->assertSame(['enseignants' => 0, 'salles' => 1, 'groupes' => 0], $generation->parametres['preferences_souhaitees']);
    }

    public function test_preference_souhaitee_groupe_oriente_le_creneau(): void
    {
        $this->souhait([
            'group_id' => $this->groupe->id,
            'day_of_week' => 'tuesday',
            'start_time' => '10:15:00',
            'end_time' => '11:45:00',
        ]);

        $generation = $this->moteur()->generer($this->planning, [
            'seances_demandes' => [$this->demande('PREF_GROUPE_1')],
            'ecraser_brouillon' => true,
        ]);

        $slot = ScheduleSlot::firstOrFail();
        $this->assertTrue($generation->estSucces());
        $this->assertSame('tuesday', $slot->day_of_week);
        $this->assertSame('10:15:00', $slot->start_time);
        $this->assertSame(['enseignants' => 0, 'salles' => 0, 'groupes' => 1], $generation->parametres['preferences_souhaitees']);
    }

    public function test_indisponibilite_declaree_reste_bloquante_dans_le_moteur(): void
    {
        ScheduleAvailability::create([
            'teacher_id' => $this->enseignant->id,
            'day_of_week' => 'monday',
            'start_time' => '08:00:00',
            'end_time' => '12:00:00',
            'type' => 'indisponible',
            'annee_universitaire_id' => $this->annee->id,
            'reason' => 'Conseil de faculté',
        ]);

        $generation = $this->moteur()->generer($this->planning, [
            'seances_demandes' => [$this->demande('HARD_1')],
            'ecraser_brouillon' => true,
        ]);

        $slot = ScheduleSlot::firstOrFail();
        $this->assertTrue($generation->estSucces());
        // Lundi 08:30 interdit par la plage dure : repli sur le lundi 14:00.
        $this->assertSame('monday', $slot->day_of_week);
        $this->assertSame('14:00:00', $slot->start_time);
    }

    public function test_audit_independant_sans_violation_apres_generation(): void
    {
        $this->souhait([
            'teacher_id' => $this->enseignant->id,
            'day_of_week' => 'monday',
            'start_time' => '14:00:00',
            'end_time' => '15:30:00',
        ]);

        $generation = $this->moteur()->generer($this->planning, [
            'seances_demandes' => [
                $this->demande('AUD_1'),
                $this->demande('AUD_2'),
                $this->demande('AUD_3', 'web'),
            ],
            'ecraser_brouillon' => true,
        ]);

        $this->assertTrue($generation->estSucces());

        $audit = app(ScheduleConstraintChecker::class)->auditerPlanning($this->planning->fresh());
        $this->assertSame(0, $audit->nombreViolationsDures());
        $this->assertSame(3, ScheduleSlot::count());
        $this->assertNotNull($generation->score_qualite);
    }

    public function test_enseignant_non_qualifie_ne_peut_pas_etre_affecte(): void
    {
        $this->enseignant->update(['qualifications' => ['AUTRE_MATIERE']]);

        $generation = $this->moteur()->generer($this->planning, [
            'seances_demandes' => [new SeanceDemande(
                id: 'QUALIF_1',
                matiereId: $this->matiereAlgo->id,
                type: 'course',
                titre: 'CM non qualifié',
                dureeMinutes: 90,
            )],
            'ecraser_brouillon' => true,
        ]);

        $this->assertSame(TimetableGeneration::STATUT_ECHEC, $generation->statut);
        $this->assertSame(0, $generation->nombre_seances_placees);
        $this->assertDatabaseCount('schedule_slots', 0);
    }

    // ------------------------------------------------------------- garde-fous

    public function test_generation_refusee_sur_planning_publie_ou_verrouille(): void
    {
        foreach (['publie', 'verrouille'] as $statut) {
            $this->planning->update(['statut' => $statut]);
            $exception = null;

            try {
                $this->moteur()->generer($this->planning, [
                    'seances_demandes' => [$this->demande('GARDE_'.$statut)],
                ]);
            } catch (DomainException $e) {
                $exception = $e;
            }

            $this->assertInstanceOf(DomainException::class, $exception, "Génération refusée en statut {$statut}");
            $this->assertStringContainsString('aucune publication automatique', (string) $exception?->getMessage());
        }

        $this->assertDatabaseCount('schedule_slots', 0);
        $this->assertSame(0, TimetableGeneration::count());
        $this->assertSame('verrouille', $this->planning->fresh()->statut);
    }

    public function test_simulation_autorisee_sur_planning_publie_sans_ecriture(): void
    {
        $this->planning->update(['statut' => 'publie']);

        $generation = $this->moteur()->simuler($this->planning, [
            'seances_demandes' => [$this->demande('SIM_PUB_1')],
        ]);

        $this->assertTrue($generation->parametres['dry_run']);
        $this->assertSame(1, $generation->nombre_seances_placees);
        $this->assertDatabaseCount('schedule_slots', 0);
        $this->assertSame('publie', $this->planning->fresh()->statut);
    }

    public function test_garde_fous_iterations_et_budget_temps_bornes(): void
    {
        $this->parametres()->set('planning.generation.iteration_max', '1500');

        $generation = $this->moteur()->simuler($this->planning, [
            'seances_demandes' => [$this->demande('BORNE_1')],
            'iteration_max' => 999999999,
            'timeout_secondes' => 9999,
        ]);

        $this->assertSame(1500, $generation->parametres['iteration_max']);
        $this->assertSame(60, $generation->parametres['timeout_secondes']);

        $generation = $this->moteur()->simuler($this->planning, [
            'seances_demandes' => [$this->demande('BORNE_2')],
            'iteration_max' => 1,
            'timeout_secondes' => 0,
        ]);

        $this->assertSame(100, $generation->parametres['iteration_max']);
        $this->assertSame(1, $generation->parametres['timeout_secondes']);
    }

    public function test_generation_ne_modifie_jamais_le_statut_du_planning(): void
    {
        $generation = $this->moteur()->generer($this->planning, [
            'seances_demandes' => [$this->demande('STATUT_1')],
            'ecraser_brouillon' => true,
        ]);

        $this->assertTrue($generation->estSucces());
        $this->assertSame('brouillon', $this->planning->fresh()->statut);
        $this->assertDatabaseCount('schedule_slots', 1);
    }

    // -------------------------------------------------------------------- HTTP

    public function test_page_generation_accessible_avec_les_ponderations_affichees(): void
    {
        $reponse = $this->actingAs($this->admin)
            ->get(route('admin.emplois-du-temps.generation.index', $this->planning));

        $reponse->assertOk();
        $reponse->assertSee('Génération automatique', false);
        $reponse->assertSee('Simuler sans écrire', false);
        $reponse->assertSee('Rappel : la génération n\'écrit jamais le statut du planning', false);
    }

    public function test_page_generation_lecture_seule_ok_ecriture_interdite_et_hors_perimetre_404(): void
    {
        $lecteur = User::creerCompteBackOffice([
            'name' => 'Lecteur B',
            'email' => 'lecteur.b@universite.test',
            'password' => 'Motdepasse@123',
        ], 'consultation');

        $this->actingAs($lecteur)
            ->get(route('admin.emplois-du-temps.generation.index', $this->planning))
            ->assertOk();
        $this->actingAs($lecteur)
            ->post(route('admin.emplois-du-temps.generation.generer', $this->planning))
            ->assertForbidden();
        $this->assertDatabaseCount('schedule_slots', 0);

        $autreFaculte = Faculte::create([
            'nom' => 'Faculté Autre B',
            'code' => 'AUTRE_B',
            'active' => true,
        ]);
        $adminAutre = User::creerCompteBackOffice([
            'name' => 'Admin Autre B',
            'email' => 'admin.autre.b@universite.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $autreFaculte->id,
        ], 'admin_faculte');

        $this->actingAs($adminAutre)
            ->get(route('admin.emplois-du-temps.generation.index', $this->planning))
            ->assertNotFound();
        $this->actingAs($adminAutre)
            ->post(route('admin.emplois-du-temps.generation.generer', $this->planning))
            ->assertNotFound();
    }

    public function test_module_desactive_repond_404(): void
    {
        config(['modules.schedule.active' => false]);

        $this->actingAs($this->admin)
            ->get(route('admin.emplois-du-temps.generation.index', $this->planning))
            ->assertNotFound();
        $this->actingAs($this->admin)
            ->post(route('admin.emplois-du-temps.generation.generer', $this->planning))
            ->assertNotFound();
    }

    public function test_simulation_http_ecrit_zero_creneau_et_trace_le_mode(): void
    {
        $reponse = $this->actingAs($this->admin)
            ->post(route('admin.emplois-du-temps.generation.simuler', $this->planning));

        $reponse->assertRedirect(route('admin.emplois-du-temps.generation.index', $this->planning));
        $reponse->assertSessionHas('success');

        $this->assertDatabaseCount('schedule_slots', 0);
        $this->assertSame('brouillon', $this->planning->fresh()->statut);

        $generation = TimetableGeneration::firstOrFail();
        $this->assertTrue($generation->parametres['dry_run']);
        $this->assertSame($this->admin->id, $generation->auteur_id);
    }

    public function test_generation_http_produit_un_brouillon_sans_publication(): void
    {
        // Un enseignement à volume CM 42 h ⇒ 2 séances de CM restantes :
        // c'est la source de demandes du moteur hors appels API.
        $type = TypeEnseignement::create(['code' => 'CM', 'nom' => 'Cours magistral', 'actif' => true]);
        Enseignement::create([
            'code' => 'ENS-ALGO-B-S1',
            'matiere_id' => $this->matiereAlgo->id,
            'type_id' => $type->id,
            'annee_universitaire_id' => $this->annee->id,
            'volume_cm' => 42,
            'volume_td' => 0,
            'volume_tp' => 0,
            'statut' => 'active',
        ]);
        $this->salleA->update(['equipements_fixes' => ['videoprojecteur']]);

        $reponse = $this->actingAs($this->admin)
            ->post(route('admin.emplois-du-temps.generation.generer', $this->planning), [
                'ecraser_brouillon' => 1,
                'jours_autorises' => ['monday', 'tuesday'],
            ]);

        $reponse->assertRedirect(route('admin.emplois-du-temps.generation.index', $this->planning));
        $reponse->assertSessionHas('success');

        // Les séances issues de l'enseignement alimentent le brouillon —
        // statut inchangé : aucune publication automatique.
        $this->assertSame(2, ScheduleSlot::where('schedule_id', $this->planning->id)->count());
        $this->assertSame('brouillon', $this->planning->fresh()->statut);

        $generation = TimetableGeneration::firstOrFail();
        $this->assertFalse($generation->parametres['dry_run']);
        $this->assertSame($this->admin->id, $generation->auteur_id);
    }

    public function test_preparation_des_demandes_suit_la_chaine_lmd_de_la_faculte(): void
    {
        $type = TypeEnseignement::create(['code' => 'CM', 'nom' => 'Cours magistral', 'actif' => true]);

        $creerEnseignement = function (Matiere $matiere) use ($type): Enseignement {
            return Enseignement::create([
                'code' => 'ENS-'.$matiere->code,
                'matiere_id' => $matiere->id,
                'type_id' => $type->id,
                'annee_universitaire_id' => $this->annee->id,
                'volume_cm' => 42,
                'volume_td' => 0,
                'volume_tp' => 0,
                'statut' => 'active',
            ]);
        };

        // 1) Matière rattachée à l'UE du programme de la faculté : retenue.
        $enseignement = $creerEnseignement($this->matiereAlgo);

        // 2) Matière sans UE : transversale (scope canonique pourFaculte).
        $matiereTransversale = Matiere::create([
            'code' => 'TRANS_B', 'nom' => 'Matière transversale', 'niveau' => 'L2', 'semestre' => 1,
        ]);
        $creerEnseignement($matiereTransversale);

        // 3) Matière d'un programme d'une AUTRE faculté : exclue.
        $faculteAutre = Faculte::create(['nom' => 'Autre B', 'code' => 'AUT_B', 'active' => true]);
        $programmeAutre = Programme::create([
            'faculte_id' => $faculteAutre->id, 'code' => 'LMI_AUT', 'nom' => 'Licence autre',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $niveauAutre = Niveau::create(['programme_id' => $programmeAutre->id, 'code' => 'L2', 'nom' => 'L2', 'ordre' => 2]);
        $semestreAutre = SemestreLmd::create([
            'niveau_id' => $niveauAutre->id, 'code' => 'S1', 'nom' => 'S1', 'numero' => 1, 'ordre' => 1,
        ]);
        $ueAutre = UniteEnseignement::create([
            'semestre_id' => $semestreAutre->id, 'programme_id' => $programmeAutre->id,
            'code' => 'UE_AUT1', 'nom' => 'UE autre', 'credits' => 6, 'coefficient' => 1,
        ]);
        $matiereAutre = Matiere::create([
            'code' => 'AUT_B1', 'nom' => 'Matière autre faculté', 'niveau' => 'L2', 'semestre' => 1, 'ue_id' => $ueAutre->id,
        ]);
        $creerEnseignement($matiereAutre);

        $demandes = app(ScheduleGeneratorService::class)
            ->preparerDemandesDepuisEnseignements($this->planning->fresh());

        $this->assertNotEmpty($demandes);
        $this->assertSame(
            [$this->matiereAlgo->id, $matiereTransversale->id],
            $demandes->pluck('matiereId')->unique()->values()->all()
        );
        $this->assertNotContains($matiereAutre->id, $demandes->pluck('matiereId')->all());
        $this->assertSame(
            ['cm_'.$enseignement->id.'_1', 'cm_'.$enseignement->id.'_2'],
            $demandes->where('matiereId', $this->matiereAlgo->id)->pluck('id')->values()->all()
        );
    }

    public function test_generation_http_refusee_sur_planning_publie(): void
    {
        $this->planning->update(['statut' => 'publie']);

        $reponse = $this->actingAs($this->admin)
            ->post(route('admin.emplois-du-temps.generation.generer', $this->planning));

        $reponse->assertRedirect(route('admin.emplois-du-temps.generation.index', $this->planning));
        $reponse->assertSessionHasErrors('generation');
        $this->assertDatabaseCount('schedule_slots', 0);
        $this->assertSame('publie', $this->planning->fresh()->statut);
    }

    public function test_generation_http_options_invalides_rejetees_sans_ecriture(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.emplois-du-temps.generation.generer', $this->planning), [
                'jours_autorises' => ['lundi'],
                'timeout_secondes' => 500,
            ])
            ->assertSessionHasErrors(['jours_autorises.0', 'timeout_secondes']);

        $this->assertDatabaseCount('schedule_slots', 0);
        $this->assertSame(0, TimetableGeneration::count());
    }

    public function test_parametres_generation_lus_par_le_controleur(): void
    {
        $this->parametres()->set('planning.generation.poids_continuite', '7');

        $reponse = $this->actingAs($this->admin)
            ->get(route('admin.emplois-du-temps.generation.index', $this->planning));

        $reponse->assertOk();
        $reponse->assertSee('Continuité : 7/10', false);

        $this->assertSame(7, app(ScheduleGenerationSettings::class)->poidsContinuite());
    }

    public function test_generation_inclut_enseignants_assignes_meme_hors_faculte(): void
    {
        $autreFaculte = Faculte::create([
            'nom' => 'Faculté d\'Économie',
            'code' => 'FEG_TEST',
            'active' => true,
        ]);
        $autreDept = Departement::create([
            'faculte_id' => $autreFaculte->id,
            'code' => 'DEP_ECO_TEST',
            'nom' => 'Département Économie',
            'actif' => true,
        ]);
        $enseignantExterne = Teacher::create([
            'matricule' => 'ENS-EXT-999',
            'nom' => 'Ben Mansour',
            'prenom' => 'Kamel',
            'email' => 'kamel.mansour@universite.test',
            'departement_id' => $autreDept->id,
            'qualifications' => ['ALGO_B'],
            'actif' => true,
        ]);

        $demande = new SeanceDemande(
            id: 'cours_ext_1',
            matiereId: $this->matiereAlgo->id,
            type: 'course',
            titre: 'Cours Algo Externe',
            dureeMinutes: 90,
            teacherId: $enseignantExterne->id
        );

        $generation = $this->moteur()->generer($this->planning, [
            'seances_demandes' => [$demande],
            'ecraser_brouillon' => true,
        ]);

        $this->assertTrue($generation->estSucces());
        $this->assertSame(1, $generation->nombre_seances_placees);
        $slot = ScheduleSlot::where('schedule_id', $this->planning->id)->firstOrFail();
        $this->assertSame($enseignantExterne->id, $slot->teacher_id);
    }

    public function test_preparer_demandes_depuis_enseignements_sans_equipement_bloquant(): void
    {
        $type = TypeEnseignement::firstOrCreate(
            ['code' => 'CM_TD'],
            ['nom' => 'Cours magistral et TD', 'actif' => true]
        );

        $enseignement = Enseignement::create([
            'code' => 'ENS-TEST-PREP',
            'matiere_id' => $this->matiereAlgo->id,
            'type_id' => $type->id,
            'annee_universitaire_id' => $this->annee->id,
            'volume_cm' => 28,
            'volume_td' => 14,
            'volume_tp' => 14,
            'statut' => 'active',
        ]);
        $enseignement->groupes()->attach($this->groupe->id);

        $demandes = $this->moteur()->preparerDemandesDepuisEnseignements($this->planning);

        $this->assertNotEmpty($demandes);
        foreach ($demandes as $demande) {
            $this->assertEmpty(
                $demande->equipementsRequis,
                "La séance {$demande->titre} ne doit pas imposer d'équipement bloquant par défaut."
            );
        }
    }
}
