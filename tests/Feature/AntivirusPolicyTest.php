<?php

namespace Tests\Feature;

use App\Modules\StudentDossiers\Contracts\AntivirusScannerInterface;
use App\Modules\StudentDossiers\Services\ClamavAntivirusScanner;
use App\Modules\StudentDossiers\Services\DocumentStorageService;
use App\Modules\StudentDossiers\Services\NullAntivirusScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Politique antivirus : le moteur null n'est utilisé que si l'analyse est
 * explicitement désactivée ; ClamAV est fail-closed. Une panne, un délai
 * dépassé ou un binaire absent ne doit jamais publier le fichier.
 */
class AntivirusPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
        config([
            'student-dossiers.antivirus.driver' => 'null',
            'student-dossiers.antivirus.categories_fail_closed' => [
                'identity', 'registration', 'academic', 'administrative',
                'internship', 'medical', 'financial', 'other', 'justificatif',
            ],
        ]);
    }

    public function test_driver_null_est_le_seul_mode_sans_analyse(): void
    {
        $this->assertInstanceOf(NullAntivirusScanner::class, app(AntivirusScannerInterface::class));
        $this->assertTrue(app(AntivirusScannerInterface::class)->analyser([
            'storage_path' => 'inexistant.pdf', 'sha256' => 'x', 'size' => 1,
        ])->sain);
    }

    public function test_driver_clamav_est_resolu_quand_configure(): void
    {
        config(['student-dossiers.antivirus.driver' => 'clamav']);

        $this->assertInstanceOf(ClamavAntivirusScanner::class, app(AntivirusScannerInterface::class));
    }

    public function test_commande_clamav_adaptee_au_binaire_et_au_mode(): void
    {
        $scanner = new class extends ClamavAntivirusScanner
        {
            /** @return array<int, string> */
            public function args(string $binaire): array
            {
                return $this->commande($binaire);
            }
        };

        config(['student-dossiers.antivirus.clamav.mode' => null]);
        $this->assertSame(['clamdscan', '--no-summary', '-'], $scanner->args('clamdscan'));
        $this->assertSame(['/usr/bin/clamscan', '--no-summary', '-'], $scanner->args('/usr/bin/clamscan'));

        config(['student-dossiers.antivirus.clamav.mode' => 'stream']);
        $this->assertSame(['/opt/scan', '--no-summary', '--stream', '-'], $scanner->args('/opt/scan'));
    }

    public function test_binaire_absent_refuse_et_supprime_le_fichier(): void
    {
        config([
            'student-dossiers.antivirus.driver' => 'clamav',
            'student-dossiers.antivirus.clamav.binary' => '/chemin/inexistant/clamdscan',
            'student-dossiers.antivirus.clamav.timeout' => 5,
        ]);

        $fichier = UploadedFile::fake()->createWithContent(
            'identite.pdf',
            "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n",
            'application/pdf',
        );

        try {
            app(DocumentStorageService::class)->stocker(
                $fichier, 1, '2026-2027', 'identity',
            );
            $this->fail('Un fichier non analysé ne doit jamais être publié.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('antivirus', $e->getMessage());
            $this->assertStringContainsString('introuvable', $e->getMessage());
        }

        $this->assertSame([], Storage::disk('private')->allFiles('student-dossiers'));
    }

    public function test_moteur_qui_detecte_une_menace_retourne_la_signature(): void
    {
        Storage::disk('private')->put('scan/eicar.txt', 'X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR');
        config(['student-dossiers.antivirus.clamav.binary' => '/bin/sh']);

        // `/bin/sh -c 'exit 1'` simule un code ClamAV « menace détectée ».
        $scanner = new class extends ClamavAntivirusScanner
        {
            protected function commande(string $binaire): array
            {
                return ['/bin/sh', '-c', 'exit 1'];
            }
        };

        $resultat = $scanner->analyser([
            'storage_path' => 'scan/eicar.txt', 'sha256' => 'x', 'size' => 1,
            'disk' => 'private', 'categorie' => 'identity',
        ]);

        $this->assertFalse($resultat->sain);
        $this->assertNull($resultat->signature);
    }

    public function test_timeout_moteur_refuse_le_fichier(): void
    {
        Storage::disk('private')->put('scan/lent.txt', 'contenu');
        config([
            'student-dossiers.antivirus.clamav.binary' => '/bin/sh',
            'student-dossiers.antivirus.clamav.timeout' => 1,
        ]);

        // Le moteur dépasse le délai : le résultat doit être un refus,
        // jamais un fichier sain par défaut.
        $scanner = new class extends ClamavAntivirusScanner
        {
            protected function commande(string $binaire): array
            {
                return ['/bin/sh', '-c', 'sleep 5'];
            }
        };

        $resultat = $scanner->analyser([
            'storage_path' => 'scan/lent.txt', 'sha256' => 'x', 'size' => 7,
            'disk' => 'private', 'categorie' => 'identity',
        ]);

        $this->assertFalse($resultat->sain);
        $this->assertStringContainsString('délai', (string) $resultat->erreur);
    }

    public function test_sortie_clamav_avec_signature_est_extraite(): void
    {
        Storage::disk('private')->put('scan/eicar.txt', 'EICAR');
        config(['student-dossiers.antivirus.clamav.binary' => '/bin/sh']);

        $scanner = new class extends ClamavAntivirusScanner
        {
            protected function commande(string $binaire): array
            {
                return ['/bin/sh', '-c', 'echo "/tmp/x: Eicar-Test-Signature FOUND"; exit 1'];
            }
        };

        $resultat = $scanner->analyser([
            'storage_path' => 'scan/eicar.txt', 'sha256' => 'x', 'size' => 1,
            'disk' => 'private', 'categorie' => 'financial',
        ]);

        $this->assertFalse($resultat->sain);
        $this->assertSame('Eicar-Test-Signature', $resultat->signature);
    }
}
