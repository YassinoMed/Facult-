<?php

namespace App\Console\Commands;

use App\Services\Sauvegardes\BackupRetentionService;
use App\Services\Sauvegardes\RegistreSauvegardesService;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Sauvegarde universitaire (§30) : base + documents privés + manifest
 * signé (sha256). Échec EXPLICITE si un binaire manque — jamais de
 * faux succès. Rétention des N derniers lots.
 */
class Sauvegarder extends Command
{
    protected $signature = 'sauvegarde:creer
        {--connexion= : Connexion DB (défaut : connexion active)}
        {--dossier= : Dossier cible (défaut : storage/app/sauvegardes)}
        {--sans-base : Ignorer la base de données}
        {--sans-documents : Ignorer les documents privés}
        {--conserver=14 : Lots conservés}
        {--conserver-jours= : Âge maximal conservé en jours (vide = sans limite)}
        {--chiffrer : Chiffrer les artefacts au repos (SAUVEGARDE_CLE requise)}
        {--verifier : Vérifier le dernier lot au lieu de sauvegarder}';

    protected $description = 'Sauvegarde base + documents avec manifest vérifiable.';

    public function handle(): int
    {
        $dossier = (string) ($this->option('dossier') ?: storage_path('app/sauvegardes'));

        if (! is_dir($dossier) && ! mkdir($dossier, 0750, true)) {
            $this->error("Dossier inaccessible : {$dossier}");

            return self::FAILURE;
        }

        if ($this->option('verifier')) {
            return $this->verifier($dossier);
        }

        $horodatage = now()->format('Ymd-His');
        $artefacts = [];

        if (! $this->option('sans-base')) {
            $dump = $this->sauvegarderBase($dossier, $horodatage);

            if ($dump === null) {
                return self::FAILURE;
            }

            $artefacts['base'] = $dump;
        }

        if (! $this->option('sans-documents')) {
            $archive = $this->sauvegarderDocuments($dossier, $horodatage);

            if ($archive === null) {
                return self::FAILURE;
            }

            $artefacts['documents'] = $archive;
        }

        $manifest = [
            'date' => now()->toIso8601String(),
            'application' => config('app.name'),
            'connexion' => $this->connexion(),
            'chiffre' => $this->option('chiffrer'),
            'artefacts' => [],
        ];

        // A4 (roadmap 05/10/2026) : chiffrement au repos des artefacts —
        // sans clé configurée, échec explicite (jamais de lot en clair
        // créé « par accident » sous couvert de --chiffrer).
        if ($this->option('chiffrer') && ($this->cleChiffrement() === null)) {
            $this->error('SAUVEGARDE_CLE absente ou invalide : générez 32 octets en base64 (cf. config/sauvegardes.php).');

            return self::FAILURE;
        }

        if ($this->option('chiffrer')) {
            foreach ($artefacts as $cle => $chemin) {
                $artefacts[$cle] = $this->chiffrerArtefact($chemin);
            }
        }

        foreach ($artefacts as $cle => $chemin) {
            $manifest['artefacts'][$cle] = [
                'fichier' => basename($chemin),
                'octets' => filesize($chemin),
                'sha256' => hash_file('sha256', $chemin),
            ];
        }

        $cheminManifest = $this->cibleUnique($dossier, 'manifest', $horodatage, 'json');
        file_put_contents($cheminManifest, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->purger($dossier, (int) $this->option('conserver'));
        $this->info("Sauvegarde : {$cheminManifest}");

        return self::SUCCESS;
    }

    /**
     * A4 : clé libsodium (32 octets) — null si absente/invalide.
     */
    private function cleChiffrement(): ?string
    {
        $cle = base64_decode((string) config('sauvegardes.cle'), true);

        return $cle !== false && strlen($cle) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES ? $cle : null;
    }

    /**
     * Chiffre un artefact au format sodium : nonce(24) || chiffré.
     * Le fichier en clair est supprimé APRÈS écriture réussie du .enc.
     */
    private function chiffrerArtefact(string $chemin): string
    {
        $cle = $this->cleChiffrement();

        if ($cle === null) {
            throw new \RuntimeException('Clé de chiffrement de sauvegarde invalide.');
        }

        $contenu = file_get_contents($chemin);

        if ($contenu === false) {
            throw new \RuntimeException("Lecture impossible : {$chemin}");
        }

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $chiffre = sodium_crypto_secretbox($contenu, $nonce, $cle);

        $cible = $chemin.'.enc';

        if (file_put_contents($cible, $nonce.$chiffre) === false) {
            throw new \RuntimeException("Écriture impossible : {$cible}");
        }

        unset($contenu, $chiffre);

        if (! @unlink($chemin)) {
            @unlink($cible);
            throw new \RuntimeException("Suppression du fichier en clair impossible : {$chemin}");
        }

        return $cible;
    }

    /**
     * Nom d'artefact unique : deux sauvegardes dans la même seconde
     * (dump + pré-restore) ne doivent jamais partager un nom —
     * sinon le second écrase le premier (perte prouvée en test).
     */
    private function cibleUnique(string $dossier, string $prefixe, string $horodatage, string $extension): string
    {
        $candidat = "{$dossier}/{$prefixe}-{$horodatage}.{$extension}";
        $compteur = 2;

        while (is_file($candidat)) {
            $candidat = "{$dossier}/{$prefixe}-{$horodatage}-{$compteur}.{$extension}";
            $compteur++;
        }

        return $candidat;
    }

    private function connexion(): string
    {
        return (string) ($this->option('connexion') ?: config('database.default'));
    }

    private function sauvegarderBase(string $dossier, string $horodatage): ?string
    {
        $connexion = $this->connexion();
        $config = config("database.connections.{$connexion}");

        if (! is_array($config)) {
            $this->error("Connexion inconnue : {$connexion}");

            return null;
        }

        $cible = $this->cibleUnique($dossier, 'base', $horodatage, 'sql');

        if (($config['driver'] ?? '') === 'sqlite') {
            $fichier = $config['database'] ?? '';

            if ($fichier === ':memory:' || ! is_file($fichier)) {
                $this->error('SQLite mémoire : aucune base fichier à sauvegarder.');

                return null;
            }

            copy($fichier, $cible);

            return $cible;
        }

        if (($config['driver'] ?? '') === 'mysql') {
            $process = new Process(['sh', '-lc', 'command -v mysqldump']);
            $process->run();

            if (! $process->isSuccessful()) {
                $this->error('mysqldump introuvable : sauvegarde de base impossible.');

                return null;
            }

            $dump = new Process([
                'mysqldump', '--single-transaction', '--quick',
                '-h', (string) ($config['host'] ?? '127.0.0.1'),
                '-P', (string) ($config['port'] ?? '3306'),
                '-u', (string) ($config['username'] ?? 'root'),
                (string) ($config['database'] ?? ''),
            ], null, ['MYSQL_PWD' => (string) ($config['password'] ?? '')] + getenv());
            $dump->setTimeout(600);
            $dump->run();

            if (! $dump->isSuccessful()) {
                $this->error('mysqldump a échoué : '.mb_substr($dump->getErrorOutput(), 0, 300));

                return null;
            }

            file_put_contents($cible, $dump->getOutput());

            return $cible;
        }

        $this->error("Pilote non pris en charge : {$config['driver']}.");

        return null;
    }

    private function sauvegarderDocuments(string $dossier, string $horodatage): ?string
    {
        $source = storage_path('app/private');
        $cible = $this->cibleUnique($dossier, 'documents', $horodatage, 'zip');

        if (! is_dir($source)) {
            $this->warn('Dossier documents absent : archive vide.');
        }

        $zip = new \ZipArchive;

        if ($zip->open($cible, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            $this->error('Création de l’archive impossible.');

            return null;
        }

        if (is_dir($source)) {
            $iterateur = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterateur as $fichier) {
                if ($fichier->isFile()) {
                    $zip->addFile($fichier->getPathname(), substr($fichier->getPathname(), strlen($source) + 1));
                }
            }
        }

        $zip->close();

        return $cible;
    }

    private function purger(string $dossier, int $conserver): void
    {
        $jours = $this->option('conserver-jours');

        app(BackupRetentionService::class)->purger(
            $dossier, $conserver, $jours === null ? null : max(1, (int) $jours),
        );
    }

    private function verifier(string $dossier): int
    {
        $cheminManifest = app(RegistreSauvegardesService::class)->dernierManifest($dossier);

        if ($cheminManifest === null) {
            $this->error('Aucune sauvegarde à vérifier.');

            return self::FAILURE;
        }
        $donnees = json_decode((string) file_get_contents($cheminManifest), true);
        $ok = true;

        foreach ($donnees['artefacts'] ?? [] as $cle => $artefact) {
            $chemin = $dossier.'/'.basename((string) ($artefact['fichier'] ?? ''));

            if (! is_file($chemin) || hash_file('sha256', $chemin) !== ($artefact['sha256'] ?? null)) {
                $this->error("Artefact invalide : {$cle}");
                $ok = false;
            }
        }

        $this->info($ok ? 'Sauvegarde vérifiée : intègre.' : 'Sauvegarde CORROMPUE.');

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
