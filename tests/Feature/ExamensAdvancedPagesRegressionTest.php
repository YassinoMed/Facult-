<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Models\Surveillance;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Non-régression des deux pages Examens Advanced qui renvoyaient une 500
 * (relation `teacher` inexistante côté surveillance, colonne `faculte_id`
 * inexistante sur `teachers` côté planification).
 */
class ExamensAdvancedPagesRegressionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private AnneeUniversitaire $annee;

    private SessionExamen $session;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'modules.evaluation.active' => true,
            'modules.schedule.active' => true,
        ]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Admin Examens',
            'email' => 'admin.examens.'.uniqid().'@universite.test',
            'password' => 'Motdepasse@123',
            'actif' => true,
        ], 'super_admin');

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'SESS-REG-'.substr(uniqid(), -6),
            'nom' => 'Session de régression',
            'type' => 'principale',
            'date_debut' => now()->addDays(7)->toDateString(),
            'date_fin' => now()->addDays(21)->toDateString(),
            'statut' => 'open',
        ]);
    }

    private function creerMatiere(): Matiere
    {
        return Matiere::create([
            'code' => 'MAT_REG_'.substr(uniqid(), -6),
            'nom' => 'Matière de régression',
            'niveau' => 'L3',
            'semestre' => 5,
        ]);
    }

    private function creerEpreuve(Matiere $matiere): Epreuve
    {
        return Epreuve::create([
            'code' => 'EPR_'.strtoupper(substr(uniqid(), -7)),
            'examen_id' => $this->creerExamen()->id,
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'matiere_id' => $matiere->id,
            'date' => now()->addDays(10)->toDateString(),
            'heure_debut' => '08:30:00',
            'heure_fin' => '10:30:00',
            'capacite_necessaire' => 30,
            'statut' => 'draft',
        ]);
    }

    private function creerExamen(?Programme $programme = null, ?Group $groupe = null): Examen
    {
        return Examen::create([
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $programme?->id,
            'groupe_id' => $groupe?->id,
            'libelle' => 'Examens de régression L3',
            'type' => 'examen',
            'statut' => 'draft',
            'responsable_id' => $this->admin->id,
        ]);
    }

    public function test_la_page_surveillance_affiche_le_surveillant_sans_erreur(): void
    {
        $teacher = Teacher::create([
            'nom' => 'Benali',
            'prenom' => 'Nadia',
            'email' => 'nadia.benali.'.uniqid().'@universite.test',
            'actif' => true,
        ]);

        $sansEnseignant = Surveillance::create([
            'epreuve_id' => $this->creerEpreuve($this->creerMatiere())->id,
            'role' => 'surveillant',
            'statut' => 'assigne',
        ]);

        Surveillance::create([
            'epreuve_id' => $sansEnseignant->epreuve_id,
            'teacher_id' => $teacher->id,
            'role' => 'principal',
            'statut' => 'assigne',
        ]);

        $reponse = $this->actingAs($this->admin)->get(route('admin.evaluation.examens.surveillance'));

        $reponse->assertOk();
        $reponse->assertSee('Nadia Benali');
        $reponse->assertSee('Dernières affectations de surveillance');
    }

    public function test_la_page_planification_genere_des_scenarios_pour_un_examen(): void
    {
        $faculte = Faculte::create([
            'nom' => 'Faculté de régression',
            'code' => 'FREG_'.uniqid(),
            'actif' => true,
        ]);

        $programme = Programme::create([
            'faculte_id' => $faculte->id,
            'code' => 'PREG_'.uniqid(),
            'nom' => 'Licence de régression',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
        ]);

        $groupe = Group::create([
            'nom' => 'Groupe régression',
            'code' => 'GREG_'.uniqid(),
            'niveau' => 'L3',
            'capacite' => 30,
            'faculte_id' => $faculte->id,
            'actif' => true,
        ]);

        Room::create([
            'code' => 'AMPHI_REG_'.substr(uniqid(), -4),
            'nom' => 'Amphi de régression',
            'capacite' => 100,
            'min_surveillants' => 1,
            'faculte_id' => $faculte->id,
            'actif' => true,
        ]);

        // Enseignant transversal (sans département) : inclus par le filtre
        // facultaire du moteur de scénarios.
        Teacher::create([
            'nom' => 'Transversal',
            'prenom' => 'Enseignant',
            'email' => 'transversal.'.uniqid().'@universite.test',
            'actif' => true,
        ]);

        $examen = $this->creerExamen($programme, $groupe);

        Epreuve::create([
            'examen_id' => $examen->id,
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'matiere_id' => $this->creerMatiere()->id,
            'groupe_id' => $groupe->id,
            'date' => now()->addDays(10)->toDateString(),
            'heure_debut' => '08:30:00',
            'heure_fin' => '10:30:00',
            'capacite_necessaire' => 30,
            'statut' => 'draft',
        ]);

        $reponse = $this->actingAs($this->admin)
            ->get(route('admin.evaluation.examens.planification', ['examen_id' => $examen->id]));

        $reponse->assertOk();
        $reponse->assertSee('Scénarios calculés pour Examens de régression L3');
        $reponse->assertSee('Scénario A');
        $reponse->assertSee('Scénario B');
        $reponse->assertSee('Scénario C');
        $reponse->assertDontSee('Aucun scénario calculable');
    }

    public function test_la_page_publication_affiche_le_style_enrichi_et_les_filtres(): void
    {
        $examen = $this->creerExamen();
        $examen->update(['statut' => 'scheduled']);

        $reponse = $this->actingAs($this->admin)->get(route('admin.evaluation.examens.publication'));

        $reponse->assertOk();
        $reponse->assertSee('Validation et publication officielle');
        $reponse->assertSee('En attente de publication');
        $reponse->assertSee('Officiellement publiés');
        $reponse->assertSee('Règle D-05');
        // La publication directe a disparu : le circuit de gouvernance est
        // la seule voie, même pour un administrateur habilité.
        $reponse->assertSee('Soumettre au circuit');
        $reponse->assertDontSee('Publier officiellement');
        $reponse->assertSee('Examens de régression L3');
    }

    public function test_la_page_convocations_affiche_le_style_enrichi_et_les_filtres(): void
    {
        $epreuve = $this->creerEpreuve($this->creerMatiere());
        $epreuve->update(['statut' => 'published']);

        $reponse = $this->actingAs($this->admin)->get(route('admin.evaluation.examens.convocations'));

        $reponse->assertOk();
        $reponse->assertSee('Convocations aux examens');
        $reponse->assertSee('Épreuves publiées');
        $reponse->assertSee('Candidats convoqués');
        $reponse->assertSee('Salles mobilisées');
        $reponse->assertSee('Documents générés');
        $reponse->assertSee('Interdiction formelle pré-publication');
        $reponse->assertSee('Émargement');
        $reponse->assertSee('Convocations');
    }
}
