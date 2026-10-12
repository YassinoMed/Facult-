<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Attendance\Models\Presence;
use App\Modules\Attendance\Services\AttendanceQrSecurityService;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleSlot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests de sécurité du système QR Code pour les présences (§8 & §22) :
 * - Validation cryptographique HMAC SHA-256
 * - Expiration temporelle stricte
 * - Aucune fuite de PII (CIN, email, téléphone)
 * - Contrôle d'inscription au groupe
 * - Idempotence (anti-double présence)
 * - Traçabilité d'audit (ATTENDANCE_QR_USED)
 */
class AttendanceQrSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private Group $groupe;

    private Etudiant $etudiant;

    private AttendanceQrSecurityService $qrService;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.attendance.active' => true]);

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences',
            'code' => 'FS',
            'description' => 'Sciences',
            'active' => true,
        ]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Agent Pointage',
            'email' => 'pointage.att@universite.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculte->id,
        ], 'admin_scolarite');

        $this->groupe = Group::create([
            'code' => 'INFO-L3',
            'nom' => 'Licence 3 Informatique',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L3',
            'capacite' => 40,
            'actif' => true,
        ]);

        $this->etudiant = Etudiant::create([
            'cin' => 'AB123456',
            'nom' => 'El Amrani',
            'prenom' => 'Hassan',
            'email' => 'hassan.elamrani@att.test',
            'telephone' => '+212611223344',
            'date_naissance' => '2003-05-12',
            'sexe' => 'M',
            'faculte_id' => $this->faculte->id,
            'groupe_id' => $this->groupe->id,
            'niveau' => 'L3',
            'annee_academique' => '2026-2027',
            'adresse' => 'Boulevard Mohammed V',
            'ville' => 'Rabat',
            'code_postal' => '10000',
            'annee_bac' => '2022',
            'password' => 'Etudiant@123',
        ]);
        $this->etudiant->definirStatut('valide');

        $this->qrService = app(AttendanceQrSecurityService::class);
    }

    public function test_token_valide_et_decodable(): void
    {
        $token = $this->qrService->genererToken(
            $this->etudiant->id,
            'SEANCE-MATIN',
            '2026-10-15',
            120
        );

        $resultat = $this->qrService->validerToken($token);

        $this->assertTrue($resultat['valide']);
        $this->assertNull($resultat['code_erreur']);
        $this->assertSame($this->etudiant->id, $resultat['payload']['sid']);
        $this->assertSame('SEANCE-MATIN', $resultat['payload']['ses']);
        $this->assertSame('2026-10-15', $resultat['payload']['d']);
    }

    public function test_absence_de_pii_dans_le_token(): void
    {
        $token = $this->qrService->genererToken(
            $this->etudiant->id,
            'SEANCE-TEST',
            '2026-10-15'
        );

        // Aucune donnée sensible ne doit figurer dans la chaîne du token
        $this->assertStringNotContainsString('AB123456', $token);
        $this->assertStringNotContainsString('El Amrani', $token);
        $this->assertStringNotContainsString('Hassan', $token);
        $this->assertStringNotContainsString('hassan.elamrani@att.test', $token);
        $this->assertStringNotContainsString('0611223344', $token);
    }

    public function test_rejet_token_falsifie(): void
    {
        $token = $this->qrService->genererToken(
            $this->etudiant->id,
            'SEANCE-MATIN',
            '2026-10-15'
        );

        // Altération de la signature
        $tokenFalsifie = $token.'MODIF';
        $resultat = $this->qrService->validerToken($tokenFalsifie);

        $this->assertFalse($resultat['valide']);
        $this->assertSame('SIGNATURE_FALSIFIEE', $resultat['code_erreur']);
    }

    public function test_rejet_token_expire(): void
    {
        // Token créé avec validité négative (-5 minutes)
        $tokenExpire = $this->qrService->genererToken(
            $this->etudiant->id,
            'SEANCE-MATIN',
            '2026-10-15',
            -5
        );

        $resultat = $this->qrService->validerToken($tokenExpire);

        $this->assertFalse($resultat['valide']);
        $this->assertSame('TOKEN_EXPIRE', $resultat['code_erreur']);
    }

    public function test_scan_qr_endpoint_enregistre_presence(): void
    {
        $token = $this->qrService->genererToken(
            $this->etudiant->id,
            'SEANCE-CM-ALGO',
            '2026-10-15'
        );

        $response = $this->actingAs($this->admin)->postJson(route('admin.presences.qr.scanner'), [
            'token' => $token,
        ]);

        $response->assertOk()
            ->assertJson([
                'succes' => true,
                'code' => 'PRESENCE_ENREGISTREE',
            ]);

        $this->assertDatabaseHas('presences', [
            'etudiant_id' => $this->etudiant->id,
            'seance' => 'SEANCE-CM-ALGO',
            'statut' => 'present',
        ]);

        $this->assertDatabaseHas('integration_logs', [
            'integration' => 'attendance',
            'operation' => 'ATTENDANCE_QR_USED',
            'status' => 'completed',
        ]);
    }

    public function test_idempotence_double_scan_qr(): void
    {
        $token = $this->qrService->genererToken(
            $this->etudiant->id,
            'SEANCE-IDEMPOTENT',
            '2026-10-15'
        );

        // Premier scan
        $resp1 = $this->actingAs($this->admin)->postJson(route('admin.presences.qr.scanner'), ['token' => $token]);
        $resp1->assertOk()->assertJson(['succes' => true, 'code' => 'PRESENCE_ENREGISTREE']);

        // Second scan identique
        $resp2 = $this->actingAs($this->admin)->postJson(route('admin.presences.qr.scanner'), ['token' => $token]);
        $resp2->assertOk()->assertJson(['succes' => true, 'code' => 'DEJA_PRESENT']);

        // Vérifier qu'un seul enregistrement existe dans la table presences
        $count = Presence::where('etudiant_id', $this->etudiant->id)
            ->whereDate('date', '2026-10-15')
            ->where('seance', 'SEANCE-IDEMPOTENT')
            ->count();

        $this->assertSame(1, $count);
    }

    public function test_rejet_etudiant_non_inscrit_au_groupe_du_creneau(): void
    {
        $autreGroupe = Group::create([
            'code' => 'INFO-L2',
            'nom' => 'L2 Info',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L2',
            'actif' => true,
        ]);

        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $sched = Schedule::create([
            'annee_universitaire_id' => $annee->id,
            'semestre' => 1,
            'groupe_id' => $autreGroupe->id,
            'nom' => 'Schedule L2',
            'actif' => true,
        ]);

        $matiere = Matiere::create([
            'code' => 'MATH101',
            'nom' => 'Maths',
            'niveau' => 'L2',
            'semestre' => 1,
        ]);

        $slotAutreGroupe = ScheduleSlot::create([
            'schedule_id' => $sched->id,
            'matiere_id' => $matiere->id,
            'day_of_week' => 'monday',
            'start_time' => '14:00:00',
            'end_time' => '16:00:00',
            'type' => 'course',
            'status' => 'scheduled',
        ]);

        // L'étudiant est en L3 (groupe INFO-L3), mais le créneau est assigné à L2 (INFO-L2)
        $token = $this->qrService->genererToken(
            $this->etudiant->id,
            $slotAutreGroupe->id,
            '2026-10-15'
        );

        $response = $this->actingAs($this->admin)->postJson(route('admin.presences.qr.scanner'), [
            'token' => $token,
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'succes' => false,
                'code' => 'ETUDIANT_NON_INSCRIT_GROUPE',
            ]);
    }
}
