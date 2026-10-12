<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\User;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Services\AdmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActAsScolarite;
use Tests\TestCase;

/**
 * Admission externe (roadmap « admission Master — entité dédiée ») :
 * acte d'admission traçable, consommé par la progression LMD.
 * Fin de cycle (L3 → M1) : jamais deviné, mais possible AVEC un acte
 * d'admission actif — sans exiger une délibération fantôme.
 */
class AdmissionTest extends TestCase
{
    use ActAsScolarite, RefreshDatabase;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private AnneeUniversitaire $anneeSource;

    private AnneeUniversitaire $anneeCible;

    private Programme $licence;

    private Programme $master;

    private Programme $programmeB;

    private Niveau $l3;

    private Niveau $m1;

    private User $scolarite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculteA = Faculte::create(['nom' => 'FA', 'code' => 'FA', 'description' => 'T', 'active' => true]);
        $this->faculteB = Faculte::create(['nom' => 'FB', 'code' => 'FB', 'description' => 'T', 'active' => true]);
        $this->anneeSource = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->anneeCible = AnneeUniversitaire::create([
            'libelle' => '2027-2028', 'date_debut' => '2027-09-01', 'date_fin' => '2028-06-30', 'active' => false,
        ]);

        $this->licence = Programme::create([
            'faculte_id' => $this->faculteA->id, 'code' => 'LINFO', 'nom' => 'Licence Informatique',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $this->l3 = Niveau::create(['programme_id' => $this->licence->id, 'code' => 'L3', 'nom' => 'Licence 3', 'ordre' => 3]);
        $this->master = Programme::create([
            'faculte_id' => $this->faculteA->id, 'code' => 'MINFO', 'nom' => 'Master Informatique',
            'duree_annees' => 2, 'semestres_nombre' => 4, 'credits_requis' => 120,
        ]);
        $this->m1 = Niveau::create(['programme_id' => $this->master->id, 'code' => 'M1', 'nom' => 'Master 1', 'ordre' => 1]);
        $this->programmeB = Programme::create([
            'faculte_id' => $this->faculteB->id, 'code' => 'PB', 'nom' => 'Programme B',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);

        $this->scolarite = $this->scolarite('admin_scolarite');
    }

    private function etudiantL3(Faculte $faculte): array
    {
        $etudiant = Etudiant::create([
            'cin' => 'BK'.random_int(100000, 999999),
            'nom' => 'Candidat', 'prenom' => 'Admission',
            'email' => uniqid('adm.', true).'@example.com',
            'sexe' => 'M', 'faculte_id' => $faculte->id,
            'niveau' => 'L3', 'annee_academique' => '2026-2027',
            'adresse' => '1 rue', 'ville' => 'Sfax',
            'code_postal' => '3000', 'annee_bac' => '2021',
            'password' => 'motdepasse1',
        ]);

        $inscription = InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id, 'annee_universitaire_id' => $this->anneeSource->id,
            'programme_id' => $this->licence->id, 'niveau_id' => $this->l3->id,
            'niveau' => 'L3', 'filiere' => 'Info', 'statut' => 'valide',
        ]);

        return [$etudiant, $inscription];
    }

    public function test_fin_de_cycle_sans_admission_ni_deliberation_refusee(): void
    {
        [$etudiant, $inscription] = $this->etudiantL3($this->faculteA);
        $this->actingAs($this->scolarite);

        $this->post(route('admin.inscriptions.progresser', $inscription), [
            'programme_id' => $this->master->id, 'niveau_id' => $this->m1->id, 'confirmer' => '1',
        ])->assertRedirect()->assertSessionHasErrors('progression');

        $this->assertSame(1, InscriptionAcademique::count());
    }

