<?php

namespace App\Services\Sauvegardes;

use App\Models\DatabaseBackup;
use App\Services\BackupService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Registre des sauvegardes (LOOP BACKUP-01) : lecture du dossier
 * configuré + synchronisation des manifestes JSON existants
 * (commande `sauvegarde:creer`) vers la table, sans dupliquer le
 * moteur de dump. Aucun contenu SQL ni secret manipulé ici.
 */
class RegistreSauvegardesService
{
    public function dossier(): string
    {
        return app(BackupService::class)->dossierBackup();
    }

    /**
     * @return Collection<int, DatabaseBackup>
     */
    public function historique(int $parPage = 20): LengthAwarePaginator
    {
        return DatabaseBackup::with('createur')->latest('created_at')->paginate($parPage);
    }

    public function dernierReussi(): ?DatabaseBackup
    {
        return DatabaseBackup::where('status', 'SUCCESS')->latest('completed_at')->first();
    }

    /**
     * @return array{total: int, succes: int, echecs: int, octets: int, dernier_test_restore: ?string}
     */
    public function statistiques(): array
    {
        return [
            'total' => DatabaseBackup::count(),
            'succes' => DatabaseBackup::where('status', 'SUCCESS')->count(),
            'echecs' => DatabaseBackup::whereIn('status', ['FAILED', 'VERIFICATION_FAILED', 'RESTORE_TEST_FAILED'])->count(),
            'octets' => (int) DatabaseBackup::where('status', 'SUCCESS')->sum('size_bytes'),
            'dernier_test_restore' => DatabaseBackup::whereNotNull('restore_tested_at')
                ->latest('restore_tested_at')->value('restore_test_status'),
        ];
    }

    /**
     * Manifest le plus récent d'un dossier, trié par date de
     * modification réelle (jamais par nom : les suffixes `-2` issus
     * des noms uniques faussent l'ordre lexical et feraient lire ou
     * purger le mauvais lot).
     */
    public function dernierManifest(string $dossier): ?string
    {
        $manifests = glob(rtrim($dossier, '/').'/manifest-*.json') ?: [];

        if ($manifests === []) {
            return null;
        }

        usort($manifests, fn ($a, $b) => filemtime($b) <=> filemtime($a));

        return $manifests[0];
    }

    /**
     * Importe les manifestes JSON présents sur disque et absents du
     * registre (reprise après migration, dossiers pré-existants).
     * Idempotent : la correspondance se fait sur le nom de fichier.
     */
    public function synchroniserDepuisManifestes(?int $auteurId = null): int
    {
        $dossier = $this->dossier();

        if (! is_dir($dossier)) {
            return 0;
        }

        $crees = 0;
        $manifests = glob("{$dossier}/manifest-*.json") ?: [];
        usort($manifests, fn ($a, $b) => filemtime($b) <=> filemtime($a));

        foreach ($manifests as $cheminManifest) {
            $donnees = json_decode((string) file_get_contents($cheminManifest), true);

            if (! is_array($donnees)) {
                continue;
            }

            $base = $donnees['artefacts']['base'] ?? null;

            if (! is_array($base) || empty($base['fichier'])) {
                continue;
            }

            $nomFichier = basename((string) $base['fichier']);

            if (DatabaseBackup::where('filename', $nomFichier)->exists()) {
                continue;
            }

            $cheminFichier = $dossier.'/'.$nomFichier;

            DatabaseBackup::create([
                'uuid' => (string) Str::uuid(),
                'filename' => $nomFichier,
                'disk' => 'local',
                'path' => $cheminFichier,
                'size_bytes' => is_file($cheminFichier) ? filesize($cheminFichier) : 0,
                'checksum' => $base['sha256'] ?? null,
                'checksum_algorithm' => 'sha256',
                'compression' => str_ends_with($nomFichier, '.gz') ? 'gzip' : null,
                'status' => is_file($cheminFichier) ? 'SUCCESS' : 'FAILED',
                'type' => 'SCHEDULED',
                'completed_at' => isset($donnees['date']) ? date_create($donnees['date']) ?: null : null,
                'created_by' => $auteurId,
            ]);
            $crees++;
        }

        return $crees;
    }
}
