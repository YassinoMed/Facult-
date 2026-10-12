<?php

namespace Tests\Feature;

use App\Models\AffectationGroupe;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\GradeEnseignant;
use App\Models\InscriptionAcademique;
use App\Models\InscriptionPedagogique;
use App\Models\Matiere;
use App\Models\Personnel;
use App\Models\User;
use App\Modules\Referentiel\Models\Maquette;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Teacher;
use App\Services\AffectationService;
use App\Services\InscriptionService;
use App\Services\StudentStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Phase 2 — Population (§55) : statuts, affectations, inscriptions,
 * transfert, suspension, personnel, fiches, import/export, sécurité.
 */
class PopulationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create([
            'nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true,
        ]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.pop@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30', 'active' => true,
        ]);
    }

    private function etudiant(array $extra = []): Etudiant
    {
        return Etudiant::create(array_merge([
            'cin' => 'BK'.random_int(100000, 999999),
            'nom' => 'Pop', 'prenom' => 'Test',
            'email' => uniqid('p.', true).'@example.com',
            'sexe' => 'M', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027',
            'adresse' => '1 rue', 'ville' => 'Rabat',
            'code_postal' => '10000', 'annee_bac' => '2024',
            'password' => 'motdepasse1',
        ], $extra));
    }

    private function chaineLmd(): array
    {
        $programme = Programme::create([
            'faculte_id' => $this->faculte->id, 'code' => 'LGL',
            'nom' => 'Licence Génie Logiciel', 'duree_annees' => 3,
            'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $niveau = Niveau::create([
            'programme_id' => $programme->id, 'code' => 'L2', 'nom' => 'L2', 'ordre' => 2,
        ]);
        $semestre = SemestreLmd::create([
            'niveau_id' => $niveau->id, 'code' => 'S3', 'nom' => 'S3', 'numero' => 3, 'ordre' => 3,
        ]);
        $ue = UniteEnseignement::create([
            'semestre_id' => $semestre->id, 'programme_id' => $programme->id,
            'code' => 'UE01', 'nom' => 'Prog', 'credits' => 6, 'coefficient' => 2,
        ]);
        $matiere = Matiere::create([
            'code' => 'J101', 'nom' => 'Java', 'ue_id' => $ue->id,
            'niveau' => 'L2', 'semestre' => 1, 'credits' => 6,
        ]);
        $groupe = Group::create([
            'code' => 'GL2-A', 'nom' => 'GL2 A', 'faculte_id' => $this->faculte->id,
            'programme_id' => $programme->id, 'niveau_id' => $niveau->id,
            'annee_universitaire_id' => $this->annee->id,
            'niveau' => 'L2', 'capacite' => 2, 'actif' => true,
        ]);
        $maquette = Maquette::create([
            'programme_id' => $programme->id, 'annee_universitaire_id' => $this->annee->id,
            'version' => 1, 'statut' => 'active', 'auteur_id' => $this->admin->id,
        ]);
        $maquette->unitesEnseignement()->attach($ue->id, ['semestre_id' => $semestre->id]);

        return compact('programme', 'niveau', 'semestre', 'ue', 'matiere', 'groupe', 'maquette');
    }

    // ── Statuts ───────────────────────────────────────────────────

    public function test_transition_statut_valide_et_historisee(): void
    {
        $etudiant = $this->etudiant();

        $this->actingAs($this->admin)->patch(
            route('admin.etudiants.updateStatut', $etudiant),
            ['statut' => 'valide', 'motif' => 'dossier complet']
        )->assertRedirect();

        $this->assertSame('valide', $etudiant->fresh()->statut);
        $this->assertDatabaseHas('situations_etudiant', [
            'etudiant_id' => $etudiant->id, 'type' => 'statut',
            'ancienne_valeur' => 'en_attente', 'nouvelle_valeur' => 'valide',
        ]);
    }

    public function test_transition_invalide_rejetee(): void
    {
        $etudiant = $this->etudiant();
        $etudiant->definirStatut('valide');

        $this->expectException(\DomainException::class);
        app(StudentStatusService::class)->changer($etudiant->fresh(), 'statut_inconnu', $this->admin->id);
    }

    public function test_statut_non_mass_assignable(): void
    {
        $etudiant = Etudiant::create([
            'cin' => 'BK999001', 'nom' => 'X', 'prenom' => 'Y',
            'email' => uniqid('x.', true).'@example.com',
            'sexe' => 'M', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027',
            'adresse' => '1', 'ville' => 'R', 'code_postal' => '1',
            'annee_bac' => '2024', 'password' => 'motdepasse1',
            'statut' => 'valide',
        ]);

        $this->assertSame('en_attente', $etudiant->fresh()->statut);
    }

    // ── Affectations ──────────────────────────────────────────────

    public function test_affectation_cree_cloture_et_synchronise(): void
    {
        $c = $this->chaineLmd();
        $etudiant = $this->etudiant(['niveau' => 'L2', 'programme_id' => $c['programme']->id]);
        $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id, 'niveau' => 'L2', 'statut' => 'en_cours',
        ]);

        $this->actingAs($this->admin)->post(
            route('admin.etudiants.affectations.store', $etudiant),
            ['groupe_id' => $c['groupe']->id, 'annee_universitaire_id' => $this->annee->id, 'motif' => 'rentrée']
        )->assertRedirect();

        $this->assertSame($c['groupe']->id, $etudiant->fresh()->groupe_id);
        $this->assertSame(1, AffectationGroupe::where('etudiant_id', $etudiant->id)->where('statut', 'active')->count());

        // Changement : ancienne clôturée, historique conservé.
        $groupeB = Group::create([
            'code' => 'GL2-B', 'nom' => 'B', 'faculte_id' => $this->faculte->id,
            'programme_id' => $c['programme']->id, 'niveau' => 'L2', 'actif' => true,
        ]);
        $this->actingAs($this->admin)->post(
            route('admin.etudiants.affectations.store', $etudiant),
            ['groupe_id' => $groupeB->id, 'annee_universitaire_id' => $this->annee->id, 'motif' => 'équilibrage']
        )->assertRedirect();

        $this->assertSame($groupeB->id, $etudiant->fresh()->groupe_id);
        $this->assertSame(1, AffectationGroupe::where('etudiant_id', $etudiant->id)->where('statut', 'active')->count());
        $this->assertSame(1, AffectationGroupe::where('etudiant_id', $etudiant->id)->where('statut', 'cloturee')->count());
        $this->assertDatabaseHas('situations_etudiant', [
            'etudiant_id' => $etudiant->id, 'type' => 'groupe', 'nouvelle_valeur' => 'GL2-B',
        ]);
    }

    public function test_capacite_bloque_sauf_force_autorise(): void
    {
        $c = $this->chaineLmd();
        $service = app(AffectationService::class);

        $premier = $this->etudiant(['niveau' => 'L2', 'programme_id' => $c['programme']->id]);
        $premier->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id, 'niveau' => 'L2', 'statut' => 'en_cours',
        ]);
        $second = $this->etudiant(['niveau' => 'L2', 'programme_id' => $c['programme']->id]);
        $second->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id, 'niveau' => 'L2', 'statut' => 'en_cours',
        ]);
        $troisieme = $this->etudiant(['niveau' => 'L2', 'programme_id' => $c['programme']->id]);
        $troisieme->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id, 'niveau' => 'L2', 'statut' => 'en_cours',
        ]);

        $service->affecter($premier, $c['groupe'], $this->annee, $this->admin->id, 'a');
        $service->affecter($second, $c['groupe'], $this->annee, $this->admin->id, 'b');

        // 2/2 : troisième refusée.
        $this->expectException(\DomainException::class);
        $service->affecter($troisieme, $c['groupe'], $this->annee, $this->admin->id, 'c');
    }

    public function test_force_exige_groupes_manage(): void
    {
        $c = $this->chaineLmd();
        $service = app(AffectationService::class);

        foreach (['x', 'y', 'z'] as $suffixe) {
            $e = $this->etudiant(['niveau' => 'L2', 'programme_id' => $c['programme']->id]);
            $e->inscriptionsAcademiques()->create([
                'annee_universitaire_id' => $this->annee->id, 'niveau' => 'L2', 'statut' => 'en_cours',
            ]);
            ${'e_'.$suffixe} = $e;
        }

        $service->affecter($e_x, $c['groupe'], $this->annee, $this->admin->id, 'a');
        $service->affecter($e_y, $c['groupe'], $this->annee, $this->admin->id, 'b');

        $agent = User::creerCompteBackOffice([
            'name' => 'Agent', 'email' => 'ag.pop@universite.test', 'password' => 'Motdepasse@123',
        ], 'agent_scolarite');

        // Agent (sans groupes.manage) : 403 même en force.
        $this->expectException(HttpException::class);
        $service->affecter($e_z, $c['groupe'], $this->annee, $agent->id, 'c', true);
    }

    // ── Suspension / transfert ────────────────────────────────────

    public function test_suspension_bloque_affectation_et_reinscription(): void
    {
        $c = $this->chaineLmd();
        $etudiant = $this->etudiant();

        $this->actingAs($this->admin)->post(
            route('admin.etudiants.suspendre', $etudiant),
            ['date_debut' => now()->toDateString(), 'motif' => 'disciplinaire']
        )->assertRedirect();

        $this->assertTrue($etudiant->fresh()->estSuspendu());

        $this->actingAs($this->admin)->post(
            route('admin.etudiants.affectations.store', $etudiant),
            ['groupe_id' => $c['groupe']->id, 'annee_universitaire_id' => $this->annee->id, 'motif' => 'x']
        )->assertRedirect();

        $this->assertNull($etudiant->fresh()->groupe_id);
        $this->assertSame(0, AffectationGroupe::where('etudiant_id', $etudiant->id)->count());
    }

    public function test_transfert_conserve_dossier(): void
    {
        $c = $this->chaineLmd();
        $etudiant = $this->etudiant(['programme_id' => $c['programme']->id]);

        $programmeB = Programme::create([
            'faculte_id' => $this->faculte->id, 'code' => 'LMI',
            'nom' => 'MIAGE', 'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);

        $this->actingAs($this->admin)->post(
            route('admin.etudiants.transferer', $etudiant),
            ['programme_id' => $programmeB->id, 'motif' => 'réorientation']
        )->assertRedirect();

        $this->assertSame($programmeB->id, $etudiant->fresh()->programme_id);
        $this->assertDatabaseHas('situations_etudiant', [
            'etudiant_id' => $etudiant->id, 'type' => 'transfert', 'nouvelle_valeur' => 'LMI',
        ]);
    }

    // ── Inscriptions ──────────────────────────────────────────────

    public function test_validation_verrouille_et_rejet_motive(): void
    {
        $etudiant = $this->etudiant();
        $inscription = InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id, 'annee_universitaire_id' => $this->annee->id,
            'niveau' => 'L1', 'statut' => 'en_cours',
        ]);

        $this->actingAs($this->admin)->post(
            route('admin.inscriptions.valider', $inscription)
        )->assertRedirect();
        $this->assertSame('valide', $inscription->fresh()->statut);

        // Verrouillée : transition interdite.
        $this->expectException(\DomainException::class);
        app(InscriptionService::class)->transiter($inscription->fresh(), 'en_cours', $this->admin->id);
    }

    public function test_reinscription_nouvelle_annee_sans_ecraser(): void
    {
        $etudiant = $this->etudiant();
        $ancienne = InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id, 'annee_universitaire_id' => $this->annee->id,
            'niveau' => 'L1', 'statut' => 'valide',
        ]);
        $nouvelleAnnee = AnneeUniversitaire::create([
            'libelle' => '2027-2028', 'date_debut' => '2027-09-01',
            'date_fin' => '2028-06-30', 'active' => false,
        ]);

        $this->actingAs($this->admin)->post(
            route('admin.inscriptions.reinscrire', $ancienne),
            ['annee_universitaire_id' => $nouvelleAnnee->id]
        )->assertRedirect();

        $this->assertSame(2, $etudiant->inscriptionsAcademiques()->count());
        $nouvelle = $etudiant->inscriptionsAcademiques()->where('annee_universitaire_id', $nouvelleAnnee->id)->first();
        $this->assertSame('reinscription', $nouvelle->type);
        $this->assertSame('en_cours', $nouvelle->statut);
        $this->assertSame(2, $nouvelle->semestres()->count());
    }

    public function test_inscription_pedagogique_verifie_maquette(): void
    {
        $c = $this->chaineLmd();
        $etudiant = $this->etudiant(['programme_id' => $c['programme']->id]);
        $inscription = InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id, 'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $c['programme']->id, 'niveau' => 'L2', 'statut' => 'valide',
        ]);

        $this->actingAs($this->admin)->post(
            route('admin.inscriptions.ecues.store', $inscription),
            ['matiere_id' => $c['matiere']->id]
        )->assertRedirect();

        $this->assertDatabaseHas('inscriptions_pedagogiques', [
            'inscription_id' => $inscription->id, 'matiere_id' => $c['matiere']->id,
        ]);

        // Hors maquette : refusé.
        $horsMaquette = Matiere::create([
            'code' => 'HX101', 'nom' => 'HX', 'niveau' => 'L9', 'semestre' => 9,
        ]);
        $this->actingAs($this->admin)->post(
            route('admin.inscriptions.ecues.store', $inscription),
            ['matiere_id' => $horsMaquette->id]
        )->assertRedirect();
        $this->assertSame(0, InscriptionPedagogique::where('matiere_id', $horsMaquette->id)->count());
    }

    // ── Personnel ─────────────────────────────────────────────────

    public function test_personnel_crud_super_admin(): void
    {
        $super = User::creerCompteBackOffice([
            'name' => 'Super', 'email' => 'su.pop@universite.test', 'password' => 'Motdepasse@123',
        ], 'super_admin');
        $compte = User::creerCompteBackOffice([
            'name' => 'Agent', 'email' => 'ag2.pop@universite.test', 'password' => 'Motdepasse@123',
        ], 'agent_scolarite');

        $this->actingAs($super)->post(route('admin.personnel.store'), [
            'user_id' => $compte->id, 'matricule' => 'P-001', 'statut' => 'actif',
        ])->assertRedirect();

        $personnel = Personnel::where('matricule', 'P-001')->first();
        $this->assertNotNull($personnel);

        // Non super-admin : refusé.
        $this->actingAs($this->admin)->get(route('admin.personnel.index'))->assertForbidden();
    }

    // ── Fiches ────────────────────────────────────────────────────

    public function test_fiche_enseignant_et_groupe(): void
    {
        $c = $this->chaineLmd();
        $enseignant = Teacher::create(['nom' => 'Benali', 'prenom' => 'Sara', 'matricule' => 'ENS-1']);

        $this->actingAs($this->admin)->get(route('admin.enseignants.show', $enseignant))
            ->assertOk()->assertSee('Sara Benali', false);
        $this->actingAs($this->admin)->get(route('admin.groupes.show', $c['groupe']))
            ->assertOk()->assertSee('GL2-A', false);
    }

    public function test_pages_stats_qualite(): void
    {
        $this->actingAs($this->admin)->get(route('admin.population.stats'))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.population.qualite'))->assertOk();
    }

    // ── Import ────────────────────────────────────────────────────

    public function test_import_etudiants_avec_rattachement(): void
    {
        $c = $this->chaineLmd();

        $csv = 'cin;nom;prenom;email;sexe;faculte;niveau;annee_academique;adresse;ville;code_postal;annee_bac;programme;groupe'."\n"
            .'IMP0001;Ba;Awa;awa.pop@example.com;F;FST;L2;2026-2027;Rue 1;Dakar;10000;2024;LGL;GL2-A';

        // Via l'orchestrateur legacy (preview → confirmation).
        $bearer = $this->token(['imports:create', 'students:write']);

        $reponse = $this->post('/api/v1/imports', [
            'type' => 'students',
            'file' => UploadedFile::fake()->createWithContent('etudiants.csv', $csv),
        ], ['Authorization' => 'Bearer '.$bearer])
            ->assertStatus(202);

        $this->assertSame(1, $reponse->json('report.valides'));

        $reportId = $reponse->json('report.id');
        $this->post('/api/v1/imports/'.$reportId.'/confirm', ['mode' => 'atomique'], [
            'Authorization' => 'Bearer '.$bearer,
        ])->assertOk();

        $etudiant = Etudiant::where('cin', 'IMP0001')->first();
        $this->assertNotNull($etudiant);
        $this->assertSame($c['programme']->id, $etudiant->programme_id);
        $this->assertSame($c['groupe']->id, $etudiant->groupe_id);
    }

    public function test_import_etudiants_reference_inconnue_erreur(): void
    {
        $csv = 'cin;nom;prenom;email;sexe;faculte;niveau;annee_academique;adresse;ville;code_postal;annee_bac;programme'."\n"
            .'IMP0002;Ba;Awa;awa2.pop@example.com;F;FST;L1;2026-2027;Rue 1;Dakar;10000;2024;INCONNU';

        $reponse = $this->post('/api/v1/imports', [
            'type' => 'students',
            'file' => UploadedFile::fake()->createWithContent('etudiants.csv', $csv),
        ], ['Authorization' => 'Bearer '.$this->token(['imports:create', 'students:write'])])
            ->assertStatus(202);

        $this->assertSame(0, $reponse->json('report.valides'));
        $this->assertSame(0, Etudiant::where('cin', 'IMP0002')->count());
    }

    public function test_import_enseignants_grade_et_groupes_programme(): void
    {
        GradeEnseignant::create(['code' => 'MA', 'nom' => 'Maître-assistant']);

        $csvEns = "nom;prenom;email;matricule;grade\nBenali;Sara;sara.pop@example.com;ENS-9;MA\n";
        $preview = $this->actingAs($this->admin)->post(
            route('admin.donnees.import.preview', 'enseignants'),
            ['fichier' => UploadedFile::fake()->createWithContent('e.csv', $csvEns, 'text/csv')]
        )->assertOk();

        $this->actingAs($this->admin)->post(
            route('admin.donnees.import.confirmer', 'enseignants'),
            ['token' => $preview->viewData('token'), 'mode' => 'creer']
        )->assertOk();

        $enseignant = Teacher::where('email', 'sara.pop@example.com')->first();
        $this->assertNotNull($enseignant?->grade_id);

        $csvGrp = "code;nom;faculte;niveau;programme;capacite\nGL9;G9;FST;L1;LGL;25\n";
        // Programme LGL inexistant ici (chaîne non créée) → erreur, rollback.
        $preview = $this->actingAs($this->admin)->post(
            route('admin.donnees.import.preview', 'groupes'),
            ['fichier' => UploadedFile::fake()->createWithContent('g.csv', $csvGrp, 'text/csv')]
        )->assertOk();

        $this->actingAs($this->admin)->post(
            route('admin.donnees.import.confirmer', 'groupes'),
            ['token' => $preview->viewData('token'), 'mode' => 'creer']
        )->assertRedirect();

        $this->assertSame(0, Group::where('code', 'GL9')->count());
    }

    public function test_idor_affectation_hors_perimetre(): void
    {
        $c = $this->chaineLmd();
        $etudiant = $this->etudiant();
        $adminA = User::creerCompteBackOffice([
            'name' => 'Admin A', 'email' => 'aa2.pop@universite.test',
            'password' => 'Motdepasse@123', 'faculte_id' => $this->faculte->id,
        ], 'admin_faculte');

        $faculteB = Faculte::create(['nom' => 'FB', 'code' => 'FB', 'description' => 'B', 'active' => true]);
        $groupeB = Group::create([
            'code' => 'GB-1', 'nom' => 'GB', 'faculte_id' => $faculteB->id, 'niveau' => 'L1', 'actif' => true,
        ]);

        $this->actingAs($adminA)->post(
            route('admin.etudiants.affectations.store', $etudiant),
            ['groupe_id' => $groupeB->id, 'annee_universitaire_id' => $this->annee->id, 'motif' => 'x']
        )->assertForbidden();
    }

    private function token(array $scopes): string
    {
        $client = User::creerCompteBackOffice([
            'name' => 'Client', 'email' => uniqid('cl.', true).'@integration.systeme',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        return $client->createToken('t', $scopes)->plainTextToken;
    }
}
