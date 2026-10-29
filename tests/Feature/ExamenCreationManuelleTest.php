<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Services\SessionPreparationService;
use App\Modules\Referentiel\Models\Maquette;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActAsScolarite;
use Tests\TestCase;

class ExamenCreationManuelleTest extends TestCase
{
    use ActAsScolarite, RefreshDatabase;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private SessionExamen $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences',
            'code' => 'FST-TEST',
            'active' => true,
        ]);

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $admin = $this->scolarite('super_admin');

        $this->session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'S-TEST-01',
            'nom' => 'Session Principale Janvier',
            'type' => 'principale',
            'date_debut' => '2027-01-05',
            'date_fin' => '2027-01-20',
            'statut' => 'open',
            'created_by' => $admin->id,
        ]);
    }

    public function test_utilisateur_autorise_voit_le_bouton_nouvel_examen_et_les_sessions(): void
    {
        $this->scolarite('admin_scolarite');

        $response = $this->get(route('admin.evaluations.examens.index'));

        $response->assertOk();
        $response->assertSee('Nouvel examen');
        $response->assertSee('Session Principale Janvier');
        $response->assertSee('form-creer-examen');
    }

    public function test_lien_examens_affiche_dans_la_sidebar_pour_utilisateur_autorise(): void
    {
        $this->scolarite('admin_scolarite');

        $response = $this->get(route('admin.dashboard'));
        $response->assertOk();
        $response->assertSee(route('admin.evaluations.examens.index'));
        $response->assertSee('Examens');

        $suivi = $this->get(route('admin.evaluations.examens.index'));
        $suivi->assertOk();
        $suivi->assertSee(route('admin.evaluations.examens.index'));
        $suivi->assertSee('admin-nav-lien-actif');
    }

    public function test_utilisateur_non_authentifie_est_redirige_sur_index(): void
    {
        auth()->logout();

        $response = $this->get(route('admin.evaluations.examens.index'));
        $response->assertRedirect();
    }

    public function test_utilisateur_non_authentifie_est_redirige_sur_store(): void
    {
        auth()->logout();

        $response = $this->post(route('admin.evaluations.examens.store'), [
            'session_id' => $this->session->id,
            'libelle' => 'Examen Anonyme',
            'type' => 'examen',
        ]);
        $response->assertRedirect();
        $this->assertSame(0, Examen::count());
    }

    public function test_utilisateur_non_autorise_ne_voit_pas_le_bouton_nouvel_examen(): void
    {
        // agent_scolarite a 'evaluations.view' mais pas 'evaluations.create'
        $this->scolarite('agent_scolarite');

        $response = $this->get(route('admin.evaluations.examens.index'));

        $response->assertOk();
        $response->assertDontSee('form-creer-examen');
        $response->assertDontSee('Créer un nouvel examen');
    }

    public function test_utilisateur_non_autorise_recout_403_sur_la_route_store(): void
    {
        $this->scolarite('agent_scolarite');

        $response = $this->post(route('admin.evaluations.examens.store'), [
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'libelle' => 'Examen Non Autorisé',
            'type' => 'examen',
        ]);

        $response->assertForbidden();
        $this->assertSame(0, Examen::count());
    }

    public function test_creation_examen_reussie_avec_donnees_valides(): void
    {
        $admin = $this->scolarite('super_admin');

        $programme = Programme::create([
            'faculte_id' => $this->faculte->id,
            'code' => 'INFO-L1',
            'nom' => 'Licence Informatique',
            'actif' => true,
        ]);

        $groupe = Group::create([
            'faculte_id' => $this->faculte->id,
            'code' => 'GRP-A',
            'nom' => 'Groupe A',
            'niveau' => 'L1',
            'actif' => true,
        ]);

        $donnees = [
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'libelle' => 'Examen Algorithmique et Structures',
            'type' => 'partiel',
            'programme_id' => $programme->id,
            'groupe_id' => $groupe->id,
            'description' => 'Calculatrices non autorisées.',
            'observations' => 'Surveillance renforcée.',
        ];

        $response = $this->post(route('admin.evaluations.examens.store'), $donnees);

        $response->assertRedirect(route('admin.evaluations.examens.index'));
        $response->assertSessionHas('succes', 'Examen « Examen Algorithmique et Structures » créé.');

        $this->assertDatabaseHas('evaluation_examens', [
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'libelle' => 'Examen Algorithmique et Structures',
            'type' => 'partiel',
            'statut' => 'draft',
            'programme_id' => $programme->id,
            'groupe_id' => $groupe->id,
            'responsable_id' => $admin->id,
            'description' => 'Calculatrices non autorisées.',
        ]);

        // Vérifier l'affichage sur la page d'index après redirection
        $suivi = $this->get(route('admin.evaluations.examens.index'));
        $suivi->assertOk();
        $suivi->assertSee('Examen Algorithmique et Structures');
        $suivi->assertSee('Session Principale Janvier');
        $suivi->assertSee('partiel');
        $suivi->assertSee('Brouillon');
    }

    public function test_creation_examen_deduit_annee_universitaire_de_la_session_si_omise(): void
    {
        $this->scolarite('super_admin');

        $response = $this->post(route('admin.evaluations.examens.store'), [
            'session_id' => $this->session->id,
            'libelle' => 'Examen Sans Année Explicite',
            'type' => 'examen',
        ]);

        $response->assertRedirect(route('admin.evaluations.examens.index'));
        $this->assertDatabaseHas('evaluation_examens', [
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->session->annee_universitaire_id,
            'libelle' => 'Examen Sans Année Explicite',
        ]);
    }

    public function test_rejet_champs_requis_manquants(): void
    {
        $this->scolarite('super_admin');

        $response = $this->from(route('admin.evaluations.examens.index'))
            ->post(route('admin.evaluations.examens.store'), []);

        $response->assertRedirect(route('admin.evaluations.examens.index'));
        $response->assertSessionHasErrors(['session_id', 'libelle', 'type']);
        $this->assertSame(0, Examen::count());
    }

    public function test_rejet_type_invalide(): void
    {
        $this->scolarite('super_admin');

        $response = $this->from(route('admin.evaluations.examens.index'))
            ->post(route('admin.evaluations.examens.store'), [
                'session_id' => $this->session->id,
                'libelle' => 'Examen Type Inconnu',
                'type' => 'type_inexistant',
            ]);

        $response->assertRedirect(route('admin.evaluations.examens.index'));
        $response->assertSessionHasErrors(['type']);
        $this->assertSame(0, Examen::count());
    }

    public function test_rejet_session_inexistante(): void
    {
        $this->scolarite('super_admin');

        $response = $this->from(route('admin.evaluations.examens.index'))
            ->post(route('admin.evaluations.examens.store'), [
                'session_id' => 999999,
                'annee_universitaire_id' => $this->annee->id,
                'libelle' => 'Examen Session Inconnue',
                'type' => 'examen',
            ]);

        $response->assertRedirect(route('admin.evaluations.examens.index'));
        $response->assertSessionHasErrors(['session_id']);
        $this->assertSame(0, Examen::count());
    }

    public function test_rejet_session_verrouillee(): void
    {
        $admin = $this->scolarite('super_admin');

        $sessionVerrouillee = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'S-LOCKED-01',
            'nom' => 'Session Verrouillée',
            'type' => 'principale',
            'date_debut' => '2027-01-05',
            'date_fin' => '2027-01-20',
            'statut' => 'locked',
            'verrouillee_at' => now(),
            'created_by' => $admin->id,
        ]);

        $response = $this->from(route('admin.evaluations.examens.index'))
            ->post(route('admin.evaluations.examens.store'), [
                'session_id' => $sessionVerrouillee->id,
                'libelle' => 'Examen Sous Session Clôturée',
                'type' => 'examen',
            ]);

        $response->assertRedirect(route('admin.evaluations.examens.index'));
        $response->assertSessionHasErrors(['session_id']);
        $this->assertSame(0, Examen::count());
    }

    public function test_rejet_session_archivee(): void
    {
        $admin = $this->scolarite('super_admin');

        $sessionArchivee = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'S-ARCH-01',
            'nom' => 'Session Archivée',
            'type' => 'principale',
            'date_debut' => '2026-01-05',
            'date_fin' => '2026-01-20',
            'statut' => 'archived',
            'created_by' => $admin->id,
        ]);

        $response = $this->from(route('admin.evaluations.examens.index'))
            ->post(route('admin.evaluations.examens.store'), [
                'session_id' => $sessionArchivee->id,
                'libelle' => 'Examen Sous Session Archivée',
                'type' => 'examen',
            ]);

        $response->assertRedirect(route('admin.evaluations.examens.index'));
        $response->assertSessionHasErrors(['session_id']);
        $this->assertSame(0, Examen::count());
    }

    public function test_rejet_annee_universitaire_incoherente_avec_la_session(): void
    {
        $this->scolarite('super_admin');

        $autreAnnee = AnneeUniversitaire::create([
            'libelle' => '2025-2026',
            'date_debut' => '2025-09-01',
            'date_fin' => '2026-06-30',
            'active' => false,
        ]);

        $response = $this->from(route('admin.evaluations.examens.index'))
            ->post(route('admin.evaluations.examens.store'), [
                'session_id' => $this->session->id,
                'annee_universitaire_id' => $autreAnnee->id,
                'libelle' => 'Examen Année Incohérente',
                'type' => 'examen',
            ]);

        $response->assertRedirect(route('admin.evaluations.examens.index'));
        $response->assertSessionHasErrors(['annee_universitaire_id']);
        $this->assertSame(0, Examen::count());
    }

    public function test_rejet_programme_autre_faculte_protection_idor(): void
    {
        $autreFaculte = Faculte::create([
            'nom' => 'Faculté de Droit',
            'code' => 'FD-TEST',
            'active' => true,
        ]);

        $programmeAutre = Programme::create([
            'faculte_id' => $autreFaculte->id,
            'code' => 'DROIT-L1',
            'nom' => 'Licence Droit',
            'actif' => true,
        ]);

        // Utilisateur restreint à la faculté des sciences
        $adminFaculte = User::creerCompteBackOffice([
            'name' => 'Admin Sciences',
            'email' => 'admin.sciences@test.ma',
            'password' => bcrypt('Secret@123'),
            'faculte_id' => $this->faculte->id,
        ], 'admin_faculte');

        $this->actingAs($adminFaculte);

        $response = $this->post(route('admin.evaluations.examens.store'), [
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $programmeAutre->id,
            'libelle' => 'Examen Pirate Cross-Faculté',
            'type' => 'examen',
        ]);

        $response->assertNotFound();
        $this->assertSame(0, Examen::count());
    }

    public function test_conservation_des_donnees_saisies_en_cas_d_erreur(): void
    {
        $this->scolarite('super_admin');

        $response = $this->from(route('admin.evaluations.examens.index'))
            ->post(route('admin.evaluations.examens.store'), [
                'session_id' => $this->session->id,
                'libelle' => 'Libellé Conservé En Session',
                'description' => 'Description Conservée',
                'type' => 'type_invalide',
            ]);

        $response->assertRedirect(route('admin.evaluations.examens.index'));
        $response->assertSessionHasErrors(['type']);
        $response->assertSessionHasInput('libelle', 'Libellé Conservé En Session');
        $response->assertSessionHasInput('description', 'Description Conservée');
    }

    public function test_absence_regression_liste_examens_et_transitions_statut(): void
    {
        $admin = $this->scolarite('super_admin');

        $examen = Examen::create([
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'libelle' => 'Examen Transition Test',
            'type' => 'examen',
            'statut' => 'draft',
            'responsable_id' => $admin->id,
        ]);

        // Transition draft -> planned
        $response = $this->post(route('admin.evaluations.examens.transiter', [$examen, 'planned']));

        $response->assertRedirect();
        $response->assertSessionHas('succes', 'Examen passé à « planned ».');
        $this->assertSame('planned', $examen->fresh()->statut);
    }

    public function test_absence_regression_generation_automatique_epreuves_session(): void
    {
        $admin = $this->scolarite('super_admin');

        $programme = Programme::create([
            'faculte_id' => $this->faculte->id,
            'code' => 'INFO-LMD',
            'nom' => 'Informatique LMD',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
            'actif' => true,
        ]);

        $niveau = Niveau::create([
            'programme_id' => $programme->id,
            'code' => 'L1',
            'nom' => 'Licence 1',
            'ordre' => 1,
        ]);

        $semestre = SemestreLmd::create([
            'niveau_id' => $niveau->id,
            'code' => 'S1',
            'nom' => 'Semestre 1',
            'numero' => 1,
            'ordre' => 1,
        ]);

        $ue = UniteEnseignement::create([
            'programme_id' => $programme->id,
            'semestre_id' => $semestre->id,
            'code' => 'UE-FOND',
            'nom' => 'Fondamentaux',
            'credits' => 12,
            'coefficient' => 2,
        ]);

        $maquette = Maquette::create([
            'programme_id' => $programme->id,
            'annee_universitaire_id' => $this->annee->id,
            'version' => 1,
            'statut' => 'active',
        ]);
        $maquette->unitesEnseignement()->attach($ue->id, ['semestre_id' => $semestre->id]);

        Matiere::create([
            'ue_id' => $ue->id,
            'code' => 'ECUE-ALGO',
            'nom' => 'Algorithmique',
            'niveau' => 'L1',
            'semestre' => 1,
            'credits' => 6,
            'coefficient' => 1,
            'actif' => true,
        ]);

        Group::create([
            'faculte_id' => $this->faculte->id,
            'programme_id' => $programme->id,
            'code' => 'GRP-L1',
            'nom' => 'Groupe L1',
            'niveau' => 'L1',
            'actif' => true,
        ]);

        $service = app(SessionPreparationService::class);
        $resultat = $service->genererEpreuves($this->session, $programme->id, 'L1', '2027-01-08');

        $this->assertGreaterThan(0, $resultat['creees']);
        $this->assertDatabaseHas('evaluation_epreuves', [
            'session_id' => $this->session->id,
        ]);
    }
}
