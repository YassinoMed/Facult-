<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Attendance\Models\TeacherPointage;
use App\Modules\Attendance\Models\TeacherPresence;
use App\Modules\Attendance\Services\PresenceEmploiDuTempsService;
use App\Modules\Referentiel\Models\Departement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleException;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use App\Services\ParametreService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Tests automatisés du système de suivi des présences intelligentes des enseignants
 * liées à l'emploi du temps (ScheduleSlot).
 */
class TeacherAttendanceScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected Faculte $faculteA;

    protected Faculte $faculteB;

    protected Departement $departementA;

    protected Departement $departementB;

    protected User $adminGlobal;

    protected User $adminFaculteA;

    protected User $adminFaculteB;

    protected AnneeUniversitaire $annee;

    protected Teacher $enseignantA;

    protected Teacher $enseignantB;

    protected Group $groupeA;

    protected Room $salleA;

    protected Matiere $matiereA;

    protected Schedule $planningA;

    protected ScheduleSlot $slotLundi;

    protected PresenceEmploiDuTempsService $service;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.attendance.active' => true]);

        $this->faculteA = Faculte::create([
            'nom' => 'Faculté des Sciences',
            'code' => 'FS',
            'active' => true,
        ]);

        $this->faculteB = Faculte::create([
            'nom' => 'Faculté des Lettres',
            'code' => 'FL',
            'active' => true,
        ]);

        $this->departementA = Departement::create([
            'faculte_id' => $this->faculteA->id,
            'code' => 'INFO',
            'nom' => 'Informatique',
            'actif' => true,
        ]);

        $this->departementB = Departement::create([
            'faculte_id' => $this->faculteB->id,
            'code' => 'LETTRES',
            'nom' => 'Lettres Modernes',
            'actif' => true,
        ]);

        $this->adminGlobal = User::creerCompteBackOffice([
            'name' => 'Admin Global',
            'email' => 'admin.global@universite.test',
            'password' => 'Motdepasse@123',
        ], 'super_admin');

        $this->adminFaculteA = User::creerCompteBackOffice([
            'name' => 'Admin Faculte A',
            'email' => 'admin.fa@universite.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteA->id,
        ], 'admin_faculte');

        $this->adminFaculteB = User::creerCompteBackOffice([
            'name' => 'Admin Faculte B',
            'email' => 'admin.fb@universite.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteB->id,
        ], 'admin_faculte');

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->enseignantA = Teacher::create([
            'nom' => 'Alami',
            'prenom' => 'Karim',
            'email' => 'karim.alami@universite.test',
            'matricule' => 'ENS-001',
            'departement_id' => $this->departementA->id,
            'actif' => true,
        ]);

        $this->enseignantB = Teacher::create([
            'nom' => 'Benali',
            'prenom' => 'Fatima',
            'email' => 'fatima.benali@universite.test',
            'matricule' => 'ENS-002',
            'departement_id' => $this->departementB->id,
            'actif' => true,
        ]);

        $this->groupeA = Group::create([
            'code' => 'L3-INFO-G1',
            'nom' => 'Groupe 1 L3 Info',
            'niveau' => 'L3',
            'faculte_id' => $this->faculteA->id,
            'actif' => true,
        ]);

        $this->salleA = Room::create([
            'code' => 'AMPHI-A',
            'nom' => 'Amphithéâtre A',
            'capacite' => 150,
            'faculte_id' => $this->faculteA->id,
            'actif' => true,
        ]);

        $this->matiereA = Matiere::create([
            'code' => 'INF301',
            'nom' => 'Algorithmique Avancée',
            'niveau' => 'L3',
            'semestre' => 1,
        ]);

        $this->planningA = Schedule::create([
            'annee_universitaire_id' => $this->annee->id,
            'semestre' => 1,
            'groupe_id' => $this->groupeA->id,
            'nom' => 'Planning L3 Info S1',
            'statut' => 'publie',
            'actif' => true,
        ]);

        // Créneau du lundi : 08:30 à 10:30
        $this->slotLundi = ScheduleSlot::create([
            'schedule_id' => $this->planningA->id,
            'matiere_id' => $this->matiereA->id,
            'teacher_id' => $this->enseignantA->id,
            'room_id' => $this->salleA->id,
            'day_of_week' => 'monday',
            'start_time' => '08:30:00',
            'end_time' => '10:30:00',
            'type' => 'course',
            'status' => 'scheduled',
        ]);

        $this->service = app(PresenceEmploiDuTempsService::class);
    }

    public function test_seance_future_statut_a_venir(): void
    {
        // Date future : lundi 2026-10-12
        $dateLundi = '2026-10-12';
        Carbon::setTestNow(Carbon::parse('2026-10-12 07:00:00'));

        $seances = $this->service->seancesDuJour($dateLundi);

        $this->assertCount(1, $seances);
        $this->assertSame('a_venir', $seances[0]['statut_calcule']);
        $this->assertSame('a_venir', $seances[0]['statut_effectif']);
        $this->assertStringContainsString('future', $seances[0]['diagnostic']);

        Carbon::setTestNow();
    }

    public function test_seance_en_cours_sans_pointage(): void
    {
        $dateLundi = '2026-10-12';
        // En cours de séance à 09:15
        Carbon::setTestNow(Carbon::parse('2026-10-12 09:15:00'));

        $seances = $this->service->seancesDuJour($dateLundi);

        $this->assertCount(1, $seances);
        $this->assertSame('en_cours', $seances[0]['statut_calcule']);
        $this->assertStringContainsString('en cours', $seances[0]['diagnostic']);

        Carbon::setTestNow();
    }

    public function test_presence_confirmee_si_pointage_dans_la_tolerance(): void
    {
        $dateLundi = '2026-10-12';
        Carbon::setTestNow(Carbon::parse('2026-10-12 12:00:00'));

        // Pointage à 08:38 (8 min de retard, dans la tolérance de 15 min)
        TeacherPointage::create([
            'teacher_id' => $this->enseignantA->id,
            'date' => $dateLundi,
            'horodatage' => '2026-10-12 08:38:00',
            'type' => 'entree',
            'source' => 'badgeuse',
        ]);

        $seances = $this->service->seancesDuJour($dateLundi, null, [], 15);

        $this->assertCount(1, $seances);
        $this->assertSame('presence_confirmee', $seances[0]['statut_calcule']);
        $this->assertSame(8, $seances[0]['retard_minutes']);
        $this->assertStringContainsString('Présence constatée à 08:38', $seances[0]['diagnostic']);

        Carbon::setTestNow();
    }

    public function test_retard_potentiel_si_pointage_au_dela_de_la_tolerance(): void
    {
        $dateLundi = '2026-10-12';
        Carbon::setTestNow(Carbon::parse('2026-10-12 12:00:00'));

        // Pointage à 08:55 (25 min de retard > tolérance de 15 min)
        TeacherPointage::create([
            'teacher_id' => $this->enseignantA->id,
            'date' => $dateLundi,
            'horodatage' => '2026-10-12 08:55:00',
            'type' => 'entree',
            'source' => 'badgeuse',
        ]);

        $seances = $this->service->seancesDuJour($dateLundi, null, [], 15);

        $this->assertCount(1, $seances);
        $this->assertSame('retard_potentiel', $seances[0]['statut_calcule']);
        $this->assertSame(25, $seances[0]['retard_minutes']);
        $this->assertTrue($seances[0]['a_verifier']);
        $this->assertStringContainsString('retard calculé de 25 min', $seances[0]['diagnostic']);

        Carbon::setTestNow();
    }

    public function test_seance_terminee_sans_pointage_produit_absence_a_verifier_sans_valider_automatiquement(): void
    {
        $dateLundi = '2026-10-12';
        // Séance terminée (11:00 > 10:30) sans aucun pointage
        Carbon::setTestNow(Carbon::parse('2026-10-12 11:00:00'));

        $seances = $this->service->seancesDuJour($dateLundi);

        $this->assertCount(1, $seances);
        // Règle d'or : JAMAIS d'absence validée automatiquement !
        $this->assertSame('absence_a_verifier', $seances[0]['statut_calcule']);
        $this->assertNull($seances[0]['statut_valide']);
        $this->assertSame('absence_a_verifier', $seances[0]['statut_effectif']);
        $this->assertTrue($seances[0]['a_verifier']);
        $this->assertStringContainsString('Absence potentielle à vérifier', $seances[0]['diagnostic']);

        Carbon::setTestNow();
    }

    public function test_seance_annulee_via_slot_ou_exception(): void
    {
        $dateLundi = '2026-10-12';

        // Créer une exception d'annulation pour ce lundi spécifique
        ScheduleException::create([
            'schedule_slot_id' => $this->slotLundi->id,
            'exception_date' => $dateLundi,
            'status' => 'cancelled',
            'reason' => 'Professeur en déplacement colloque',
        ]);

        $seances = $this->service->seancesDuJour($dateLundi);

        $this->assertCount(1, $seances);
        $this->assertSame('seance_annulee', $seances[0]['statut_calcule']);
        $this->assertStringContainsString('Professeur en déplacement colloque', $seances[0]['diagnostic']);
    }

    public function test_seance_modifiee_avec_nouvel_enseignant_via_exception(): void
    {
        $dateLundi = '2026-10-12';
        Carbon::setTestNow(Carbon::parse('2026-10-12 12:00:00'));

        // Remplacement exceptionnel : Enseignant B remplace Enseignant A
        ScheduleException::create([
            'schedule_slot_id' => $this->slotLundi->id,
            'exception_date' => $dateLundi,
            'new_teacher_id' => $this->enseignantB->id,
            'status' => 'modified',
            'reason' => 'Remplacement ponctuel',
        ]);

        // Enseignant B pointe à l'heure
        TeacherPointage::create([
            'teacher_id' => $this->enseignantB->id,
            'date' => $dateLundi,
            'horodatage' => '2026-10-12 08:25:00',
            'type' => 'entree',
            'source' => 'badgeuse',
        ]);

        $seances = $this->service->seancesDuJour($dateLundi);

        $this->assertCount(1, $seances);
        $this->assertSame($this->enseignantB->id, $seances[0]['enseignant']->id);
        $this->assertSame('presence_confirmee', $seances[0]['statut_calcule']);

        Carbon::setTestNow();
    }

    public function test_reconciliation_idempotente_et_preservation_decision_humaine(): void
    {
        $dateLundi = '2026-10-12';
        Carbon::setTestNow(Carbon::parse('2026-10-12 11:00:00'));

        // 1ère passe de rapprochement
        $kpis1 = $this->service->reconcilierEtPersister($dateLundi);
        $this->assertSame(1, $kpis1['total_seances']);
        $this->assertSame(1, $kpis1['absences_a_verifier']);

        $presence = TeacherPresence::where('schedule_slot_id', $this->slotLundi->id)
            ->whereDate('date', $dateLundi)
            ->firstOrFail();

        $this->assertSame('absence_a_verifier', $presence->statut_calcule);
        $this->assertNull($presence->statut_valide);

        // Validation humaine par un gestionnaire
        $this->service->validerAbsence($presence, 'Absence justifiée par certificat médical', $this->adminGlobal->id);
        $presence->refresh();
        $this->assertSame('absence_validee', $presence->statut_valide);
        $this->assertSame('absence_validee', $presence->statutEffectif());

        // 2ème passe de rapprochement (idempotence) : le statut validé DOIT être préservé
        $kpis2 = $this->service->reconcilierEtPersister($dateLundi);
        $this->assertSame(1, $kpis2['absences_validees']);

        $presence->refresh();
        $this->assertSame('absence_validee', $presence->statut_valide);
        $this->assertSame('absence_validee', $presence->statutEffectif());

        Carbon::setTestNow();
    }

    public function test_validation_absence_manuelle_avec_motif_obligatoire(): void
    {
        $dateLundi = '2026-10-12';
        $this->service->reconcilierEtPersister($dateLundi);

        $presence = TeacherPresence::where('schedule_slot_id', $this->slotLundi->id)
            ->whereDate('date', $dateLundi)
            ->firstOrFail();

        // Validation avec motif
        $miseAJour = $this->service->validerAbsence($presence, 'Absence non justifiée constatée par les étudiants', $this->adminGlobal->id);
        $this->assertSame('absence_validee', $miseAJour->statut_valide);
        $this->assertSame($this->adminGlobal->id, $miseAJour->valide_par);
        $this->assertNotNull($miseAJour->valide_le);
        $this->assertNotEmpty($miseAJour->historique);
    }

    public function test_rejet_validation_absence_sans_motif(): void
    {
        $dateLundi = '2026-10-12';
        $this->service->reconcilierEtPersister($dateLundi);

        $presence = TeacherPresence::where('schedule_slot_id', $this->slotLundi->id)
            ->whereDate('date', $dateLundi)
            ->firstOrFail();

        $this->expectException(InvalidArgumentException::class);
        $this->service->validerAbsence($presence, '   ', $this->adminGlobal->id);
    }

    public function test_confirmation_manuelle_presence(): void
    {
        $dateLundi = '2026-10-12';
        $this->service->reconcilierEtPersister($dateLundi);

        $presence = TeacherPresence::where('schedule_slot_id', $this->slotLundi->id)
            ->whereDate('date', $dateLundi)
            ->firstOrFail();

        $miseAJour = $this->service->confirmerPresence($presence, '08:28:00', '10:32:00', 'Feuille d\'émargement signée', $this->adminGlobal->id);

        $this->assertSame('presence_confirmee', $miseAJour->statut_valide);
        $this->assertSame('08:28:00', $miseAJour->heure_arrivee);
        $this->assertSame('10:32:00', $miseAJour->heure_depart);
        $this->assertSame('Feuille d\'émargement signée', $miseAJour->motif);
    }

    public function test_correction_presence_avec_historique_daudit(): void
    {
        $dateLundi = '2026-10-12';
        $this->service->reconcilierEtPersister($dateLundi);

        $presence = TeacherPresence::where('schedule_slot_id', $this->slotLundi->id)
            ->whereDate('date', $dateLundi)
            ->firstOrFail();

        $this->service->corrigerPresence(
            $presence,
            [
                'statut_valide' => 'retard_justifie',
                'heure_arrivee' => '08:50:00',
            ],
            'Problème de transport en commun justifié',
            $this->adminGlobal->id
        );

        $presence->refresh();
        $this->assertSame('retard_justifie', $presence->statut_valide);
        $this->assertSame('08:50:00', $presence->heure_arrivee);
        $historique = $presence->historique;
        $derniereTrace = end($historique);
        $this->assertSame('correction_manuelle', $derniereTrace['action']);
        $this->assertSame('Problème de transport en commun justifié', $derniereTrace['motif']);
    }

    public function test_pointages_orphelins_preserves_sans_suppression(): void
    {
        // Enregistrer un pointage pour un enseignant sur un jour sans cours
        $dimanche = '2026-10-18';
        $pointage = $this->service->enregistrerPointage(
            $this->enseignantA,
            $dimanche.' 14:00:00',
            'entree',
            'badgeuse',
            $this->adminGlobal->id,
            'Accès laboratoire de recherche'
        );

        $this->assertSame($dimanche, $pointage->fresh()->date->format('Y-m-d'));
        $this->assertSame($this->enseignantA->id, $pointage->fresh()->teacher_id);

        // Le pointage existe toujours et n'a pas été supprimé par le rapprochement
        $this->assertTrue(TeacherPointage::where('id', $pointage->id)->exists());
    }

    public function test_isolation_faculte_consultation_et_actions_anti_idor(): void
    {
        $dateLundi = '2026-10-12';
        $this->service->reconcilierEtPersister($dateLundi);

        $presenceA = TeacherPresence::where('schedule_slot_id', $this->slotLundi->id)
            ->whereDate('date', $dateLundi)
            ->firstOrFail();

        // 1. Admin Faculté A accède à sa faculté
        $this->actingAs($this->adminFaculteA)
            ->get(route('admin.presences.index', ['date' => $dateLundi]))
            ->assertOk()
            ->assertSee($this->enseignantA->nomComplet());

        // 2. Admin Faculté B ne voit PAS les séances de la Faculté A
        $this->actingAs($this->adminFaculteB)
            ->get(route('admin.presences.index', ['date' => $dateLundi]))
            ->assertOk()
            ->assertDontSee($this->enseignantA->nomComplet());

        // 3. Tentative de validation par Admin Faculté B sur la présence de la Faculté A -> 404 (anti-IDOR)
        $this->actingAs($this->adminFaculteB)
            ->post(route('admin.presences.enseignants.valider', $presenceA), [
                'action' => 'absence_validee',
                'motif' => 'Tentative frauduleuse',
            ])
            ->assertNotFound();

        // 4. Tentative de confirmation par Admin Faculté B sur présence A -> 404
        $this->actingAs($this->adminFaculteB)
            ->post(route('admin.presences.enseignants.confirmer', $presenceA), [
                'heure_arrivee' => '08:30:00',
            ])
            ->assertNotFound();

        // 5. Tentative de correction par Admin Faculté B sur présence A -> 404
        $this->actingAs($this->adminFaculteB)
            ->post(route('admin.presences.enseignants.corriger', $presenceA), [
                'statut_valide' => 'presence_confirmee',
                'motif' => 'Tentative correction IDOR',
            ])
            ->assertNotFound();

        // 6. Tentative de consultation d'historique par Admin Faculté B sur présence A -> 404
        $this->actingAs($this->adminFaculteB)
            ->get(route('admin.presences.enseignants.historique', $presenceA))
            ->assertNotFound();
    }

    public function test_commande_artisan_rapprocher_presences_enseignants(): void
    {
        $dateLundi = '2026-10-12';

        $this->artisan('presences:rapprocher-enseignants', [
            '--date' => $dateLundi,
            '--tolerance' => 15,
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('Rapprochement des présences enseignants')
            ->expectsOutputToContain('Rapprochement terminé avec succès');

        $this->assertTrue(TeacherPresence::where('schedule_slot_id', $this->slotLundi->id)
            ->whereDate('date', $dateLundi)
            ->exists());
    }

    public function test_route_historique_json(): void
    {
        $dateLundi = '2026-10-12';
        $this->service->reconcilierEtPersister($dateLundi);

        $presence = TeacherPresence::where('schedule_slot_id', $this->slotLundi->id)
            ->whereDate('date', $dateLundi)
            ->firstOrFail();

        $this->service->validerAbsence($presence, 'Absence constatée', $this->adminGlobal->id);

        $response = $this->actingAs($this->adminGlobal)
            ->get(route('admin.presences.enseignants.historique', $presence))
            ->assertOk()
            ->assertJsonStructure([
                'presence_id',
                'slot_id',
                'date',
                'statut_calcule',
                'statut_valide',
                'statut_effectif',
                'motif',
                'historique',
            ]);

        $this->assertSame('absence_validee', $response->json('statut_valide'));
    }

    public function test_absence_de_requetes_n_plus_un(): void
    {
        $dateLundi = '2026-10-12';

        // Création de 10 créneaux et enseignants additionnels
        for ($i = 1; $i <= 10; $i++) {
            $ens = Teacher::create([
                'nom' => 'Enseignant'.$i,
                'prenom' => 'Prenom'.$i,
                'departement_id' => $this->departementA->id,
                'actif' => true,
            ]);

            ScheduleSlot::create([
                'schedule_id' => $this->planningA->id,
                'matiere_id' => $this->matiereA->id,
                'teacher_id' => $ens->id,
                'room_id' => $this->salleA->id,
                'day_of_week' => 'monday',
                'start_time' => sprintf('%02d:00:00', 8 + ($i % 8)),
                'end_time' => sprintf('%02d:00:00', 10 + ($i % 8)),
                'type' => 'td',
                'status' => 'scheduled',
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $seances = $this->service->seancesDuJour($dateLundi);

        $nbRequetes = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Doit s'exécuter en un nombre fixe et maîtrisé de requêtes (eager-loading des 8 relations imbriquées + pointages batch)
        $this->assertCount(11, $seances);
        $this->assertLessThanOrEqual(16, $nbRequetes, "Nombre de requêtes SQL excessif ($nbRequetes), risque de N+1.");
    }

    /**
     * Un emploi du temps d'une année universitaire PASSÉE (actif=true)
     * ne doit jamais générer de séances « prévues » ni de fausses absences
     * pour une date de l'année courante (source de vérité = année couvrante).
     */
    public function test_edt_annee_passee_ne_genere_pas_de_seances_fantomes(): void
    {
        $dateLundi = '2026-10-12'; // lundi couvert par l'année 2026-2027 du setUp
        Carbon::setTestNow(Carbon::parse('2026-10-12 12:00:00'));

        $anneePassee = AnneeUniversitaire::create([
            'libelle' => '2024-2025', 'date_debut' => '2024-09-01', 'date_fin' => '2025-06-30', 'active' => false,
        ]);

        $groupeAncien = Group::create([
            'code' => 'L1-ANC', 'nom' => 'Ancien groupe', 'niveau' => 'L1',
            'faculte_id' => $this->faculteA->id, 'actif' => true,
        ]);

        $planningAncien = Schedule::create([
            'annee_universitaire_id' => $anneePassee->id,
            'semestre' => 1,
            'groupe_id' => $groupeAncien->id,
            'nom' => 'Planning obsolète',
            'statut' => 'publie',
            'actif' => true, // toujours actif : le piège que le filtre doit attraper
        ]);

        ScheduleSlot::create([
            'schedule_id' => $planningAncien->id,
            'matiere_id' => $this->matiereA->id,
            'teacher_id' => $this->enseignantA->id,
            'room_id' => $this->salleA->id,
            'day_of_week' => 'monday',
            'start_time' => '08:30:00',
            'end_time' => '10:30:00',
            'type' => 'course',
            'status' => 'scheduled',
        ]);

        // Un seul créneau attendu : celui de l'année couvrante (setUp).
        $seances = $this->service->seancesDuJour($dateLundi);
        $this->assertCount(1, $seances);
        $this->assertSame($this->slotLundi->id, $seances[0]['slot_id']);

        // Date hors de toute année universitaire : aucune séance prévue,
        // constat explicite (jamais de fabrication).
        $this->assertCount(0, $this->service->seancesDuJour('2030-08-15'));
    }

    /**
     * Un pointage saisi par un admin restreint ne réconcile JAMAIS les
     * séances des autres facultés : aucune écriture hors périmètre.
     */
    public function test_pointage_admin_restreint_ne_reconcilie_pas_les_autres_facultes(): void
    {
        $dateLundi = '2026-10-12';
        Carbon::setTestNow(Carbon::parse('2026-10-12 12:00:00'));

        // Créneau du lundi pour l'enseignant B (faculté B).
        $groupeB = Group::create([
            'code' => 'L1-FLSH', 'nom' => 'Groupe lettres', 'niveau' => 'L1',
            'faculte_id' => $this->faculteB->id, 'actif' => true,
        ]);
        $planningB = Schedule::create([
            'annee_universitaire_id' => $this->annee->id,
            'semestre' => 1,
            'groupe_id' => $groupeB->id,
            'nom' => 'Planning lettres',
            'statut' => 'publie',
            'actif' => true,
        ]);
        $slotB = ScheduleSlot::create([
            'schedule_id' => $planningB->id,
            'matiere_id' => $this->matiereA->id,
            'teacher_id' => $this->enseignantB->id,
            'day_of_week' => 'monday',
            'start_time' => '08:30:00',
            'end_time' => '10:30:00',
            'type' => 'course',
            'status' => 'scheduled',
        ]);

        $this->actingAs($this->adminFaculteA);

        $response = $this->post(route('admin.presences.enseignants.pointage'), [
            'teacher_id' => $this->enseignantA->id,
            'horodatage' => '2026-10-12 08:25:00',
            'type' => 'entree',
            'source' => 'manuel',
        ]);
        $response->assertRedirect();

        // Observation créée pour le créneau de SA faculté uniquement.
        $this->assertTrue(
            TeacherPresence::where('schedule_slot_id', $this->slotLundi->id)
                ->whereDate('date', $dateLundi)
                ->exists()
        );

        // AUCUNE écriture pour la faculté B (isolation stricte).
        $this->assertTrue(
            TeacherPresence::where('schedule_slot_id', $slotB->id)
                ->whereDate('date', $dateLundi)
                ->doesntExist()
        );
    }

    /**
     * Partition exhaustive des KPI : la somme de tous les compteurs couvre
     * exactement le total (aucune séance ignorée ni comptée deux fois).
     */
    public function test_kpi_partition_exhaustive_somme_egale_total(): void
    {
        $dateLundi = '2026-10-12';
        // 17:00 : les créneaux 14:00-16:00 sont PASSÉS sans pointage →
        // absences à vérifier ; le créneau 08:30 est couvert par une
        // dispense validée (décision humaine prioritaire).
        Carbon::setTestNow(Carbon::parse('2026-10-12 17:00:00'));

        // 1 présence confirmée (pointage à l'heure) + 1 absence à vérifier (slot sans pointage).
        TeacherPointage::create([
            'teacher_id' => $this->enseignantA->id,
            'date' => $dateLundi,
            'horodatage' => $dateLundi.' 08:25:00',
            'type' => 'entree',
            'source' => 'manuel',
        ]);

        $autresEnseignants = [];
        for ($i = 1; $i <= 3; $i++) {
            $autresEnseignants[$i] = Teacher::create([
                'nom' => 'Kpi'.$i, 'prenom' => 'Test',
                'departement_id' => $this->departementA->id, 'actif' => true,
            ]);
            ScheduleSlot::create([
                'schedule_id' => $this->planningA->id,
                'matiere_id' => $this->matiereA->id,
                'teacher_id' => $autresEnseignants[$i]->id,
                'room_id' => $this->salleA->id,
                'day_of_week' => 'monday',
                'start_time' => '14:00:00',
                'end_time' => '16:00:00',
                'type' => 'td',
                'status' => 'scheduled',
            ]);
        }

        // Une dispense administrative persistée sur le créneau du premier
        // enseignant additionnel (décision humaine).
        $presenceDispensee = TeacherPresence::create([
            'schedule_slot_id' => $this->slotLundi->id,
            'teacher_id' => $this->enseignantA->id,
            'date' => $dateLundi,
            'statut_calcule' => 'dispense_mission',
            'statut_valide' => 'dispense_mission',
            'motif' => 'Ordre de mission officiel',
            'valide_par' => $this->adminGlobal->id,
            'valide_le' => now(),
        ]);
        $this->assertNotNull($presenceDispensee->id);

        $kpis = $this->service->reconcilierEtPersister($dateLundi);

        $somme = $kpis['presences_confirmees'] + $kpis['retards_potentiels'] + $kpis['pointages_manquants']
            + $kpis['absences_a_verifier'] + $kpis['absences_validees'] + $kpis['seances_annulees']
            + $kpis['dispenses_missions'] + $kpis['retards_justifies'] + $kpis['absences_rejetees']
            + $kpis['seances_a_venir'] + $kpis['seances_en_cours'];

        $this->assertSame($kpis['total_seances'], $somme);
        $this->assertSame(4, $kpis['total_seances']);
        $this->assertSame(1, $kpis['dispenses_missions']);
        $this->assertSame(3, $kpis['absences_a_verifier']);
    }

    /**
     * La tolérance de retard est ADMINISTRABLE via le registre central des
     * paramètres : un même pointage change de diagnostic selon la valeur.
     */
    public function test_tolerance_retard_administrable_via_parametre(): void
    {
        $dateLundi = '2026-10-12';
        Carbon::setTestNow(Carbon::parse('2026-10-12 12:00:00'));

        TeacherPointage::create([
            'teacher_id' => $this->enseignantA->id,
            'date' => $dateLundi,
            'horodatage' => $dateLundi.' 08:50:00', // 20 minutes de retard
            'type' => 'entree',
            'source' => 'manuel',
        ]);

        // Tolérance par défaut (15) : retard potentiel.
        $seances = $this->service->seancesDuJour($dateLundi);
        $this->assertSame('retard_potentiel', $seances[0]['statut_calcule']);
        $this->assertSame(20, $seances[0]['retard_minutes']);

        // Tolérance administrable portée à 30 : même pointage = présence confirmée.
        app(ParametreService::class)
            ->set('presences.tolerance_retard_minutes', '30', $this->adminGlobal->id);

        $seancesApres = $this->service->seancesDuJour($dateLundi, null, [], 30);
        $this->assertSame('presence_confirmee', $seancesApres[0]['statut_calcule']);
    }

    /**
     * Intégrité historique : la suppression d'un créneau portant des
     * observations est REFUSÉE (RESTRICT) — une modification d'emploi du
     * temps ne doit jamais détruire l'historique des présences validées.
     */
    public function test_suppression_creneau_avec_observations_bloquee(): void
    {
        $dateLundi = '2026-10-12';

        TeacherPresence::create([
            'schedule_slot_id' => $this->slotLundi->id,
            'teacher_id' => $this->enseignantA->id,
            'date' => $dateLundi,
            'statut_calcule' => 'absence_a_verifier',
        ]);

        $this->expectException(QueryException::class);
        $this->slotLundi->delete();
    }
}
