<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Déchiffrement d'un artefact de sauvegarde (A4, roadmap 05/10/2026) :
 * sodium secretbox, nonce(24) || chiffré — cf. `sauvegarde:creer
 * --chiffrer`. Le fichier chiffré reste intact : la sortie est un
 * NOUVEAU fichier à côté (ou --sortie).
 */
class DechiffrerSauvegarde extends Command
{
    protected $signature = 'sauvegarde:dechiffrer
        {fichier : Artefact .enc à déchiffrer}
        {--sortie= : Fichier de sortie (défaut : sans extension .enc)}';

    protected $description = 'Déchiffre un artefact de sauvegarde (.enc → fichier).';

    public function handle(): int
    {
        $source = (string) $this->argument('fichier');

        if (! is_file($source)) {
            $this->error("Fichier introuvable : {$source}");

            return self::FAILURE;
        }

        $cle = base64_decode((string) config('sauvegardes.cle'), true);

        if ($cle === false || strlen($cle) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            $this->error('SAUVEGARDE_CLE absente ou invalide (32 octets en base64, cf. config/sauvegardes.php).');

            return self::FAILURE;
        }

        $brut = file_get_contents($source);

        if ($brut === false || strlen($brut) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            $this->error('Artefact trop court ou illisible.');

            return self::FAILURE;
        }

        $nonce = substr($brut, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $chiffre = substr($brut, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $clair = sodium_crypto_secretbox_open($chiffre, $nonce, $cle);

        if ($clair === false) {
            $this->error('Déchiffrement impossible : clé erronée ou fichier altéré.');

            return self::FAILURE;
        }

        $sortie = (string) ($this->option('sortie') ?: preg_replace('/\.enc$/', '', $source));

        if ($sortie === $source) {
            $this->error('La sortie doit différer de la source (utilisez --sortie).');

            return self::FAILURE;
        }

        if (file_put_contents($sortie, $clair) === false) {
            $this->error("Écriture impossible : {$sortie}");

            return self::FAILURE;
        }

        $this->info("Artefact déchiffré : {$sortie} (le fichier .enc est conservé).");

        return self::SUCCESS;
    }
}
