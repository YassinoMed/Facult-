<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\DossierCorrection;
use App\Models\DossierVerification;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\MensReference;
use App\Models\SituationEtudiant;
use App\Models\User;
use App\Services\ExperienceInscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests de la LOOP 22 — Mesure de l'expérience et amélioration continue.
 *
 * Vérifie :
 * 1. Définition et calcul exact de chaque métrique sur jeu contrôlé.
 * 2. Scoping faculté étanche.
 * 3. Minimisation stricte des données (zéro PII dans vue et export).
 * 4. Parité stricte entre affichage index et export CSV.
 * 5. Garde-fou de non-causalité et autorisations.
 */
class InscriptionMesureExperienceTest extends TestCase
{
    use RefreshDatabase;

    private User $adminCentral;

    private User $adminFaculteA;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private AnneeUniversitaire $annee;

    private ExperienceInscriptionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->faculteA = Faculte::create([
            'nom' => 'Faculté des Sciences de Tunis',
            'code' => 'FST',
        ]);

        $this->faculteB = Faculte::create([
            'nom' => 'Faculté de Médecine de Tunis',
            'code' => 'FMT',
        ]);

        $this->adminCentral = User::creerCompteBackOffice([
            'name' => 'Admin Scolarité Central',
            'email' => 'admin.scolarite@universite.tn',
            'password' => 'PassSecret@123',
        ], 'admin_scolarite');

        $this->adminFaculteA = User::creerCompteBackOffice([
            'name' => 'Admin Scolarité FST',
            'email' => 'admin.fst@universite.tn',
            'password' => 'PassSecret@123',
            'faculte_id' => $this->faculteA->id,
        ], 'admin_faculte');

