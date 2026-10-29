<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\AnomalieQualite;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\User;
use App\Modules\Announcements\Models\Annonce;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\Evaluation\Models\Deliberation;
use App\Modules\Tasks\Models\Task;
use App\Services\Kpi\CatalogueKpi;
use App\Services\Kpi\CockpitKpiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * LOT 03 — Suite de tests complète pour le Cockpit KPI scolarité :
 * - Catalogue et définitions documentées
 * - Réconciliation arithmétique exacte avec les sources
 * - Gestion des données vides (zéro division par zéro)
 * - Isolation facultaire stricte (anti-IDOR)
 * - Fraîcheur des données et SLA
 * - Exposition HTTP, JSON et export CSV
 * - Scoping des widgets transversaux (annonces, évaluation)
 */
class CockpitKpiServiceTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private AnneeUniversitaire $annee;

    private User $adminA;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculteA = Faculte::create([
            'nom' => 'Faculté des Sciences A',
            'code' => 'FSA',
            'description' => 'Faculté A',
            'active' => true,
        ]);

        $this->faculteB = Faculte::create([
            'nom' => 'Faculté des Lettres B',
            'code' => 'FLB',
            'description' => 'Faculté B',
            'active' => true,
        ]);

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        // Compte restreint à la faculté A (rôle admin_faculte)
        $this->adminA = User::creerCompteBackOffice([
            'name' => 'Admin Faculte A',
            'email' => 'admin.a@universite.test',
            'password' => 'MotDePasse123!',
            'faculte_id' => $this->faculteA->id,
        ], 'admin_faculte');

        // Super administrateur transversal
        $this->superAdmin = User::creerCompteBackOffice([
            'name' => 'Super Administrateur',
            'email' => 'super.admin@universite.test',
            'password' => 'MotDePasse123!',
        ], 'super_admin');
    }

    public function test_catalogue_kpi_contient_tous_les_indicateurs_avec_metadonnees_completes(): void
    {
        $catalogue = CatalogueKpi::tous();

        $this->assertGreaterThanOrEqual(14, count($catalogue));
        $this->assertSame('1.0', CatalogueKpi::VERSION);

        foreach ($catalogue as $code => $definition) {
            $this->assertSame($code, $definition->code);
            $this->assertNotEmpty($definition->nom);
            $this->assertNotEmpty($definition->categorie);
            $this->assertNotEmpty($definition->definition);
            $this->assertNotEmpty($definition->formule);
            $this->assertNotEmpty($definition->sourceVerite);
            $this->assertNotEmpty($definition->unite);
            $this->assertFalse($definition->sensiblePii, "Le KPI {$code} ne doit pas être sensible PII.");
        }

        $this->assertTrue(CatalogueKpi::existe('effectif_total'));
        $this->assertTrue(CatalogueKpi::existe('taux_validation_inscriptions'));
        $this->assertTrue(CatalogueKpi::existe('taux_reussite_deliberations'));
        $this->assertTrue(CatalogueKpi::existe('taux_validation_credits'));
    }

    public function test_seuils_alerte_evaluent_correctement_les_valeurs(): void
    {
        $defTaux = CatalogueKpi::obtenir('taux_validation_inscriptions');

        // seuils : critique si < 50%, attention si < 75%
        $this->assertSame('critique', $defTaux->evaluerAlerte(45.0));
        $this->assertSame('attention', $defTaux->evaluerAlerte(65.0));
        $this->assertSame('normal', $defTaux->evaluerAlerte(85.0));
        $this->assertSame('indetermine', $defTaux->evaluerAlerte(null));
    }

    public function test_reconciliation_exacte_des_indicateurs_avec_les_sources_de_reference(): void
    {
        $delib = Deliberation::create([
            'code' => 'DELIB-2026-FSA',
            'annee_universitaire_id' => $this->annee->id,
            'statut' => 'published',
        ]);

        // 1. Inscriptions pour Faculté A
        for ($i = 1; $i <= 10; $i++) {
            $etudiant = $this->creerEtudiant("CIN_A_{$i}", $this->faculteA);
            $statut = $i <= 7 ? 'valide' : 'en_attente';
            $inscription = InscriptionAcademique::create([
                'etudiant_id' => $etudiant->id,
                'annee_universitaire_id' => $this->annee->id,
                'niveau' => 'L1',
                'statut' => $statut,
                'credits_obtenus' => $i <= 7 ? 30 : 0,
                'credits_total' => 30,
                'moyenne_annuelle' => $i <= 7 ? (10.0 + $i) : null,
            ]);

            // Délibération pour 5 étudiants
            if ($i <= 5) {
                DecisionDeliberation::create([
                    'deliberation_id' => $delib->id,
                    'etudiant_id' => $etudiant->id,
                    'inscription_academique_id' => $inscription->id,
                    'moyenne' => 12.0 + $i,
                    'credits_obtenus' => 30,
                    'credits_total' => 30,
                    'decision' => $i <= 4 ? 'admis' : 'ajourne',
                    'publie' => true,
                ]);
            }
        }

        // 2. Tâches pour Faculté A
        Task::create([
            'titre' => 'Tâche critique A',
            'cle_unique' => 'TASK-TEST-CRIT-A',
            'source' => 'systeme',
            'statut' => 'pending',
            'priorite' => 'critical',
            'faculte_id' => $this->faculteA->id,
            'echeance_a' => now()->subDay(),
        ]);
        Task::create([
            'titre' => 'Tâche normale A',
            'cle_unique' => 'TASK-TEST-NORM-A',
            'source' => 'systeme',
            'statut' => 'pending',
            'priorite' => 'normal',
            'faculte_id' => $this->faculteA->id,
            'echeance_a' => now()->addDay(),
        ]);

        // 3. Anomalies qualité pour Faculté A
        AnomalieQualite::create([
            'cle_unique' => 'ANOM-001',
            'source' => 'test',
            'gravite' => 'critique',
            'statut' => 'ouverte',
            'message' => 'Dossier incomplet critique',
            'faculte_id' => $this->faculteA->id,
        ]);
        AnomalieQualite::create([
            'cle_unique' => 'ANOM-002',
            'source' => 'test',
            'gravite' => 'avertissement',
            'statut' => 'ouverte',
            'message' => 'Pièce justificative floue',
            'faculte_id' => $this->faculteA->id,
        ]);

        $service = app(CockpitKpiService::class);
        $snapshot = $service->calculerSnapshot($this->annee->id, $this->faculteA->id, null, null, true);

        // Vérifications arithmétiques
        $this->assertSame(10, $snapshot->get('effectif_total')->valeur);
        $this->assertSame(7, $snapshot->get('inscriptions_validees')->valeur);
        $this->assertSame(3, $snapshot->get('inscriptions_en_attente')->valeur);
        $this->assertSame(70.0, $snapshot->get('taux_validation_inscriptions')->valeur);

        // Taux de réussite délibérations : 4 admis sur 5 publiés = 80.0%
        $this->assertSame(80.0, $snapshot->get('taux_reussite_deliberations')->valeur);

        // Taux crédits : 7 * 30 = 210 obtenus sur 300 inscrits = 70.0%
        $this->assertSame(70.0, $snapshot->get('taux_validation_credits')->valeur);

        // Médiane des moyennes (7 étudiants avec moyenne : 11, 12, 13, 14, 15, 16, 17 -> médiane = 14)
        $this->assertSame(14.0, $snapshot->get('moyenne_mediane')->valeur);

        // Tâches
        $this->assertSame(1, $snapshot->get('taches_critiques_ouvertes')->valeur);
        $this->assertSame(1, $snapshot->get('taches_en_retard_sla')->valeur);

        // Anomalies qualité
        $this->assertSame(2, $snapshot->get('anomalies_qualite_ouvertes')->valeur);
        $this->assertSame(1, $snapshot->get('anomalies_critiques')->valeur);

        // Synthèse des alertes
        $this->assertArrayHasKey('critique', $snapshot->syntheseAlertes);
        $this->assertArrayHasKey('attention', $snapshot->syntheseAlertes);
    }

    public function test_gestion_donnees_vides_sans_division_par_zero(): void
    {
        $service = app(CockpitKpiService::class);
        $snapshot = $service->calculerSnapshot($this->annee->id, $this->faculteB->id, null, null, true);

        $this->assertSame(0, $snapshot->get('effectif_total')->valeur);
        $this->assertSame(0, $snapshot->get('inscriptions_validees')->valeur);
        $this->assertNull($snapshot->get('taux_validation_inscriptions')->valeur);
        $this->assertSame('N/A', $snapshot->get('taux_validation_inscriptions')->valeurFormatee);
        $this->assertSame('donnees_insuffisantes', $snapshot->get('taux_validation_inscriptions')->statutFraicheur);

        $this->assertNull($snapshot->get('taux_reussite_deliberations')->valeur);
        $this->assertNull($snapshot->get('taux_validation_credits')->valeur);
        $this->assertNull($snapshot->get('moyenne_mediane')->valeur);
    }

    public function test_isolation_facultaire_stricte_anti_idor(): void
    {
        // Faculté A : 5 étudiants
        for ($i = 1; $i <= 5; $i++) {
            $this->creerEtudiant("CIN_A_ISO_{$i}", $this->faculteA);
        }

        // Faculté B : 8 étudiants
        for ($i = 1; $i <= 8; $i++) {
            $this->creerEtudiant("CIN_B_ISO_{$i}", $this->faculteB);
        }

        $service = app(CockpitKpiService::class);

        // 1. Admin A tente d'interroger la Faculté B -> forcé à la Faculté A (anti-IDOR)
        $snapshotAdminA = $service->calculerSnapshot($this->annee->id, $this->faculteB->id, null, $this->adminA, true);
        $this->assertSame($this->faculteA->id, $snapshotAdminA->faculteId);
        $this->assertSame(5, $snapshotAdminA->get('effectif_total')->valeur);

        // 2. Super Admin interroge sans filtre -> global (13 étudiants)
        $snapshotSuperAdmin = $service->calculerSnapshot($this->annee->id, null, null, $this->superAdmin, true);
        $this->assertNull($snapshotSuperAdmin->faculteId);
        $this->assertSame(13, $snapshotSuperAdmin->get('effectif_total')->valeur);

        // 3. Super Admin filtre explicitement sur B
        $snapshotSuperB = $service->calculerSnapshot($this->annee->id, $this->faculteB->id, null, $this->superAdmin, true);
        $this->assertSame($this->faculteB->id, $snapshotSuperB->faculteId);
        $this->assertSame(8, $snapshotSuperB->get('effectif_total')->valeur);
    }

    public function test_drill_down_fournit_les_requetes_sources_avec_isolation(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            $this->creerEtudiant("CIN_DD_A_{$i}", $this->faculteA);
        }
        for ($i = 1; $i <= 6; $i++) {
            $this->creerEtudiant("CIN_DD_B_{$i}", $this->faculteB);
        }

        $service = app(CockpitKpiService::class);

        // Requête source pour Admin A (doit retourner exactement 4 étudiants car restreint à A)
        $queryA = $service->donneesSources('effectif_total', $this->annee->id, $this->faculteB->id, null, $this->adminA);
        $this->assertSame(4, $queryA->count());

        // Requête source pour Super Admin ciblant B (doit retourner 6 étudiants)
        $queryB = $service->donneesSources('effectif_total', $this->annee->id, $this->faculteB->id, null, $this->superAdmin);
        $this->assertSame(6, $queryB->count());
    }

    public function test_page_cockpit_admin_affiche_le_ruban_kpi(): void
    {
        $this->creerEtudiant('CIN_COCKPIT_1', $this->faculteA);

        $this->actingAs($this->adminA)
            ->get(route('admin.cockpit.index'))
            ->assertOk()
            ->assertSee('Indicateurs clés de scolarité')
            ->assertSee('Effectif total')
            ->assertSee('Export KPI (CSV)');
    }

    public function test_endpoint_kpi_json_retourne_le_snapshot_structure(): void
    {
        $this->creerEtudiant('CIN_JSON_1', $this->faculteA);

        $reponse = $this->actingAs($this->adminA)
            ->getJson(route('admin.cockpit.kpi'))
            ->assertOk();

        $reponse->assertJsonStructure([
            'version',
            'horodatage',
            'perimetre' => ['annee_id', 'faculte_id', 'programme_id'],
            'statut_fraicheur_global',
            'synthese_alertes',
            'indicateurs',
        ]);

        $this->assertSame($this->faculteA->id, $reponse->json('perimetre.faculte_id'));
    }

    public function test_export_csv_kpi_conforme_sans_pii(): void
    {
        $reponse = $this->actingAs($this->adminA)
            ->get(route('admin.cockpit.kpi.export'))
            ->assertOk();

        $reponse->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $contenu = $reponse->streamedContent();

        $this->assertStringContainsString('code;nom;categorie;valeur;unite', $contenu);
        $this->assertStringContainsString('effectif_total', $contenu);
        $this->assertStringContainsString('taux_validation_inscriptions', $contenu);
        // Aucune donnée nominative personnelle
        $this->assertStringNotContainsString('admin.a@universite.test', $contenu);
    }

    public function test_scoping_facultaire_des_widgets_transversaux(): void
    {
        // 1. Annonces : 1 pour Faculté A, 1 pour Faculté B, 1 globale
        Annonce::create([
            'titre' => 'Annonce Faculte A',
            'contenu' => 'Contenu A',
            'type' => 'information',
            'statut' => 'published',
            'audience' => 'tous',
            'faculte_id' => $this->faculteA->id,
        ]);
        Annonce::create([
            'titre' => 'Annonce Faculte B',
            'contenu' => 'Contenu B',
            'type' => 'information',
            'statut' => 'published',
            'audience' => 'tous',
            'faculte_id' => $this->faculteB->id,
        ]);
        Annonce::create([
            'titre' => 'Annonce Globale',
            'contenu' => 'Contenu G',
            'type' => 'information',
            'statut' => 'published',
            'audience' => 'tous',
            'faculte_id' => null,
        ]);

        // Connecté en tant qu'admin Faculté A : doit voir 2 annonces (A + globale), PAS B
        $this->actingAs($this->adminA);
        $vueA = View::make('modules.announcements.widget-admin');
        $vueA->render();
        $this->assertSame(2, $vueA->getData()['annoncesPubliees']);

        // Connecté en tant que Super Admin : doit voir les 3
        $this->actingAs($this->superAdmin);
        $vueSuper = View::make('modules.announcements.widget-admin');
        $vueSuper->render();
        $this->assertSame(3, $vueSuper->getData()['annoncesPubliees']);
    }

    private function creerEtudiant(string $cin, Faculte $faculte): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin,
            'nom' => 'Nom'.$cin,
            'prenom' => 'Prenom'.$cin,
            'email' => strtolower($cin).'@universite.test',
            'sexe' => 'M',
            'faculte_id' => $faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => 'Campus',
            'ville' => 'Casablanca',
            'code_postal' => '20000',
            'annee_bac' => '2024',
            'password' => 'MotDePasse123!',
        ]);
        $etudiant->forceFill(['num_inscription' => 'INS-'.$cin])->save();
        $etudiant->definirStatut('valide');

        return $etudiant->fresh();
    }
}
