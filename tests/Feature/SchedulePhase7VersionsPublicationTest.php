<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Batiment;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\ScheduleVersion;
use App\Modules\Schedule\Models\Teacher;
use App\Modules\Schedule\Services\ScheduleVersioningService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchedulePhase7VersionsPublicationTest extends TestCase
{
    use RefreshDatabase;

    protected Faculte $faculte;

    protected Group $groupe;

    protected AnneeUniversitaire $annee;

    protected Batiment $batiment;

    protected Schedule $schedule;

    protected Matiere $matiereAlgo;

    protected Matiere $matiereWeb;

    protected Teacher $profAlgo;

    protected Room $salle101;

    protected Room $salle102;

    protected User $doyen;

    protected ScheduleVersioningService $versioningService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences Test P7',
            'code' => 'FST_P7_'.uniqid(),
            'actif' => true,
        ]);

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027-P7-'.uniqid(),
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->batiment = Batiment::create([
            'nom' => 'Bâtiment Principal P7',
            'code' => 'BATP7_'.uniqid(),
            'faculte_id' => $this->faculte->id,
            'actif' => true,
        ]);

        $this->groupe = Group::create([
            'nom' => 'Groupe L3 Info P7',
            'code' => 'L3_P7_'.uniqid(),
            'niveau' => 'L3',
            'capacite' => 30,
            'faculte_id' => $this->faculte->id,
            'actif' => true,
        ]);

        $this->schedule = Schedule::create([
            'annee_universitaire_id' => $this->annee->id,
            'semestre' => 1,
            'groupe_id' => $this->groupe->id,
            'nom' => 'Emploi du temps L3 Info S1 P7',
            'statut' => 'brouillon',
            'actif' => true,
        ]);

        $codeAlgo = 'ALG_P7_'.substr(uniqid(), -6);
        $this->matiereAlgo = Matiere::create([
            'code' => $codeAlgo,
            'nom' => 'Algorithmique P7',
            'niveau' => 'L3',
            'semestre' => 1,
            'faculte_id' => $this->faculte->id,
        ]);

        $codeWeb = 'WEB_P7_'.substr(uniqid(), -6);
        $this->matiereWeb = Matiere::create([
            'code' => $codeWeb,
            'nom' => 'Web Avancé P7',
            'niveau' => 'L3',
            'semestre' => 1,
            'faculte_id' => $this->faculte->id,
        ]);

        $this->profAlgo = Teacher::create([
            'matricule' => 'ENS-P7-'.substr(uniqid(), -6),
            'nom' => 'Gharbi',
            'prenom' => 'Hassen',
            'email' => 'hassen.gharbi@test.tn',
            'charge_statutaire' => 192.0,
            'qualifications' => [$codeAlgo, $codeWeb],
            'actif' => true,
        ]);

        $this->salle101 = Room::create([
            'code' => 'S101_P7_'.uniqid(),
            'nom' => 'Salle 101 P7',
            'capacite' => 40,
            'actif' => true,
            'batiment' => 'Batiment P7',
            'batiment_id' => $this->batiment->id,
            'faculte_id' => $this->faculte->id,
            'heure_ouverture' => '08:00:00',
            'heure_fermeture' => '19:00:00',
        ]);

        $this->salle102 = Room::create([
            'code' => 'S102_P7_'.uniqid(),
            'nom' => 'Salle 102 P7',
            'capacite' => 45,
            'actif' => true,
            'batiment' => 'Batiment P7',
            'batiment_id' => $this->batiment->id,
            'faculte_id' => $this->faculte->id,
            'heure_ouverture' => '08:00:00',
            'heure_fermeture' => '19:00:00',
        ]);

        $this->doyen = User::factory()->create(['name' => 'Doyen P7']);
        $this->versioningService = app(ScheduleVersioningService::class);
    }

    public function test_publication_cree_version_immuable_et_met_a_jour_planning(): void
    {
        ScheduleSlot::create([
            'schedule_id' => $this->schedule->id,
            'matiere_id' => $this->matiereAlgo->id,
            'teacher_id' => $this->profAlgo->id,
            'room_id' => $this->salle101->id,
            'day_of_week' => 'monday',
            'start_time' => '08:30:00',
            'end_time' => '10:00:00',
            'type' => 'course',
            'status' => 'scheduled',
        ]);

        $version = $this->versioningService->creerVersionPubliee(
            $this->schedule,
            $this->doyen->id,
            'Publication officielle rentrée'
        );

        $this->assertSame(1, $version->numero_version);
        $this->assertSame('V1.0', $version->code_version);
        $this->assertSame(ScheduleVersion::STATUT_PUBLIEE, $version->statut);
        $this->assertSame(1, $version->nb_slots);
        $this->assertNotNull($version->published_at);
        $this->assertNotEmpty($version->snapshot_slots_json);

        $this->schedule->refresh();
        $this->assertSame('publie', $this->schedule->statut);
        $this->assertNotNull($this->schedule->publie_a);
    }

    public function test_publication_interdite_si_planning_sans_creneaux(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('sans aucun créneau');

        $this->versioningService->creerVersionPubliee(
            $this->schedule,
            $this->doyen->id
        );
    }

    public function test_seconde_publication_incremente_version_et_archive_precedente(): void
    {
        ScheduleSlot::create([
            'schedule_id' => $this->schedule->id,
            'matiere_id' => $this->matiereAlgo->id,
            'teacher_id' => $this->profAlgo->id,
            'room_id' => $this->salle101->id,
            'day_of_week' => 'monday',
            'start_time' => '08:30:00',
            'end_time' => '10:00:00',
            'type' => 'course',
            'status' => 'scheduled',
        ]);

        $v1 = $this->versioningService->creerVersionPubliee($this->schedule, $this->doyen->id, 'Version 1');
        $this->assertSame('V1.0', $v1->code_version);
        $this->assertSame(ScheduleVersion::STATUT_PUBLIEE, $v1->statut);

        // Repassage temporaire en révision pour modifier
        $this->schedule->update(['statut' => 'brouillon']);

        // Ajout d'un 2ème créneau
        ScheduleSlot::create([
            'schedule_id' => $this->schedule->id,
            'matiere_id' => $this->matiereWeb->id,
            'teacher_id' => $this->profAlgo->id,
            'room_id' => $this->salle101->id,
            'day_of_week' => 'wednesday',
            'start_time' => '14:00:00',
            'end_time' => '15:30:00',
            'type' => 'course',
            'status' => 'scheduled',
        ]);

        $v2 = $this->versioningService->creerVersionPubliee($this->schedule, $this->doyen->id, 'Version 2 ajustée');

        $this->assertSame(2, $v2->numero_version);
        $this->assertSame('V2.0', $v2->code_version);
        $this->assertSame(ScheduleVersion::STATUT_PUBLIEE, $v2->statut);

        // L'ancienne V1 doit être archivée
        $this->assertSame(ScheduleVersion::STATUT_ARCHIVEE, $v1->fresh()->statut);
    }

    public function test_comparaison_de_deux_versions_identifie_ajouts_et_modifications(): void
    {
        $slot1 = ScheduleSlot::create([
            'schedule_id' => $this->schedule->id,
            'matiere_id' => $this->matiereAlgo->id,
            'teacher_id' => $this->profAlgo->id,
            'room_id' => $this->salle101->id,
            'day_of_week' => 'monday',
            'start_time' => '08:30:00',
            'end_time' => '10:00:00',
            'type' => 'course',
            'status' => 'scheduled',
        ]);

        $v1 = $this->versioningService->creerVersionPubliee($this->schedule, $this->doyen->id, 'V1');

        $this->schedule->update(['statut' => 'brouillon']);

        // Modification de la salle du slot 1
        $slot1->update(['room_id' => $this->salle102->id]);

        // Ajout d'un slot web
        ScheduleSlot::create([
            'schedule_id' => $this->schedule->id,
            'matiere_id' => $this->matiereWeb->id,
            'teacher_id' => $this->profAlgo->id,
            'room_id' => $this->salle102->id,
            'day_of_week' => 'tuesday',
            'start_time' => '10:00:00',
            'end_time' => '12:00:00',
            'type' => 'course',
            'status' => 'scheduled',
        ]);

        $v2 = $this->versioningService->creerVersionPubliee($this->schedule, $this->doyen->id, 'V2');

        $diff = $this->versioningService->comparerVersions($v1, $v2);

        $this->assertCount(1, $diff['ajoutes']);
        $this->assertCount(1, $diff['modifies']);
        $this->assertStringContainsString('+1 créneaux', $diff['resume']);
    }

    public function test_restauration_version_reinitialise_creneaux_et_repasse_en_brouillon(): void
    {
        // V1 initiale avec 1 seul slot
        ScheduleSlot::create([
            'schedule_id' => $this->schedule->id,
            'matiere_id' => $this->matiereAlgo->id,
            'teacher_id' => $this->profAlgo->id,
            'room_id' => $this->salle101->id,
            'day_of_week' => 'monday',
            'start_time' => '08:30:00',
            'end_time' => '10:00:00',
            'type' => 'course',
            'status' => 'scheduled',
        ]);

        $v1 = $this->versioningService->creerVersionPubliee($this->schedule, $this->doyen->id, 'V1');

        // Passage en brouillon puis ajout de 2 autres créneaux (total 3 créneaux)
        $this->schedule->update(['statut' => 'brouillon']);
        ScheduleSlot::create([
            'schedule_id' => $this->schedule->id,
            'matiere_id' => $this->matiereWeb->id,
            'teacher_id' => $this->profAlgo->id,
            'room_id' => $this->salle101->id,
            'day_of_week' => 'tuesday',
            'start_time' => '08:30:00',
            'end_time' => '10:00:00',
            'type' => 'course',
            'status' => 'scheduled',
        ]);
        ScheduleSlot::create([
            'schedule_id' => $this->schedule->id,
            'matiere_id' => $this->matiereWeb->id,
            'teacher_id' => $this->profAlgo->id,
            'room_id' => $this->salle102->id,
            'day_of_week' => 'wednesday',
            'start_time' => '10:00:00',
            'end_time' => '12:00:00',
            'type' => 'course',
            'status' => 'scheduled',
        ]);

        $this->assertSame(3, $this->schedule->slots()->count());

        // Rollback vers la V1
        $planningRestaure = $this->versioningService->restaurerVersion(
            $v1,
            $this->doyen->id,
            'Retour à la V1 suite à annulation des modifications'
        );

        $this->assertSame('brouillon', $planningRestaure->statut);
        $this->assertSame(1, $planningRestaure->slots()->count());

        $slotRestaure = $planningRestaure->slots()->first();
        $this->assertSame($this->matiereAlgo->id, $slotRestaure->matiere_id);
        $this->assertSame('08:30:00', $slotRestaure->start_time);
    }

    public function test_restauration_interdite_sur_planning_verrouille(): void
    {
        ScheduleSlot::create([
            'schedule_id' => $this->schedule->id,
            'matiere_id' => $this->matiereAlgo->id,
            'teacher_id' => $this->profAlgo->id,
            'room_id' => $this->salle101->id,
            'day_of_week' => 'monday',
            'start_time' => '08:30:00',
            'end_time' => '10:00:00',
            'type' => 'course',
            'status' => 'scheduled',
        ]);

        $v1 = $this->versioningService->creerVersionPubliee($this->schedule, $this->doyen->id);

        $this->schedule->update(['statut' => 'verrouille']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('verrouillé ou archivé');

        $this->versioningService->restaurerVersion($v1, $this->doyen->id);
    }
}
