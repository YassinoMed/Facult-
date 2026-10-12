<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Modules\Attendance\Models\Presence;
use App\Modules\Attendance\Models\SeanceAppel;
use App\Modules\Attendance\Services\AppelPresenceService;
use App\Modules\Attendance\Services\AttendanceQrSecurityService;
use App\Modules\Referentiel\Models\Departement;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOOP 21 — QR temporaire d'appel : jeton nominatif 15 minutes lié à
 * l'occurrence d'appel, validation serveur complète, idempotence,
 * impossible de pointer pour un autre, refusé si clôturé.
 */
class AppelQrTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private Teacher $enseignant;

    private Teacher $etranger;

    private Group $groupe;

    private Group $groupeAutre;

    private Etudiant $etudiant;

    private Etudiant $camarade;

    private SeanceAppel $appel;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.attendance.active' => true, 'modules.schedule.active' => true]);

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'active' => true]);
        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $programme = Programme::create([
            'code' => 'L-INFO', 'nom' => 'Licence Info', 'faculte_id' => $this->faculte->id,
            'cycle' => 'licence', 'duree_annees' => 3, 'actif' => true,
        ]);
        $matiere = Matiere::create([
            'code' => 'ALGO', 'nom' => 'Algo', 'niveau' => 'L1', 'semestre' => 1, 'coefficient' => 2, 'credits' => 4,
        ]);
        $salle = Room::create([
            'code' => 'A1', 'nom' => 'A1', 'capacite' => 100, 'faculte_id' => $this->faculte->id, 'actif' => true,
        ]);
        $this->groupe = Group::create([
            'faculte_id' => $this->faculte->id, 'code' => 'G1', 'nom' => 'Groupe 1', 'niveau' => 'L1', 'actif' => true,
        ]);
        $this->groupeAutre = Group::create([
            'faculte_id' => $this->faculte->id, 'code' => 'G2', 'nom' => 'Groupe 2', 'niveau' => 'L1', 'actif' => true,
        ]);
        $planning = Schedule::create([
            'nom' => 'EDT', 'annee_universitaire_id' => $annee->id,
            'faculte_id' => $this->faculte->id, 'programme_id' => $programme->id,
            'groupe_id' => $this->groupe->id, 'semestre' => 1, 'statut' => 'published',
        ]);

        $this->enseignant = $this->creerEnseignant();
        $this->etranger = $this->creerEnseignant();

        $slot = ScheduleSlot::create([
            'schedule_id' => $planning->id, 'matiere_id' => $matiere->id,
            'teacher_id' => $this->enseignant->id, 'room_id' => $salle->id,
            'day_of_week' => strtolower(Carbon::today()->format('l')), 'start_time' => '08:00:00', 'end_time' => '10:00:00',
            'type' => 'course', 'status' => 'scheduled',
        ]);

        $this->etudiant = $this->creerEtudiant($this->groupe, 'Ben Ali');
        $this->camarade = $this->creerEtudiant($this->groupe, 'Trabelsi');

        $this->appel = app(AppelPresenceService::class)->ouvrir($slot, Carbon::today()->toDateString(), $this->enseignant);
    }

    private function creerEnseignant(): Teacher
    {
        $departement = Departement::create([
            'code' => 'DEP-'.uniqid(), 'nom' => 'Département', 'faculte_id' => $this->faculte->id,
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

    private function creerEtudiant(Group $groupe, string $nom): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => 'CIN'.uniqid(), 'nom' => $nom, 'prenom' => 'Prenom',
            'email' => 'etu'.uniqid().'@test.ma', 'date_naissance' => '2004-01-01',
            'sexe' => 'M', 'faculte_id' => $groupe->faculte_id, 'niveau' => 'L1',
            'annee_academique' => '2026-2027', 'adresse' => '12 rue', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
            'groupe_id' => $groupe->id,
        ]);
        $etudiant->definirStatut('valide');

        return $etudiant;
    }

    private function jetonPour(Etudiant $etudiant, ?SeanceAppel $appel = null, int $minutes = 15): string
    {
        $appel ??= $this->appel;

        return app(AttendanceQrSecurityService::class)->genererToken(
            $etudiant->id, 'APPEL-'.$appel->id, $appel->date->toDateString(), $minutes
        );
    }

    public function test_enseignant_affiche_qr_nominatif_et_idor(): void
    {
        $this->withSession(['enseignant_auth_id' => $this->enseignant->id])
            ->get(route('enseignant.appel.qr', [$this->appel, $this->etudiant]))
            ->assertOk()
            ->assertSee('<svg', false)
            ->assertSee($this->etudiant->nom);

        // Enseignant étranger : 404, rien ne fuit.
        $this->withSession(['enseignant_auth_id' => $this->etranger->id])
            ->get(route('enseignant.appel.qr', [$this->appel, $this->etudiant]))
            ->assertNotFound();

        // Étudiant hors groupe : 404.
        $intrus = $this->creerEtudiant($this->groupeAutre, 'Intrus');
        $this->withSession(['enseignant_auth_id' => $this->enseignant->id])
            ->get(route('enseignant.appel.qr', [$this->appel, $intrus]))
            ->assertNotFound();
    }

    public function test_pointage_nominal_idempotent(): void
    {
        $jeton = $this->jetonPour($this->etudiant);

        $this->withSession(['etudiant_auth_id' => $this->etudiant->id])
            ->post(route('etudiant.appel.pointer'), ['jeton' => $jeton])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertEquals('present', Presence::where('etudiant_id', $this->etudiant->id)->firstOrFail()->statut);

        // Double scan : idempotent, toujours une seule ligne.
        $this->withSession(['etudiant_auth_id' => $this->etudiant->id])
            ->post(route('etudiant.appel.pointer'), ['jeton' => $jeton])
            ->assertSessionHasNoErrors();

        $this->assertEquals(1, Presence::where('etudiant_id', $this->etudiant->id)->count());
    }

    public function test_impossible_de_pointer_pour_un_autre(): void
    {
        $jetonCamarade = $this->jetonPour($this->camarade);

        $this->withSession(['etudiant_auth_id' => $this->etudiant->id])
            ->post(route('etudiant.appel.pointer'), ['jeton' => $jetonCamarade])
            ->assertSessionHasErrors(['jeton']);

        $this->assertEquals(0, Presence::where('etudiant_id', $this->camarade)->count());
        $this->assertEquals(0, Presence::where('etudiant_id', $this->etudiant->id)->count());
    }

    public function test_jeton_expire_falsifie_et_cloture_refuses(): void
    {
        // Expiré.
        $this->withSession(['etudiant_auth_id' => $this->etudiant->id])
            ->post(route('etudiant.appel.pointer'), ['jeton' => $this->jetonPour($this->etudiant, null, -5)])
            ->assertSessionHasErrors(['jeton']);

        // Falsifié.
        $jeton = $this->jetonPour($this->etudiant);
        $this->withSession(['etudiant_auth_id' => $this->etudiant->id])
            ->post(route('etudiant.appel.pointer'), ['jeton' => $jeton.'falsifie'])
            ->assertSessionHasErrors(['jeton']);

        // Appel clôturé : QR refusé des deux côtés.
        app(AppelPresenceService::class)->cloturer($this->appel->fresh(), true);

        $this->withSession(['etudiant_auth_id' => $this->etudiant->id])
            ->post(route('etudiant.appel.pointer'), ['jeton' => $this->jetonPour($this->etudiant)])
            ->assertSessionHasErrors(['jeton']);

        $this->withSession(['enseignant_auth_id' => $this->enseignant->id])
            ->get(route('enseignant.appel.qr', [$this->appel->fresh(), $this->etudiant]))
            ->assertStatus(422);

        $this->assertEquals(0, Presence::where('etudiant_id', $this->etudiant->id)->count());
    }

    public function test_export_feuille_enseignant_scope_et_neutralise_formules(): void
    {
        $piege = $this->creerEtudiant($this->groupe, '=CMD()');
        app(AppelPresenceService::class)->enregistrer(
            $this->appel->fresh(), [
                ['etudiant_id' => $this->etudiant->id, 'statut' => 'present'],
                ['etudiant_id' => $piege->id, 'statut' => 'absent'],
            ], $this->enseignant
        );

        $reponse = $this->withSession(['enseignant_auth_id' => $this->enseignant->id])
            ->get(route('enseignant.appel.exporter', $this->appel))
            ->assertOk();

        $contenu = $reponse->streamedContent();
        $this->assertStringContainsString('Ben Ali', $contenu);
        $this->assertStringContainsString('present', $contenu);
        $this->assertStringContainsString("'=CMD()", $contenu);

        $this->withSession(['enseignant_auth_id' => $this->etranger->id])
            ->get(route('enseignant.appel.exporter', $this->appel))
            ->assertNotFound();
    }

    public function test_export_absences_etudiant_isole(): void
    {
        $this->withSession(['etudiant_auth_id' => $this->etudiant->id])
            ->post(route('etudiant.appel.pointer'), ['jeton' => $this->jetonPour($this->etudiant)])
            ->assertSessionHasNoErrors();

        $contenu = $this->withSession(['etudiant_auth_id' => $this->etudiant->id])
            ->get(route('etudiant.absences.exporter'))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('present', $contenu);

        // Le camarade ne voit pas le relevé dans SON export.
        $contenuCamarade = $this->withSession(['etudiant_auth_id' => $this->camarade->id])
            ->get(route('etudiant.absences.exporter'))
            ->assertOk()
            ->streamedContent();

        $this->assertStringNotContainsString('present', $contenuCamarade);
    }

    public function test_gardes_exigent_authentification(): void
    {
        $this->get(route('etudiant.appel.qr'))->assertRedirect(route('etudiant.connexion.show'));
        $this->post(route('etudiant.appel.pointer'), ['jeton' => 'x'])
            ->assertRedirect(route('etudiant.connexion.show'));
        $this->get(route('enseignant.appel.qr', [$this->appel, $this->etudiant]))
            ->assertRedirect(route('enseignant.connexion.show'));
    }
}
