<?php

namespace App\Console\Commands;

use App\Modules\StudentDossiers\Events\DocumentExpired;
use App\Modules\StudentDossiers\Models\GeneratedDocument;
use App\Modules\StudentDossiers\Models\StudentDocument;
use Illuminate\Console\Command;

/**
 * Expiration documentaire (§22.15) : bascule `expired` les documents
 * dont expires_at est dépassé. Aucune suppression (destruction =
 * workflow manuel contrôlé, jamais automatique).
 */
class MarquerDocumentsExpires extends Command
{
    protected $signature = 'documents:marquer-expires';

    protected $description = 'Bascule en expiré les documents dont la date est dépassée.';

    public function handle(): int
    {
        $documents = 0;

        foreach (StudentDocument::where('status', '!=', 'expired')->where('expires_at', '<=', now())->cursor() as $document) {
            $document->update(['status' => 'expired']);
            DocumentExpired::dispatch($document);
            $documents++;
        }

        $generes = GeneratedDocument::where('expires_at', '<=', now())->whereNotNull('expires_at')->count();

        $this->info("Documents expirés : {$documents}, générés périmés : {$generes}.");

        return self::SUCCESS;
    }
}
