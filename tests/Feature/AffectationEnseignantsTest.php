<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Services\EpreuveService;
use App\Modules\Evaluation\Services\SurveillanceService;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOOP SIDEBAR AFFECTATION — page lecture seule réutilisant les flux
 * existants (aucune route d'écriture, aucun moteur) : navigation,
 * KPIs, filtres, statuts de couverture, gates d'actions, scope, N+1.
 */
class AffectationEnseignantsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Matiere $ecue;

    private Group $groupe;

    private SessionExamen $session;

    private int $compteur = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.evaluation.active' => true]);

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.affect@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);

        $programme = Programme::create([
            'faculte_id' => $this->faculte->id, 'code' => 'LGL', 'nom' => 'Licence GL',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $niveau = Niveau::create(['programme_id' => $programme->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1]);
        $semestre = SemestreLmd::create(['niveau_id' => $niveau->id, 'code' => 'S1', 'nom' => 'S1', 'numero' => 1, 'ordre' => 1]);
        $ue = UniteEnseignement::create([
            'semestre_id' => $semestre->id, 'programme_id' => $programme->id,
            'code' => 'UE01', 'nom' => 'Algo', 'credits' => 6, 'coefficient' => 2,
        ]);
        $this->ecue = Matiere::create([
            'code' => 'A101', 'nom' => 'Algorithmique', 'ue_id' => $ue->id,
            'niveau' => 'L1', 'semestre' => 1, 'credits' => 6, 'coefficient' => 2,
        ]);
        $this->groupe = Group::create([
            'code' => 'G1', 'nom' => 'Groupe 1', 'faculte_id' => $this->faculte->id, 'niveau' => 'L1', 'actif' => true,
        ]);
        Room::create(['code' => 'B12', 'nom' => 'B12', 'capacite' => 40, 'actif' => true, 'faculte_id' => $this->faculte->id]);

        $this->session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id, 'code' => 'S-AFF',
            'nom' => 'Principale', 'type' => 'principale',
            'date_debut' => '2027-01-05', 'date_fin' => '2027-06-30', 'statut' => 'open', 'created_by' => $this->admin->id,
        ]);
    }

    private function creerExamen(): Examen
    {
        return Examen::create([
            'session_id' => $this->session->id, 'annee_universitaire_id' => $this->annee->id,
            'libelle' => 'Examen S1', 'type' => 'examen', 'statut' => 'scheduled', 'responsable_id' => $this->admin->id,
        ]);
    }

    private function creerEpreuve(array $attributs = []): Epreuve
    {
        $this->compteur++;

        return app(EpreuveService::class)->creer($this->creerExamen(), array_merge([
            'session_id' => $this->session->id, 'matiere_id' => $this->ecue->id,
            'groupe_id' => $this->groupe->id,
            'date' => '2027-03-'.sprintf('%02d', $this->compteur),
            'heure_debut' => '09:00', 'heure_fin' => '11:00',
        ], $attributs));
    }

    private function creerEnseignant(string $suffixe): Teacher
    {
        return Teacher::create([
            'matricule' => 'ENS-A'.$suffixe, 'nom' => 'Nom'.$suffixe, 'prenom' => 'Prenom'.$suffixe,
            'email' => "aff{$suffixe}@universite.test", 'actif' => true,
        ]);
    }

    public function test_navigation_visible_avec_permission(): void
    {
        $this->actingAs($this->admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Affectation enseignants');
    }

    public function test_navigation_masquee_module_desactive(): void
    {
        config(['modules.evaluation.active' => false]);

        $this->actingAs($this->admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee('Affectation enseignants');
    }

    public function test_etat_actif_sur_la_page(): void
    {
        $reponse = $this->actingAs($this->admin)->get(route('admin.evaluations.affectations.index'));
        $reponse->assertOk();
        $reponse->assertSee('aria-current="page"', false);
        $reponse->assertSee('Affectation des enseignants');
    }

    public function test_page_kpis_et_statuts(): void
    {
        $epreuveComplete = $this->creerEpreuve();
        $epreuveVide = $this->creerEpreuve();
        $t1 = $this->creerEnseignant('K1');
        $t2 = $this->creerEnseignant('K2');
        $t3 = $this->creerEnseignant('K3');

        $service = app(SurveillanceService::class);
        $service->assigner($epreuveComplete, ['teacher_id' => $t1->id, 'role' => 'principal']);
        $service->assigner($epreuveComplete, ['teacher_id' => $t2->id, 'role' => 'adjoint']);
        $service->assigner($epreuveComplete, ['teacher_id' => $t3->id, 'role' => 'reserve']);

        $reponse = $this->actingAs($this->admin)->get(route('admin.evaluations.affectations.index'));
        $reponse->assertOk();
        $reponse->assertSee('Examens');
        $reponse->assertSee('Sans surveillant');
        $reponse->assertSee('Enseignants disponibles');
        $reponse->assertSee('Affectations');
        $reponse->assertSee('Affecté');
        $reponse->assertSee('Non affecté');
        $reponse->assertSee('Principal');
    }

    public function test_filtres_restreignent(): void
    {
        $this->creerEpreuve(['code' => 'EPR-FILTRE-A']);
        $this->creerEpreuve(['code' => 'EPR-FILTRE-B']);

        // Recherche globale.
        $this->actingAs($this->admin)
            ->get(route('admin.evaluations.affectations.index', ['recherche' => 'FILTRE-A']))
            ->assertOk()
            ->assertSee('EPR-FILTRE-A')
            ->assertDontSee('EPR-FILTRE-B');

        // Filtre enseignant (via gardes actives).
        $enseignant = $this->creerEnseignant('FLT');
        $epreuve = Epreuve::where('code', 'EPR-FILTRE-B')->firstOrFail();
        app(SurveillanceService::class)->assigner($epreuve, ['teacher_id' => $enseignant->id]);

        $this->actingAs($this->admin)
            ->get(route('admin.evaluations.affectations.index', ['teacher_id' => $enseignant->id]))
            ->assertOk()
            ->assertSee('EPR-FILTRE-B')
            ->assertDontSee('EPR-FILTRE-A');

        // Filtre couverture : sans surveillant.
        $this->actingAs($this->admin)
            ->get(route('admin.evaluations.affectations.index', ['couverture' => 'sans_surveillant']))
            ->assertOk()
            ->assertSee('EPR-FILTRE-A')
            ->assertDontSee('EPR-FILTRE-B');
    }

    public function test_scope_faculte_isole(): void
    {
        $this->creerEpreuve(['code' => 'EPR-FST-X']);
        $autreFaculte = Faculte::create(['nom' => 'FSJES', 'code' => 'FSJES', 'description' => 'T', 'active' => true]);
        $adminRestreint = User::creerCompteBackOffice([
            'name' => 'Fac', 'email' => 'fac.affect@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $autreFaculte->id,
        ], 'admin_faculte');

        $this->actingAs($adminRestreint)->get(route('admin.evaluations.affectations.index'))
            ->assertOk()
            ->assertDontSee('EPR-FST-X');
    }

    public function test_liens_action_gates(): void
    {
        $epreuve = $this->creerEpreuve();
        $agent = User::creerCompteBackOffice([
            'name' => 'Agent', 'email' => 'agent.affect@universite.test', 'password' => 'Motdepasse@123',
        ], 'agent_scolarite');

        // Sans surveillances.assign : Détails oui, Affecter non.
        $this->actingAs($agent)->get(route('admin.evaluations.affectations.index'))
            ->assertOk()
            ->assertSee('Détails')
            ->assertDontSee('>Affecter<', false);

        $this->actingAs($this->admin)->get(route('admin.evaluations.affectations.index'))
            ->assertOk()
            ->assertSee('>Affecter<', false);
    }

    public function test_sans_n_plus_un(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->creerEpreuve();
        }

        \DB::enableQueryLog();
        $this->actingAs($this->admin)->get(route('admin.evaluations.affectations.index'))->assertOk();
        $requetes = count(\DB::getQueryLog());
        \DB::disableQueryLog();

        $this->assertLessThan(50, $requetes, "Page affectations : {$requetes} requêtes (N+1 interdit).");
    }
}
