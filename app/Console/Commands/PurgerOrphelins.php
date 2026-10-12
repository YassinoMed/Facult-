<?php

namespace App\Console\Commands;

use App\Models\DocumentAcademique;
use App\Modules\OnlineRequests\Models\OnlineRequestDocument;
use App\Modules\StudentDossiers\Models\GeneratedDocument;
use App\Modules\StudentDossiers\Models\StudentDocument;
use App\Modules\StudentDossiers\Models\StudentDocumentVersion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Purge des pièces ORPHELINES (roadmap « restes connus ») : fichiers
 * présents sur le stockage privé SANS ligne en base (cascade BD sans
 * suppression physique, crash, restoration partielle).
 *
 * Racines scannées — UNIQUEMENT les racines documentaires connues :
 *   local    · archives/            → documents_academiques.chemin
 *   private  · student-dossiers/   → student_documents + versions +
 *                                    generated_documents (storage_path)
 *   private  · online-requests/     → online_request_documents.storage_path
 * Jamais de scan global : sauvegardes, imports, exports, OCR temp
 * ne sont pas concernés. Dry-run par défaut ; --execute supprime.
 */
class PurgerOrphelins extends Command
{
    protected $signature = 'documents:purger-orphelins
                            {--execute : Supprime réellement les fichiers (sinon : simulation)}';

    protected $description = 'Détecte (et supprime avec --execute) les fichiers GED sans ligne en base — dry-run par défaut.';

    /** @var array<int, string> */
    private array $rapport = [];

    public function handle(): int
    {
        $this->analyser(
            disque: 'local',
            racine: 'archives',
            connus: DocumentAcademique::pluck('chemin')->filter()->all(),
        );

        $this->analyser(
            disque: 'private',
            racine: 'student-dossiers',
            connus: array_merge(
                StudentDocument::pluck('storage_path')->filter()->all(),
                StudentDocumentVersion::pluck('storage_path')->filter()->all(),
                GeneratedDocument::pluck('storage_path')->filter()->all(),
            ),
        );

        $this->analyser(
            disque: 'private',
            racine: 'online-requests',
            connus: OnlineRequestDocument::pluck('storage_path')->filter()->all(),
        );

        $total = array_sum(array_column($this->rapport, 'orphelins'));
        $octets = array_sum(array_column($this->rapport, 'octets'));

        foreach ($this->rapport as $ligne) {
            $this->line(sprintf(
                '  %-9s %-18s %4d orphelin(s) — %s',
                $ligne['disque'],
                $ligne['racine'],
                $ligne['orphelins'],
                $this->formaterTaille($ligne['octets']),
            ));
        }

        $this->info(sprintf('%d fichier(s) orphelin(s) — %s%s',
            $total,
            $this->formaterTaille($octets),
            $this->option('execute') ? ' — supprimés.' : ' — simulation (ajoutez --execute pour supprimer).',
        ));

        Log::channel(config('logging.default'))->info('documents.purge_orphelins', [
            'total' => $total,
            'execute' => (bool) $this->option('execute'),
            'racines' => array_map(fn ($l) => $l['racine'], $this->rapport),
        ]);

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $connus
     */
    private function analyser(string $disque, string $racine, array $connus): void
    {
        $stockage = Storage::disk($disque);

        if (! $stockage->exists($racine)) {
            $this->rapport[] = ['disque' => $disque, 'racine' => $racine, 'orphelins' => 0, 'octets' => 0];

            return;
        }

        $connus = array_flip($connus);
        $orphelins = 0;
        $octets = 0;

        foreach ($stockage->allFiles($racine) as $fichier) {
            if (isset($connus[$fichier])) {
                continue;
            }

            $orphelins++;
            $octets += (int) $stockage->size($fichier);

            if ($this->option('execute')) {
                $stockage->delete($fichier);
            }
        }

        $this->rapport[] = ['disque' => $disque, 'racine' => $racine, 'orphelins' => $orphelins, 'octets' => $octets];
    }

    private function formaterTaille(int $octets): string
    {
        if ($octets >= 1048576) {
            return round($octets / 1048576, 1).' Mo';
        }

        if ($octets >= 1024) {
            return round($octets / 1024, 1).' Ko';
        }

        return $octets.' o';
    }
}
