<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\GradeEnseignant;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Enseignement\Models\ComposanteEvaluation;
use App\Modules\Enseignement\Models\Enseignement;
use App\Modules\Enseignement\Models\SeuilService;
use App\Modules\Enseignement\Models\TypeEnseignement;
use App\Modules\Enseignement\Services\ServiceEnseignantService;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Phase 3 — Enseignement (§55) : volumes, affectations, workflow,
 * seuils, évaluation, qualité, import/export, sécurité.
 */
class EnseignementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Teacher $enseignant;

    private Matiere $ecue;

    private TypeEnseignement $typeTd;

    private Group $groupe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create([
            'nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true,
        ]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.ens@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->typeTd = TypeEnseignement::create(['code' => 'TD', 'nom' => 'Travaux dirigés']);

        $programme = Programme::create([
            'faculte_id' => $this->faculte->id, 'code' => 'LGL', 'nom' => 'Licence GL',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $niveau = Niveau::create(['programme_id' => $programme->id, 'code' => 'L2', 'nom' => 'L2', 'ordre' => 2]);
        $semestre = SemestreLmd::create(['niveau_id' => $niveau->id, 'code' => 'S3', 'nom' => 'S3', 'numero' => 3, 'ordre' => 3]);
        $ue = UniteEnseignement::create([
            'semestre_id' => $semestre->id, 'programme_id' => $programme->id,
            'code' => 'UE01', 'nom' => 'Prog', 'credits' => 6, 'coefficient' => 2,
        ]);
        $this->ecue = Matiere::create([
            'code' => 'J101', 'nom' => 'Java', 'ue_id' => $ue->id,
            'niveau' => 'L2', 'semestre' => 1, 'credits' => 6,
        ]);
        $this->enseignant = Teacher::create([
            'nom' => 'Benali', 'prenom' => 'Sara', 'email' => 'sara.ens@universite.test',
            'matricule' => 'ENS-1', 'actif' => true, 'statut' => 'actif',
        ]);
        $this->groupe = Group::create([
            'code' => 'GL2-A', 'nom' => 'GL2 A', 'faculte_id' => $this->faculte->id,
            'programme_id' => $programme->id, 'niveau_id' => $niveau->id,
            'annee_universitaire_id' => $this->annee->id,
            'niveau' => 'L2', 'capacite' => 30, 'actif' => true,
        ]);
    }

    private function creer(): Enseignement
    {
        $ens = Enseignement::create([
            'matiere_id' => $this->ecue->id, 'type_id' => $this->typeTd->id,
            'annee_universitaire_id' => $this->annee->id,
            'semestre_id' => $this->ecue->ue->semestre_id, 'programme_id' => $this->ecue->ue->programme_id,
            'volume_td' => 12, 'volume_tp' => 6, 'statut' => 'draft', 'cree_par' => $this->admin->id,
        ]);

        return $ens;
    }

    // ── Enseignements ─────────────────────────────────────────────

    public function test_creation_volumes_et_total(): void
    {
        $reponse = $this->actingAs($this->admin)->post(route('admin.enseignements.store'), [
            'matiere_id' => $this->ecue->id, 'type_id' => $this->typeTd->id,
            'annee_universitaire_id' => $this->annee->id,
            'volume_cm' => 18, 'volume_td' => 12, 'volume_tp' => 6,
        ])->assertRedirect();

        $ens = Enseignement::first();
        $this->assertNotNull($ens);
        $this->assertSame(36, $ens->volumeTotal());
        $this->assertSame('draft', $ens->statut);
        $this->assertNotNull($reponse);
    }

    public function test_doublon_refuse_unique(): void
    {
        $this->creer();

        $this->actingAs($this->admin)->post(route('admin.enseignements.store'), [
            'matiere_id' => $this->ecue->id, 'type_id' => $this->typeTd->id,
            'annee_universitaire_id' => $this->annee->id,
        ])->assertSessionHasErrors('enseignement');
        $this->assertSame(1, Enseignement::count());
    }

    public function test_workflow_controle(): void
    {
        $ens = $this->creer();

        foreach (['planned', 'validated', 'active'] as $cible) {
            $this->actingAs($this->admin)->patch(
                route('admin.enseignements.transition', $ens), ['statut' => $cible]
            )->assertRedirect();
            $this->assertSame($cible, $ens->fresh()->statut);
        }

        // Interdit : active → draft.
        $this->actingAs($this->admin)->patch(
            route('admin.enseignements.transition', $ens), ['statut' => 'draft']
        )->assertRedirect();
        $this->assertSame('active', $ens->fresh()->statut);
    }

    public function test_verrouillage_active_motif_requis(): void
    {
        $ens = $this->creer();
        foreach (['planned', 'validated', 'active'] as $cible) {
            $ens->update(['statut' => $cible]);
        }

        $this->actingAs($this->admin)->patch(route('admin.enseignements.update', $ens), [
            'volume_td' => 20,
        ])->assertSessionHasErrors('enseignement');
        $this->assertSame(12, $ens->fresh()->volume_td);

        // Avec motif : accepté.
        $this->actingAs($this->admin)->patch(route('admin.enseignements.update', $ens), [
            'volume_td' => 20, 'motif' => 'ajustement ministère',
        ])->assertRedirect();
        $this->assertSame(20, $ens->fresh()->volume_td);
    }

    // ── Affectations ──────────────────────────────────────────────

    public function test_affectation_ensemble_avant_transition(): void
    {
        $ens = $this->creer();

        $this->actingAs($this->admin)->post(route('admin.enseignements.affectations.store', $ens), [
            'teacher_id' => $this->enseignant->id, 'role' => 'enseignant', 'volume_heures' => 12,
        ])->assertRedirect();

        $this->actingAs($this->admin)->post(route('admin.enseignements.groupes.store', $ens), [
            'groupe_id' => $this->groupe->id,
        ])->assertRedirect();

        $this->assertSame(1, $ens->fresh()->affectations()->count());
        $this->assertSame(1, $ens->fresh()->groupes()->count());

        // Anti-doublon : refusé.
        $this->actingAs($this->admin)->post(route('admin.enseignements.affectations.store', $ens), [
            'teacher_id' => $this->enseignant->id, 'role' => 'enseignant', 'volume_heures' => 5,
        ])->assertSessionHasErrors('affectation');
    }

    public function test_enseignant_inactif_refuse(): void
    {
        $ens = $this->creer();
        $this->enseignant->update(['actif' => false]);

        $this->actingAs($this->admin)->post(route('admin.enseignements.affectations.store', $ens), [
            'teacher_id' => $this->enseignant->id, 'role' => 'enseignant', 'volume_heures' => 12,
        ])->assertSessionHasErrors('affectation');
    }

    public function test_groupe_incompatible_refuse(): void
    {
        $faculteB = Faculte::create(['nom' => 'FB', 'code' => 'FB', 'description' => 'B', 'active' => true]);
        $groupeB = Group::create([
            'code' => 'GL9', 'nom' => 'G9', 'faculte_id' => $faculteB->id,
            'programme_id' => Programme::create([
                'faculte_id' => $faculteB->id, 'code' => 'MI', 'nom' => 'MI',
                'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
            ])->id,
            'niveau' => 'L1', 'actif' => true,
        ]);

        $ens = $this->creer();

        $this->actingAs($this->admin)->post(route('admin.enseignements.groupes.store', $ens), [
            'groupe_id' => $groupeB->id,
        ])->assertSessionHasErrors('groupe');
        $this->assertSame(0, $ens->fresh()->groupes()->count());
    }

    // ── Services / seuils ─────────────────────────────────────────

    public function test_charge_et_seuils(): void
    {
        $typeCm = TypeEnseignement::create(['code' => 'CM', 'nom' => 'CM']);
        $ens1 = Enseignement::create([
            'matiere_id' => $this->ecue->id, 'type_id' => $typeCm->id,
            'annee_universitaire_id' => $this->annee->id, 'volume_cm' => 10, 'statut' => 'active',
        ]);
        $ens2 = Enseignement::create([
            'matiere_id' => $this->ecue->id, 'type_id' => $this->typeTd->id,
            'annee_universitaire_id' => $this->annee->id, 'volume_td' => 12, 'statut' => 'active',
        ]);
        $ens1->affectations()->create(['teacher_id' => $this->enseignant->id, 'role' => 'enseignant', 'volume_heures' => 10]);
        $ens2->affectations()->create(['teacher_id' => $this->enseignant->id, 'role' => 'enseignant', 'volume_heures' => 12]);

        $grade = GradeEnseignant::create(['code' => 'MA', 'nom' => 'Maître-assistant']);
        $this->enseignant->update(['grade_id' => $grade->id]);

        SeuilService::create([
            'grade_id' => $grade->id, 'annee_universitaire_id' => $this->annee->id,
            'service_min' => 20, 'service_cible' => 30, 'service_max' => 21,
        ]);

        $charge = app(ServiceEnseignantService::class)->charge($this->enseignant->id, $this->annee->id);
        $this->assertSame(10.0, $charge['cm']);
        $this->assertSame(12.0, $charge['td']);
        $this->assertSame(22.0, $charge['total']);

        $seuils = app(ServiceEnseignantService::class)->seuils($this->enseignant->fresh(), $this->annee->id);
        $this->assertSame(21.0, $seuils['max']);
        $this->assertGreaterThanOrEqual($seuils['max'], $charge['total']); // surcharge détectable (total ≥ max)

        $this->actingAs($this->admin)->get(route('admin.enseignements.services'))
            ->assertOk()->assertSee('Surcharge', false);
    }

    // ── Évaluation / qualité ──────────────────────────────────────

    public function test_composantes_somme_100(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.enseignements.evaluation.edit', $this->ecue))->assertOk();

        $this->actingAs($this->admin)->put(
            route('admin.enseignements.evaluation.composantes', $this->ecue),
            ['composantes' => [
                ['code' => 'CC', 'libelle' => 'Contrôle continu', 'pourcentage' => 40],
                ['code' => 'EXAM', 'libelle' => 'Examen', 'pourcentage' => 60],
            ]]
        )->assertRedirect();

        $this->assertSame(2, ComposanteEvaluation::count());

        // Refus : somme ≠ 100.
        $this->actingAs($this->admin)->put(
            route('admin.enseignements.evaluation.composantes', $this->ecue),
            ['composantes' => [['code' => 'CC', 'libelle' => 'CC', 'pourcentage' => 30]]]
        )->assertSessionHasErrors('composantes');
    }

    public function test_responsable_actif_requis(): void
    {
        $this->enseignant->update(['actif' => false]);

        $this->actingAs($this->admin)->patch(
            route('admin.enseignements.evaluation.responsable', $this->ecue),
            ['responsable_teacher_id' => $this->enseignant->id]
        )->assertSessionHasErrors('responsable');
    }

    public function test_qualite_liste_anomalies(): void
    {
        $this->creer();

        $this->actingAs($this->admin)->get(route('admin.enseignements.qualite'))
            ->assertOk()->assertSee('sans enseignant', false);
    }

    // ── Sécurité / IDOR ───────────────────────────────────────────

    public function test_sans_permission_403(): void
    {
        $agent = User::creerCompteBackOffice([
            'name' => 'Agent', 'email' => 'ag.ens@universite.test', 'password' => 'Motdepasse@123',
        ], 'consultation');

        $ens = $this->creer();

        $this->actingAs($agent)->get(route('admin.enseignements.show', $ens))->assertOk();
        $this->actingAs($agent)->post(route('admin.enseignements.store'), [])->assertForbidden();
        $this->actingAs($agent)->patch(
            route('admin.enseignements.transition', $ens), ['statut' => 'planned']
        )->assertForbidden();
    }

    public function test_idor_hors_perimetre(): void
    {
        $faculteB = Faculte::create(['nom' => 'FB', 'code' => 'FB', 'description' => 'B', 'active' => true]);
        $adminA = User::creerCompteBackOffice([
            'name' => 'Admin A', 'email' => 'aa.ens@universite.test',
            'password' => 'Motdepasse@123', 'faculte_id' => $this->faculte->id,
        ], 'admin_faculte');

        $programmeB = Programme::create([
            'faculte_id' => $faculteB->id, 'code' => 'MI', 'nom' => 'MI', 'duree_annees' => 3,
            'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $niveauB = Niveau::create(['programme_id' => $programmeB->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1]);
        $semestreB = SemestreLmd::create(['niveau_id' => $niveauB->id, 'code' => 'S1', 'nom' => 'S1', 'numero' => 1, 'ordre' => 1]);
        $ueB = UniteEnseignement::create([
            'semestre_id' => $semestreB->id, 'programme_id' => $programmeB->id,
            'code' => 'UE-B', 'nom' => 'UE B', 'credits' => 6,
        ]);
        $ecueB = Matiere::create(['code' => 'B101', 'nom' => 'B', 'ue_id' => $ueB->id, 'niveau' => 'L1', 'semestre' => 1]);
        $ensB = Enseignement::create([
            'matiere_id' => $ecueB->id, 'type_id' => $this->typeTd->id,
            'annee_universitaire_id' => $this->annee->id, 'statut' => 'draft',
        ]);

        $this->actingAs($adminA)->get(route('admin.enseignements.show', $ensB))->assertForbidden();
        $this->actingAs($adminA)->delete(route('admin.enseignements.destroy', $ensB))->assertForbidden();
    }

    // ── Import / Export ───────────────────────────────────────────

    public function test_import_enseignements_avec_references(): void
    {
        $csv = 'ecue;type;annee;volume_td;statut'."\n"
            .'J101;TD;2026-2027;12;draft'."\n";

        $preview = $this->actingAs($this->admin)->post(
            route('admin.donnees.import.preview', 'enseignements'),
            ['fichier' => UploadedFile::fake()->createWithContent('e.csv', $csv, 'text/csv')]
        )->assertOk();

        $this->actingAs($this->admin)->post(
            route('admin.donnees.import.confirmer', 'enseignements'),
            ['token' => $preview->viewData('token'), 'mode' => 'creer']
        )->assertOk();

        $this->assertSame(1, Enseignement::count());
        $this->assertSame(12, Enseignement::first()->volume_td);
    }

    public function test_import_enseignements_reference_inconnue_rollback(): void
    {
        $csv = 'ecue;type;annee;volume_td'."\n".'INCONNU;TD;2026-2027;12'."\n";

        $preview = $this->actingAs($this->admin)->post(
            route('admin.donnees.import.preview', 'enseignements'),
            ['fichier' => UploadedFile::fake()->createWithContent('e.csv', $csv, 'text/csv')]
        )->assertOk();

        $this->actingAs($this->admin)->post(
            route('admin.donnees.import.confirmer', 'enseignements'),
            ['token' => $preview->viewData('token'), 'mode' => 'creer']
        )->assertRedirect();

        $this->assertSame(0, Enseignement::count());
    }

    public function test_export_enseignements_csv(): void
    {
        $this->creer();

        $contenu = $this->actingAs($this->admin)
            ->get(route('admin.donnees.export', ['enseignements', 'csv']))
            ->assertOk()->streamedContent();

        $this->assertStringContainsString('J101', $contenu);
        $this->assertStringContainsString('TD', $contenu);
    }

    // ── Dashboard / vues ──────────────────────────────────────────

    public function test_tableau_bord_et_liste(): void
    {
        $this->creer();

        $this->actingAs($this->admin)->get(route('admin.enseignements.tableau-bord'))
            ->assertOk()->assertSee('Heures prévues', false);
        $this->actingAs($this->admin)->get(route('admin.enseignements.index'))
            ->assertOk()->assertSee('J101', false);
    }

    public function test_types_crud(): void
    {
        $type = TypeEnseignement::create(['code' => 'ATELIER', 'nom' => 'Atelier']);

        $this->actingAs($this->admin)->get(route('admin.enseignements.types'))
            ->assertOk()->assertSee('ATELIER', false);
    }
}
