<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\NoteEpreuve;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Services\EpreuveService;
use App\Modules\Evaluation\Services\SaisieNoteService;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Services\PopulationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Notes via population centrale (§14-16, §31) : saisie sur la population
 * convoquée (workflow verrouillage inchangé), exports sensibles scopés.
 * Le moteur de population ne contourne JAMAIS les règles de notes.
 */
class GradePopulationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Group $groupe;

    private Matiere $matiere;

    private Epreuve $epreuve;

    private Etudiant $etudiant;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.evaluation.active' => true]);

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.notes@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->groupe = Group::create([
            'code' => 'NT1', 'nom' => 'NT1', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'capacite' => 30, 'actif' => true,
        ]);
        $this->matiere = Matiere::create([
            'code' => 'NTM01', 'nom' => 'Notée', 'niveau' => 'L1', 'semestre' => 1, 'credits' => 4,
        ]);

        $session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'S-NT', 'nom' => 'Session', 'type' => 'principale',
            'date_debut' => '2026-06-01', 'date_fin' => '2026-06-30',
            'statut' => 'open', 'created_by' => $this->admin->id,
        ]);
        $examen = Examen::create([
            'session_id' => $session->id, 'annee_universitaire_id' => $this->annee->id,
            'libelle' => 'Examen', 'type' => 'examen', 'statut' => 'scheduled',
            'responsable_id' => $this->admin->id,
        ]);
        $salle = Room::create(['code' => 'B12', 'nom' => 'B12', 'capacite' => 40, 'actif' => true]);
        $this->epreuve = Epreuve::create([
            'examen_id' => $examen->id, 'session_id' => $session->id,
            'annee_universitaire_id' => $this->annee->id,
            'matiere_id' => $this->matiere->id, 'groupe_id' => $this->groupe->id,
            'salle_id' => $salle->id, 'date' => '2026-06-10',
            'heure_debut' => '08:00', 'heure_fin' => '10:00',
            'coefficient' => 1, 'bareme' => 20, 'note_max' => 20, 'statut' => 'scheduled',
        ]);

        $this->etudiant = $this->creerEtudiant('NTE00001');
        $this->actingAs($this->admin);
        app(EpreuveService::class)->genererCandidats($this->epreuve);
    }

    public function test_saisie_validee_verrouillee_workflow_intact(): void
    {
        $service = app(SaisieNoteService::class);

        $resultat = $service->saisirLot($this->epreuve, [
            ['etudiant_id' => $this->etudiant->id, 'valeur' => 14.5],
        ], $this->admin->id);
        $this->assertSame(1, $resultat['saisies']);

        // Non convoqué refusé (population convoquée fait foi).
        $autre = $this->creerEtudiant('NTE00002');
        $resultat = $service->saisirLot($this->epreuve, [
            ['etudiant_id' => $autre->id, 'valeur' => 12],
        ], $this->admin->id);
        $this->assertSame('Étudiant non convoqué à cette épreuve.', $resultat['erreurs'][0]);

        $service->valider(
            NoteEpreuve::where('epreuve_id', $this->epreuve->id)
                ->where('etudiant_id', $this->etudiant->id)->firstOrFail(),
            $this->admin->id
        );
        $service->verrouiller(
            NoteEpreuve::where('epreuve_id', $this->epreuve->id)
                ->where('etudiant_id', $this->etudiant->id)->firstOrFail(),
            $this->admin->id, 'fin de session'
        );

        $this->assertDatabaseHas('evaluation_notes', [
            'epreuve_id' => $this->epreuve->id, 'etudiant_id' => $this->etudiant->id, 'statut' => 'locked',
        ]);
        // Sync officielle effectuée.
        $this->assertDatabaseHas('notes', ['etudiant_id' => $this->etudiant->id]);
    }

    public function test_grade_list_dataset_population_matiere(): void
    {
        $ids = app(PopulationService::class)
            ->requete('grade_list', ['matiere_id' => $this->matiere->id], $this->admin)
            ->pluck('etudiants.id')->all();

        // Population pédagogique ECUE (notes lues séparément, jamais jointes).
        $this->assertEmpty($ids);
    }

    public function test_export_notes_epreuves_scope_et_permission(): void
    {
        $this->actingAs($this->admin)->get(route('admin.donnees.export', [
            'entite' => 'notes_epreuves', 'format' => 'csv',
        ]))->assertOk();

        $consultation = User::creerCompteBackOffice([
            'name' => 'Cons', 'email' => 'cons.notes@universite.test', 'password' => 'Motdepasse@123',
        ], 'consultation');

        $this->actingAs($consultation)->get(route('admin.donnees.export', [
            'entite' => 'notes_epreuves', 'format' => 'csv',
        ]))->assertForbidden();
    }

    private function creerEtudiant(string $cin): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin, 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => strtolower($cin).'@notes.test',
            'date_naissance' => '2004-01-01', 'sexe' => 'F', 'faculte_id' => $this->faculte->id,
            'groupe_id' => $this->groupe->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
        $etudiant->definirStatut('valide');
        $inscription = $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id, 'niveau' => 'L1', 'statut' => 'en_cours',
        ]);
        $inscription->semestres()->create(['numero' => 1]);
        $inscription->semestres()->create(['numero' => 2]);

        return $etudiant;
    }
}
