<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Schedule\Models\Group;
use App\Services\PopulationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Sécurité datasets (§27, §31) : IDOR inter-facultés/programmes/groupes,
 * exports notes/présences non autorisés, colonnes sensibles.
 */
class DatasetSecurityTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Group $groupe;

    private Matiere $matiere;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.attendance.active' => true]);

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->groupe = Group::create([
            'code' => 'SEC1', 'nom' => 'SEC1', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'capacite' => 30, 'actif' => true,
        ]);
        $this->matiere = Matiere::create([
            'code' => 'SEC01', 'nom' => 'Sec', 'niveau' => 'L1', 'semestre' => 1, 'credits' => 4,
        ]);
        $this->creerEtudiant('SEC00001');
    }

    private function adminFaculte(Faculte $faculte): User
    {
        static $compteur = 0;

        return User::creerCompteBackOffice([
            'name' => 'Fac', 'email' => 'fac.'.strtolower($faculte->code).'.'.(++$compteur).'.sec@universite.test',
            'password' => 'Motdepasse@123', 'faculte_id' => $faculte->id,
        ], 'admin_faculte');
    }

    public function test_cross_faculty_dataset_vide(): void
    {
        $autre = Faculte::create(['nom' => 'FMP', 'code' => 'FMP', 'description' => 'T', 'active' => true]);

        foreach (['students', 'students_by_group', 'students_by_ecue', 'exam_candidates', 'attendance_list', 'grade_list'] as $dataset) {
            $ids = app(PopulationService::class)->requete($dataset, [
                'groupe_id' => $this->groupe->id, 'matiere_id' => $this->matiere->id,
            ], $this->adminFaculte($autre))->pluck('etudiants.id')->all();

            $this->assertSame([], $ids, "Fuite cross-faculté via {$dataset}.");
        }
    }

    public function test_cross_group_manipulation_vide(): void
    {
        $autreFaculte = Faculte::create(['nom' => 'FMP', 'code' => 'FMP', 'description' => 'T', 'active' => true]);
        $adminFaculte = $this->adminFaculte($autreFaculte);

        $this->actingAs($adminFaculte)->get(route('admin.presences.index'))->assertOk();
        $this->actingAs($adminFaculte)->get(route('admin.listes.exporter', [
            'format' => 'csv', 'groupe_id' => $this->groupe->id,
        ]))->assertSessionHasErrors('export');
    }

    public function test_unauthorized_grades_attendance_export(): void
    {
        $consultation = User::creerCompteBackOffice([
            'name' => 'Cons', 'email' => 'cons.sec@universite.test', 'password' => 'Motdepasse@123',
        ], 'consultation');

        $this->actingAs($consultation)->get(route('admin.donnees.export', [
            'entite' => 'notes', 'format' => 'csv',
        ]))->assertForbidden();

        $this->actingAs($consultation)->get(route('admin.donnees.export', [
            'entite' => 'presences', 'format' => 'csv',
        ]))->assertForbidden();
    }

    public function test_dataset_inconnu_404(): void
    {
        $admin = User::creerCompteBackOffice([
            'name' => 'S', 'email' => 's.sec@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->expectException(HttpException::class);
        app(PopulationService::class)->requete('etudiants_par_humeur', [], $admin);
    }

    private function creerEtudiant(string $cin): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin, 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => strtolower($cin).'@sec.test',
            'date_naissance' => '2004-01-01', 'sexe' => 'F', 'faculte_id' => $this->faculte->id,
            'groupe_id' => $this->groupe->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
        $etudiant->definirStatut('valide');
        $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id, 'niveau' => 'L1', 'statut' => 'en_cours',
        ]);

        return $etudiant;
    }
}
