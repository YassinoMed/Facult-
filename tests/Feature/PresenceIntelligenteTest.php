<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\AppNotification;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\TeacherReclamation;
use App\Models\User;
use App\Modules\Attendance\Models\TeacherPointage;
use App\Modules\Attendance\Models\TeacherPresence;
use App\Modules\Attendance\Services\PresenceEmploiDuTempsService;
use App\Modules\Referentiel\Models\Departement;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleException;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Présence intelligente enseignants : rapprochement, anomalies, taux,
 * régularisation (dépôt enseignant + traitement scolarité tracé).
 * Principe : sans pointage ≠ absence confirmée (jamais de validation arbitraire).
 */
class PresenceIntelligenteTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private Faculte $faculteB;

    private AnneeUniversitaire $annee;

    private Programme $programme;

    private Matiere $matiere;

    private Room $salle;

    private string $datePassee;

    private string $jourPasse;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'modules.attendance.active' => true,
            'modules.schedule.active' => true,
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
        $this->matiere = Matiere::create([
            'code' => 'ALGO', 'nom' => 'Algo', 'niveau' => 'L1', 'semestre' => 1, 'coefficient' => 2, 'credits' => 4,
        ]);
        $this->salle = Room::create([
            'code' => 'A1', 'nom' => 'A1', 'capacite' => 100, 'faculte_id' => $this->faculte->id, 'actif' => true,
        ]);

        // Date passée déterministe (jour cohérent avec le créneau testé).
        $this->datePassee = Carbon::today()->subDays(7)->toDateString();
        $this->jourPasse = strtolower(Carbon::parse($this->datePassee)->format('l'));
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

    private function pointer(Teacher $enseignant, string $dateHeure, string $type = 'entree'): TeacherPointage
    {
        return TeacherPointage::create([
            'teacher_id' => $enseignant->id,
            'date' => substr($dateHeure, 0, 10),
            'horodatage' => $dateHeure,
            'type' => $type,
            'source' => 'badgeuse',
        ]);
    }

    private function creerAdmin(): User
    {
        return User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.'.uniqid().'@univ.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
    }

    // 1. Accès à ses propres présences (stats + anomalies + orphelins rendus).
    public function test_acces_propres_presences_avec_stats(): void
    {
        $moi = $this->creerEnseignant($this->faculte);
        $this->creerCreneau($moi, $this->jourPasse);
        app(PresenceEmploiDuTempsService::class)->reconcilierEtPersister($this->datePassee, $this->faculte->id);

        $this->withSession(['enseignant_auth_id' => $moi->id])
            ->get(route('enseignant.presences.index'))
            ->assertOk()
            ->assertSee('Absence à vérifier')
            ->assertSee('Algo');
    }

    // 2. Non authentifié redirigé.
    public function test_invite_redirige_connexion(): void
    {
        $this->get(route('enseignant.presences.index'))
            ->assertRedirect(route('enseignant.connexion.show'));
    }

    // 3. Sans rôle enseignant (back-office) : pas d'accès portail.
    public function test_back_office_sans_acces_portail(): void
    {
        $admin = $this->creerAdmin();

        $this->actingAs($admin)
            ->get(route('enseignant.presences.index'))
            ->assertRedirect(route('enseignant.connexion.show'));
    }

    // 4. Isolation entre enseignants.
    public function test_isolation_entre_enseignants(): void
    {
        $moi = $this->creerEnseignant($this->faculte);
        $autre = $this->creerEnseignant($this->faculte);
        $slotAutre = $this->creerCreneau($autre, $this->jourPasse);
        $presenceAutre = TeacherPresence::create([
            'schedule_slot_id' => $slotAutre->id, 'teacher_id' => $autre->id,
            'date' => $this->datePassee, 'statut_calcule' => 'presence_confirmee',
        ]);

        $this->withSession(['enseignant_auth_id' => $moi->id])
            ->get(route('enseignant.presences.show', $presenceAutre))
            ->assertNotFound();
    }

    // 5. Isolation entre facultés (portail + mini-admin).
    public function test_isolation_facultes(): void
    {
        $moi = $this->creerEnseignant($this->faculte);
        $autreFac = $this->creerEnseignant($this->faculteB);
        $slotB = $this->creerCreneau($autreFac, $this->jourPasse);
        $slotB->schedule->update(['faculte_id' => $this->faculteB->id]);
        $groupeB = $slotB->schedule->groupe;
        $groupeB->update(['faculte_id' => $this->faculteB->id]);
        $presenceB = TeacherPresence::create([
            'schedule_slot_id' => $slotB->id, 'teacher_id' => $autreFac->id,
            'date' => $this->datePassee, 'statut_calcule' => 'presence_confirmee',
        ]);

        // Portail : la présence d'autrui (autre faculté) est invisible.
        $this->withSession(['enseignant_auth_id' => $moi->id])
            ->get(route('enseignant.presences.show', $presenceB))
            ->assertNotFound();

        // Mini-admin restreint fac A : dossier fac B → 404.
        $adminRestreint = User::creerCompteBackOffice([
            'name' => 'Resp', 'email' => 'resp.'.uniqid().'@univ.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculte->id,
        ], 'admin_faculte');
        $reclamationB = TeacherReclamation::create([
            'reference' => 'REC-TEST-1', 'teacher_id' => $autreFac->id, 'teacher_presence_id' => $presenceB->id,
            'faculte_id' => $this->faculteB->id, 'categorie' => 'presence',
            'objet' => 'X', 'description' => 'Y', 'statut' => 'ouverte',
        ]);

        $this->actingAs($adminRestreint)
            ->get(route('admin.presences.regularisations.show', $reclamationB))
            ->assertNotFound();
    }

    // 6+7. Séance terminée sans pointage → absence À VÉRIFIER, jamais validée.
    public function test_seance_sans_pointage_signalee_non_validee(): void
    {
        $moi = $this->creerEnseignant($this->faculte);
        $this->creerCreneau($moi, $this->jourPasse);

        $kpis = app(PresenceEmploiDuTempsService::class)
            ->reconcilierEtPersister($this->datePassee, $this->faculte->id);

        $this->assertSame(1, $kpis['absences_a_verifier']);

        $presence = TeacherPresence::where('teacher_id', $moi->id)->firstOrFail();
        $this->assertSame('absence_a_verifier', $presence->statut_calcule);
        $this->assertNull($presence->statut_valide);
        $this->assertFalse($presence->estValide());
    }

    // 8. Annulées/déplacées exclues (pas de fausse absence).
    public function test_seance_annulee_exclue_sans_fausse_absence(): void
    {
        $moi = $this->creerEnseignant($this->faculte);
        $slot = $this->creerCreneau($moi, $this->jourPasse);
        ScheduleException::create([
            'schedule_slot_id' => $slot->id, 'exception_date' => $this->datePassee,
            'status' => 'cancelled', 'reason' => 'Férié',
        ]);

        $service = app(PresenceEmploiDuTempsService::class);
        $service->reconcilierEtPersister($this->datePassee, $this->faculte->id);

        $presence = TeacherPresence::where('teacher_id', $moi->id)->firstOrFail();
        $this->assertSame('seance_annulee', $presence->statut_calcule);

        $stats = $service->statistiquesEnseignant($moi->id, $this->datePassee, $this->datePassee);
        $this->assertSame(1, $stats['annulees']);
        $this->assertSame(0, $stats['prises_en_compte']);
        $this->assertNull($stats['taux']);
    }

    // 9. Retard selon la tolérance configurée.
    public function test_retard_selon_tolerance(): void
    {
        $moi = $this->creerEnseignant($this->faculte);
        $this->creerCreneau($moi, $this->jourPasse);
        $this->pointer($moi, $this->datePassee.' 08:20:00');

        $service = app(PresenceEmploiDuTempsService::class);
        $service->reconcilierEtPersister($this->datePassee, $this->faculte->id, null, 15);

        $presence = TeacherPresence::where('teacher_id', $moi->id)->firstOrFail();
        $this->assertSame('retard_potentiel', $presence->statut_calcule);
        $this->assertSame(20, (int) $presence->retard_minutes);

        // Tolérance 30 : même pointage = présence confirmée.
        $service->reconcilierEtPersister($this->datePassee, $this->faculte->id, null, 30);
        $this->assertSame('presence_confirmee', $presence->fresh()->statut_calcule);
    }

    // Fenêtre d'appariement administrable.
    public function test_fenetre_appariement_parametrable(): void
    {
        $moi = $this->creerEnseignant($this->faculte);
        $this->creerCreneau($moi, $this->jourPasse);
        $this->pointer($moi, $this->datePassee.' 07:00:00');

        $service = app(PresenceEmploiDuTempsService::class);

        $avec60 = $service->seancesDuJour($this->datePassee, $this->faculte->id, [], 15, 60)->first();
        $this->assertSame('presence_confirmee', $avec60['statut_calcule']);

        $avec30 = $service->seancesDuJour($this->datePassee, $this->faculte->id, [], 15, 30)->first();
        $this->assertSame('absence_a_verifier', $avec30['statut_calcule']);
    }

    // 10. Anti-doublon pointage (soumission répétée = même ligne).
    public function test_pointage_double_soumission_idempotent(): void
    {
        $moi = $this->creerEnseignant($this->faculte);
        $service = app(PresenceEmploiDuTempsService::class);

        $premier = $service->enregistrerPointage($moi->id, $this->datePassee.' 08:00:00', 'entree', 'badgeuse');
        $second = $service->enregistrerPointage($moi->id, $this->datePassee.' 08:00:30', 'entree', 'badgeuse');

        $this->assertSame($premier->id, $second->id);
        $this->assertSame(1, TeacherPointage::where('teacher_id', $moi->id)->count());

        // Type différent à la même minute = événement distinct légitime.
        $sortie = $service->enregistrerPointage($moi->id, $this->datePassee.' 08:00:45', 'sortie', 'badgeuse');
        $this->assertNotSame($premier->id, $sortie->id);
    }

    // 11. Pointages incomplets/incompatibles gérés sans erreur.
    public function test_pointage_incomplet_sans_sortie(): void
    {
        $moi = $this->creerEnseignant($this->faculte);
        $this->creerCreneau($moi, $this->jourPasse);
        $this->pointer($moi, $this->datePassee.' 07:59:00');

        app(PresenceEmploiDuTempsService::class)->reconcilierEtPersister($this->datePassee, $this->faculte->id);

        $presence = TeacherPresence::where('teacher_id', $moi->id)->firstOrFail();
        $this->assertSame('presence_confirmee', $presence->statut_calcule);
        $this->assertNull($presence->heure_depart);
    }

    // 12. Dépôt d'une régularisation liée à la présence.
    public function test_depot_regularisation_liee(): void
    {
        $moi = $this->creerEnseignant($this->faculte);
        $this->creerCreneau($moi, $this->jourPasse);
        app(PresenceEmploiDuTempsService::class)->reconcilierEtPersister($this->datePassee, $this->faculte->id);
        $presence = TeacherPresence::where('teacher_id', $moi->id)->firstOrFail();

        $this->withSession(['enseignant_auth_id' => $moi->id])
            ->post(route('enseignant.reclamations.store'), [
                'categorie' => 'presence',
                'objet' => 'Badgeuse en panne',
                'description' => "J'étais présent, badgeuse hors service.",
                'presence_id' => $presence->id,
            ])
            ->assertRedirect(route('enseignant.reclamations.show', TeacherReclamation::firstOrFail()));

        $reclamation = TeacherReclamation::firstOrFail();
        $this->assertSame($presence->id, (int) $reclamation->teacher_presence_id);
        $this->assertSame('ouverte', $reclamation->statut);

        // Catégorie incohérente avec présence liée : refusée.
        $this->withSession(['enseignant_auth_id' => $moi->id])
            ->post(route('enseignant.reclamations.store'), [
                'categorie' => 'document',
                'objet' => 'X', 'description' => 'Y',
                'presence_id' => $presence->id,
            ])
            ->assertSessionHasErrors(['categorie']);

        // Présence d'autrui : 404, rien créé.
        $autre = $this->creerEnseignant($this->faculte);
        $slotAutre = $this->creerCreneau($autre, $this->jourPasse);
        $presenceAutre = TeacherPresence::create([
            'schedule_slot_id' => $slotAutre->id, 'teacher_id' => $autre->id,
            'date' => $this->datePassee, 'statut_calcule' => 'absence_a_verifier',
        ]);
        $this->withSession(['enseignant_auth_id' => $moi->id])
            ->post(route('enseignant.reclamations.store'), [
                'categorie' => 'presence',
                'objet' => 'X', 'description' => 'Y',
                'presence_id' => $presenceAutre->id,
            ])
            ->assertNotFound();
        $this->assertSame(1, TeacherReclamation::count());
    }

    // 13+16. Traitement admin : résolution corrige + trace ; rejet notifié.
    public function test_traitement_admin_resoudre_et_rejeter(): void
    {
        $admin = $this->creerAdmin();
        $moi = $this->creerEnseignant($this->faculte);
        $this->creerCreneau($moi, $this->jourPasse);
        app(PresenceEmploiDuTempsService::class)->reconcilierEtPersister($this->datePassee, $this->faculte->id);
        $presence = TeacherPresence::where('teacher_id', $moi->id)->firstOrFail();
        $reclamation = TeacherReclamation::create([
            'reference' => 'REC-TEST-1', 'teacher_id' => $moi->id, 'teacher_presence_id' => $presence->id,
            'faculte_id' => $this->faculte->id, 'categorie' => 'presence',
            'objet' => 'Badgeuse HS', 'description' => 'Présent.', 'statut' => 'ouverte',
        ]);

        // Sans permission : 403.
        $consultation = User::creerCompteBackOffice([
            'name' => 'Lecture', 'email' => 'lec.'.uniqid().'@univ.test', 'password' => 'Motdepasse@123',
        ], 'consultation');
        $this->actingAs($consultation)
            ->post(route('admin.presences.regularisations.traiter', $reclamation), [
                'decision' => 'rejeter', 'motif' => 'Non fondé.',
            ])
            ->assertForbidden();

        // Résolution avec correction.
        $this->actingAs($admin)
            ->post(route('admin.presences.regularisations.traiter', $reclamation), [
                'decision' => 'resoudre', 'statut_valide' => 'presence_confirmee', 'motif' => 'Badgeuse HS confirmée.',
            ])
            ->assertRedirect(route('admin.presences.regularisations.index'));

        $presence->refresh();
        $this->assertSame('presence_confirmee', $presence->statut_valide);
        $this->assertSame($admin->id, (int) $presence->valide_par);
        $this->assertSame('resolue', $reclamation->fresh()->statut);
        $this->assertTrue(
            AppNotification::where('teacher_id', $moi->id)->where('type', 'regularisation.resolue')->exists()
        );
        // Traçabilité : trace correction sur la présence + historique demande.
        $actions = array_column($presence->historique ?? [], 'action');
        $this->assertContains('correction_manuelle', $actions);
        $this->assertSame($admin->id, (int) $reclamation->fresh()->traitee_par);

        // Rejet d'une seconde demande.
        $reclamation2 = TeacherReclamation::create([
            'reference' => 'REC-TEST-2', 'teacher_id' => $moi->id,
            'faculte_id' => $this->faculte->id, 'categorie' => 'presence',
            'objet' => 'Autre', 'description' => 'Y', 'statut' => 'ouverte',
        ]);
        $this->actingAs($admin)
            ->post(route('admin.presences.regularisations.traiter', $reclamation2), [
                'decision' => 'rejeter', 'motif' => 'Non fondé après vérification.',
            ])
            ->assertRedirect(route('admin.presences.regularisations.index'));
        $this->assertSame('rejetee', $reclamation2->fresh()->statut);
        $this->assertTrue(
            AppNotification::where('teacher_id', $moi->id)->where('type', 'regularisation.rejetee')->exists()
        );
    }

    // 14. L'enseignant ne peut pas approuver (aucune route de traitement côté portail).
    public function test_enseignant_ne_peut_pas_traiter(): void
    {
        $moi = $this->creerEnseignant($this->faculte);
        $reclamation = TeacherReclamation::create([
            'reference' => 'REC-TEST-9', 'teacher_id' => $moi->id,
            'faculte_id' => $this->faculte->id, 'categorie' => 'presence',
            'objet' => 'X', 'description' => 'Y', 'statut' => 'ouverte',
        ]);

        // Session enseignant seule : le back-office redirige vers sa connexion.
        $this->withSession(['enseignant_auth_id' => $moi->id])
            ->post(route('admin.presences.regularisations.traiter', $reclamation), [
                'decision' => 'resoudre', 'motif' => 'Auto-approbation.',
            ])
            ->assertRedirect(route('admin.connexion.show'));
        $this->assertSame('ouverte', $reclamation->fresh()->statut);
    }

    // 15. Pièces jointes privées : 404 inter-enseignants.
    public function test_piece_jointe_privee_idor(): void
    {
        $moi = $this->creerEnseignant($this->faculte);
        $autre = $this->creerEnseignant($this->faculte);

        $reclamation = TeacherReclamation::create([
            'reference' => 'REC-TEST-5', 'teacher_id' => $autre->id,
            'faculte_id' => $this->faculte->id, 'categorie' => 'presence',
            'objet' => 'X', 'description' => 'Y', 'statut' => 'ouverte',
            'piece_jointe_chemin' => 'private/enseignant/reclamations/faux.pdf',
            'piece_jointe_nom' => 'faux.pdf',
        ]);

        $this->withSession(['enseignant_auth_id' => $moi->id])
            ->get(route('enseignant.reclamations.piece-jointe', $reclamation))
            ->assertNotFound();
    }

    // 17. Idempotence : double réconciliation + double traitement.
    public function test_idempotence_recalcul_et_traitement(): void
    {
        $admin = $this->creerAdmin();
        $moi = $this->creerEnseignant($this->faculte);
        $this->creerCreneau($moi, $this->jourPasse);

        $service = app(PresenceEmploiDuTempsService::class);
        $service->reconcilierEtPersister($this->datePassee, $this->faculte->id);
        $service->reconcilierEtPersister($this->datePassee, $this->faculte->id);

        $this->assertSame(1, TeacherPresence::where('teacher_id', $moi->id)->count());

        $presence = TeacherPresence::where('teacher_id', $moi->id)->firstOrFail();
        $reclamation = TeacherReclamation::create([
            'reference' => 'REC-TEST-7', 'teacher_id' => $moi->id, 'teacher_presence_id' => $presence->id,
            'faculte_id' => $this->faculte->id, 'categorie' => 'presence',
            'objet' => 'X', 'description' => 'Y', 'statut' => 'ouverte',
        ]);

        $payload = ['decision' => 'resoudre', 'statut_valide' => 'presence_confirmee', 'motif' => 'Vérifié.'];
        $this->actingAs($admin)->post(route('admin.presences.regularisations.traiter', $reclamation), $payload)
            ->assertRedirect(route('admin.presences.regularisations.index'));
        // Second traitement : refusé proprement, pas de double correction.
        $this->actingAs($admin)->post(route('admin.presences.regularisations.traiter', $reclamation), $payload)
            ->assertSessionHasErrors(['decision']);
        $this->assertSame(1, TeacherPresence::where('teacher_id', $moi->id)->count());
    }

    // 18. Performance sur volume représentatif.
    public function test_performance_volume(): void
    {
        $moi = $this->creerEnseignant($this->faculte);
        $jours = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'];

        for ($i = 0; $i < 40; $i++) {
            $slot = $this->creerCreneau($moi, $jours[$i % 5], [
                'start_time' => sprintf('%02d:00:00', 8 + ($i % 6)),
                'end_time' => sprintf('%02d:00:00', 10 + ($i % 6)),
            ]);
            TeacherPresence::create([
                'schedule_slot_id' => $slot->id, 'teacher_id' => $moi->id,
                'date' => Carbon::today()->subDays(1 + ($i % 30))->toDateString(),
                'statut_calcule' => $i % 3 === 0 ? 'absence_a_verifier' : 'presence_confirmee',
            ]);
        }

        DB::enableQueryLog();
        $debut = microtime(true);
        $this->withSession(['enseignant_auth_id' => $moi->id])
            ->get(route('enseignant.presences.index'))
            ->assertOk()
            ->assertSee('Mon emploi du temps')
            ->assertSee('Mes surveillances')
            ->assertSee('Mes présences');
        $duree = microtime(true) - $debut;
        $requetes = count(DB::getQueryLog());
        DB::disableQueryLog();

        fwrite(STDERR, "\n[PERF-PRES-ENS] 40 présences : ".round($duree, 2).'s, '.$requetes.' requêtes'."\n");
        $this->assertLessThan(40, $requetes, "40 présences : {$requetes} requêtes.");
        $this->assertLessThan(30, $duree, 'Page trop lente.');
    }

    // Taux : définition formelle (exclusions + anomalies non assimilées).
    public function test_taux_presence_definition(): void
    {
        $moi = $this->creerEnseignant($this->faculte);
        $service = app(PresenceEmploiDuTempsService::class);

        // Vide : taux null (affiché « — »), jamais 0 % trompeur... ni 100 %.
        $stats = $service->statistiquesEnseignant($moi->id, $this->datePassee, $this->datePassee);
        $this->assertNull($stats['taux']);

        $slot = $this->creerCreneau($moi, $this->jourPasse);
        foreach ([
            ['statut_calcule' => 'presence_confirmee'],
            ['statut_calcule' => 'absence_a_verifier'],
            ['statut_calcule' => 'seance_annulee'],
            ['statut_calcule' => 'a_venir'],
        ] as $ligne) {
            $slotLigne = $this->creerCreneau($moi, $this->jourPasse);
            TeacherPresence::create([
                'schedule_slot_id' => $slotLigne->id, 'teacher_id' => $moi->id,
                'date' => $this->datePassee, ...$ligne,
            ]);
        }

        $stats = $service->statistiquesEnseignant($moi->id, $this->datePassee, $this->datePassee);
        $this->assertSame(4, $stats['total']);
        $this->assertSame(1, $stats['annulees']);
        $this->assertSame(1, $stats['non_echues']);
        $this->assertSame(1, $stats['anomalies']);
        $this->assertSame(2, $stats['prises_en_compte']);
        // 1 confirmée / 2 prises en compte = 50 % (l'anomalie pèse sans être une absence).
        $this->assertSame(50.0, $stats['taux']);
    }

    // Orphelins : pointage sans créneau couvrant.
    public function test_pointages_orphelins_detectes(): void
    {
        $moi = $this->creerEnseignant($this->faculte);
        $this->creerCreneau($moi, $this->jourPasse); // 08:00-10:00
        $this->pointer($moi, $this->datePassee.' 08:05:00');
        $this->pointer($moi, $this->datePassee.' 15:00:00'); // hors séance

        $orphelins = app(PresenceEmploiDuTempsService::class)
            ->pointagesOrphelins($moi->id, $this->datePassee, $this->datePassee);

        $this->assertSame(1, $orphelins->count());
        $this->assertStringContainsString('15:00', (string) $orphelins->first()->horodatage);

        $this->withSession(['enseignant_auth_id' => $moi->id])
            ->get(route('enseignant.presences.index'))
            ->assertOk()
            ->assertSee('sans séance');
    }

    // Contrat stats : clés consommées par la vue (verrou anti-dérive).
    public function test_contrat_statistiques_complet(): void
    {
        $moi = $this->creerEnseignant($this->faculte);
        $service = app(PresenceEmploiDuTempsService::class);

        $stats = $service->statistiquesEnseignant($moi->id, $this->datePassee, $this->datePassee);

        foreach (['total', 'prises_en_compte', 'confirmees', 'dispenses', 'anomalies', 'absences_a_verifier', 'absences_validees', 'pointages_manquants', 'retards', 'annulees', 'non_echues', 'retard_minutes', 'taux'] as $cle) {
            $this->assertArrayHasKey($cle, $stats, "Clé manquante : {$cle}");
        }
    }
}
