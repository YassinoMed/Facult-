<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\StudentArchive\Models\StudentSensitiveRecord;
use App\Modules\StudentArchive\Policies\SensitiveRecordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Policy dossiers sensibles (§15-17) : type→permission + scope faculté.
 * Archive ≠ Sensitive ≠ Medical ≠ Disciplinary.
 */
class SensitiveRecordPolicyTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private Etudiant $etudiant;

    private StudentSensitiveRecord $medical;

    private StudentSensitiveRecord $disciplinaire;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->etudiant = Etudiant::create([
            'cin' => 'SEN00001', 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => 'sen@sen.test', 'date_naissance' => '2004-01-01', 'sexe' => 'F',
            'faculte_id' => $this->faculte->id, 'niveau' => 'L1', 'annee_academique' => '2026-2027',
            'adresse' => 'X', 'ville' => 'Casa', 'code_postal' => '20000', 'annee_bac' => '2024',
            'password' => 'Etudiant@123',
        ]);
        $this->etudiant->definirStatut('valide');
        $this->etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $annee->id, 'niveau' => 'L1', 'statut' => 'en_cours',
        ]);

        $this->medical = StudentSensitiveRecord::create([
            'etudiant_id' => $this->etudiant->id, 'type' => 'medical',
            'titre' => 'Aménagement session', 'statut' => 'ouvert',
        ]);
        $this->disciplinaire = StudentSensitiveRecord::create([
            'etudiant_id' => $this->etudiant->id, 'type' => 'disciplinaire',
            'titre' => 'Incident bibliothèque', 'statut' => 'en_cours',
        ]);
    }

    private function compte(string $role, ?int $faculteId = null): User
    {
        static $compteur = 0;

        return User::creerCompteBackOffice([
            'name' => $role, 'email' => $role.'.'.(++$compteur).'.sen@universite.test',
            'password' => 'Motdepasse@123', 'faculte_id' => $faculteId,
        ], $role);
    }

    public function test_admin_scolarite_tout_types(): void
    {
        $policy = app(SensitiveRecordPolicy::class);
        $admin = $this->compte('admin_scolarite');

        $this->assertTrue($policy->voir($admin, $this->medical));
        $this->assertTrue($policy->voir($admin, $this->disciplinaire));
        $this->assertTrue($policy->gerer($admin, $this->medical));
    }

    public function test_archive_ne_ouvre_pas_sensible(): void
    {
        $policy = app(SensitiveRecordPolicy::class);

        foreach (['agent_scolarite', 'consultation'] as $role) {
            $user = $this->compte($role);

            $this->assertTrue($user->can('student_archive.view'));
            $this->assertFalse($policy->voir($user, $this->medical));
            $this->assertFalse($policy->voir($user, $this->disciplinaire));
            $this->assertFalse($policy->gerer($user, $this->medical));
        }
    }

    public function test_faculte_meme_perimetre_sans_permission(): void
    {
        $policy = app(SensitiveRecordPolicy::class);
        $adminFaculte = $this->compte('admin_faculte', $this->faculte->id);

        $this->assertTrue($adminFaculte->can('student_archive.view'));
        $this->assertFalse($policy->voir($adminFaculte, $this->medical));
    }

    public function test_hors_faculte_toujours_refuse(): void
    {
        $policy = app(SensitiveRecordPolicy::class);
        $autre = Faculte::create(['nom' => 'FMP', 'code' => 'FMP', 'description' => 'T', 'active' => true]);
        $admin = $this->compte('admin_scolarite');
        $adminAutreFaculte = $this->compte('admin_faculte', $autre->id);

        // admin_scolarite : périmètre global → OK.
        $this->assertTrue($policy->voir($admin, $this->medical));
        // admin_faculte autre faculté → refusé malgré d'éventuelles permissions.
        $this->assertFalse($policy->voir($adminAutreFaculte, $this->medical));
    }

    public function test_telechargement_exige_piece_et_permission(): void
    {
        $policy = app(SensitiveRecordPolicy::class);
        $admin = $this->compte('admin_scolarite');

        // Sans pièce liée → jamais téléchargeable.
        $this->assertFalse($policy->telecharger($admin, $this->medical));
    }
}
