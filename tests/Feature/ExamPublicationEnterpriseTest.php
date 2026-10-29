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
use App\Modules\Evaluation\Services\ExamPublicationOrchestratorService;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Teacher;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Suite de tests Enterprise pour EA-EXAMENS-06 — Validation, Publication & Scellement (Menu 10).
 *
 * Valide :
 *  - Permissions d'accès et redirections sécurisées
 *  - Calcul des KPI d'audit de publication (prêts, publiés, taux)
 *  - Diagnostic de pré-publication (détection d'anomalies bloquantes vs conformité)
 *  - Empreinte cryptographique SHA-256 déterministe et immuable
 *  - Publication officielle avec scellement et mise à jour synchrone des épreuves
 *  - Rejet bloquant strict si violation des contraintes dures
 *  - Cloisonnement facultaire et garantie zéro écriture sur les requêtes GET
 *  - Rendu complet de la vue Blade
 */
class ExamPublicationEnterpriseTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $adminFaculteA;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private AnneeUniversitaire $annee;

    private Programme $programmeA;

    private Programme $programmeB;

    private Matiere $matiere;

    private Room $salleConforme;

    private SessionExamen $session;

    private Examen $examenConforme;

    private Examen $examenNonConforme;

    private Teacher $prof1;

    private Teacher $prof2;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.evaluation.active' => true]);

        $this->faculteA = Faculte::create([
            'nom' => 'Faculté des Sciences Informatiques',
            'code' => 'FSI',
            'active' => true,
        ]);

        $this->faculteB = Faculte::create([
            'nom' => 'Faculté des Lettres',
            'code' => 'FL',
            'active' => true,
        ]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Directeur Examens Central',
            'email' => 'dir.exam.'.uniqid().'@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->adminFaculteA = User::creerCompteBackOffice([
            'name' => 'Admin Faculté Sciences',
            'email' => 'admin.fsi.'.uniqid().'@universite.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteA->id,
        ], 'admin_faculte');

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->programmeA = Programme::create([
            'faculte_id' => $this->faculteA->id,
            'code' => 'L_INFO',
            'nom' => 'Licence Informatique',
            'type' => 'licence',
            'duree_annees' => 3,
            'statut' => 'actif',
        ]);

        $this->programmeB = Programme::create([
            'faculte_id' => $this->faculteB->id,
            'code' => 'L_LETTRES',
            'nom' => 'Licence Lettres',
            'type' => 'licence',
            'duree_annees' => 3,
            'statut' => 'actif',
        ]);

        $niveau = Niveau::create([
            'programme_id' => $this->programmeA->id,
            'code' => 'L3',
            'nom' => 'Licence 3',
            'ordre' => 3,
        ]);

        $semestre = SemestreLmd::create([
            'niveau_id' => $niveau->id,
            'code' => 'S5',
            'nom' => 'Semestre 5',
            'numero' => 5,
            'ordre' => 5,
        ]);

        $ue = UniteEnseignement::create([
            'semestre_id' => $semestre->id,
            'programme_id' => $this->programmeA->id,
            'code' => 'UE-ALGO',
            'nom' => 'Algorithmique Avancée',
            'credits' => 6,
            'coefficient' => 2,
        ]);

        $this->matiere = Matiere::create([
            'ue_id' => $ue->id,
            'code' => 'MAT-ALGO',
            'nom' => 'Structures de données',
            'niveau' => 'L3',
            'semestre' => 5,
            'credits' => 4,
            'coefficient' => 2,
            'statut' => 'actif',
        ]);

        $groupe = Group::create([
            'code' => 'GRP_INFO_L3',
            'nom' => 'Groupe L3 Info',
            'niveau' => 'L3',
            'faculte_id' => $this->faculteA->id,
            'capacite' => 40,
            'actif' => true,
        ]);

        $this->salleConforme = Room::create([
            'nom' => 'Amphithéâtre Turing',
            'code' => 'AMPHI_TURING',
            'capacite' => 120,
            'type' => 'amphi',
            'actif' => true,
            'heure_ouverture' => '07:30:00',
            'heure_fermeture' => '20:00:00',
            'min_surveillants' => 2,
        ]);

        $this->prof1 = Teacher::create([
            'nom' => 'Alami',
            'prenom' => 'Karim',
            'email' => 'karim.alami@universite.ma',
            'statut' => 'actif',
        ]);

        $this->prof2 = Teacher::create([
            'nom' => 'Bennani',
            'prenom' => 'Salma',
            'email' => 'salma.bennani@universite.ma',
            'statut' => 'actif',
        ]);

        $this->session = SessionExamen::create([
            'code' => 'SESS-PUB-2027',
            'nom' => 'Session Principale Janvier 2027',
            'type' => 'normale',
            'annee_universitaire_id' => $this->annee->id,
            'date_debut' => '2027-01-10',
            'date_fin' => '2027-01-25',
            'statut' => 'open',
        ]);

        // 1. Examen Conforme (prêt à publier)
        $this->examenConforme = Examen::create([
            'code' => 'EX-CONF-001',
            'libelle' => 'Examen Informatique Conforme',
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programmeA->id,
            'niveau_id' => $niveau->id,
            'semestre_lmd_id' => $semestre->id,
            'type' => 'examen',
            'statut' => 'scheduled',
        ]);

        $epreuveConforme = Epreuve::create([
            'code' => 'EPR-CONF-01',
            'examen_id' => $this->examenConforme->id,
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'matiere_id' => $this->matiere->id,
            'groupe_id' => $groupe->id,
            'salle_id' => $this->salleConforme->id,
            'date' => '2027-01-15',
            'heure_debut' => '09:00:00',
            'heure_fin' => '11:00:00',
            'duree_minutes' => 120,
            'statut' => 'scheduled',
            'capacite_necessaire' => 40,
        ]);

        Surveillance::create([
            'epreuve_id' => $epreuveConforme->id,
            'teacher_id' => $this->prof1->id,
            'role' => 'principal',
            'statut' => 'confirme',
        ]);

        Surveillance::create([
            'epreuve_id' => $epreuveConforme->id,
            'teacher_id' => $this->prof2->id,
            'role' => 'adjoint',
            'statut' => 'confirme',
        ]);

        // 2. Examen Non Conforme (sans salle et sous-surveillé)
        $this->examenNonConforme = Examen::create([
            'code' => 'EX-NONCONF-002',
            'libelle' => 'Examen Informatique Non Conforme',
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programmeA->id,
            'type' => 'examen',
            'statut' => 'scheduled',
        ]);

        Epreuve::create([
            'code' => 'EPR-SANS-SALLE',
            'examen_id' => $this->examenNonConforme->id,
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'matiere_id' => $this->matiere->id,
            'salle_id' => null,
            'date' => '2027-01-16',
            'heure_debut' => '14:00:00',
            'heure_fin' => '16:00:00',
            'statut' => 'scheduled',
        ]);
    }

    public function test_acces_non_authentifie_redirige(): void
    {
        $response = $this->get(route('admin.evaluation.examens.publication'));
        $response->assertRedirect('/admin/connexion');
    }

    public function test_audit_publication_calcule_statistiques_exactes(): void
    {
        $orchestrateur = app(ExamPublicationOrchestratorService::class);
        $resultat = $orchestrateur->auditerPublication($this->annee->id);

        $stats = $resultat['statsPublication'];
        $this->assertSame(2, $stats['scheduled']);
        $this->assertSame(0, $stats['published']);
        $this->assertSame(2, $stats['epreuves_scheduled']);
        $this->assertSame(0, $stats['epreuves_published']);
        $this->assertSame(0.0, $stats['taux_publication']);
        $this->assertGreaterThanOrEqual(1, $stats['bloquants_visibles']);
    }

    public function test_diagnostic_examen_detecte_anomalies_bloquantes_ou_conforme(): void
    {
        $orchestrateur = app(ExamPublicationOrchestratorService::class);

        // Examen conforme
        $diagConforme = $orchestrateur->diagnostiquerExamen($this->examenConforme);
        $this->assertTrue($diagConforme['conforme']);
        $this->assertSame('pret_a_publier', $diagConforme['etat']);
        $this->assertSame(100, $diagConforme['score']);
        $this->assertEmpty($diagConforme['conflits']);

        // Examen non conforme
        $diagNonConforme = $orchestrateur->diagnostiquerExamen($this->examenNonConforme);
        $this->assertFalse($diagNonConforme['conforme']);
        $this->assertSame('bloque_anomalies', $diagNonConforme['etat']);
        $this->assertNotEmpty($diagNonConforme['conflits']);
    }

    public function test_calcul_empreinte_cryptographique_est_deterministe_et_immuable(): void
    {
        $orchestrateur = app(ExamPublicationOrchestratorService::class);

        $hash1 = $orchestrateur->calculerEmpreintePublication($this->examenConforme);
        $hash2 = $orchestrateur->calculerEmpreintePublication($this->examenConforme);

        $this->assertIsString($hash1);
        $this->assertSame(64, strlen($hash1)); // SHA-256 = 64 caractères hexa
        $this->assertSame($hash1, $hash2);
    }

    public function test_publication_reussie_d_un_examen_conforme_via_orchestrateur(): void
    {
        $orchestrateur = app(ExamPublicationOrchestratorService::class);

        $publie = $orchestrateur->publierExamen($this->examenConforme, $this->admin->id);

        $this->assertSame('published', $publie->statut);
        $this->assertNotNull($publie->published_at);

        // Vérification que les épreuves associées sont aussi passées à published
        $epreuves = Epreuve::where('examen_id', $publie->id)->get();
        foreach ($epreuves as $ep) {
            $this->assertSame('published', $ep->statut);
        }

        // Le diagnostic passe à 'deja_publie'
        $diag = $orchestrateur->diagnostiquerExamen($publie);
        $this->assertSame('deja_publie', $diag['etat']);
        $this->assertTrue($diag['conforme']);
    }

    public function test_rejet_strict_de_publication_si_contraintes_bloquantes(): void
    {
        $orchestrateur = app(ExamPublicationOrchestratorService::class);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Publication impossible : contraintes');

        $orchestrateur->publierExamen($this->examenNonConforme, $this->admin->id);
    }

    public function test_publication_refusee_sans_permission_publish_meme_hors_http(): void
    {
        $orchestrateur = app(ExamPublicationOrchestratorService::class);

        // admin_faculte possède evaluations.update mais PAS
        // evaluations.publish : l'orchestrateur doit refuser avant toute
        // écriture, même s'il est appelé directement hors contrôleur.
        $this->assertFalse($this->adminFaculteA->hasPermission('evaluations.publish'));

        try {
            $orchestrateur->publierExamen($this->examenConforme, $this->adminFaculteA->id);
            $this->fail('La publication aurait dû être refusée.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('evaluations.publish', $e->getMessage());
        }

        $this->assertSame('scheduled', $this->examenConforme->fresh()->statut);
        $this->assertNull($this->examenConforme->fresh()->published_at);
    }

    public function test_cloisonnement_facultaire_et_zero_ecriture_en_consultation(): void
    {
        // Créer un examen appartenant à la faculté B
        $examenFacB = Examen::create([
            'code' => 'EX-FAC-B',
            'libelle' => 'Examen Faculté B Exclusif',
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programmeB->id,
            'statut' => 'scheduled',
        ]);

        // Compte initial en BDD
        $totalExamens = Examen::count();
        $totalEpreuves = Epreuve::count();

        // Admin restreint à la faculté A
        $response = $this->actingAs($this->adminFaculteA)->get(route('admin.evaluation.examens.publication'));
        $response->assertOk();

        // Doit voir l'examen de sa faculté A
        $response->assertSee('Examen Informatique Conforme');

        // Ne doit PAS voir l'examen de la faculté B
        $response->assertDontSee('Examen Faculté B Exclusif');

        // Garantie zéro écriture sur requête GET
        $this->assertSame($totalExamens, Examen::count());
        $this->assertSame($totalEpreuves, Epreuve::count());
    }

    public function test_vue_publication_affiche_kpi_et_elements_interactifs_sans_erreur(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.evaluation.examens.publication'));

        $response->assertOk();
        $response->assertSee('Validation et publication officielle');
        $response->assertSee('Règle D-05');
        $response->assertSee('En attente de publication');
        $response->assertSee('Officiellement publiés');
        // La publication n'est plus un bouton direct : elle passe par le
        // circuit de gouvernance affiché dans la colonne Action.
        $response->assertSee('Soumettre au circuit');
        $response->assertDontSee('Publier officiellement');
        $response->assertSee('Examen Informatique Conforme');
        $response->assertSee('Conforme (Prêt à publier)');
        $response->assertSee('Protocole de Scellement');
    }
}
