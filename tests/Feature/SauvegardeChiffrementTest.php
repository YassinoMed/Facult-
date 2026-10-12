<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Sauvegardes chiffrées au repos (A4 — roadmap « restes connus ») :
 * `--chiffrer` exige une clé valide, produit des artefacts .enc
 * (le fichier en clair disparaît), reste vérifiables par le manifeste
 * et restaurables via `sauvegarde:dechiffrer`. Sans clé : échec
 * explicite — jamais de lot en clair « par accident ».
 */
class SauvegardeChiffrementTest extends TestCase
{
    use RefreshDatabase;

    private string $dossier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dossier = storage_path('app/sauvegardes-test-'.uniqid());
        mkdir($this->dossier, 0750, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dossier.'/*') ?: [] as $fichier) {
            @unlink($fichier);
        }
        @rmdir($this->dossier);

        parent::tearDown();
    }

    private function configurerCle(): string
    {
        $cle = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        config(['sauvegardes.cle' => $cle]);

        return $cle;
    }

    /**
     * La suite tourne sur SQLite mémoire (non sauvegardable) : on
     * fournit une connexion fichier dédiée à la commande.
     */
    private function preparerConnexionFichier(): string
    {
        $chemin = $this->dossier.'/base-source.sqlite';
        $pdo = new \PDO('sqlite:'.$chemin);
        $pdo->exec('CREATE TABLE temoin (id INTEGER PRIMARY KEY, valeur TEXT)');
        $pdo->exec("INSERT INTO temoin (valeur) VALUES ('secret-etape')");
        $pdo = null;

        config(['database.connections.sauvegarde_test' => [
            'driver' => 'sqlite',
            'database' => $chemin,
        ]]);

        return 'sauvegarde_test';
    }

    public function test_chiffrer_sans_cle_echoue_explicitement(): void
    {
        config(['sauvegardes.cle' => null]);

        $this->assertSame(1, Artisan::call('sauvegarde:creer', [
            '--connexion' => $this->preparerConnexionFichier(),
            '--dossier' => $this->dossier, '--chiffrer' => true, '--sans-documents' => true,
        ]));
        $this->assertStringContainsString('SAUVEGARDE_CLE', Artisan::output());
    }

    public function test_sauvegarde_chiffree_verifiable_et_restaurable(): void
    {
        $this->configurerCle();

        $this->assertSame(0, Artisan::call('sauvegarde:creer', [
            '--connexion' => $this->preparerConnexionFichier(),
            '--dossier' => $this->dossier, '--chiffrer' => true, '--sans-documents' => true,
        ]));

        $manifests = glob($this->dossier.'/manifest-*.json');
        $this->assertNotEmpty($manifests);

        $manifeste = json_decode((string) file_get_contents($manifests[0]), true);
        $this->assertTrue((bool) $manifeste['chiffre']);

        $artefact = $manifeste['artefacts']['base'];
        $fichierChiffre = $this->dossier.'/'.$artefact['fichier'];
        $fichierClair = preg_replace('/\.enc$/', '', $fichierChiffre);

        // Artefact chiffré présent, fichier en clair ABSENT.
        $this->assertFileExists($fichierChiffre);
        $this->assertFileDoesNotExist($fichierClair);
        $this->assertSame($artefact['sha256'], hash_file('sha256', $fichierChiffre));

        // Le lot chiffré reste vérifiable par le manifeste.
        $this->assertSame(0, Artisan::call('sauvegarde:creer', [
            '--dossier' => $this->dossier, '--verifier' => true,
        ]));
        $this->assertStringContainsString('intègre', Artisan::output());

        // Restauration : déchiffrement fidèle.
        $this->assertSame(0, Artisan::call('sauvegarde:dechiffrer', [
            'fichier' => $fichierChiffre, '--sortie' => $fichierClair.'.restaure',
        ]));
        $this->assertFileExists($fichierClair.'.restaure');

        // Mauvaise clé : refus propre, aucun fichier trompeur produit.
        config(['sauvegardes.cle' => base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES))]);
        $this->assertSame(1, Artisan::call('sauvegarde:dechiffrer', [
            'fichier' => $fichierChiffre, '--sortie' => $fichierClair.'.trompeur',
        ]));
        $this->assertStringContainsString('Déchiffrement impossible', Artisan::output());
        $this->assertFileDoesNotExist($fichierClair.'.trompeur');
    }
}