        $this->service = app(ExperienceInscriptionService::class);
    }

    private function creerEtudiant(array $attributs = []): Etudiant
    {
        static $compteur = 1;

        $defauts = [
            'num_inscription' => '2026-FST-'.str_pad((string) $compteur, 4, '0', STR_PAD_LEFT),
            'cin' => '07'.str_pad((string) $compteur, 6, '0', STR_PAD_LEFT),
            'nom' => 'Nom'.$compteur,
            'prenom' => 'Prenom'.$compteur,
            'email' => "etudiant{$compteur}@test.tn",
            'date_naissance' => '2004-01-01',
            'sexe' => 'M',
            'niveau' => 'L1',
            'adresse' => '12 Rue de la Paix',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2024',
            'faculte_id' => $this->faculteA->id,
            'statut' => 'en_attente',
            'annee_academique' => '2026-2027',
            'password' => 'Secret123!',
        ];

        $compteur++;

        return Etudiant::create(array_merge($defauts, $attributs));
    }

    /**
     * Test 1 : Complétude au premier dépôt sur jeu de données contrôlé.
     */
    public function test_completude_premier_depot_formule_exacte(): void
    {
        // Étudiant 1 : sans correction
        $e1 = $this->creerEtudiant(['cin' => '07111111']);

        // Étudiant 2 : avec correction demandée
        $e2 = $this->creerEtudiant(['cin' => '07222222']);

        DossierCorrection::create([
            'etudiant_id' => $e2->id,
            'champ' => 'adresse',
            'ancienne_valeur' => 'Ancienne',
            'nouvelle_valeur' => 'Nouvelle',
            'motif' => 'Précision',
            'statut' => 'proposee',
            'propose_par' => $this->adminCentral->id,
        ]);

        // 1 sans correction sur 2 au total = 50.0%
        $taux = $this->service->completudePremierDepot($this->faculteA->id);
        $this->assertSame(50.0, $taux);
    }

    /**
     * Test 2 : Taux d'abandon mesurable via comparaison non invasive MENS / Inscriptions.
     */
    public function test_taux_abandon_mesurable_sans_pistage_invasif(): void
    {
        // 3 candidats référencés au bac MENS
        MensReference::create([
            'cin_normalise' => '07100001',
            'cin_original' => '07100001',
            'date_naissance' => '2004-01-01',
            'nom' => 'Candidat1',
            'prenom' => 'Test1',
            'annee' => '2026-2027',
            'source_hash' => str_repeat('1', 64),
        ]);
        MensReference::create([
            'cin_normalise' => '07100002',
            'cin_original' => '07100002',
            'date_naissance' => '2004-02-02',
            'nom' => 'Candidat2',
            'prenom' => 'Test2',
            'annee' => '2026-2027',
            'source_hash' => str_repeat('2', 64),
        ]);
        MensReference::create([
            'cin_normalise' => '07100003',
            'cin_original' => '07100003',
            'date_naissance' => '2004-03-03',
            'nom' => 'Candidat3',
            'prenom' => 'Test3',
            'annee' => '2026-2027',
            'source_hash' => str_repeat('3', 64),
        ]);

        // Seuls 2 candidats ont déposé leur dossier
        $this->creerEtudiant(['cin' => '07100001']);
        $this->creerEtudiant(['cin' => '07100002']);

        // 1 abandon sur 3 = 33.3%
        $abandon = $this->service->tauxAbandonMesurable();
        $this->assertSame(33.3, $abandon);
    }

    /**
     * Test 3 : Taux de corrections appliquées et délais de traitement.
     */
    public function test_delai_traitement_et_corrections_appliquees(): void
    {
        $e = $this->creerEtudiant([
            'cin' => '07555555',
        ]);
        $e->definirStatut('valide');
        Etudiant::where('id', $e->id)->update(['created_at' => '2026-09-01 00:00:00']);

        SituationEtudiant::create([
            'etudiant_id' => $e->id,
            'type' => 'statut',
            'ancienne_valeur' => 'en_attente',
            'nouvelle_valeur' => 'valide',
            'date_effet' => '2026-09-05',
            'auteur_id' => $this->adminCentral->id,
        ]);

        DossierCorrection::create([
            'etudiant_id' => $e->id,
            'champ' => 'prenom',
            'ancienne_valeur' => 'Etudian',
            'nouvelle_valeur' => 'Etudiant',
            'motif' => 'Typo',
            'statut' => 'appliquee',
            'propose_par' => $this->adminCentral->id,
            'decide_par' => $this->adminCentral->id,
        ]);
        DossierCorrection::create([
            'etudiant_id' => $e->id,
            'champ' => 'email',
            'ancienne_valeur' => 'delai@test.tn',
            'nouvelle_valeur' => 'delai2@test.tn',
            'motif' => 'Non justifié',
            'statut' => 'rejetee',
            'propose_par' => $this->adminCentral->id,
            'decide_par' => $this->adminCentral->id,
        ]);

        // Délai : 6 - 2 = 4.0 jours
        $delai = $this->service->delaiMoyenTraitementJours($this->faculteA->id);
        $this->assertSame(4.0, $delai);

        // Corrections appliquées : 1 sur 2 décidées = 50.0%
        $corrections = $this->service->tauxCorrection($this->faculteA->id);
        $this->assertSame(50.0, $corrections['taux_corrections_appliquees']);
    }

    /**
     * Test 4 : Taux d'anomalies bloquantes sur dossier_verifications.
     */
    public function test_taux_anomalies_bloquantes(): void
    {
        $e1 = $this->creerEtudiant(['cin' => '07666666']);
        $e2 = $this->creerEtudiant(['cin' => '07777777']);

        DossierVerification::create([
            'etudiant_id' => $e1->id,
            'verdict' => 'CONFORME',
            'score' => 100,
            'regles_verifiees' => 5,
            'regles_reussies' => 5,
            'anomalies_count' => 0,
            'duree_ms' => 10,
            'source' => 'test',
        ]);

        DossierVerification::create([
            'etudiant_id' => $e2->id,
            'verdict' => 'BLOQUANT',
            'score' => 30,
            'regles_verifiees' => 5,
            'regles_reussies' => 2,
            'anomalies_count' => 1,
            'duree_ms' => 15,
            'source' => 'test',
        ]);

        // 1 bloquante sur 2 = 50.0%
        $taux = $this->service->tauxAnomaliesTechniques($this->faculteA->id);
        $this->assertSame(50.0, $taux);
    }

    /**
     * Test 5 : Scoping faculté étanche sur les indicateurs.
     */
    public function test_scoping_faculte_etanche_sur_les_indicateurs(): void
    {
        // Étudiant faculté A
        $this->creerEtudiant([
            'faculte_id' => $this->faculteA->id,
            'cin' => '07888888',
        ]);

        // Étudiant faculté B
        $this->creerEtudiant([
            'faculte_id' => $this->faculteB->id,
            'cin' => '07999999',
        ]);

        // L'admin de la faculté A ne voit qu'1 seul étudiant
        $response = $this->actingAs($this->adminFaculteA)
            ->get(route('admin.statistiques.index'));

        $response->assertOk();
        $indicateurs = $response->viewData('indicateurs');
        $this->assertSame(1, $indicateurs['Étudiants']);
    }

    /**
     * Test 6 : Minimisation des données — Zéro PII dans la vue et l'export CSV.
     */
    public function test_minimisation_des_donnees_zero_pii_dans_vue_et_export(): void
    {
        $this->creerEtudiant([
            'nom' => 'ConfidentialNom',
            'prenom' => 'SecretPrenom',
            'email' => 'secret.email@prive.tn',
            'cin' => '07987654',
        ]);

        // 1. Vue HTML agrégée
        $respHtml = $this->actingAs($this->adminCentral)
            ->get(route('admin.statistiques.index'));

        $respHtml->assertOk();
        $respHtml->assertDontSee('ConfidentialNom');
        $respHtml->assertDontSee('SecretPrenom');
        $respHtml->assertDontSee('secret.email@prive.tn');
        $respHtml->assertDontSee('07987654');

        // 2. Export CSV
        $respCsv = $this->actingAs($this->adminCentral)
            ->get(route('admin.statistiques.export'));

        $respCsv->assertOk();
        $contenuCsv = $respCsv->streamedContent();

        $this->assertStringNotContainsString('ConfidentialNom', $contenuCsv);
        $this->assertStringNotContainsString('SecretPrenom', $contenuCsv);
        $this->assertStringNotContainsString('secret.email@prive.tn', $contenuCsv);
        $this->assertStringNotContainsString('07987654', $contenuCsv);
        $this->assertStringContainsString('Complétude au premier dépôt (%)', $contenuCsv);
        $this->assertStringContainsString('Taux d’abandon mesurable (%)', $contenuCsv);
    }

    /**
     * Test 7 : Parité stricte entre indicateurs index et export CSV.
     */
    public function test_parite_stricte_index_et_export_csv(): void
    {
        $respIndex = $this->actingAs($this->adminCentral)
            ->get(route('admin.statistiques.index'));

        $indicateursIndex = array_keys($respIndex->viewData('indicateurs'));

        $respCsv = $this->actingAs($this->adminCentral)
            ->get(route('admin.statistiques.export'));

        $contenuCsv = $respCsv->streamedContent();

        foreach ($indicateursIndex as $libelle) {
            $this->assertStringContainsString($libelle, $contenuCsv, "L'indicateur '{$libelle}' doit être présent dans l'export CSV.");
        }
    }

    /**
     * Test 8 : Contrôle d'accès et permissions requises (403 sans autorisation).
     */
    public function test_controle_acces_et_permissions_requises(): void
    {
        $utilisateurSansDroits = User::factory()->create();

        // Sans rôle back-office (donc sans statistiques.view) -> 403
        $this->actingAs($utilisateurSansDroits)
            ->get(route('admin.statistiques.index'))
            ->assertForbidden();

        // Utilisateur consultation : possède statistiques.view (200), mais pas statistiques.export (403)
        $utilisateurConsultation = User::creerCompteBackOffice([
            'name' => 'Agent Consultation',
            'email' => 'consultation@universite.tn',
            'password' => 'PassSecret@123',
        ], 'consultation');

        $this->actingAs($utilisateurConsultation)
            ->get(route('admin.statistiques.index'))
            ->assertOk();

        $this->actingAs($utilisateurConsultation)
            ->get(route('admin.statistiques.export'))
            ->assertForbidden();
    }
}
