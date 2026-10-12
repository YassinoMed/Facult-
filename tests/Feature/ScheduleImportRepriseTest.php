<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleImport;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * SCHEDULE-08 — Import interrompu et reprise : le contrat existant est
 * « tout ou rien » par confirmation (rollback intégral, rapport `failed`,
 * token consommé). La reprise = re-prévisualisation puis nouvelle
 * confirmation ; jamais de ré-application silencieuse ni de doublons.
 */
class ScheduleImportRepriseTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Group $groupe;

    private AnneeUniversitaire $annee;

    private Matiere $matiere;

    private Teacher $enseignant;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.schedule.active' => true]);

        $faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'x', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Sco', 'email' => 'sco.rep@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->groupe = Group::create([
            'code' => 'GI2-R', 'nom' => 'GI2 R', 'faculte_id' => $faculte->id,
            'niveau' => 'L1', 'filiere' => 'Informatique', 'actif' => true,
        ]);
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->matiere = Matiere::create([
            'code' => 'REP101', 'nom' => 'Développement Web',
            'coefficient' => 2, 'credits' => 6, 'niveau' => 'L1', 'semestre' => 1,
        ]);
        $this->enseignant = Teacher::create(['nom' => 'Enseignant', 'prenom' => 'A', 'email' => 'rep.a@universite.test']);
        Room::create(['code' => 'B12', 'capacite' => 40]);
    }

    private function csvValide(): string
    {
        return implode("\n", [
            'academic_year,semester,group,day,start_time,end_time,course,teacher,room,type',
            '2026/2027,S1,GI2-R,monday,08:00,10:00,Développement Web,Enseignant A,B12,course',
            '2026/2027,S1,GI2-R,tuesday,14:00,16:00,Développement Web,Enseignant A,B12,td',
        ]);
    }

    private function preview(): string
    {
        $this->actingAs($this->admin)->post(route('admin.emplois-du-temps.import.preview'), [
            'fichier' => UploadedFile::fake()->createWithContent('planning.csv', $this->csvValide(), 'text/csv'),
        ])->assertOk();

        foreach (session()->get('schedule_imports', []) as $token => $payload) {
            return $token;
        }

        $this->fail('Aucun token de prévisualisation en session.');
    }

    /**
     * TOCTOU : entre la prévisualisation et la confirmation, un créneau
     * conflictuel (même enseignant, même plage, autre groupe) apparaît →
     * la re-validation à la confirmation REFUSE, zéro écriture.
     */
    public function test_conflit_apparu_entre_preview_et_confirmation_refuse_sans_ecriture(): void
    {
        $token = $this->preview();

        // Concurrence réelle simulée : un autre planning verrouille l'enseignant
        // sur le même créneau (lundi 08:00-10:00).
        $groupeB = Group::create([
            'code' => 'GB-2', 'nom' => 'Groupe B2', 'faculte_id' => $this->groupe->faculte_id,
            'niveau' => 'L1', 'actif' => true,
        ]);
        $planningB = Schedule::create([
            'annee_universitaire_id' => $this->annee->id, 'semestre' => 1,
            'groupe_id' => $groupeB->id, 'nom' => 'Plan B', 'actif' => true,
        ]);
        ScheduleSlot::create([
            'schedule_id' => $planningB->id, 'matiere_id' => $this->matiere->id,
            'teacher_id' => $this->enseignant->id, 'room_id' => null,
            'day_of_week' => 'monday', 'start_time' => '08:00:00', 'end_time' => '10:00:00',
            'type' => 'course', 'status' => 'scheduled',
        ]);

        $this->actingAs($this->admin)->post(route('admin.emplois-du-temps.import.confirmer'), [
            'token' => $token, 'mode' => 'creer',
        ])->assertSessionHasErrors();

        $this->assertSame(1, Schedule::count(), 'Seul le planning concurrent existe : rien de partial de l\'import.');
        $this->assertSame(1, ScheduleSlot::count());
        $this->assertSame(0, ScheduleImport::where('status', 'completed')->count());
    }

    /**
     * Interruption PENDANT la transaction (planning existant verrouillé
     * entre preview et confirm en mode remplacement) : le delete des
     * anciens créneaux est annulé avec le reste — ancien état intact,
     * rapport `failed`, aucun doublon à la reprise.
     */
    public function test_interruption_en_transaction_rollback_integral_et_reprise(): void
    {
        // Premier import réussi (état pré-existant).
        $token1 = $this->preview();
        $this->actingAs($this->admin)->post(route('admin.emplois-du-temps.import.confirmer'), [
            'token' => $token1, 'mode' => 'creer',
        ])->assertOk(); // succès = vue rapport

        $planning = Schedule::where('groupe_id', $this->groupe->id)->firstOrFail();
        $this->assertSame(2, $planning->slots()->count());

        // Le planning est verrouillé pendant que l'agent prépare le remplacement.
        $token2 = $this->preview();
        $planning->forceFill(['statut' => 'verrouille'])->save();

        $this->actingAs($this->admin)->post(route('admin.emplois-du-temps.import.confirmer'), [
            'token' => $token2, 'mode' => 'remplacer', 'confirmation' => '1',
        ])->assertOk(); // échec transactionnel = rapport `failed` affiché, jamais 500

        $this->assertSame(2, $planning->slots()->count(), 'Rollback : les anciens créneaux supprimés en transaction sont restaurés.');
        $this->assertTrue(ScheduleImport::where('status', 'failed')->exists());

        // Reprise : après retour au brouillon, le même fichier remplace proprement.
        $planning->forceFill(['statut' => 'brouillon'])->save();
        $token3 = $this->preview();
        $this->actingAs($this->admin)->post(route('admin.emplois-du-temps.import.confirmer'), [
            'token' => $token3, 'mode' => 'remplacer', 'confirmation' => '1',
        ])->assertOk();

        $this->assertSame(2, $planning->fresh()->slots()->count(), 'Reprise idempotente : exactement les lignes du fichier, aucun doublon.');
        $this->assertTrue(ScheduleImport::where('status', 'completed')->exists());
    }

    /** Le token de prévisualisation est à usage unique (pas de rejeu). */
    public function test_token_consomme_rejeu_refuse(): void
    {
        $token = $this->preview();

        $this->actingAs($this->admin)->post(route('admin.emplois-du-temps.import.confirmer'), [
            'token' => $token, 'mode' => 'creer',
        ])->assertOk(); // premier confirm : rapport 200

        $this->actingAs($this->admin)->post(route('admin.emplois-du-temps.import.confirmer'), [
            'token' => $token, 'mode' => 'creer',
        ])->assertSessionHasErrors(); // rejeu : token consommé → erreur explicite

        $this->assertSame(2, ScheduleSlot::count(), 'Le rejeu n\'a rien ré-importé.');
    }

    /** Les lignes du fichier confirmé donnent un planning complet traçable. */
    public function test_confirm_reussi_trace_rapport_et_lignes(): void
    {
        $token = $this->preview();

        $this->actingAs($this->admin)->post(route('admin.emplois-du-temps.import.confirmer'), [
            'token' => $token, 'mode' => 'creer',
        ])->assertOk();

        $rapport = ScheduleImport::where('status', 'completed')->firstOrFail();
        $this->assertSame(2, (int) $rapport->successful_rows);
        $this->assertSame($this->admin->id, (int) $rapport->imported_by);
        $this->assertSame(2, ScheduleSlot::where('schedule_id', $rapport->schedule_id)->count());
    }
}
