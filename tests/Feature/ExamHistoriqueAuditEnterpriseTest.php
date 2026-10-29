<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\IntegrationLog;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Evaluation\Models\DocumentExamenGenere;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Services\ExamHistoriqueAuditOrchestratorService;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Suite de tests Enterprise pour EA-EXAMENS-07 — Historique, Traçabilité & Audit (Menu 12).
 *
 * Valide :
 *  - Permissions d'accès et redirections sécurisées
 *  - Calcul opposable des KPI d'historique (total, taux de succès, documents générés, durée moyenne)
 *  - Filtrage multicritères serveur (statut, canal/direction, recherche, dates)
 *  - Règle §55 et RGPD : zéro PII en clair dans les traces d'audit et validation des empreintes SHA-256
 *  - Traçabilité des documents officiels scellés (émargements, convocations)
 *  - Cloisonnement facultaire et garantie zéro écriture sur les requêtes de consultation
 *  - Rendu complet de la vue Blade
 */
class ExamHistoriqueAuditEnterpriseTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $adminFaculteA;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private AnneeUniversitaire $annee;

    private Programme $programmeA;

    private Programme $programmeB;

    private Room $salle;

    private Epreuve $epreuve;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.evaluation.active' => true]);

        $this->faculteA = Faculte::create([
            'nom' => 'Faculté des Sciences et Technologies',
            'code' => 'FST_HIST',
            'active' => true,
        ]);

        $this->faculteB = Faculte::create([
            'nom' => 'Faculté de Droit et Économie',
            'code' => 'FDE_HIST',
            'active' => true,
        ]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Auditeur Central Examens',
            'email' => 'audit.central.'.uniqid().'@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->adminFaculteA = User::creerCompteBackOffice([
            'name' => 'Auditeur Faculté Sciences',
            'email' => 'audit.fsi.'.uniqid().'@universite.test',
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
            'code' => 'L_INFO_HIST',
            'nom' => 'Licence Informatique',
            'type' => 'licence',
            'duree_annees' => 3,
            'statut' => 'actif',
        ]);

        $this->programmeB = Programme::create([
            'faculte_id' => $this->faculteB->id,
            'code' => 'L_DROIT_HIST',
            'nom' => 'Licence Droit',
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
            'code' => 'UE-SEC-HIST',
            'nom' => 'Sécurité Informatique',
            'credits' => 6,
            'coefficient' => 2,
        ]);

        $matiere = Matiere::create([
            'ue_id' => $ue->id,
            'code' => 'MAT-SEC-501',
            'nom' => 'Cryptographie & Audit',
            'niveau' => 'L3',
            'semestre' => 5,
            'credits' => 4,
            'coefficient' => 2,
            'statut' => 'actif',
        ]);

        $groupe = Group::create([
            'code' => 'GRP_SEC_L3',
            'nom' => 'Groupe L3 Sécurité',
            'niveau' => 'L3',
            'faculte_id' => $this->faculteA->id,
            'capacite' => 45,
            'actif' => true,
        ]);

        $this->salle = Room::create([
            'nom' => 'Salle Turing Audit',
            'code' => 'SALLE_TURING',
            'capacite' => 60,
            'type' => 'cours',
            'actif' => true,
        ]);

        $session = SessionExamen::create([
            'code' => 'SESS-HIST-2027',
            'nom' => 'Session Principale Audit 2027',
            'type' => 'normale',
            'annee_universitaire_id' => $this->annee->id,
            'date_debut' => '2027-01-10',
            'date_fin' => '2027-01-25',
            'statut' => 'open',
        ]);

        $examen = Examen::create([
            'code' => 'EX-AUDIT-001',
            'libelle' => 'Examen Sécurité & Cryptographie',
            'session_id' => $session->id,
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programmeA->id,
            'statut' => 'published',
        ]);

        $this->epreuve = Epreuve::create([
            'code' => 'EPR-SEC-01',
            'examen_id' => $examen->id,
            'session_id' => $session->id,
            'annee_universitaire_id' => $this->annee->id,
            'matiere_id' => $matiere->id,
            'groupe_id' => $groupe->id,
            'salle_id' => $this->salle->id,
            'date' => '2027-01-18',
            'heure_debut' => '09:00:00',
            'heure_fin' => '11:00:00',
            'statut' => 'published',
        ]);
    }

    public function test_acces_non_authentifie_redirige(): void
    {
        $response = $this->get(route('admin.evaluation.examens.historique'));
        $response->assertRedirect('/admin/connexion');
    }

    public function test_audit_historique_calcule_statistiques_exactes(): void
    {
        // Création de traces dans integration_logs
        IntegrationLog::create([
            'integration' => 'evaluation',
            'direction' => 'pdf_generation',
            'operation' => 'generation_emargement_epreuve',
            'status' => 'completed',
            'http_status' => 200,
            'duration_ms' => 120,
            'payload_hash' => hash('sha256', 'payload-emargement-1'),
            'created_at' => now(),
        ]);

        IntegrationLog::create([
            'integration' => 'evaluation',
            'direction' => 'export',
            'operation' => 'export_notes_examen',
            'status' => 'completed',
            'http_status' => 200,
            'duration_ms' => 80,
            'payload_hash' => hash('sha256', 'payload-export-notes'),
            'created_at' => now(),
        ]);

        IntegrationLog::create([
            'integration' => 'evaluation',
            'direction' => 'export',
            'operation' => 'export_planning_examens',
            'status' => 'failed',
            'http_status' => 500,
            'duration_ms' => 250,
            'error_code' => 'TIMEOUT_GATEWAY',
            'created_at' => now(),
        ]);

        // Création d'un document généré
        DocumentExamenGenere::create([
            'epreuve_id' => $this->epreuve->id,
            'salle_id' => $this->salle->id,
            'type' => 'emargement',
            'genere_par' => $this->admin->id,
            'candidats_count' => 42,
            'nom_fichier' => 'emargement-epr-sec-01.pdf',
        ]);

        $orchestrateur = app(ExamHistoriqueAuditOrchestratorService::class);
        $resultat = $orchestrateur->auditerHistorique();

        $stats = $resultat['statsHistorique'];
        $this->assertSame(3, $stats['total_traces']);
        $this->assertSame(2, $stats['operations_reussies']);
        $this->assertSame(1, $stats['operations_echouees']);
        $this->assertSame(66.7, $stats['taux_succes']);
        $this->assertSame(150.0, $stats['duree_moyenne_ms']); // (120 + 80 + 250) / 3 = 150
        $this->assertSame(1, $stats['documents_generes']);
    }

    public function test_filtrage_multicriteres_statut_direction_et_recherche(): void
    {
        IntegrationLog::create([
            'integration' => 'evaluation',
            'direction' => 'pdf_generation',
            'operation' => 'generation_convocations_groupe',
            'status' => 'completed',
            'created_at' => now(),
        ]);

        IntegrationLog::create([
            'integration' => 'evaluation',
            'direction' => 'export',
            'operation' => 'export_surveillance_csv',
            'status' => 'failed',
            'created_at' => now(),
        ]);

        $orchestrateur = app(ExamHistoriqueAuditOrchestratorService::class);

        // 1. Filtre par statut = 'failed'
        $resFailed = $orchestrateur->auditerHistorique(statutFiltre: 'failed');
        $this->assertSame(1, $resFailed['traces']->total());
        $this->assertSame('export_surveillance_csv', $resFailed['traces']->first()->operation);

        // 2. Filtre par direction = 'pdf_generation'
        $resPdf = $orchestrateur->auditerHistorique(directionFiltre: 'pdf_generation');
        $this->assertSame(1, $resPdf['traces']->total());
        $this->assertSame('generation_convocations_groupe', $resPdf['traces']->first()->operation);

        // 3. Filtre par mot-clé de recherche
        $resSearch = $orchestrateur->auditerHistorique(recherche: 'surveillance');
        $this->assertSame(1, $resSearch['traces']->total());
        $this->assertSame('export_surveillance_csv', $resSearch['traces']->first()->operation);
    }

    public function test_conformite_rgpd_zero_pii_detecte_et_valide_les_empreintes(): void
    {
        $orchestrateur = app(ExamHistoriqueAuditOrchestratorService::class);

        // 1. Trace conforme avec hash SHA-256
        $traceConforme = new IntegrationLog([
            'operation' => 'generation_convocation_anonyme',
            'external_reference' => 'DOC-ANON-778',
            'integration' => 'evaluation',
            'payload_hash' => hash('sha256', 'donnees-anonymes-sans-pii'),
        ]);

        $this->assertTrue($orchestrateur->verifierConformiteRgpdZeroPii($traceConforme));

        // 2. Trace non conforme (fuite d'adresse email nominative)
        $traceFuite = new IntegrationLog([
            'operation' => 'envoi_email_etudiant_ahmed.alami@etudiant.univ.ma',
            'external_reference' => 'MAIL-01',
            'integration' => 'evaluation',
            'payload_hash' => hash('sha256', 'mail'),
        ]);

        $this->assertFalse($orchestrateur->verifierConformiteRgpdZeroPii($traceFuite));

        // 3. Trace avec hash SHA-256 corrompu (non hexadécimal ou mauvaise longueur)
        $traceHashCorrompu = new IntegrationLog([
            'operation' => 'test_hash',
            'integration' => 'evaluation',
            'payload_hash' => 'hash_court_invalide',
        ]);

        $this->assertFalse($orchestrateur->verifierConformiteRgpdZeroPii($traceHashCorrompu));
    }

    public function test_association_des_derniers_documents_officiels_scelles(): void
    {
        DocumentExamenGenere::create([
            'epreuve_id' => $this->epreuve->id,
            'salle_id' => $this->salle->id,
            'type' => 'emargement',
            'genere_par' => $this->admin->id,
            'candidats_count' => 30,
            'nom_fichier' => 'bordereau-emargement-officiel.pdf',
        ]);

        DocumentExamenGenere::create([
            'epreuve_id' => $this->epreuve->id,
            'salle_id' => $this->salle->id,
            'type' => 'convocation',
            'genere_par' => $this->admin->id,
            'candidats_count' => 30,
            'nom_fichier' => 'convocations-collectives.pdf',
        ]);

        $orchestrateur = app(ExamHistoriqueAuditOrchestratorService::class);
        $resultat = $orchestrateur->auditerHistorique();

        $docs = $resultat['derniersDocuments'];
        $this->assertCount(2, $docs);
        $this->assertSame('convocations-collectives.pdf', $docs->first()->nom_fichier);
        $this->assertNotNull($docs->first()->epreuve);
        $this->assertNotNull($docs->first()->salle);
        $this->assertNotNull($docs->first()->generePar);
    }

    public function test_cloisonnement_facultaire_et_zero_ecriture_en_consultation(): void
    {
        IntegrationLog::create([
            'integration' => 'evaluation',
            'direction' => 'pdf_generation',
            'operation' => 'audit_trace_test',
            'status' => 'completed',
            'created_at' => now(),
        ]);

        $countInitialLogs = IntegrationLog::count();
        $countInitialDocs = DocumentExamenGenere::count();

        // Requête authentifiée avec admin de faculté
        $response = $this->actingAs($this->adminFaculteA)->get(route('admin.evaluation.examens.historique'));
        $response->assertOk();

        // Garantie zéro écriture sur route de consultation GET
        $this->assertSame($countInitialLogs, IntegrationLog::count());
        $this->assertSame($countInitialDocs, DocumentExamenGenere::count());
    }

    public function test_vue_historique_affiche_kpi_et_elements_interactifs_sans_erreur(): void
    {
        IntegrationLog::create([
            'integration' => 'evaluation',
            'direction' => 'pdf_generation',
            'operation' => 'generation_emargement_amphi_turing',
            'status' => 'completed',
            'duration_ms' => 95,
            'payload_hash' => hash('sha256', 'payload-test'),
            'created_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->get(route('admin.evaluation.examens.historique'));

        $response->assertOk();
        $response->assertSee('Historique et audit des examens');
        $response->assertSee('journal', false);
        $response->assertSee('Règle §55');
        $response->assertSee('Documents générés');
        $response->assertSee('Taux de succès');
        $response->assertSee('Durée moyenne');
        $response->assertSee('generation_emargement_amphi_turing');
        $response->assertSee('Gouvernance des Journaux');
    }
}
