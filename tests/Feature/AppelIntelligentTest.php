<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Attendance\Models\Presence;
use App\Modules\Attendance\Models\SeanceAppel;
use App\Modules\Attendance\Services\AppelPresenceService;
use App\Modules\Referentiel\Models\Departement;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Appel intelligent : l'enseignant fait l'appel de SES séances (groupe de
 * la séance, statuts validés serveur, clôture explicite, corrections
 * motivées tracées). Sans ligne = non renseigné, jamais absent.
 */
class AppelIntelligentTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private Faculte $faculteB;

    private AnneeUniversitaire $annee;

    private Programme $programme;

    private Matiere $matiere;

    private Room $salle;

    private Group $groupe;

    private Schedule $planning;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.attendance.active' => true, 'modules.schedule.active' => true]);

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
        $this->groupe = Group::create([
            'faculte_id' => $this->faculte->id, 'code' => 'G1', 'nom' => 'Groupe 1', 'niveau' => 'L1', 'actif' => true,
        ]);
        $this->planning = Schedule::create([
            'nom' => 'EDT', 'annee_universitaire_id' => $this->annee->id,
            'faculte_id' => $this->faculte->id, 'programme_id' => $this->programme->id,
            'groupe_id' => $this->groupe->id, 'semestre' => 1, 'statut' => 'published',
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

    private function creerEtudiant(Group $groupe, string $nom, array $surcharge = []): Etudiant
    {
        $etudiant = Etudiant::create(array_merge([
            'cin' => 'CIN'.uniqid(), 'nom' => $nom, 'prenom' => 'Prenom',
            'email' => 'etu'.uniqid().'@test.ma', 'date_naissance' => '2004-01-01',
            'sexe' => 'M', 'faculte_id' => $groupe->faculte_id, 'niveau' => 'L1',
            'annee_academique' => '2026-2027', 'adresse' => '12 rue', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
            'groupe_id' => $groupe->id,
        ], $surcharge));
        $etudiant->definirStatut('valide');

        return $etudiant;
    }

    private function creerCreneau(Teacher $enseignant, string $jour): ScheduleSlot
    {
        return ScheduleSlot::create([
            'schedule_id' => $this->planning->id, 'matiere_id' => $this->matiere->id,
            'teacher_id' => $enseignant->id, 'room_id' => $this->salle->id,
            'day_of_week' => $jour, 'start_time' => '08:00:00', 'end_time' => '10:00:00',
            'type' => 'course', 'status' => 'scheduled',
        ]);
    }

    private function datePasseePour(string $jour): string
    {
        $date = Carbon::today()->subDays(7);
        while (strtolower($date->format('l')) !== $jour) {
            $date->subDay();
        }

        return $date->toDateString();
    }

    // 1. Ouvrir l'appel de sa propre séance (état + effectif).
    public function test_ouvrir_appel_propre_seance(): void
    {
        $moi = $this->creerEnseignant($this->faculte);
        $this->creerEtudiant($this->groupe, 'Alpha');
        $this->creerEtudiant($this->groupe, 'Beta');
        $slot = $this->creerCreneau($moi, 'monday');
        $date = $this->datePasseePour('monday');

        $this->withSession(['enseignant_auth_id' => $moi->id])
            ->get(route('enseignant.appel.selection', ['date' => $date]))
            ->assertOk()
            ->assertSee('Algo')
            ->assertSee('2 étudiant(s)');

        $this->withSession(['enseignant_auth_id' => $moi->id])
            ->post(route('enseignant.appel.ouvrir', ['slot' => $slot->id, 'date' => $date]))
            ->assertRedirect();

        $appel = SeanceAppel::firstOrFail();
        $this->assertSame('en_cours', $appel->statut);

        $this->withSession(['enseignant_auth_id' => $moi->id])
            ->get(route('enseignant.appel.feuille', $appel))
            ->assertOk()
            ->assertSee('Alpha')
            ->assertSee('Beta');
    }

    // 2. Séance d'autrui : 404 partout.
    public function test_seance_autrui_404(): void
    {
        $moi = $this->creerEnseignant($this->faculte);
        $autre = $this->creerEnseignant($this->faculte);
        $slot = $this->creerCreneau($autre, 'monday');
        $date = $this->datePasseePour('monday');

        $session = ['enseignant_auth_id' => $moi->id];
        $this->withSession($session)->post(route('enseignant.appel.ouvrir', ['slot' => $slot->id, 'date' => $date]))->assertNotFound();

        $appel = SeanceAppel::create([
            'schedule_slot_id' => $slot->id, 'date' => $date, 'teacher_id' => $autre->id, 'statut' => 'en_cours',
        ]);
        $this->withSession($session)->get(route('enseignant.appel.feuille', $appel))->assertNotFound();
        $this->withSession($session)->post(route('enseignant.appel.enregistrer', $appel), ['releves' => []])->assertNotFound();
        $this->withSession($session)->post(route('enseignant.appel.cloturer', $appel))->assertNotFound();
        $this->assertSame(0, Presence::count());
    }

    // 3. Non authentifié : redirection connexion.
    public function test_invite_redirige_connexion(): void
    {
        $this->get(route('enseignant.appel.selection'))->assertRedirect(route('enseignant.connexion.show'));
    }

    // 4. Étudiant hors groupe refusé (tout-ou-rien, rien persisté).
    public function test_etudiant_hors_groupe_refuse(): void
    {
        $moi = $this->creerEnseignant($this->faculte);
        $dedans = $this->creerEtudiant($this->groupe, 'Dedans');
        $groupeB = Group::create([
            'faculte_id' => $this->faculte->id, 'code' => 'GB-'.uniqid(), 'nom' => 'GB', 'niveau' => 'L1', 'actif' => true,
        ]);
        $dehors = $this->creerEtudiant($groupeB, 'Dehors');
        $slot = $this->creerCreneau($moi, 'monday');
        $date = $this->datePasseePour('monday');
        $appel = SeanceAppel::create([
            'schedule_slot_id' => $slot->id, 'date' => $date, 'teacher_id' => $moi->id, 'statut' => 'en_cours',
        ]);

        $this->withSession(['enseignant_auth_id' => $moi->id])
            ->post(route('enseignant.appel.enregistrer', $appel), ['releves' => [
                ['etudiant_id' => $dedans->id, 'statut' => 'present'],
                ['etudiant_id' => $dehors->id, 'statut' => 'present'],
            ]])
            ->assertSessionHasErrors(['appel']);

        $this->assertSame(0, Presence::count());

        // Rejeté du dossier exclu aussi.
        $rejete = $this->creerEtudiant($this->groupe, 'Rejete');
        $rejete->definirStatut('rejete');
        $this->withSession(['enseignant_auth_id' => $moi->id])
            ->get(route('enseignant.appel.feuille', $appel))
            ->assertOk()
            ->assertDontSee('Rejete');
    }

    // 5+6. Statuts persistés ; non traité ≠ absent.
    public function test_statuts_persiste_et_non_renseigne_distinct(): void
    {
        $moi = $this->creerEnseignant($this->faculte);
        $present = $this->creerEtudiant($this->groupe, 'Present');
        $absent = $this->creerEtudiant($this->groupe, 'Absent');
        $retard = $this->creerEtudiant($this->groupe, 'Retard');
        $oublie = $this->creerEtudiant($this->groupe, 'Oublie');
        $slot = $this->creerCreneau($moi, 'monday');
        $date = $this->datePasseePour('monday');
        $appel = SeanceAppel::create([
            'schedule_slot_id' => $slot->id, 'date' => $date, 'teacher_id' => $moi->id, 'statut' => 'en_cours',
        ]);

        // Statut invalide refusé.
        $this->withSession(['enseignant_auth_id' => $moi->id])
            ->post(route('enseignant.appel.enregistrer', $appel), ['releves' => [
                ['etudiant_id' => $present->id, 'statut' => 'excuse'],
            ]])
            ->assertSessionHasErrors(['appel']);
        $this->assertSame(0, Presence::count());

        $this->withSession(['enseignant_auth_id' => $moi->id])
            ->post(route('enseignant.appel.enregistrer', $appel), ['releves' => [
                ['etudiant_id' => $present->id, 'statut' => 'present'],
                ['etudiant_id' => $absent->id, 'statut' => 'absent'],
                ['etudiant_id' => $retard->id, 'statut' => 'retard'],
            ]])
            ->assertRedirect(route('enseignant.appel.feuille', $appel));

        $this->assertSame('present', Presence::where('etudiant_id', $present->id)->firstOrFail()->statut);
        $this->assertSame('absent', Presence::where('etudiant_id', $absent->id)->firstOrFail()->statut);
        $this->assertSame('retard', Presence::where('etudiant_id', $retard->id)->firstOrFail()->statut);
        $this->assertNull(Presence::where('etudiant_id', $oublie->id)->first());

        // Rattachement séance + auteur enseignant.
        $ligne = Presence::where('etudiant_id', $present->id)->firstOrFail();
        $this->assertSame($slot->id, (int) $ligne->schedule_slot_id);
        $this->assertSame($moi->id, (int) $ligne->teacher_id);
    }

    // 7. Bulk : tout-ou-rien (une ligne invalide = rien persisté).
    public function test_bulk_tout_ou_rien(): void
    {
        $moi = $this->creerEnseignant($this->faculte);
        $ok = $this->creerEtudiant($this->groupe, 'Ok');
        $slot = $this->creerCreneau($moi, 'monday');
        $date = $this->datePasseePour('monday');
        $appel = SeanceAppel::create([
            'schedule_slot_id' => $slot->id, 'date' => $date, 'teacher_id' => $moi->id, 'statut' => 'en_cours',
        ]);

        $this->withSession(['enseignant_auth_id' => $moi->id])
            ->post(route('enseignant.appel.enregistrer', $appel), ['releves' => [
                ['etudiant_id' => $ok->id, 'statut' => 'present'],
                ['etudiant_id' => 999999, 'statut' => 'present'],
            ]])
            ->assertSessionHasErrors(['appel']);

        $this->assertSame(0, Presence::count());
    }

    // 8. Reprise d'un appel en cours (pré-rempli).
    public function test_reprise_appel_en_cours(): void
    {
        $moi = $this->creerEnseignant($this->faculte);
        $etudiant = $this->creerEtudiant($this->groupe, 'Repris');
        $slot = $this->creerCreneau($moi, 'monday');
        $date = $this->datePasseePour('monday');
        $appel = SeanceAppel::create([
            'schedule_slot_id' => $slot->id, 'date' => $date, 'teacher_id' => $moi->id, 'statut' => 'en_cours',
        ]);

        $this->withSession(['enseignant_auth_id' => $moi->id])
            ->post(route('enseignant.appel.enregistrer', $appel), ['releves' => [
                ['etudiant_id' => $etudiant->id, 'statut' => 'retard'],
            ]])
            ->assertRedirect();

        // La feuille affiche le statut enregistré (reprise possible).
        $this->withSession(['enseignant_auth_id' => $moi->id])
            ->get(route('enseignant.appel.feuille', $appel))
            ->assertOk()
            ->assertSee('Enregistré : retard');
    }

    // 9. Doublons empêchés (ré-enregistrement idempotent + unique DB).
    public function test_doublons_empeches(): void
    {
        $moi = $this->creerEnseignant($this->faculte);
        $etudiant = $this->creerEtudiant($this->groupe, 'Unique');
        $slot = $this->creerCreneau($moi, 'monday');
        $date = $this->datePasseePour('monday');
        $appel = SeanceAppel::create([
            'schedule_slot_id' => $slot->id, 'date' => $date, 'teacher_id' => $moi->id, 'statut' => 'en_cours',
        ]);
        $payload = ['releves' => [['etudiant_id' => $etudiant->id, 'statut' => 'present']]];
        $session = ['enseignant_auth_id' => $moi->id];

        $this->withSession($session)->post(route('enseignant.appel.enregistrer', $appel), $payload)->assertRedirect();
        $this->withSession($session)->post(route('enseignant.appel.enregistrer', $appel), $payload)->assertRedirect();

        $this->assertSame(1, Presence::where('etudiant_id', $etudiant->id)->count());
    }

    // 10. Clôture : incomplet refusé sans confirmation, non-renseignés jamais absents.
    public function test_cloture_incomplet_exige_confirmation(): void
    {
        $moi = $this->creerEnseignant($this->faculte);
        $traite = $this->creerEtudiant($this->groupe, 'Traite');
        $oublie = $this->creerEtudiant($this->groupe, 'Oublie');
        $slot = $this->creerCreneau($moi, 'monday');
        $date = $this->datePasseePour('monday');
        $appel = SeanceAppel::create([
            'schedule_slot_id' => $slot->id, 'date' => $date, 'teacher_id' => $moi->id, 'statut' => 'en_cours',
        ]);
        $session = ['enseignant_auth_id' => $moi->id];
        $this->withSession($session)->post(route('enseignant.appel.enregistrer', $appel), [
            'releves' => [['etudiant_id' => $traite->id, 'statut' => 'present']],
        ]);

        // Sans confirmation : refus avec compte.
        $this->withSession($session)->post(route('enseignant.appel.cloturer', $appel))
            ->assertSessionHasErrors(['appel']);
        $this->assertSame('en_cours', $appel->fresh()->statut);

        // Avec confirmation : clôture, l'oublié reste sans ligne (pas absent).
        $this->withSession($session)->post(route('enseignant.appel.cloturer', $appel), ['confirmer_incomplet' => true])
            ->assertRedirect();
        $frais = $appel->fresh();
        $this->assertSame('cloture', $frais->statut);
        $this->assertNotNull($frais->cloture_le);
        $this->assertSame(1, (int) $frais->presents);
        $this->assertSame(1, (int) $frais->non_renseignes);
        $this->assertNull(Presence::where('etudiant_id', $oublie->id)->first());
    }

    // 11. Corrections motivées tracées ; post-clôture verrouillée sans motif.
    public function test_correction_motivee_tracee(): void
    {
        $moi = $this->creerEnseignant($this->faculte);
        $etudiant = $this->creerEtudiant($this->groupe, 'Corrige');
        $slot = $this->creerCreneau($moi, 'monday');
        $date = $this->datePasseePour('monday');
        $appel = SeanceAppel::create([
            'schedule_slot_id' => $slot->id, 'date' => $date, 'teacher_id' => $moi->id, 'statut' => 'en_cours',
        ]);
        $session = ['enseignant_auth_id' => $moi->id];
        $this->withSession($session)->post(route('enseignant.appel.enregistrer', $appel), [
            'releves' => [['etudiant_id' => $etudiant->id, 'statut' => 'absent']],
        ]);
        $this->withSession($session)->post(route('enseignant.appel.cloturer', $appel), ['confirmer_incomplet' => true]);

        // Sans motif : refusé.
        $this->withSession($session)->post(route('enseignant.appel.corriger', $appel), [
            'etudiant_id' => $etudiant->id, 'statut' => 'present', 'motif' => 'x',
        ])->assertSessionHasErrors(['motif']);

        $this->withSession($session)->post(route('enseignant.appel.corriger', $appel), [
            'etudiant_id' => $etudiant->id, 'statut' => 'present', 'motif' => 'Erreur de ligne, présent constaté.',
        ])->assertRedirect();

        $ligne = Presence::where('etudiant_id', $etudiant->id)->firstOrFail();
        $this->assertSame('present', $ligne->statut);
        $actions = array_column($ligne->historique ?? [], 'action');
        $this->assertContains('correction_appel', $actions);

        // Feuille clôturée affiche l'historique (enregistrement + correction).
        $this->withSession($session)->get(route('enseignant.appel.feuille', $appel))
            ->assertOk()
            ->assertSee('Historique (2)');
    }

    // 12. Isolation facultés (séance + appel d'une autre faculté invisibles).
    public function test_isolation_facultes(): void
    {
        $moi = $this->creerEnseignant($this->faculte);
        $collegueB = $this->creerEnseignant($this->faculteB);
        $slotB = $this->creerCreneau($collegueB, 'monday');
        $slotB->schedule->update(['faculte_id' => $this->faculteB->id, 'programme_id' => Programme::create([
            'code' => 'P-B', 'nom' => 'Prog B', 'faculte_id' => $this->faculteB->id,
            'cycle' => 'licence', 'duree_annees' => 3, 'actif' => true,
        ])->id]);
        $date = $this->datePasseePour('monday');
        $appelB = SeanceAppel::create([
            'schedule_slot_id' => $slotB->id, 'date' => $date, 'teacher_id' => $collegueB->id, 'statut' => 'en_cours',
        ]);

        $session = ['enseignant_auth_id' => $moi->id];
        $this->withSession($session)->post(route('enseignant.appel.ouvrir', ['slot' => $slotB->id, 'date' => $date]))->assertNotFound();
        $this->withSession($session)->get(route('enseignant.appel.feuille', $appelB))->assertNotFound();
    }

    // 13. Compteurs conformes aux lignes.
    public function test_compteurs_conformes(): void
    {
        $moi = $this->creerEnseignant($this->faculte);
        $eleves = [];
        foreach (['A', 'B', 'C', 'D'] as $nom) {
            $eleves[] = $this->creerEtudiant($this->groupe, 'Eleve'.$nom);
        }
        $slot = $this->creerCreneau($moi, 'monday');
        $date = $this->datePasseePour('monday');
        $appel = SeanceAppel::create([
            'schedule_slot_id' => $slot->id, 'date' => $date, 'teacher_id' => $moi->id, 'statut' => 'en_cours',
        ]);

        $this->withSession(['enseignant_auth_id' => $moi->id])
            ->post(route('enseignant.appel.enregistrer', $appel), ['releves' => [
                ['etudiant_id' => $eleves[0]->id, 'statut' => 'present'],
                ['etudiant_id' => $eleves[1]->id, 'statut' => 'absent'],
                ['etudiant_id' => $eleves[2]->id, 'statut' => 'retard'],
            ]])
            ->assertRedirect();

        $this->withSession(['enseignant_auth_id' => $moi->id])
            ->post(route('enseignant.appel.cloturer', $appel), ['confirmer_incomplet' => true])
            ->assertRedirect();

        $frais = $appel->fresh();
        $this->assertSame(['presents' => 1, 'absents' => 1, 'retards' => 1, 'non_renseignes' => 1], [
            'presents' => (int) $frais->presents, 'absents' => (int) $frais->absents,
            'retards' => (int) $frais->retards, 'non_renseignes' => (int) $frais->non_renseignes,
        ]);
    }

    // 14. Concurrence : double clôture + double enregistrement sans corruption.
    public function test_concurrence_sans_corruption(): void
    {
        $moi = $this->creerEnseignant($this->faculte);
        $etudiant = $this->creerEtudiant($this->groupe, 'Conc');
        $slot = $this->creerCreneau($moi, 'monday');
        $date = $this->datePasseePour('monday');
        $appel = SeanceAppel::create([
            'schedule_slot_id' => $slot->id, 'date' => $date, 'teacher_id' => $moi->id, 'statut' => 'en_cours',
        ]);
        $session = ['enseignant_auth_id' => $moi->id];
        $payload = ['releves' => [['etudiant_id' => $etudiant->id, 'statut' => 'present']]];

        $this->withSession($session)->post(route('enseignant.appel.enregistrer', $appel), $payload)->assertRedirect();
        $this->withSession($session)->post(route('enseignant.appel.enregistrer', $appel), $payload)->assertRedirect();
        $this->assertSame(1, Presence::where('etudiant_id', $etudiant->id)->count());

        $this->withSession($session)->post(route('enseignant.appel.cloturer', $appel))->assertRedirect();
        // Enregistrer après clôture : refusé (correction motivée exigée).
        $this->withSession($session)->post(route('enseignant.appel.enregistrer', $appel), $payload)
            ->assertSessionHasErrors(['appel']);
        // Re-clôturer : idempotent, mêmes compteurs.
        $this->withSession($session)->post(route('enseignant.appel.cloturer', $appel))->assertRedirect();
        $this->assertSame('cloture', $appel->fresh()->statut);
    }

    // 15. Performance sur grand groupe (120 étudiants).
    public function test_performance_grand_groupe(): void
    {
        $moi = $this->creerEnseignant($this->faculte);

        for ($i = 0; $i < 120; $i++) {
            $this->creerEtudiant($this->groupe, 'Eleve'.str_pad((string) $i, 3, '0', STR_PAD_LEFT));
        }

        $slot = $this->creerCreneau($moi, 'monday');
        $date = $this->datePasseePour('monday');
        $appel = SeanceAppel::create([
            'schedule_slot_id' => $slot->id, 'date' => $date, 'teacher_id' => $moi->id, 'statut' => 'en_cours',
        ]);

        DB::enableQueryLog();
        $debut = microtime(true);
        $this->withSession(['enseignant_auth_id' => $moi->id])
            ->get(route('enseignant.appel.feuille', $appel))
            ->assertOk();
        $duree = microtime(true) - $debut;
        $requetes = count(DB::getQueryLog());
        DB::disableQueryLog();

        fwrite(STDERR, "\n[PERF-APPEL] 120 étudiants : ".round($duree, 2).'s, '.$requetes.' requêtes'."\n");
        $this->assertLessThan(15, $requetes, "Feuille 120 : {$requetes} requêtes.");
        $this->assertLessThan(30, $duree, 'Feuille trop lente.');
    }

    // Séance annulée / future : refus explicite, rien créé.
    public function test_seance_annulee_ou_future_refusee(): void
    {
        $moi = $this->creerEnseignant($this->faculte);
        $slot = $this->creerCreneau($moi, 'monday');
        $date = $this->datePasseePour('monday');
        $session = ['enseignant_auth_id' => $moi->id];

        $slot->update(['status' => 'cancelled']);
        $this->withSession($session)->post(route('enseignant.appel.ouvrir', ['slot' => $slot->id, 'date' => $date]))
            ->assertSessionHasErrors(['appel']);
        $this->assertSame(0, SeanceAppel::count());

        $slot->update(['status' => 'scheduled']);
        $futur = Carbon::today()->addDays(3)->toDateString();
        $this->withSession($session)->post(route('enseignant.appel.ouvrir', ['slot' => $slot->id, 'date' => $futur]))
            ->assertStatus(422);
        $this->assertSame(0, SeanceAppel::count());
    }

    // Back-office : consultation des appels (scope + 403).
    public function test_back_office_consulte_appels(): void
    {
        $admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.'.uniqid().'@univ.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $moi = $this->creerEnseignant($this->faculte);
        $etudiant = $this->creerEtudiant($this->groupe, 'Vu');
        $slot = $this->creerCreneau($moi, 'monday');
        $date = $this->datePasseePour('monday');
        $appel = SeanceAppel::create([
            'schedule_slot_id' => $slot->id, 'date' => $date, 'teacher_id' => $moi->id, 'statut' => 'en_cours',
        ]);
        app(AppelPresenceService::class)
            ->enregistrer($appel, [['etudiant_id' => $etudiant->id, 'statut' => 'present']], $moi);

        $this->actingAs($admin)->get(route('admin.appels.index'))->assertOk()->assertSee('Algo');
        $this->actingAs($admin)->get(route('admin.appels.show', $appel))->assertOk()->assertSee('Vu');

        $consultation = User::creerCompteBackOffice([
            'name' => 'Lecture', 'email' => 'lec.'.uniqid().'@univ.test', 'password' => 'Motdepasse@123',
        ], 'consultation');
        // Lecture seule : le rôle consultation voit (attendance.view) sans pouvoir traiter.
        $this->actingAs($consultation)->get(route('admin.appels.index'))->assertOk();
    }
}
