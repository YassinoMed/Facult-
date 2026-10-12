<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\GradeEnseignant;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Referentiel\Models\Departement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use App\Services\PreferencesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase D — Interface (recherche matricule, filtres combinés, pagination
 * perso), fiche sans N+1 (requêtes bornées), code mort supprimé.
 */
class EnseignantInterfaceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private Departement $departement;

    private GradeEnseignant $grade;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.int@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->departement = Departement::create([
            'code' => 'DEP-I', 'nom' => 'Département', 'faculte_id' => $this->faculte->id,
        ]);
        $this->grade = GradeEnseignant::create(['code' => 'MA', 'nom' => 'Maître-assistant', 'ordre' => 3, 'actif' => true]);
    }

    private function creerEnseignant(string $suffixe, array $surcharge = []): Teacher
    {
        return Teacher::create(array_merge([
            'nom' => 'Nom'.$suffixe, 'prenom' => 'Prenom'.$suffixe,
            'email' => "int{$suffixe}@universite.test", 'matricule' => 'MAT-'.$suffixe,
            'actif' => true, 'statut' => 'actif',
        ], $surcharge));
    }

    private function compterRequetes(callable $appel): int
    {
        $compte = 0;
        DB::listen(function () use (&$compte): void {
            $compte++;
        });
        $appel();
        DB::flushQueryLog();

        return $compte;
    }

    public function test_recherche_par_matricule(): void
    {
        $this->creerEnseignant('X');
        $this->creerEnseignant('Y');

        $reponse = $this->actingAs($this->admin)
            ->get(route('admin.enseignants.index', ['search' => 'MAT-X']));

        $reponse->assertOk()->assertSee('NomX')->assertDontSee('NomY');
    }

    public function test_filtres_combines_et_pagination_conservee(): void
    {
        $this->creerEnseignant('A', ['departement_id' => $this->departement->id, 'grade_id' => $this->grade->id]);
        $this->creerEnseignant('B', ['departement_id' => $this->departement->id, 'statut' => 'suspendu', 'actif' => false]);
        $this->creerEnseignant('C');

        $reponse = $this->actingAs($this->admin)->get(route('admin.enseignants.index', [
            'departement_id' => $this->departement->id, 'statut' => 'actif',
        ]));

        $reponse->assertOk()->assertSee('NomA')->assertDontSee('NomB')->assertDontSee('NomC');

        // Filtre grade seul.
        $this->actingAs($this->admin)->get(route('admin.enseignants.index', ['grade_id' => $this->grade->id]))
            ->assertOk()->assertSee('NomA')->assertDontSee('NomC');

        // Paramètres préservés dans la pagination (2 pages de 5).
        app(PreferencesService::class)->ecrire((int) $this->admin->id, ['lignes_par_page' => 5]);
        for ($i = 1; $i <= 6; $i++) {
            $this->creerEnseignant('M'.$i, ['departement_id' => $this->departement->id]);
        }
        $pagee = $this->actingAs($this->admin)->get(route('admin.enseignants.index', [
            'departement_id' => $this->departement->id,
        ]));
        $pagee->assertOk();
        $this->assertStringContainsString(
            'departement_id='.((string) $this->departement->id), $pagee->getContent()
        );
    }

    public function test_pagination_suit_preference_utilisateur(): void
    {
        app(PreferencesService::class)->ecrire((int) $this->admin->id, ['lignes_par_page' => 5]);

        for ($i = 1; $i <= 7; $i++) {
            $this->creerEnseignant('P'.$i);
        }

        $reponse = $this->actingAs($this->admin)->get(route('admin.enseignants.index'));

        $reponse->assertOk();
        $this->assertSame(5, $this->compterLignesTableau($reponse->getContent()));
    }

    private function compterLignesTableau(string $html): int
    {
        return substr_count($html, '<tr class="hover:bg-slate-50/60">');
    }

    public function test_fiche_requetes_bornees_sans_n_plus_un(): void
    {
        $enseignant = $this->creerEnseignant('F', [
            'departement_id' => $this->departement->id, 'grade_id' => $this->grade->id,
        ]);

        $matiere = Matiere::create([
            'code' => 'INT101', 'nom' => 'Interface', 'niveau' => 'L1', 'semestre' => 1,
        ]);
        $groupe = Group::create([
            'code' => 'G-INT', 'nom' => 'Groupe', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'actif' => true,
        ]);
        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $planning = Schedule::create([
            'annee_universitaire_id' => $annee->id, 'semestre' => 1,
            'groupe_id' => $groupe->id, 'nom' => 'Planning',
        ]);
        foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday'] as $jour) {
            ScheduleSlot::create([
                'schedule_id' => $planning->id, 'matiere_id' => $matiere->id,
                'teacher_id' => $enseignant->id, 'day_of_week' => $jour,
                'start_time' => '08:00:00', 'end_time' => '10:00:00',
            ]);
        }

        $requetes = [];
        DB::listen(function ($requete) use (&$requetes): void {
            $requetes[] = $requete->sql;
        });
        $this->actingAs($this->admin)
            ->get(route('admin.enseignants.show', $enseignant))->assertOk();
        DB::flushQueryLog();

        $accesMatiereUnitaire = array_filter(
            $requetes,
            fn ($sql) => str_contains($sql, 'matieres') && str_contains($sql, '?')
        );

        // Aucune requête matiere à l'unité (N+1), volume total borné.
        $this->assertCount(0, $accesMatiereUnitaire);
        $this->assertLessThanOrEqual(15, count($requetes), count($requetes).' requêtes sur la fiche.');
    }

    public function test_controleurs_morts_supprimes(): void
    {
        $this->assertFalse(class_exists('App\Http\Controllers\Enseignant\SurveillanceController'));
        $this->assertFalse(class_exists('App\Http\Controllers\Enseignant\PresenceController'));
        $this->assertFalse(class_exists('App\Http\Controllers\Enseignant\EmploiDuTempsController'));
    }
}
