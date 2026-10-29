<?php

use App\Models\Faculte;
use App\Models\IntegrationLog;
use App\Models\User;
use App\Modules\HubIntegration\Models\AuditIdempotence;
use App\Modules\HubIntegration\Models\ContratConnecteur;
use App\Modules\HubIntegration\Models\RejeuIntegration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Lot 18 — Hub d'intégration : tests du cahier.
 *
 * Couverture : matrice honnête (contrat absent ≠ conforme, non audité ≠
 * douteux), contrats versionnés par connecteur (dupliqué refusé, connecteur
 * inconnu refusé, activation tracée, dépréciation), séparation des
 * environnements (sandbox refusé sans autorisation), audits d'idempotence
 * SAISIS avec preuve obligatoire, rejeu borné (plafond explicite) et
 * autorisé-audité (motif, auteur), journal INSERT-ONLY jamais modifié,
 * permissions réutilisées, module désactivé, performance.
 *
 * Données 100% synthétiques.
 */
class HubIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('modules.hub-integration.active', true);
        Config::set('integrations.sandbox_autorise', false);
        Config::set('integrations.plafond_rejeux', 3);
    }

    /* ── Utilisateurs ────────────────────────────────────────────── */

    private function adminScolarite(): User
    {
        return User::creerCompteBackOffice([
            'name' => 'Admin Scolarite', 'email' => 'scol-'.uniqid().'@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
    }

    private function agentScolarite(): User
    {
        return User::creerCompteBackOffice([
            'name' => 'Agent', 'email' => 'agent-'.uniqid().'@universite.test', 'password' => 'Motdepasse@123',
        ], 'agent_scolarite');
    }

    private function adminFaculte(): User
    {
        $faculte = Faculte::create(['nom' => 'Sciences Test', 'code' => 'FST', 'description' => 'T', 'active' => true]);

        return User::creerCompteBackOffice([
            'name' => 'Admin Faculte', 'email' => 'adminfac-'.uniqid().'@universite.test',
            'password' => 'Motdepasse@123', 'faculte_id' => $faculte->id,
        ], 'admin_faculte');
    }

    private function consultateur(): User
    {
        return User::creerCompteBackOffice([
            'name' => 'Consultation', 'email' => 'consult-'.uniqid().'@universite.test', 'password' => 'Motdepasse@123',
        ], 'consultation');
    }

    /* ── Appels ──────────────────────────────────────────────────── */

    private function appelPost(User $u, string $route, array $params, array $data): TestResponse
    {
        return $this->actingAs($u)->postJson(route($route, $params), $data);
    }

    private function appelGet(User $u, string $route, array $params = []): TestResponse
    {
        return $this->actingAs($u)->getJson(route($route, $params));
    }

    private function logEchec(): IntegrationLog
    {
        return IntegrationLog::tracer('test-connector', 'inbound', 'import', 'failed', 'payload-synthetique', 500, 12, 'ERR_TEST');
    }

    /* ── Matrice honnête (donnée absente ≠ conformité) ───────────── */

    public function test_matrice_rapporte_les_absences_explicites(): void
    {
        $reponse = $this->appelGet($this->adminScolarite(), 'admin.hub-integration.connecteurs')->assertOk();
        $lignes = collect($reponse->json('connecteurs'));

        $this->assertGreaterThan(10, $lignes->count()); // registre réel recensé

        // Un connecteur SANS contrat et SANS audit : absences EXPLICITES.
        $vierge = $lignes->firstWhere('connecteur', 'domaines');
        $this->assertNotNull($vierge, 'Le connecteur « domaines » doit être recensé par le registre.');
        $this->assertSame('absent', $vierge['contrat_etat']);
        $this->assertSame('non_audite', $vierge['audit_etat']);
        $this->assertNull($vierge['audit_verdict']);
    }

    public function test_matrice_refusee_sans_import_view(): void
    {
        $this->appelGet($this->consultateur(), 'admin.hub-integration.connecteurs')->assertStatus(403);
    }

    /* ── Contrats versionnés ─────────────────────────────────────── */

    public function test_contrat_declare_puis_doublon_refuse(): void
    {
        $admin = $this->adminScolarite();

        $this->appelPost($admin, 'admin.hub-integration.contrats.creer', [], [
            'connecteur' => 'facultes', 'version' => '1.0',
            'schema' => ['colonnes' => ['nom', 'code']], 'changelog' => 'Contrat initial du connecteur facultés.',
        ])->assertStatus(201);

        $this->appelPost($admin, 'admin.hub-integration.contrats.creer', [], [
            'connecteur' => 'facultes', 'version' => '1.0',
        ])->assertStatus(422); // jamais d'écrasement de version

        // Une NOUVELLE version coexiste (catalogue versionné).
        $this->appelPost($admin, 'admin.hub-integration.contrats.creer', [], [
            'connecteur' => 'facultes', 'version' => '1.1',
        ])->assertStatus(201);
        $this->assertSame(2, ContratConnecteur::count());
    }

    public function test_contrat_connecteur_inconnu_du_registre_refuse(): void
    {
        $this->appelPost($this->adminScolarite(), 'admin.hub-integration.contrats.creer', [], [
            'connecteur' => 'inconnu-xyz', 'version' => '1.0',
        ])->assertStatus(422);
    }

    public function test_contrat_exige_import_template_manage(): void
    {
        $this->appelPost($this->adminFaculte(), 'admin.hub-integration.contrats.creer', [], [
            'connecteur' => 'facultes', 'version' => '1.0',
        ])->assertStatus(403); // admin_faculte : import.* mais PAS import_template.manage
    }

    public function test_contrat_activation_tracee_puis_re_activation_refusee(): void
    {
        $admin = $this->adminScolarite();
        $contrat = ContratConnecteur::create([
            'connecteur' => 'mentions', 'version' => '2.0', 'statut' => 'brouillon',
            'environnement' => 'production', 'cree_par' => $admin->id,
        ]);

        $reponse = $this->appelPost($admin, 'admin.hub-integration.contrats.activer', ['id' => $contrat->id], [])->assertOk();

        $contrat->refresh();
        $this->assertSame('actif', $contrat->statut);
        $this->assertSame($admin->id, (int) $contrat->active_par);
        $this->assertNotNull($contrat->active_le);

        $this->appelPost($admin, 'admin.hub-integration.contrats.activer', ['id' => $contrat->id], [])->assertStatus(422);
    }

    public function test_deprecation_reflechie_dans_la_matrice(): void
    {
        $admin = $this->adminScolarite();
        $contrat = ContratConnecteur::create([
            'connecteur' => 'domaines', 'version' => '1.0', 'statut' => 'actif',
            'environnement' => 'production', 'active_par' => $admin->id, 'active_le' => now(),
        ]);

        $this->appelPost($admin, 'admin.hub-integration.contrats.deprecier', ['id' => $contrat->id], [])->assertOk();

        $lignes = collect($this->appelGet($admin, 'admin.hub-integration.connecteurs')->json('connecteurs'));
        $this->assertSame('deprecie', $lignes->firstWhere('connecteur', 'domaines')['contrat_etat']);
    }

    public function test_sandbox_refusee_sans_autorisation_explicite(): void
    {
        $admin = $this->adminScolarite();
        $contrat = ContratConnecteur::create([
            'connecteur' => 'facultes', 'version' => '0.9-test', 'statut' => 'brouillon',
            'environnement' => 'sandbox', 'cree_par' => $admin->id,
        ]);

        // Séparation des environnements : sandbox INTERDIT par défaut.
        $reponse = $this->appelPost($admin, 'admin.hub-integration.contrats.activer', ['id' => $contrat->id], []);
        $reponse->assertStatus(422);
        $this->assertStringContainsString('SANDBOX', (string) $reponse->json('erreur'));
        $this->assertSame('brouillon', $contrat->refresh()->statut);

        // Autorisation EXPLICITE requise (jamais silencieuse).
        Config::set('integrations.sandbox_autorise', true);
        $this->appelPost($admin, 'admin.hub-integration.contrats.activer', ['id' => $contrat->id], [])->assertOk();
        $this->assertSame('actif', $contrat->refresh()->statut);
    }

    /* ── Audits d'idempotence (SAISIS, jamais calculés) ──────────── */

    public function test_audit_exige_preuve_et_trace_l_auditeur(): void
    {
        $admin = $this->adminScolarite();

        $this->appelPost($admin, 'admin.hub-integration.audits.enregistrer', [], [
            'connecteur' => 'etudiants', 'verdict' => 'verifie', 'preuve' => 'court',
        ])->assertStatus(422); // preuve datée OBLIGATOIRE

        $this->appelPost($admin, 'admin.hub-integration.audits.enregistrer', [], [
            'connecteur' => 'etudiants', 'verdict' => 'verifie',
            'preuve' => 'Test ImportIdempotence exécuté le 2026-10-25 : re-import même fichier → 0 doublon.',
        ])->assertStatus(201);

        $audit = AuditIdempotence::sole();
        $this->assertSame($admin->id, (int) $audit->audite_par);
        $this->assertNotNull($audit->audite_le);

        // La matrice reflète le verdict SAISI.
        $lignes = collect($this->appelGet($admin, 'admin.hub-integration.connecteurs')->json('connecteurs'));
        $ligne = $lignes->firstWhere('connecteur', 'etudiants');
        $this->assertSame('verifie', $ligne['audit_etat']);
    }

    public function test_audit_verdict_absent_jamais_confondu(): void
    {
        $admin = $this->adminScolarite();
        $this->appelPost($admin, 'admin.hub-integration.audits.enregistrer', [], [
            'connecteur' => 'mentions', 'verdict' => 'absent',
            'preuve' => 'Aucune clé d\'idempotence déclarée par ce connecteur (constat du 2026-10-25).',
        ])->assertStatus(201);

        $lignes = collect($this->appelGet($admin, 'admin.hub-integration.connecteurs')->json('connecteurs'));
        $this->assertSame('absent', $lignes->firstWhere('connecteur', 'mentions')['audit_etat']);
    }

    /* ── Journal : lecture filtrée, rejeu borné et autorisé-audité ─ */

    public function test_logs_index_filtre_par_statut(): void
    {
        $admin = $this->adminScolarite();
        IntegrationLog::tracer('conn-a', 'inbound', 'import', 'completed', 'x');
        $this->logEchec();

        $tous = $this->appelGet($admin, 'admin.hub-integration.logs.index')->assertOk()->json('logs');
        $this->assertCount(2, $tous);

        $echecs = $this->appelGet($admin, 'admin.hub-integration.logs.index', ['status' => 'failed'])->assertOk()->json('logs');
        $this->assertCount(1, $echecs);
        $this->assertSame('failed', $echecs[0]['status']);
    }

    public function test_rejeu_autorise_trace_puis_borne_explicite(): void
    {
        $admin = $this->adminScolarite();
        $log = $this->logEchec();

        // 3 autorisations motivées, 4e REFUSÉE : borne explicite, jamais de boucle.
        for ($i = 1; $i <= 3; $i++) {
            $reponse = $this->appelPost($admin, 'admin.hub-integration.logs.rejouer', ['id' => $log->id], [
                'motif' => 'Relance autorisée après correction du connecteur source (tentative '.$i.').',
            ])->assertStatus(201);
            $this->assertSame($i, $reponse->json('rejeu.tentative_numero'));
        }

        $quatrieme = $this->appelPost($admin, 'admin.hub-integration.logs.rejouer', ['id' => $log->id], [
            'motif' => 'Tentative au-delà de la borne.',
        ])->assertStatus(422);
        $this->assertStringContainsString('épuisés', (string) $quatrieme->json('erreur'));
        $this->assertSame(3, RejeuIntegration::count());

        $premier = RejeuIntegration::where('tentative_numero', 1)->sole();
        $this->assertSame('autorise', $premier->statut);
        $this->assertSame($admin->id, (int) $premier->autorise_par);
        $this->assertNotNull($premier->autorise_le);
    }

    public function test_echange_complete_ne_peut_pas_etre_rejoue(): void
    {
        $log = IntegrationLog::tracer('conn-b', 'outbound', 'export', 'completed', 'ok');

        $this->appelPost($this->adminScolarite(), 'admin.hub-integration.logs.rejouer', ['id' => $log->id], [
            'motif' => 'Tentative non légitime sur un échange complété.',
        ])->assertStatus(422);
    }

    public function test_rejeu_exige_import_execute(): void
    {
        $log = $this->logEchec();

        // agent_scolarite : import.validate OUI, import.execute NON.
        $this->appelPost($this->agentScolarite(), 'admin.hub-integration.logs.rejouer', ['id' => $log->id], [
            'motif' => 'Motif de test suffisamment long pour la garde.',
        ])->assertStatus(403);
    }

    public function test_rejeu_execute_puis_re_marquage_refuse(): void
    {
        $admin = $this->adminScolarite();
        $log = $this->logEchec();
        $rejeu = RejeuIntegration::create([
            'integration_log_id' => $log->id, 'tentative_numero' => 1,
            'motif' => 'Relance après correction amont.', 'autorise_par' => $admin->id, 'autorise_le' => now(),
        ]);

        $this->appelPost($admin, 'admin.hub-integration.rejeux.executer', ['id' => $rejeu->id], [])->assertOk();
        $this->assertSame('execute', $rejeu->refresh()->statut);

        $this->appelPost($admin, 'admin.hub-integration.rejeux.executer', ['id' => $rejeu->id], [])->assertStatus(422);
    }

    public function test_journal_integration_insert_only_jamais_modifie(): void
    {
        $admin = $this->adminScolarite();
        $log = $this->logEchec();
        $snapshot = $log->only(['id', 'status', 'payload_hash', 'error_code', 'http_status']);

        $this->appelPost($admin, 'admin.hub-integration.logs.rejouer', ['id' => $log->id], [
            'motif' => 'Autorisation de relance tracée pour test d\'immuabilité.',
        ])->assertStatus(201);
        $rejeu = RejeuIntegration::sole();
        $this->appelPost($admin, 'admin.hub-integration.rejeux.executer', ['id' => $rejeu->id], [])->assertOk();

        // Le journal n'est NI modifié NI étendu par le hub (INSERT-ONLY).
        $this->assertSame($snapshot, IntegrationLog::sole()->only(['id', 'status', 'payload_hash', 'error_code', 'http_status']));
        $this->assertSame(1, IntegrationLog::count());
    }

    /* ── Module, non-régression, performance ─────────────────────── */

    public function test_module_desactive_renvoie_404(): void
    {
        Config::set('modules.hub-integration.active', false);
        $admin = $this->adminScolarite();

        $this->appelGet($admin, 'admin.hub-integration.connecteurs')->assertStatus(404);
        $this->appelPost($admin, 'admin.hub-integration.contrats.creer', [], [
            'connecteur' => 'facultes', 'version' => '9.9',
        ])->assertStatus(404);
    }

    public function test_performance_matrice_et_journal_synthetiques(): void
    {
        $admin = $this->adminScolarite();
        ContratConnecteur::create([
            'connecteur' => 'facultes', 'version' => '1.0', 'statut' => 'actif',
            'environnement' => 'production', 'active_par' => $admin->id, 'active_le' => now(),
        ]);
        AuditIdempotence::create([
            'connecteur' => 'facultes', 'verdict' => 'verifie',
            'preuve' => 'Preuve synthétique de performance.', 'audite_par' => $admin->id, 'audite_le' => now(),
        ]);
        for ($i = 0; $i < 100; $i++) {
            IntegrationLog::tracer('conn-perf', 'inbound', 'import', $i % 2 === 0 ? 'completed' : 'failed', 'lot-'.$i);
        }

        $debut = microtime(true);
        $reponse = $this->appelGet($admin, 'admin.hub-integration.connecteurs')->assertOk();
        $duree = microtime(true) - $debut;

        $ligne = collect($reponse->json('connecteurs'))->firstWhere('connecteur', 'facultes');
        $this->assertSame('actif', $ligne['contrat_etat']);
        $this->assertSame('verifie', $ligne['audit_etat']);

        $this->assertLessThan(3.0, $duree, 'Matrice trop lente : '.$duree.' s');
    }
}
