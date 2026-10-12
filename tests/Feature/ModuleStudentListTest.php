<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Enseignement\Models\Enseignement;
use App\Modules\Enseignement\Models\TypeEnseignement;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Services\PopulationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ECUE/UE → étudiants (§3, §31) : population via dataset + liens pages,
 * relations pédagogiques réutilisées, aucune query ad hoc.
 */
class ModuleStudentListTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Matiere $matiere;

    private UniteEnseignement $ue;

    private SemestreLmd $semestre;

    private TypeEnseignement $typeTd;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.referentiel.active' => true]);

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.mod@universite.test', 'password' => 'Motdepasse@123',
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
            'code' => 'MOD01', 'nom' => 'Module', 'ue_id' => $this->ue->id,
            'niveau' => 'L1', 'semestre' => 1, 'credits' => 4,
        ]);
        $this->typeTd = TypeEnseignement::create(['code' => 'TD', 'nom' => 'Travaux dirigés']);
    }

    public function test_ecue_vers_etudiants_via_dataset(): void
    {
        $cible = $this->creerEtudiant('MOD00001', $this->matiere->id);
        $this->creerEtudiant('MOD00002', null);

        $ids = app(PopulationService::class)
            ->requete('students_by_ecue', ['matiere_id' => $this->matiere->id], $this->admin)
            ->pluck('etudiants.id')->all();

        $this->assertSame([$cible->id], $ids);
    }

    public function test_ue_vers_etudiants_via_dataset(): void
    {
        $this->creerEtudiant('MOD00003', $this->matiere->id);

        $ids = app(PopulationService::class)
            ->requete('students_by_ue', ['ue_id' => $this->ue->id], $this->admin)
            ->pluck('etudiants.id')->all();

        $this->assertNotEmpty($ids);
    }

    public function test_page_ecue_propose_population(): void
    {
        $reponse = $this->actingAs($this->admin)->get(route('admin.referentiel.ecues.show', $this->matiere));

        $reponse->assertOk();
        $reponse->assertSee(route('admin.listes.preview', ['matiere_id' => $this->matiere->id]), false);
    }

    public function test_page_enseignement_propose_population(): void
    {
        config(['modules.enseignement.active' => true]);

        $enseignement = Enseignement::create([
            'matiere_id' => $this->matiere->id, 'type_id' => $this->typeTd->id,
            'annee_universitaire_id' => $this->annee->id,
            'semestre_id' => $this->semestre->id, 'programme_id' => $this->ue->programme_id,
            'volume_td' => 12, 'statut' => 'draft', 'cree_par' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)->get(route('admin.enseignements.show', $enseignement))
            ->assertOk()
            ->assertSee(route('admin.listes.preview', ['matiere_id' => $this->matiere->id]), false);
    }

    private function creerEtudiant(string $cin, ?int $matiereId): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin, 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => strtolower($cin).'@mod.test',
            'date_naissance' => '2004-01-01', 'sexe' => 'F', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
        $etudiant->definirStatut('valide');
        $inscription = $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id, 'niveau' => 'L1', 'statut' => 'en_cours',
        ]);

        if ($matiereId !== null) {
            $inscription->pedagogiques()->create([
                'semestre_id' => $this->semestre->id, 'ue_id' => $this->ue->id,
                'matiere_id' => $matiereId, 'statut' => 'inscrit',
            ]);
        }

        return $etudiant;
    }
}