    public function test_admission_active_permet_la_progression_et_est_consommee(): void
    {
        [$etudiant, $inscription] = $this->etudiantL3($this->faculteA);

        $admission = app(AdmissionService::class)->accorder(
            $etudiant, $this->master, $this->m1, $this->anneeCible, 'admis',
            'Dossier admis sur titre — commission Master', (int) $this->scolarite->id,
        );

        $this->actingAs($this->scolarite);
        $this->post(route('admin.inscriptions.progresser', $inscription), [
            'programme_id' => $this->master->id, 'niveau_id' => $this->m1->id, 'confirmer' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $nouvelle = InscriptionAcademique::where('etudiant_id', $etudiant->id)
            ->where('annee_universitaire_id', $this->anneeCible->id)->firstOrFail();
        $this->assertSame('M1', $nouvelle->niveau);
        $this->assertSame((int) $this->master->id, (int) $nouvelle->programme_id);

        $admission = $admission->fresh();
        $this->assertNotNull($admission->consumed_at);
        $this->assertSame((int) $nouvelle->id, (int) $admission->consumed_inscription_id);

        // La consommation est tracée au journal d'audit.
        $this->assertDatabaseHas('historique_academique', [
            'etudiant_id' => $etudiant->id, 'action' => 'admission_consommee',
        ]);
    }

    public function test_admission_refusee_ne_permet_pas_la_progression(): void
    {
        [$etudiant, $inscription] = $this->etudiantL3($this->faculteA);

        app(AdmissionService::class)->accorder(
            $etudiant, $this->master, $this->m1, $this->anneeCible, 'refuse',
            'Dossier incomplet', (int) $this->scolarite->id,
        );

        $this->actingAs($this->scolarite);
        $this->post(route('admin.inscriptions.progresser', $inscription), [
            'programme_id' => $this->master->id, 'niveau_id' => $this->m1->id, 'confirmer' => '1',
        ])->assertRedirect()->assertSessionHasErrors('progression');

        $this->assertSame(1, InscriptionAcademique::count());
    }

    public function test_service_refuse_niveau_hors_programme_et_doublon_actif(): void
    {
        [$etudiant] = $this->etudiantL3($this->faculteA);
        $service = app(AdmissionService::class);

        $this->expectException(\DomainException::class);
        $service->accorder($etudiant, $this->master, $this->l3, $this->anneeCible, 'admis', null, (int) $this->scolarite->id);
    }

    public function test_service_refuse_deux_admissions_actives_identiques(): void
    {
        [$etudiant] = $this->etudiantL3($this->faculteA);
        $service = app(AdmissionService::class);

        $service->accorder($etudiant, $this->master, $this->m1, $this->anneeCible, 'admis', null, (int) $this->scolarite->id);

        $this->expectException(\DomainException::class);
        $service->accorder($etudiant, $this->master, $this->m1, $this->anneeCible, 'admis', null, (int) $this->scolarite->id);
    }

    public function test_crud_http_et_audit(): void
    {
        [$etudiant] = $this->etudiantL3($this->faculteA);
        $this->actingAs($this->scolarite);

        $this->post(route('admin.admissions.store'), [
            'etudiant_id' => $etudiant->id, 'programme_id' => $this->master->id,
            'niveau_id' => $this->m1->id, 'annee_universitaire_id' => $this->anneeCible->id,
            'decision' => 'admis', 'motif' => 'Commission du 04/10',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $admission = Admission::where('etudiant_id', $etudiant->id)->firstOrFail();
        $this->assertSame('admis', $admission->decision);
        $this->assertDatabaseHas('historique_academique', [
            'etudiant_id' => $etudiant->id, 'action' => 'admission_accordee',
        ]);

        // Listing visible + suppression (non consommée uniquement).
        $this->get(route('admin.admissions.index'))->assertOk()->assertSee('Master Informatique');
        $this->delete(route('admin.admissions.destroy', $admission))->assertRedirect();
        $this->assertDatabaseMissing('admissions', ['id' => $admission->id]);
    }

    public function test_isolation_faculte_sur_le_crud(): void
    {
        [$etudiantB] = $this->etudiantL3($this->faculteB);
        [$etudiantA] = $this->etudiantL3($this->faculteA);

        $adminFaculteA = User::creerCompteBackOffice([
            'name' => 'Fac A', 'email' => uniqid('fa.', true).'@scolarite.test',
            'password' => 'Motdepasse@123', 'faculte_id' => $this->faculteA->id,
        ], 'admin_faculte');
        $this->actingAs($adminFaculteA);

        // Étudiant d'une autre faculté → 404.
        $this->post(route('admin.admissions.store'), [
            'etudiant_id' => $etudiantB->id, 'programme_id' => $this->master->id,
            'annee_universitaire_id' => $this->anneeCible->id, 'decision' => 'admis',
        ])->assertNotFound();

        // Programme cible d'une autre faculté → 404.
        $this->post(route('admin.admissions.store'), [
            'etudiant_id' => $etudiantA->id, 'programme_id' => $this->programmeB->id,
            'annee_universitaire_id' => $this->anneeCible->id, 'decision' => 'admis',
        ])->assertNotFound();

        // Création légitime : 302.
        $this->post(route('admin.admissions.store'), [
            'etudiant_id' => $etudiantA->id, 'programme_id' => $this->master->id,
            'niveau_id' => $this->m1->id, 'annee_universitaire_id' => $this->anneeCible->id,
            'decision' => 'admis',
        ])->assertRedirect()->assertSessionHasNoErrors();

        // Le listing d'un compte restreint n'expose pas les admissions de B.
        $admissionB = Admission::create([
            'etudiant_id' => $etudiantB->id, 'programme_id' => $this->programmeB->id,
            'annee_universitaire_id' => $this->anneeCible->id, 'decision' => 'admis',
            'decided_by' => $this->scolarite->id, 'decided_at' => now(),
        ]);

        $reponse = $this->get(route('admin.admissions.index'))->assertOk();
        $this->assertTrue($reponse->viewData('admissions')->pluck('id')->doesntContain($admissionB->id));

        // Suppression de l'admission d'autrui → 404.
        $this->delete(route('admin.admissions.destroy', $admissionB))->assertNotFound();
        $this->assertDatabaseHas('admissions', ['id' => $admissionB->id]);
    }

    public function test_admission_consommee_non_supprimable(): void
    {
        [$etudiant, $inscription] = $this->etudiantL3($this->faculteA);
        $admission = app(AdmissionService::class)->accorder(
            $etudiant, $this->master, $this->m1, $this->anneeCible, 'admis', null, (int) $this->scolarite->id,
        );

        $this->actingAs($this->scolarite);
        $this->post(route('admin.inscriptions.progresser', $inscription), [
            'programme_id' => $this->master->id, 'niveau_id' => $this->m1->id, 'confirmer' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->delete(route('admin.admissions.destroy', $admission->fresh()))
            ->assertRedirect()->assertSessionHasErrors('admission');
        $this->assertDatabaseHas('admissions', ['id' => $admission->id]);
    }
}
