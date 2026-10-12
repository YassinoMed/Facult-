<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\AppNotification;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Announcements\Models\Annonce;
use App\Modules\Attendance\Models\TeacherPresence;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\EvaluationIncident;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Services\SurveillanceService;
use App\Modules\Referentiel\Models\Departement;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Espace Enseignant — modules : emploi du temps, surveillances (+ incidents),
 * présences, annonces, documents, notifications. Ownership stricte depuis la
 * session, sans permission back-office.
 */
class EspaceEnseignantModulesTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private Faculte $faculteB;

    private AnneeUniversitaire $annee;

    private Programme $programme;

    private SessionExamen $session;

    private Examen $examen;

    private Matiere $matiere;

    private Room $salle;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'modules.evaluation.active' => true,
            'modules.schedule.active' => true,
            'modules.attendance.active' => true,
            'modules.announcements.active' => true,
        ]);

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'active' => true]);
        $this->faculteB = Faculte::create(['nom' => 'FLSH', 'code' => 'FLSH', 'active' => true]);
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->programme = Programme::create([
            'code' => 'L-INFO', 'nom' => 'Licence Info', 'faculte_id' => $this->faculte->id,
            'cycle' => 'licence', 'duree_annees' => 3, 'actif' => true,
        ]);
        $this->session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id, 'code' => 'SESS-ENS', 'nom' => 'Session',
            'type' => 'principale', 'statut' => 'open', 'date_debut' => '2027-01-10', 'date_fin' => '2027-01-20',
        ]);
        $this->examen = Examen::create([
            'session_id' => $this->session->id, 'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programme->id, 'libelle' => 'Examens', 'type' => 'examen', 'statut' => 'scheduled',
        ]);
        $this->matiere = Matiere::create([
            'code' => 'ALGO', 'nom' => 'Algo', 'niveau' => 'L1', 'semestre' => 1, 'coefficient' => 2, 'credits' => 4,
        ]);
        $this->salle = Room::create([
            'code' => 'A1', 'nom' => 'A1', 'capacite' => 100, 'faculte_id' => $this->faculte->id, 'actif' => true,
        ]);
    }

    private function creerEnseignant(Faculte $faculte, array $surcharge = []): Teacher
    {
        $departement = Departement::create([
            'code' => 'DEP-'.uniqid(), 'nom' => 'Département', 'faculte_id' => $faculte->id,
        ]);

        return Teacher::create(array_merge([
            'nom' => 'Nom', 'prenom' => 'Prenom',
            'email' => 'ens.'.uniqid().'@univ.test',
            'matricule' => 'ENS-'.uniqid(),
            'departement_id' => $departement->id,
            'actif' => true, 'statut' => 'actif',
            'password' => 'Motdepasse@123',
        ], $surcharge));
    }

    private function creerEpreuve(array $surcharge = []): Epreuve
    {
        return Epreuve::create(array_merge([
            'code' => 'EPR-'.uniqid(),
            'examen_id' => $this->examen->id, 'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id, 'matiere_id' => $this->matiere->id,
            'salle_id' => $this->salle->id,
            'date' => Carbon::today()->addDays(2)->toDateString(),
            'heure_debut' => '09:00:00', 'heure_fin' => '11:00:00',
            'duree_minutes' => 120, 'type_evaluation' => 'examen', 'statut' => 'scheduled',
        ], $surcharge));
    }

    private function creerCreneau(Teacher $enseignant, string $jour): ScheduleSlot
    {
        $groupe = Group::create([
            'faculte_id' => $this->faculte->id, 'code' => 'G-'.uniqid(), 'nom' => 'G', 'niveau' => 'L1', 'actif' => true,
        ]);
        $planning = Schedule::create([
            'nom' => 'EDT', 'annee_universitaire_id' => $this->annee->id,
            'faculte_id' => $this->faculte->id, 'programme_id' => $this->programme->id,
            'groupe_id' => $groupe->id, 'semestre' => 1, 'statut' => 'published',
        ]);

        return ScheduleSlot::create([
            'schedule_id' => $planning->id, 'matiere_id' => $this->matiere->id,
            'teacher_id' => $enseignant->id, 'room_id' => $this->salle->id,
            'day_of_week' => $jour, 'start_time' => '08:00:00', 'end_time' => '10:00:00',
            'type' => 'course', 'status' => 'scheduled',
        ]);
    }

    // ---------- Emploi du temps ----------

    public function test_emploi_affiche_mes_creneaux_uniquement(): void
    {
        $moi = $this->creerEnseignant($this->faculte);
        $autre = $this->creerEnseignant($this->faculte);
        $this->creerCreneau($moi, 'monday');
        $this->creerCreneau($autre, 'monday');

        $this->withSession(['enseignant_auth_id' => $moi->id])
            ->get(route('enseignant.emploi-du-temps.semaine'))
            ->assertOk()
            ->assertSee('Algo')
            ->assertDontSee($autre->email);
    }

    public function test_emploi_exclut_creneaux_annules(): void
    {
        $moi = $this->creerEnseignant($this->faculte);
        $slot = $this->creerCreneau($moi, strtolower(Carbon::now()->format('l')));
        $slot->update(['status' => 'cancelled']);

        // Dashboard : aucune séance du jour (créneau annulé exclu).
        $this->withSession(['enseignant_auth_id' => $moi->id])
            ->get(route('enseignant.dashboard'))
            ->assertOk()
            ->assertSee('Aucune séance prévue');
    }

    // ---------- Surveillances ----------

    public function test_surveillances_liste_et_detail_avec_role(): void
    {
        $moi = $this->creerEnseignant($this->faculte);
        $epreuve = $this->creerEpreuve();
        app(SurveillanceService::class)
            ->assigner($epreuve, ['teacher_id' => $moi->id, 'role' => 'reserve']);

        $this->withSession(['enseignant_auth_id' => $moi->id])
            ->get(route('enseignant.surveillances.index'))
            ->assertOk()
            ->assertSee($epreuve->code)
            ->assertSee('Garde / Réserve');

        $this->withSession(['enseignant_auth_id' => $moi->id])
            ->get(route('enseignant.epreuves.show', $epreuve))
            ->assertOk()
            ->assertSee($epreuve->code);
    }

    public function test_surveillance_epreuve_autrui_404(): void
    {
        $moi = $this->creerEnseignant($this->faculte);
        $autre = $this->creerEnseignant($this->faculte);
        $epreuve = $this->creerEpreuve();
        app(SurveillanceService::class)
            ->assigner($epreuve, ['teacher_id' => $autre->id, 'role' => 'principal']);

        $this->withSession(['enseignant_auth_id' => $moi->id])
            ->get(route('enseignant.epreuves.show', $epreuve))
            ->assertNotFound();
        $this->withSession(['enseignant_auth_id' => $moi->id])
            ->get(route('enseignant.epreuves.emargement', $epreuve))
            ->assertNotFound();
        // Ma liste ne fuit pas l'épreuve d'autrui.
        $this->withSession(['enseignant_auth_id' => $moi->id])
            ->get(route('enseignant.surveillances.index'))
            ->assertOk()
            ->assertDontSee($epreuve->code);
    }

    public function test_emargement_pdf_mes_epreuves(): void
    {
        $moi = $this->creerEnseignant($this->faculte);
        $epreuve = $this->creerEpreuve();
        app(SurveillanceService::class)
            ->assigner($epreuve, ['teacher_id' => $moi->id, 'role' => 'principal']);

        $this->withSession(['enseignant_auth_id' => $moi->id])
            ->get(route('enseignant.epreuves.emargement', $epreuve))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_enseignant_ne_peut_pas_attribuer_ni_parametrer(): void
    {
        $moi = $this->creerEnseignant($this->faculte);

        // Aucune route d'attribution côté portail : le back-office exige un compte admin.
        $this->withSession(['enseignant_auth_id' => $moi->id])
            ->post(route('admin.evaluations.surveillants.store', $this->creerEpreuve()), ['teacher_id' => $moi->id])
            ->assertRedirect(route('admin.connexion.show'));
    }

    // ---------- Incidents (signalements) ----------

    public function test_signaler_incident_sur_mon_epreuve(): void
    {
        $moi = $this->creerEnseignant($this->faculte);
        $epreuve = $this->creerEpreuve();
        app(SurveillanceService::class)
            ->assigner($epreuve, ['teacher_id' => $moi->id, 'role' => 'principal']);

        $this->withSession(['enseignant_auth_id' => $moi->id])
            ->post(route('enseignant.epreuves.incidents.signaler', $epreuve), [
                'type' => 'probleme_salle', 'description' => 'Vidéoprojecteur en panne.',
            ])
            ->assertRedirect(route('enseignant.epreuves.show', $epreuve));

        $incident = EvaluationIncident::where('epreuve_id', $epreuve->id)->firstOrFail();
        $this->assertSame('signale', $incident->statut);
        $this->assertSame($moi->id, (int) $incident->teacher_id);
        $this->assertNull($incident->created_by);
    }

    public function test_signaler_incident_epreuve_autrui_404_et_type_invalide(): void
    {
        $moi = $this->creerEnseignant($this->faculte);
        $autre = $this->creerEnseignant($this->faculte);
        $epreuve = $this->creerEpreuve();
        app(SurveillanceService::class)
            ->assigner($epreuve, ['teacher_id' => $autre->id, 'role' => 'principal']);

        $this->withSession(['enseignant_auth_id' => $moi->id])
            ->post(route('enseignant.epreuves.incidents.signaler', $epreuve), [
                'type' => 'materiel', 'description' => 'X',
            ])
            ->assertNotFound();
        $this->assertSame(0, EvaluationIncident::count());

        // Type hors nomenclature refusé.
        $mienne = $this->creerEpreuve();
        app(SurveillanceService::class)
            ->assigner($mienne, ['teacher_id' => $moi->id, 'role' => 'adjoint']);
        $this->withSession(['enseignant_auth_id' => $moi->id])
            ->post(route('enseignant.epreuves.incidents.signaler', $mienne), [
                'type' => 'invention', 'description' => 'X',
            ])
            ->assertSessionHasErrors(['type']);
    }

    // ---------- Présences ----------

    public function test_presences_mes_lignes_uniquement(): void
    {
        $moi = $this->creerEnseignant($this->faculte);
        $autre = $this->creerEnseignant($this->faculte);
        $slot = $this->creerCreneau($moi, 'monday');
        $slotAutre = $this->creerCreneau($autre, 'monday');

        $mienne = TeacherPresence::create([
            'schedule_slot_id' => $slot->id, 'teacher_id' => $moi->id,
            'date' => '2027-01-12', 'statut_calcule' => 'presence_confirmee',
        ]);
        TeacherPresence::create([
            'schedule_slot_id' => $slotAutre->id, 'teacher_id' => $autre->id,
            'date' => '2027-01-13', 'statut_calcule' => 'absence_a_verifier',
        ]);

        $this->withSession(['enseignant_auth_id' => $moi->id])
            ->get(route('enseignant.presences.index'))
            ->assertOk()
            ->assertSee('Présence confirmée')
            ->assertSee('12/01/2027')
            ->assertDontSee('13/01/2027');

        $this->withSession(['enseignant_auth_id' => $moi->id])
            ->get(route('enseignant.presences.show', $mienne))
            ->assertOk();
    }

    public function test_presence_autrui_404(): void
    {
        $moi = $this->creerEnseignant($this->faculte);
        $autre = $this->creerEnseignant($this->faculte);
        $slotAutre = $this->creerCreneau($autre, 'monday');
        $presenceAutre = TeacherPresence::create([
            'schedule_slot_id' => $slotAutre->id, 'teacher_id' => $autre->id,
            'date' => '2027-01-12', 'statut_calcule' => 'presence_confirmee',
        ]);

        $this->withSession(['enseignant_auth_id' => $moi->id])
            ->get(route('enseignant.presences.show', $presenceAutre))
            ->assertNotFound();
    }

    // ---------- Annonces ----------

    public function test_annonces_audience_et_faculte(): void
    {
        $moi = $this->creerEnseignant($this->faculte);
        $visible = Annonce::create([
            'titre' => 'Conseil pédagogique', 'contenu' => 'Réunion jeudi.', 'type' => 'information',
            'statut' => 'published', 'audience' => 'enseignants', 'faculte_id' => $this->faculte->id,
        ]);
        $etudiants = Annonce::create([
            'titre' => 'Bourses', 'contenu' => 'Dossier.', 'type' => 'information',
            'statut' => 'published', 'audience' => 'etudiants', 'faculte_id' => $this->faculte->id,
        ]);
        $autreFaculte = Annonce::create([
            'titre' => 'Ailleurs', 'contenu' => 'X.', 'type' => 'information',
            'statut' => 'published', 'audience' => 'enseignants', 'faculte_id' => $this->faculteB->id,
        ]);

        $this->withSession(['enseignant_auth_id' => $moi->id])
            ->get(route('enseignant.annonces.index'))
            ->assertOk()
            ->assertSee('Conseil pédagogique')
            ->assertDontSee('Bourses')
            ->assertDontSee('Ailleurs');

        $this->withSession(['enseignant_auth_id' => $moi->id])
            ->get(route('enseignant.annonces.show', $visible))
            ->assertOk();
        $this->withSession(['enseignant_auth_id' => $moi->id])
            ->get(route('enseignant.annonces.show', $etudiants))
            ->assertNotFound();
        $this->withSession(['enseignant_auth_id' => $moi->id])
            ->get(route('enseignant.annonces.show', $autreFaculte))
            ->assertNotFound();
    }

    // ---------- Documents ----------

    public function test_documents_telechargements_autorises_scopes(): void
    {
        $moi = $this->creerEnseignant($this->faculte);
        $epreuve = $this->creerEpreuve();
        app(SurveillanceService::class)
            ->assigner($epreuve, ['teacher_id' => $moi->id, 'role' => 'principal']);

        $this->withSession(['enseignant_auth_id' => $moi->id])
            ->get(route('enseignant.documents.index'))
            ->assertOk()
            ->assertSee($epreuve->code);

        // Relevés CSV : mes lignes uniquement.
        $csv = $this->withSession(['enseignant_auth_id' => $moi->id])
            ->get(route('enseignant.surveillances.export'))
            ->assertOk();
        $this->assertStringContainsString($epreuve->code, (string) $csv->streamedContent());

        $autre = $this->creerEnseignant($this->faculte);
        $csvAutre = $this->withSession(['enseignant_auth_id' => $autre->id])
            ->get(route('enseignant.surveillances.export'))
            ->assertOk();
        $this->assertStringNotContainsString($epreuve->code, (string) $csvAutre->streamedContent());
    }

    // ---------- Notifications ----------

    public function test_notifications_centre_propriete_et_marquage(): void
    {
        $moi = $this->creerEnseignant($this->faculte);
        $autre = $this->creerEnseignant($this->faculte);
        $service = app(NotificationService::class);
        $service->notifierEnseignant($moi, 'info', 'Ma notif', 'Contenu', route('enseignant.dashboard'));
        $service->notifierEnseignant($autre, 'info', 'Pas à moi', 'Secret', null);

        $this->withSession(['enseignant_auth_id' => $moi->id])
            ->get(route('enseignant.notifications.index'))
            ->assertOk()
            ->assertSee('Ma notif')
            ->assertDontSee('Pas à moi');

        $mienne = AppNotification::where('teacher_id', $moi->id)->firstOrFail();
        $celleAutre = AppNotification::where('teacher_id', $autre->id)->firstOrFail();

        // Marquer la sienne lue.
        $this->withSession(['enseignant_auth_id' => $moi->id])
            ->patch(route('enseignant.notifications.lire', $mienne))
            ->assertRedirect($mienne->lien);
        $this->assertNotNull($mienne->fresh()->read_at);

        // Celle d'autrui : 404, jamais marquée.
        $this->withSession(['enseignant_auth_id' => $moi->id])
            ->patch(route('enseignant.notifications.lire', $celleAutre))
            ->assertNotFound();
        $this->assertNull($celleAutre->fresh()->read_at);
    }

    public function test_affectation_cree_notification_enseignant(): void
    {
        $moi = $this->creerEnseignant($this->faculte);
        $epreuve = $this->creerEpreuve();
        app(SurveillanceService::class)
            ->assigner($epreuve, ['teacher_id' => $moi->id, 'role' => 'adjoint']);

        $this->assertTrue(
            AppNotification::where('teacher_id', $moi->id)
                ->where('type', 'surveillance.affectation')->exists()
        );

        // Badge du tableau de bord.
        $this->withSession(['enseignant_auth_id' => $moi->id])
            ->get(route('enseignant.dashboard'))
            ->assertOk()
            ->assertSee('Notifications non lues');
    }

    // ---------- Activation admin ----------

    public function test_admin_active_compte_enseignant(): void
    {
        $admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco@univ.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $enseignant = $this->creerEnseignant($this->faculte, ['password' => null]);
        $this->assertNull($enseignant->fresh()->password);

        $this->actingAs($admin)->put(route('admin.enseignants.update', $enseignant), [
            'nom' => $enseignant->nom, 'prenom' => $enseignant->prenom,
            'password' => 'Initial@12345', 'password_confirmation' => 'Initial@12345',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('Initial@12345', $enseignant->fresh()->password));

        // Mot de passe vide = conservation.
        $this->actingAs($admin)->put(route('admin.enseignants.update', $enseignant), [
            'nom' => $enseignant->nom, 'prenom' => $enseignant->prenom, 'bureau' => 'B9',
        ])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('Initial@12345', $enseignant->fresh()->password));

        // Connexion possible avec le mot de passe défini par l'administration.
        $this->post(route('enseignant.connexion.login'), [
            'identifiant' => $enseignant->email, 'password' => 'Initial@12345',
        ])->assertRedirect(route('enseignant.dashboard'));
    }

    // ---------- Performance ----------

    public function test_pages_portail_requetes_bornees(): void
    {
        $moi = $this->creerEnseignant($this->faculte);
        $this->creerCreneau($moi, strtolower(Carbon::now()->format('l')));
        $epreuve = $this->creerEpreuve();
        app(SurveillanceService::class)
            ->assigner($epreuve, ['teacher_id' => $moi->id, 'role' => 'principal']);

        \DB::enableQueryLog();
        $this->withSession(['enseignant_auth_id' => $moi->id])->get(route('enseignant.dashboard'))->assertOk();
        $this->withSession(['enseignant_auth_id' => $moi->id])->get(route('enseignant.surveillances.index'))->assertOk();
        $this->withSession(['enseignant_auth_id' => $moi->id])->get(route('enseignant.emploi-du-temps.semaine'))->assertOk();
        $requetes = count(\DB::getQueryLog());
        \DB::disableQueryLog();

        $this->assertLessThan(60, $requetes, "Portail 3 pages : {$requetes} requêtes.");
    }
}
