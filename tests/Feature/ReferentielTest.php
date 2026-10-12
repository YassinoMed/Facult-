<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Referentiel\Models\Departement;
use App\Modules\Referentiel\Models\Domaine;
use App\Modules\Referentiel\Models\Maquette;
use App\Modules\Referentiel\Models\Mention;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\Specialite;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Referentiel\Services\MaquetteService;
use App\Modules\Referentiel\Services\PrerequisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Référentiel LMD (§33) : CRUD, validation, intégrité, maquettes,
 * prérequis, permissions, sécurité, import/export.
 */
class ReferentielTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create([
            'nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true,
        ]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.ref@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
    }

    /**
     * @return array<string, mixed>
     */
    private function chaine(): array
    {
        $departement = Departement::create([
            'faculte_id' => $this->faculte->id, 'code' => 'INFO', 'nom' => 'Informatique',
        ]);
        $domaine = Domaine::create([
            'faculte_id' => $this->faculte->id, 'code' => 'ST', 'nom' => 'Sciences et Technologies',
        ]);
        $mention = Mention::create(['domaine_id' => $domaine->id, 'code' => 'INF', 'nom' => 'Informatique']);
        $specialite = Specialite::create(['mention_id' => $mention->id, 'code' => 'GL', 'nom' => 'Génie Logiciel']);
        $programme = Programme::create([
            'faculte_id' => $this->faculte->id, 'departement_id' => $departement->id,
            'specialite_id' => $specialite->id, 'code' => 'LGL', 'nom' => 'Licence Génie Logiciel',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $niveau = Niveau::create([
            'programme_id' => $programme->id, 'code' => 'L1', 'nom' => 'Licence 1', 'ordre' => 1,
        ]);
        $semestre = SemestreLmd::create([
            'niveau_id' => $niveau->id, 'code' => 'S1', 'nom' => 'Semestre 1', 'numero' => 1, 'ordre' => 1,
        ]);
        $ue = UniteEnseignement::create([
            'semestre_id' => $semestre->id, 'programme_id' => $programme->id,
            'code' => 'UE01', 'nom' => 'Programmation', 'credits' => 12, 'coefficient' => 3,
        ]);
        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30', 'active' => true,
        ]);

        return compact('departement', 'domaine', 'mention', 'specialite', 'programme', 'niveau', 'semestre', 'ue', 'annee');
    }

    // ── CRUD ──────────────────────────────────────────────────────

    public function test_crud_departement(): void
    {
        $this->actingAs($this->admin)->post(route('admin.referentiel.entites.store', 'departements'), [
            'faculte_id' => $this->faculte->id, 'code' => 'MATH', 'nom' => 'Mathématiques',
        ])->assertRedirect();

        $departement = Departement::where('code', 'MATH')->first();
        $this->assertNotNull($departement);

        $this->actingAs($this->admin)
            ->get(route('admin.referentiel.entites.show', ['departements', $departement]))
            ->assertOk()->assertSee('Mathématiques', false);

        $this->actingAs($this->admin)->put(
            route('admin.referentiel.entites.update', ['departements', $departement]),
            ['faculte_id' => $this->faculte->id, 'code' => 'MATH', 'nom' => 'Maths']
        )->assertRedirect();
        $this->assertSame('Maths', $departement->fresh()->nom);

        $this->actingAs($this->admin)->delete(
            route('admin.referentiel.entites.destroy', ['departements', $departement])
        )->assertRedirect();
        $this->assertSame(0, Departement::where('code', 'MATH')->count());
    }

    public function test_code_duplique_refuse_dans_contexte(): void
    {
        Departement::create(['faculte_id' => $this->faculte->id, 'code' => 'INFO', 'nom' => 'Info']);

        $this->actingAs($this->admin)->post(route('admin.referentiel.entites.store', 'departements'), [
            'faculte_id' => $this->faculte->id, 'code' => 'INFO', 'nom' => 'Doublon',
        ])->assertSessionHasErrors('code');
    }

    public function test_credits_coefficients_volumes_negatifs_refuses(): void
    {
        $c = $this->chaine();

        $this->actingAs($this->admin)->post(route('admin.referentiel.entites.store', 'ues'), [
            'semestre_id' => $c['semestre']->id, 'code' => 'UE99', 'nom' => 'X', 'credits' => -3,
        ])->assertSessionHasErrors('credits');

        $matiere = Matiere::create([
            'code' => 'ALG101', 'nom' => 'Algo', 'ue_id' => $c['ue']->id,
            'niveau' => 'L1', 'semestre' => 1,
        ]);

        $this->actingAs($this->admin)->patch(route('admin.referentiel.ecues.update', $matiere), [
            'volume_cm' => -5,
        ])->assertSessionHasErrors('volume_cm');
    }

    // ── Intégrité ─────────────────────────────────────────────────

    public function test_suppression_interdite_si_dependances(): void
    {
        $c = $this->chaine();

        $this->actingAs($this->admin)->delete(
            route('admin.referentiel.entites.destroy', ['domaines', $c['domaine']])
        )->assertRedirect();
        $this->assertNotNull(Domaine::find($c['domaine']->id));

        $this->actingAs($this->admin)->delete(
            route('admin.referentiel.entites.destroy', ['programmes', $c['programme']])
        )->assertRedirect();
        $this->assertNotNull(Programme::find($c['programme']->id));
    }

    // ── Maquettes ─────────────────────────────────────────────────

    public function test_maquette_cycle_complet(): void
    {
        $c = $this->chaine();

        $reponse = $this->actingAs($this->admin)->post(route('admin.referentiel.maquettes.store'), [
            'programme_id' => $c['programme']->id,
            'annee_universitaire_id' => $c['annee']->id,
        ])->assertRedirect();

        $maquette = Maquette::first();
        $this->assertSame('draft', $maquette->statut);
        $this->assertSame(1, $maquette->version);

        // Contenu en brouillon.
        $this->actingAs($this->admin)->put(
            route('admin.referentiel.maquettes.contenu', $maquette),
            ['ues' => [['ue_id' => $c['ue']->id, 'semestre_id' => $c['semestre']->id]]]
        )->assertRedirect();
        $this->assertSame(1, $maquette->fresh()->unitesEnseignement()->count());

        // Transitions : draft → review → approved → active.
        foreach (['review', 'approved', 'active'] as $statut) {
            $this->actingAs($this->admin)->patch(
                route('admin.referentiel.maquettes.transition', $maquette),
                ['statut' => $statut]
            )->assertRedirect();
            $this->assertSame($statut, $maquette->fresh()->statut);
        }

        // Verrouillée : contenu + suppression refusés.
        $this->actingAs($this->admin)->put(
            route('admin.referentiel.maquettes.contenu', $maquette), ['ues' => []]
        )->assertRedirect();
        $this->assertSame(1, $maquette->fresh()->unitesEnseignement()->count());

        $this->actingAs($this->admin)->delete(
            route('admin.referentiel.maquettes.destroy', $maquette)
        )->assertRedirect();
        $this->assertNotNull(Maquette::find($maquette->id));

        // Transition illégale active → draft refusée.
        $this->actingAs($this->admin)->patch(
            route('admin.referentiel.maquettes.transition', $maquette),
            ['statut' => 'draft']
        )->assertRedirect();
        $this->assertSame('active', $maquette->fresh()->statut);

        $this->assertNotNull($reponse);
    }

    public function test_activation_unique_par_programme_et_nouvelle_version(): void
    {
        $c = $this->chaine();
        $service = app(MaquetteService::class);

        $v1 = Maquette::create([
            'programme_id' => $c['programme']->id, 'annee_universitaire_id' => $c['annee']->id,
            'version' => 1, 'statut' => 'draft', 'auteur_id' => $this->admin->id,
        ]);
        $service->transiter($v1, 'review', $this->admin->id);
        $service->transiter($v1->fresh(), 'approved', $this->admin->id);
        $service->transiter($v1->fresh(), 'active', $this->admin->id);

        $v2 = $service->nouvelleVersion($v1->fresh(), $c['annee']->id, $this->admin->id, 'v2');
        $this->assertSame(2, $v2->version);
        $this->assertSame('draft', $v2->statut);

        $service->transiter($v2, 'review', $this->admin->id);
        $service->transiter($v2->fresh(), 'approved', $this->admin->id);
        $service->transiter($v2->fresh(), 'active', $this->admin->id);

        // v1 automatiquement archivée : une seule active.
        $this->assertSame('archived', $v1->fresh()->statut);
        $this->assertSame(1, Maquette::where('programme_id', $c['programme']->id)
            ->where('statut', 'active')->count());
    }

    // ── Prérequis ─────────────────────────────────────────────────

    public function test_prerequis_valide_puis_cycle_refuse(): void
    {
        $c = $this->chaine();
        $service = app(PrerequisService::class);

        $a = Matiere::create(['code' => 'A101', 'nom' => 'A', 'ue_id' => $c['ue']->id, 'niveau' => 'L1', 'semestre' => 1]);
        $b = Matiere::create(['code' => 'B101', 'nom' => 'B', 'ue_id' => $c['ue']->id, 'niveau' => 'L1', 'semestre' => 1]);
        $d = Matiere::create(['code' => 'D101', 'nom' => 'D', 'ue_id' => $c['ue']->id, 'niveau' => 'L1', 'semestre' => 1]);

        $service->ajouter($b, $a);
        $service->ajouter($d, $b);
        $this->assertTrue($b->fresh()->prerequis()->where('matieres.id', $a->id)->exists());

        // Cycle D → … → A → D interdit.
        $this->expectException(\DomainException::class);
        $service->ajouter($a, $d);
    }

    public function test_auto_prerequis_refuse(): void
    {
        $c = $this->chaine();
        $a = Matiere::create(['code' => 'A102', 'nom' => 'A', 'ue_id' => $c['ue']->id, 'niveau' => 'L1', 'semestre' => 1]);

        $this->expectException(\DomainException::class);
        app(PrerequisService::class)->ajouter($a, $a);
    }

    // ── Permissions & sécurité ────────────────────────────────────

    public function test_permissions_par_role(): void
    {
        // consultation : lecture seule.
        $lecture = User::creerCompteBackOffice([
            'name' => 'Consult', 'email' => 'co.ref@universite.test', 'password' => 'Motdepasse@123',
        ], 'consultation');

        $this->actingAs($lecture)->get(route('admin.referentiel.tableau-bord'))->assertOk();
        $this->actingAs($lecture)->post(route('admin.referentiel.entites.store', 'domaines'), [
            'faculte_id' => $this->faculte->id, 'code' => 'X', 'nom' => 'X',
        ])->assertForbidden();

        // Invité : redirection.
        auth()->logout();
        $this->get(route('admin.referentiel.tableau-bord'))->assertRedirect();
    }

    public function test_idor_faculte(): void
    {
        $faculteB = Faculte::create(['nom' => 'FB', 'code' => 'FB', 'description' => 'B', 'active' => true]);
        $adminA = User::creerCompteBackOffice([
            'name' => 'Admin A', 'email' => 'aa.ref@universite.test',
            'password' => 'Motdepasse@123', 'faculte_id' => $this->faculte->id,
        ], 'admin_faculte');

        $departementB = Departement::create([
            'faculte_id' => $faculteB->id, 'code' => 'INFO', 'nom' => 'Info B',
        ]);

        // Lecture + suppression hors périmètre refusées.
        $this->actingAs($adminA)
            ->get(route('admin.referentiel.entites.show', ['departements', $departementB]))
            ->assertForbidden();
        $this->actingAs($adminA)->delete(
            route('admin.referentiel.entites.destroy', ['departements', $departementB])
        )->assertForbidden();
        $this->assertNotNull(Departement::find($departementB->id));

        // Création hors périmètre refusée.
        $this->actingAs($adminA)->post(route('admin.referentiel.entites.store', 'departements'), [
            'faculte_id' => $faculteB->id, 'code' => 'MATH', 'nom' => 'Maths',
        ])->assertForbidden();
    }

    // ── Vues transverses ──────────────────────────────────────────

    public function test_tableau_bord_arbre_qualite(): void
    {
        $this->chaine();

        $this->actingAs($this->admin)->get(route('admin.referentiel.tableau-bord'))
            ->assertOk()->assertSee('Référentiel académique', false);
        $this->actingAs($this->admin)->get(route('admin.referentiel.arbre'))
            ->assertOk()->assertSee('LGL', false);
        $this->actingAs($this->admin)->get(route('admin.referentiel.qualite'))
            ->assertOk();
    }

    public function test_annee_courante_unique(): void
    {
        $a1 = AnneeUniversitaire::create([
            'libelle' => '2025-2026', 'date_debut' => '2025-09-01',
            'date_fin' => '2026-06-30', 'active' => true,
        ]);
        $a2 = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30', 'active' => false,
        ]);

        $a2->definirCourante();

        $this->assertTrue($a2->fresh()->active);
        $this->assertFalse($a1->fresh()->active);
    }

    public function test_volume_total_ecue(): void
    {
        $c = $this->chaine();
        $matiere = Matiere::create([
            'code' => 'V101', 'nom' => 'V', 'ue_id' => $c['ue']->id,
            'niveau' => 'L1', 'semestre' => 1,
            'volume_cm' => 20, 'volume_td' => 15, 'volume_tp' => 10,
        ]);

        $this->assertSame(45, $matiere->volumeTotal());
    }

    // ── Import / Export ───────────────────────────────────────────

    public function test_import_departements_preview_puis_confirmation(): void
    {
        $fichier = UploadedFile::fake()->createWithContent(
            'departements.csv', "code;faculte;nom;responsable\nINFO;FST;Informatique;Dr X\n", 'text/csv'
        );

        $preview = $this->actingAs($this->admin)
            ->post(route('admin.donnees.import.preview', 'departements'), ['fichier' => $fichier])
            ->assertOk();

        $this->actingAs($this->admin)->post(
            route('admin.donnees.import.confirmer', 'departements'),
            ['token' => $preview->viewData('token'), 'mode' => 'creer']
        )->assertOk();

        $this->assertTrue(Departement::where('code', 'INFO')->exists());
    }

    public function test_import_ue_relation_inexistante_rollback(): void
    {
        $fichier = UploadedFile::fake()->createWithContent(
            'ues.csv', "code;semestre;programme;nom;credits\nUE01;S1;INCONNU;X;6\n", 'text/csv'
        );

        $preview = $this->actingAs($this->admin)
            ->post(route('admin.donnees.import.preview', 'ues'), ['fichier' => $fichier])
            ->assertOk();

        $this->actingAs($this->admin)->post(
            route('admin.donnees.import.confirmer', 'ues'),
            ['token' => $preview->viewData('token'), 'mode' => 'creer']
        )->assertRedirect();

        $this->assertSame(0, UniteEnseignement::where('code', 'UE01')->count());
    }

    public function test_export_ues_csv_scope(): void
    {
        $this->chaine();

        $this->actingAs($this->admin)
            ->get(route('admin.donnees.export', ['ues', 'csv']))
            ->assertOk();
    }
}
