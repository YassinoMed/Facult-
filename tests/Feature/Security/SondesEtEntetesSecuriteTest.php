<?php

namespace Tests\Feature\Security;

use App\Support\SupervisionAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOOP-SECURITY-REMEDIATION-01 — sondes de supervision réduites et
 * en-têtes durcis :
 * - `/api/v1/ready` et `/api/v1/version` ne révèlent publiquement QUE
 *   l'état ; le détail (base, cache, stockage, disque, environnement,
 *   versions internes) est réservé aux IP de `SUPERVISION_ALLOWED_IPS` ;
 * - `X-Powered-By` (version de PHP) est masqué ;
 * - la CSP est durcie (`base-uri 'none'`, `media-src 'none'`,
 *   `upgrade-insecure-requests` sur HTTPS).
 */
class SondesEtEntetesSecuriteTest extends TestCase
{
    use RefreshDatabase;

    public function test_ready_public_ne_revele_que_l_etat(): void
    {
        $reponse = $this->getJson('/api/v1/ready');

        // 200 si tout est ok, 503 sinon (disque plein) — l'état HTTP
        // reste exploitable par un load-balancer.
        $this->assertContains($reponse->status(), [200, 503]);

        $charge = $reponse->json();

        $this->assertArrayHasKey('status', $charge);
        $this->assertArrayNotHasKey('sondes', $charge);
        $this->assertStringNotContainsString('inscription_etudiants', $reponse->getContent());
        $this->assertStringNotContainsString('storage/app', $reponse->getContent());
    }

    public function test_ready_detail_pour_ip_de_supervision(): void
    {
        config(['security.supervision_allowed_ips' => ['10.9.9.9']]);

        $reponse = $this->withServerVariables(['REMOTE_ADDR' => '10.9.9.9'])
            ->getJson('/api/v1/ready');

        $this->assertContains($reponse->status(), [200, 503]);
        $this->assertArrayHasKey('sondes', $reponse->json());
        $this->assertCount(5, $reponse->json('sondes'));
    }

    public function test_ready_ne_considere_pas_une_ip_hors_liste(): void
    {
        config(['security.supervision_allowed_ips' => ['10.9.9.9']]);

        $reponse = $this->withServerVariables(['REMOTE_ADDR' => '10.9.9.10'])
            ->getJson('/api/v1/ready');

        $this->assertArrayNotHasKey('sondes', $reponse->json());
    }

    public function test_version_publique_ne_revele_ni_environnement_ni_versions(): void
    {
        $reponse = $this->getJson('/api/v1/version');

        $reponse->assertOk();

        $charge = $reponse->json();

        $this->assertSame('ok', $charge['status']);
        $this->assertSame(['status', 'timestamp'], array_keys($charge));
    }

    public function test_version_detail_pour_ip_de_supervision(): void
    {
        config(['security.supervision_allowed_ips' => ['10.9.9.9']]);

        $reponse = $this->withServerVariables(['REMOTE_ADDR' => '10.9.9.9'])
            ->getJson('/api/v1/version');

        $reponse->assertOk();
        $this->assertArrayHasKey('app_version', $reponse->json());
        $this->assertArrayHasKey('db_version', $reponse->json());
        $this->assertArrayHasKey('environnement', $reponse->json());
    }

    public function test_supervision_access_refuse_les_ips_vides_ou_inconnues(): void
    {
        config(['security.supervision_allowed_ips' => []]);

        $this->assertFalse(SupervisionAccess::autorisee(null));
        $this->assertFalse(SupervisionAccess::autorisee(''));
        $this->assertFalse(SupervisionAccess::autorisee('127.0.0.1'));

        config(['security.supervision_allowed_ips' => ['127.0.0.1']]);
        $this->assertTrue(SupervisionAccess::autorisee('127.0.0.1'));
        $this->assertFalse(SupervisionAccess::autorisee('127.0.0.2'));
    }

    public function test_entetes_ne_revelent_pas_php_et_csp_durcie(): void
    {
        $reponse = $this->get('/');

        $this->assertFalse($reponse->headers->has('X-Powered-By'));

        $csp = (string) $reponse->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("base-uri 'none'", $csp);
        $this->assertStringContainsString("media-src 'none'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringNotContainsString('upgrade-insecure-requests', $csp);

        $this->assertSame('none', $reponse->headers->get('X-Permitted-Cross-Domain-Policies'));
    }

    public function test_csp_https_ajoute_upgrade_insecure_requests(): void
    {
        $reponse = $this->get('https://localhost/');

        $csp = (string) $reponse->headers->get('Content-Security-Policy');

        $this->assertStringContainsString('upgrade-insecure-requests', $csp);
    }
}
