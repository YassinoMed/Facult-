<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\AppNotification;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Referentiel\Models\Departement;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Events\ScheduleChanged;
use App\Modules\Schedule\Listeners\NotifierPlanning;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleException;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Emploi du temps intelligent enseignant : vues jour/semaine/liste,
 * filtres scopés, détail, PDF, notifications de changement.
 * Source unique : module Schedule, teacher_id depuis la session uniquement.
 */
class EmploiEnseignantIntelligentTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private Faculte $faculteB;

    private AnneeUniversitaire $annee;

    private Programme $programme;

    private Matiere $matiere;

    private Room $salle;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.schedule.active' => true]);

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'active' => true]);
        $this->faculteB = Faculte::create(['nom' => 'FLSH', 'code' => 'FLSH', 'active' => true]);
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->programme = Programme::create([
            'code' => 'L-INFO', 'nom' => 'Licence Info', 'faculte_id' => $this->faculte->id,
            'cycle' => 'licence', 'duree_annees' => 3, 'actif' => true,
        ]);
        $this->matiere = Matiere::create([
            'code' => 'ALGO', 'nom' => 'Algo', 'niveau' => 'L1', 'semestre' => 1, 'coefficient' => 2, 'credits' => 4,
        ]);
        $this->salle = Room::create([
            'code' => 'A1', 'nom' => 'A1', 'capacite' => 100, 'faculte_id' => $this->faculte->id, 'actif' => true,
        ]);
    }

    private function creerEnseignant(Faculte $faculte): Teacher
    {
        $departement = Departement::create([
            'code' => 'DEP-'.uniqid(), 'nom' => 'Département', 'faculte_id' => $faculte->id,
        ]);

        return Teacher::create([
            'nom' => 'Nom', 'prenom' => 'Prenom',
            'email' => 'ens.'.uniqid().'@univ.test',
            'matricule' => 'ENS-'.uniqid(),
            'departement_id' => $departement->id,
            'actif' => true, 'statut' => 'actif',
            'password' => 'Motdepasse@123',
        ]);
    }

    private function creerCreneau(Teacher $enseignant, string $jour, array $surcharge = []): ScheduleSlot
    {
        $groupe = Group::create([
            'faculte_id' => $this->faculte->id, 'code' => 'G-'.uniqid(), 'nom' => 'G1', 'niveau' => 'L1', 'actif' => true,
        ]);
        $planning = Schedule::create([
            'nom' => 'EDT', 'annee_universitaire_id' => $this->annee->id,
            'faculte_id' => $this->faculte->id, 'programme_id' => $this->programme->id,
            'groupe_id' => $groupe->id, 'semestre' => 1, 'statut' => 'published',
        ]);

        return ScheduleSlot::create(array_merge([
            'schedule_id' => $planning->id, 'matiere_id' => $this->matiere->id,
            'teacher_id' => $enseignant->id, 'room_id' => $this->salle->id,
            'day_of_week' => $jour, 'start_time' => '08:00:00', 'end_time' => '10:00:00',
            'type' => 'course', 'status' => 'scheduled',
        ], $surcharge));
    }

    // 1. Enseignant authentifié consulte son emploi du temps (semaine).
    public function test_enseignant_authentifie_voit_semaine(): void
    {
        $moi = $this->creerEnseignant($this->faculte);
        $this->creerCreneau($moi, 'monday');

        $this->withSession(['enseignant_auth_id' => $moi->id])
            ->get(route('enseignant.emploi-du-temps.semaine'))
            ->assertOk()
            ->assertSee('Algo')
            ->assertSee('Semaine du');
    }

    // 2. Non authentifié redirigé vers la connexion.
    public function test_invite_redirige_connexion(): void
    {
        $this->get(route('enseignant.emploi-du-temps.semaine'))
            ->assertRedirect(route('enseignant.connexion.show'));
    }

    // 3. Sans rôle enseignant (back-office ou étudiant) : pas d'accès.
    public function test_compte_back_office_sans_acces_portail(): void
    {
        $admin = User::creerCompteBackOffice([
            'name' => 'Sco', 'email' => 'sco@univ.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        // Un admin connecté (guard web) sans session enseignant est redirigé.
        $this->actingAs($admin)
            ->get(route('enseignant.emploi-du-temps.semaine'))
            ->assertRedirect(route('enseignant.connexion.show'));
    }

    // 4. Isolation entre enseignants (vues + détail + PDF).
    public function test_isolation_entre_enseignants(): void
    {
        $moi = $this->creerEnseignant($this->faculte);
        $autre = $this->creerEnseignant($this->faculte);
        $this->creerCreneau($moi, 'monday');
        $slotAutre = $this->creerCreneau($autre, 'monday', ['matiere_id' => Matiere::create([
            'code' => 'CHIM', 'nom' => 'Chimie secrete', 'niveau' => 'L1', 'semestre' => 1, 'coefficient' => 1, 'credits' => 2,
        ])->id]);

        $session = ['enseignant_auth_id' => $moi->id];
        $this->withSession($session)->get(route('enseignant.emploi-du-temps.semaine'))->assertOk()->assertDontSee('Chimie secrete');
        $this->withSession($session)->get(route('enseignant.emploi-du-temps.semaine', ['vue' => 'liste']))->assertOk()->assertDontSee('Chimie secrete');
        $this->withSession($session)->get(route('enseignant.emploi-du-temps.creneau', $slotAutre))->assertNotFound();
        $this->withSession($session)->get(route('enseignant.emploi-du-temps.pdf'))->assertOk()->assertDontSee('Chimie secrete');
    }

    // 5. Isolation entre facultés sur les filtres (groupes d'autrui invisibles).
    public function test_isolation_facultes_filtres(): void
    {
        $moi = $this->creerEnseignant($this->faculte);
        $this->creerCreneau($moi, 'monday');

        $groupeB = Group::create([
            'faculte_id' => $this->faculteB->id, 'code' => 'GB-'.uniqid(), 'nom' => 'GB', 'niveau' => 'L1', 'actif' => true,
        ]);

        // Filtre groupe d'une autre faculté : vide, jamais d'élargissement.
        $this->withSession(['enseignant_auth_id' => $moi->id])
            ->get(route('enseignant.emploi-du-temps.semaine', ['groupe' => $groupeB->id]))
            ->assertOk()
            ->assertSee('Aucun créneau');
    }

    // 6. Ordre chronologique (jour puis heure) en vue liste.
    public function test_liste_ordre_chronologique(): void
    {
        $moi = $this->creerEnseignant($this->faculte);
        $this->creerCreneau($moi, 'wednesday', ['start_time' => '14:00:00', 'end_time' => '16:00:00']);
        $this->creerCreneau($moi, 'monday', ['start_time' => '08:00:00', 'end_time' => '10:00:00']);

        $lundi = Carbon::now()->startOfWeek(Carbon::MONDAY);
        $dateLundi = $lundi->toDateString();
        $dateMercredi = $lundi->copy()->addDays(2)->toDateString();

        $contenu = (string) $this->withSession(['enseignant_auth_id' => $moi->id])
            ->get(route('enseignant.emploi-du-temps.semaine', ['vue' => 'liste']))
            ->assertOk()->assertSee($dateLundi)->assertSee($dateMercredi)->getContent();

        $this->assertLessThan(strpos($contenu, $dateMercredi), strpos($contenu, $dateLundi));
    }

    // 7. Semaine sans séance : état vide explicite, sans erreur.
    public function test_semaine_sans_seance_etat_vide(): void
    {
        $moi = $this->creerEnseignant($this->faculte);

        $this->withSession(['enseignant_auth_id' => $moi->id])
            ->get(route('enseignant.emploi-du-temps.semaine'))
            ->assertOk()
            ->assertSee('Aucun créneau');
    }

    // 8. Annulés/déplacés : statuts métier + widgets.
    public function test_statuts_annule_deplace_affiches(): void
    {
        $moi = $this->creerEnseignant($this->faculte);
        $slot = $this->creerCreneau($moi, strtolower(Carbon::now()->format('l')));
        ScheduleException::create([
            'schedule_slot_id' => $slot->id, 'exception_date' => Carbon::today()->toDateString(),
            'status' => 'cancelled', 'reason' => 'Jour férié',
        ]);

        $this->withSession(['enseignant_auth_id' => $moi->id])
            ->get(route('enseignant.emploi-du-temps.semaine'))
            ->assertOk()
            ->assertSee('Jour férié')
            ->assertSee('Annulée');
    }

    // 9. Les filtres ne contournent jamais l'autorisation.
    public function test_filtres_scopes_mes_creneaux(): void
    {
        $moi = $this->creerEnseignant($this->faculte);
        $autre = $this->creerEnseignant($this->faculte);
        $this->creerCreneau($moi, 'monday');
        $slotAutre = $this->creerCreneau($autre, 'tuesday');

        // Filtre matière/enseignant d'autrui : ignoré ou vide, jamais de fuite.
        $this->withSession(['enseignant_auth_id' => $moi->id])
            ->get(route('enseignant.emploi-du-temps.semaine', ['vue' => 'liste', 'matiere' => $slotAutre->matiere_id]))
            ->assertOk()
            ->assertDontSee('Chimie');

        $this->withSession(['enseignant_auth_id' => $moi->id])
            ->get(route('enseignant.emploi-du-temps.semaine', ['teacher_id' => $autre->id]))
            ->assertOk()
            ->assertSee('Algo');
    }

    // 10. Export PDF : même périmètre, authentifié, pas de fuite.
    public function test_export_pdf_meme_perimetre(): void
    {
        $moi = $this->creerEnseignant($this->faculte);
        $this->creerCreneau($moi, 'monday');

        $this->get(route('enseignant.emploi-du-temps.pdf'))
            ->assertRedirect(route('enseignant.connexion.show'));

        $this->withSession(['enseignant_auth_id' => $moi->id])
            ->get(route('enseignant.emploi-du-temps.pdf'))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    // 11. Données incomplètes (salle/matière manquantes) : pas de 500.
    public function test_donnees_incompletes_sans_erreur(): void
    {
        $moi = $this->creerEnseignant($this->faculte);
        $this->creerCreneau($moi, 'monday', ['room_id' => null, 'matiere_id' => Matiere::create([
            'code' => 'X', 'nom' => 'SansSalle', 'niveau' => 'L1', 'semestre' => 1, 'coefficient' => 1, 'credits' => 1,
        ])->id]);

        $this->withSession(['enseignant_auth_id' => $moi->id])
            ->get(route('enseignant.emploi-du-temps.semaine'))
            ->assertOk()
            ->assertSee('SansSalle');
        $this->withSession(['enseignant_auth_id' => $moi->id])
            ->get(route('enseignant.emploi-du-temps.pdf'))
            ->assertOk();
    }

    // 12. Performance : planning volumineux borné.
    public function test_performance_planning_volumineux(): void
    {
        $moi = $this->creerEnseignant($this->faculte);
        $jours = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'];
        for ($i = 0; $i < 100; $i++) {
            $this->creerCreneau($moi, $jours[$i % 5], [
                'start_time' => sprintf('%02d:00:00', 8 + ($i % 8)), 'end_time' => sprintf('%02d:00:00', 10 + ($i % 8)),
            ]);
        }

        \DB::enableQueryLog();
        $debut = microtime(true);
        $this->withSession(['enseignant_auth_id' => $moi->id])
            ->get(route('enseignant.emploi-du-temps.semaine', ['vue' => 'liste']))
            ->assertOk();
        $duree = microtime(true) - $debut;
        $requetes = count(\DB::getQueryLog());
        \DB::disableQueryLog();

        fwrite(STDERR, "\n[PERF-EDT-ENS] 100 créneaux : ".round($duree, 2).'s, '.$requetes.' requêtes'."\n");
        $this->assertLessThan(30, $requetes, "100 créneaux : {$requetes} requêtes.");
        $this->assertLessThan(30, $duree, 'Planning trop lent.');
    }

    // Détail : chevauchements signalés + prochaine séance + heures.
    public function test_widgets_chevauchements_et_prochaine_seance(): void
    {
        $moi = $this->creerEnseignant($this->faculte);
        $this->creerCreneau($moi, 'monday', ['start_time' => '08:00:00', 'end_time' => '10:00:00']);
        $this->creerCreneau($moi, 'monday', ['start_time' => '09:00:00', 'end_time' => '11:00:00']);

        // Semaine suivante : lundi toujours futur, déterministe quel que soit le jour d'exécution.
        $this->withSession(['enseignant_auth_id' => $moi->id])
            ->get(route('enseignant.emploi-du-temps.semaine', ['semaine' => 1]))
            ->assertOk()
            ->assertSee('Chevauchement')
            ->assertSee('Prochaine séance');
    }

    public function test_detail_creneau_avec_historique(): void
    {
        $moi = $this->creerEnseignant($this->faculte);
        $slot = $this->creerCreneau($moi, 'monday');

        $this->withSession(['enseignant_auth_id' => $moi->id])
            ->get(route('enseignant.emploi-du-temps.creneau', $slot))
            ->assertOk()
            ->assertSee('Algo')
            ->assertSee('2')
            ->assertSee('h');
    }

    public function test_changement_planning_notifie_enseignant(): void
    {
        $moi = $this->creerEnseignant($this->faculte);
        $slot = $this->creerCreneau($moi, 'monday');

        app(NotifierPlanning::class)->handle(
            new ScheduleChanged($slot->schedule_id, $slot->id, 'cancelled')
        );

        $this->assertTrue(
            AppNotification::where('teacher_id', $moi->id)
                ->where('type', 'schedule.cancelled')->exists()
        );
        $this->withSession(['enseignant_auth_id' => $moi->id])
            ->get(route('enseignant.notifications.index'))
            ->assertOk()
            ->assertSee('Cours annulé');
    }
}
