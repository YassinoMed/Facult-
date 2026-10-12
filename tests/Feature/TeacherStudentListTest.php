<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Enseignement\Models\AffectationEnseignement;
use App\Modules\Enseignement\Models\Enseignement;
use App\Modules\Enseignement\Models\TypeEnseignement;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Teacher;
use App\Services\PopulationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Enseignant → étudiants (§4, §31) : ECUE via affectations pédagogiques,
 * page enseignant, isolation stricte (aucune découverte hors périmètre).
 */
class TeacherStudentListTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Teacher $enseignant;

    private Matiere $matiere;

    private SemestreLmd $semestre;

    private UniteEnseignement $ue;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.teach@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);

        $programme = Programme::create([
            'faculte_id' => $this->faculte->id, 'code' => 'LGL', 'nom' => 'Licence GL',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $niveau = Niveau::create(['programme_id' => $programme->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1]);
        $this->semestre = SemestreLmd::create(['niveau_id' => $niveau->id, 'code' => 'S1', 'nom' => 'S1', 'numero' => 1, 'ordre' => 1]);
        $this->ue = UniteEnseignement::create([
            'semestre_id' => $this->semestre->id, 'programme_id' => $programme->id,
            'code' => 'UE01', 'nom' => 'Bases', 'credits' => 6, 'coefficient' => 2,
        ]);
        $this->matiere = Matiere::create([
            'code' => 'TEA01', 'nom' => 'Enseignée', 'ue_id' => $this->ue->id,
            'niveau' => 'L1', 'semestre' => 1, 'credits' => 4,
        ]);

        $this->enseignant = Teacher::create([
            'nom' => 'Benali', 'prenom' => 'Sara', 'email' => 'sara.teach@universite.test',
            'matricule' => 'ENS-T', 'actif' => true, 'statut' => 'actif',
        ]);

        $type = TypeEnseignement::create(['code' => 'TD', 'nom' => 'Travaux dirigés']);
        $enseignement = Enseignement::create([
            'matiere_id' => $this->matiere->id, 'type_id' => $type->id,
            'annee_universitaire_id' => $this->annee->id,
            'semestre_id' => $this->semestre->id, 'programme_id' => $programme->id,
            'volume_td' => 12, 'statut' => 'draft', 'cree_par' => $this->admin->id,
        ]);
        AffectationEnseignement::create([
            'enseignement_id' => $enseignement->id, 'teacher_id' => $this->enseignant->id,
            'role' => 'enseignant', 'statut' => 'active',
        ]);
    }

    public function test_enseignant_vers_etudiants_via_dataset(): void
    {
        $cible = $this->creerEtudiant('TEA00001', true);
        $this->creerEtudiant('TEA00002', false);

        $ids = app(PopulationService::class)
            ->requete('students_by_teacher', ['enseignant_id' => $this->enseignant->id], $this->admin)
            ->pluck('etudiants.id')->all();

        $this->assertSame([$cible->id], $ids);
    }

    public function test_page_enseignant_propose_population(): void
    {
        $this->actingAs($this->admin)->get(route('admin.enseignants.show', $this->enseignant))
            ->assertOk()
            ->assertSee(route('admin.listes.preview', ['enseignant_id' => $this->enseignant->id]), false);
    }

    public function test_isolation_enseignant_autre_faculte(): void
    {
        $autreFaculte = Faculte::create(['nom' => 'FMP', 'code' => 'FMP', 'description' => 'T', 'active' => true]);
        $adminFaculte = User::creerCompteBackOffice([
            'name' => 'Fac', 'email' => 'fac.teach@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $autreFaculte->id,
        ], 'admin_faculte');

        // Manipulation enseignant_id hors périmètre → population vide.
        $this->actingAs($adminFaculte)->get(route('admin.listes.preview', [
            'enseignant_id' => $this->enseignant->id,
        ]))->assertOk()->assertSee('0 résultat(s)', false);
    }

    private function creerEtudiant(string $cin, bool $inscrit = false): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin, 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => strtolower($cin).'@teach.test',
            'date_naissance' => '2004-01-01', 'sexe' => 'F', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
        $etudiant->definirStatut('valide');
        $inscription = $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id, 'niveau' => 'L1', 'statut' => 'en_cours',
        ]);

        if ($inscrit) {
            $inscription->pedagogiques()->create([
                'semestre_id' => $this->semestre->id, 'ue_id' => $this->ue->id,
                'matiere_id' => $this->matiere->id, 'statut' => 'inscrit',
            ]);
        }

        return $etudiant;
    }
}
