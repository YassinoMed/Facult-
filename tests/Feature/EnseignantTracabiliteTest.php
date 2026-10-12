<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\HistoriqueAcademique;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Referentiel\Models\Departement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Phase E — Traçabilité : CRUD tracé sans PII dans le journal existant,
 * centre d'audit scopé, imports déjà tracés par le framework.
 */
class EnseignantTracabiliteTest extends TestCase
{
    use RefreshDatabase;

    private User $scolarite;

    private User $adminFaculteA;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private Departement $departementA;

    private Departement $departementB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculteA = Faculte::create(['nom' => 'FA', 'code' => 'FA', 'description' => 'T', 'active' => true]);
        $this->faculteB = Faculte::create(['nom' => 'FB', 'code' => 'FB', 'description' => 'T', 'active' => true]);
        $this->departementA = Departement::create([
            'code' => 'DEP-A', 'nom' => 'Département A', 'faculte_id' => $this->faculteA->id,
        ]);
        $this->departementB = Departement::create([
            'code' => 'DEP-B', 'nom' => 'Département B', 'faculte_id' => $this->faculteB->id,
        ]);
        $this->scolarite = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.tra@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->adminFaculteA = User::creerCompteBackOffice([
            'name' => 'Admin FA', 'email' => 'admin.fa.tra@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteA->id,
        ], 'admin_faculte');
    }

    private function sansPii(HistoriqueAcademique $entree, Teacher $enseignant): void
    {
        $texte = json_encode($entree->toArray(), JSON_UNESCAPED_UNICODE);
        $this->assertNotFalse($texte);
        foreach ([$enseignant->nom, $enseignant->prenom, $enseignant->email] as $pii) {
            $this->assertStringNotContainsString((string) $pii, (string) $texte);
        }
        $this->assertNull($entree->etudiant_id);
    }

    public function test_creation_tracee_sans_pii(): void
    {
        $this->actingAs($this->scolarite)->post(route('admin.enseignants.store'), [
            'nom' => 'Trace', 'prenom' => 'Tp', 'email' => 'trace@universite.test',
            'departement_id' => $this->departementA->id,
        ])->assertRedirect();

        $enseignant = Teacher::where('email', 'trace@universite.test')->firstOrFail();
        $entree = HistoriqueAcademique::where('entite', 'enseignant')
            ->where('entite_id', $enseignant->id)->firstOrFail();

        $this->assertSame(HistoriqueAcademique::ACTION_ENSEIGNANT_CREATION, $entree->action);
        $this->assertSame($this->scolarite->id, (int) $entree->utilisateur_id);
        $nouvelle = json_decode((string) $entree->nouvelle_valeur, true);
        $this->assertSame(['statut', 'actif', 'departement_id', 'grade_id'], array_keys($nouvelle));
        $this->sansPii($entree, $enseignant);
    }

    public function test_modification_tracee_avant_apres(): void
    {
        $enseignant = Teacher::create([
            'nom' => 'Avant', 'prenom' => 'A', 'email' => 'avant@universite.test',
            'departement_id' => $this->departementA->id, 'actif' => true, 'statut' => 'actif',
        ]);

        $this->actingAs($this->scolarite)->put(route('admin.enseignants.update', $enseignant), [
            'nom' => 'Avant', 'prenom' => 'A', 'statut' => 'suspendu',
        ])->assertRedirect();

        $entree = HistoriqueAcademique::where('entite', 'enseignant')
            ->where('entite_id', $enseignant->id)->firstOrFail();

        $this->assertSame(HistoriqueAcademique::ACTION_ENSEIGNANT_MODIFICATION, $entree->action);
        $ancienne = json_decode((string) $entree->ancienne_valeur, true);
        $nouvelle = json_decode((string) $entree->nouvelle_valeur, true);
        $this->assertSame('actif', $ancienne['statut']);
        $this->assertSame('suspendu', $nouvelle['statut']);
        $this->assertFalse((bool) $nouvelle['actif']);
        $this->sansPii($entree, $enseignant->fresh());
    }

    public function test_suppression_tracee_et_refus_non_trace(): void
    {
        $enseignant = Teacher::create([
            'nom' => 'Suppr', 'prenom' => 'S', 'email' => 'suppr@universite.test',
        ]);

        $this->actingAs($this->scolarite)->delete(route('admin.enseignants.destroy', $enseignant))
            ->assertRedirect();
        $entree = HistoriqueAcademique::where('entite', 'enseignant')
            ->where('entite_id', $enseignant->id)->firstOrFail();
        $this->assertSame(HistoriqueAcademique::ACTION_ENSEIGNANT_SUPPRESSION, $entree->action);

        // Refus de suppression (garde Phase A) : aucune trace (pas de mutation).
        $garde = Teacher::create(['nom' => 'Garde', 'prenom' => 'G']);
        $garde->slots()->create([
            'schedule_id' => $this->creerPlanning()->id,
            'matiere_id' => $this->creerMatiere()->id,
            'day_of_week' => 'monday', 'start_time' => '08:00:00', 'end_time' => '10:00:00',
        ]);
        $this->actingAs($this->scolarite)->delete(route('admin.enseignants.destroy', $garde))
            ->assertSessionHasErrors('enseignant');
        $this->assertSame(
            0,
            HistoriqueAcademique::where('entite', 'enseignant')->where('entite_id', $garde->id)->count()
        );
    }

    public function test_centre_audit_scope_au_perimetre(): void
    {
        $enseignantA = Teacher::create([
            'nom' => 'NomA', 'prenom' => 'A', 'departement_id' => $this->departementA->id,
        ]);
        $enseignantB = Teacher::create([
            'nom' => 'NomB', 'prenom' => 'B', 'departement_id' => $this->departementB->id,
        ]);
        $this->actingAs($this->scolarite)->put(route('admin.enseignants.update', $enseignantA), [
            'nom' => 'NomA', 'prenom' => 'A', 'bureau' => 'B1',
        ]);
        $this->actingAs($this->scolarite)->put(route('admin.enseignants.update', $enseignantB), [
            'nom' => 'NomB', 'prenom' => 'B', 'bureau' => 'B2',
        ]);

        // Rôle global : les deux événements visibles avec l'entité.
        $global = $this->actingAs($this->scolarite)->get(route('admin.audit.index'));
        $global->assertOk()
            ->assertSee('enseignant #'.$enseignantA->id, false)
            ->assertSee('enseignant #'.$enseignantB->id, false);

        // Faculté A : son périmètre seul (transversaux + FA).
        $restreint = $this->actingAs($this->adminFaculteA)->get(route('admin.audit.index'));
        $restreint->assertOk()
            ->assertSee('enseignant #'.$enseignantA->id, false)
            ->assertDontSee('enseignant #'.$enseignantB->id, false);
    }

    public function test_import_trace_par_le_framework(): void
    {
        $fichier = UploadedFile::fake()->createWithContent(
            'enseignants.csv',
            "nom;prenom;email;statut\nTrace;Import;imp.tra@universite.test;ACTIF\n",
            'text/csv'
        );

        $preview = $this->actingAs($this->scolarite)
            ->post('/admin/donnees/enseignants/import/preview', ['fichier' => $fichier])
            ->assertOk();
        $this->assertSame(0, Teacher::where('email', 'imp.tra@universite.test')->count());

        $this->actingAs($this->scolarite)->post('/admin/donnees/enseignants/import/confirmer', [
            'token' => $preview->viewData('token'), 'mode' => 'creer',
        ])->assertOk();

        $enseignant = Teacher::where('email', 'imp.tra@universite.test')->firstOrFail();
        $this->assertSame('actif', $enseignant->statut);
        $this->assertDatabaseHas('import_reports', [
            'type' => 'data.enseignants', 'status' => 'completed', 'valid_rows' => 1,
        ]);
        $this->assertDatabaseHas('integration_logs', [
            'integration' => 'data-exchange', 'operation' => 'data.import.enseignants', 'status' => 'completed',
        ]);
    }

    private function creerMatiere(): Matiere
    {
        return Matiere::create([
            'code' => 'TRA101', 'nom' => 'Traçabilité', 'niveau' => 'L1', 'semestre' => 1,
        ]);
    }

    private function creerPlanning(): Schedule
    {
        $groupe = Group::create([
            'code' => 'G-TRA', 'nom' => 'Groupe', 'faculte_id' => $this->faculteA->id,
            'niveau' => 'L1', 'actif' => true,
        ]);
        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);

        return Schedule::create([
            'annee_universitaire_id' => $annee->id, 'semestre' => 1,
            'groupe_id' => $groupe->id, 'nom' => 'Planning',
        ]);
    }
}
