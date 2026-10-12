<?php

namespace App\Modules\StudentDossiers\Jobs;

use App\Models\ImportReport;
use App\Models\User;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\Evaluation\Models\Deliberation;
use App\Modules\StudentDossiers\Services\Pdf\OfficialDocumentService;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Génération massive de documents officiels (Phase 7 §75-76) : jamais
 * 10 000 PDF dans une requête HTTP. Portée = décisions publiées d'une
 * délibération, chunk 200, rapport suivi, notification finale, retry
 * file. Idempotent (anti-régénération par hash de contenu).
 */
class GenerateOfficialDocumentsJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public function __construct(
        public readonly int $rapportId,
        public readonly int $deliberationId,
        public readonly string $type,
        public readonly int $userId,
    ) {}

    public function handle(OfficialDocumentService $officiels, NotificationService $notifications): void
    {
        $rapport = ImportReport::find($this->rapportId);

        if ($rapport === null || $rapport->status === 'expired') {
            return;
        }

        $deliberation = Deliberation::find($this->deliberationId);

        if ($deliberation === null) {
            $rapport->update(['status' => 'failed', 'errors' => ['Délibération introuvable.']]);

            return;
        }

        $debut = microtime(true);
        $rapport->update(['status' => 'processing']);

        $generes = 0;
        $ignores = 0;
        $erreurs = [];

        try {
            DecisionDeliberation::where('deliberation_id', $deliberation->id)
                ->where('publie', true)
                ->with('etudiant')
                ->chunkById(200, function ($decisions) use ($officiels, &$generes, &$ignores, &$erreurs) {
                    foreach ($decisions as $decision) {
                        if ($decision->etudiant === null) {
                            $ignores++;

                            continue;
                        }

                        try {
                            $officiels->generer(
                                $decision->etudiant, $this->type,
                                (int) $decision->inscription_academique_id, $this->userId === 0 ? null : $this->userId,
                            );
                            $generes++;
                        } catch (\DomainException $e) {
                            // Règle métier (ex. attestation sans admis*) :
                            // compté, pas d'échec global.
                            $ignores++;
                        } catch (\Throwable $e) {
                            $erreurs[] = "Étudiant #{$decision->etudiant_id} : génération impossible.";
                        }
                    }
                });

            $rapport->update([
                'status' => 'completed',
                'total_rows' => $generes + $ignores + count($erreurs),
                'valid_rows' => $generes,
                'errors' => array_slice($erreurs, 0, 50),
                'duration_ms' => (int) ((microtime(true) - $debut) * 1000),
            ]);

            $destinataire = $this->userId === 0 ? null : User::find($this->userId);

            if ($destinataire !== null) {
                $notifications->notifierUser(
                    $destinataire,
                    'document.lot',
                    'Génération terminée',
                    "{$generes} document(s) généré(s), {$ignores} ignoré(s), ".count($erreurs).' erreur(s).',
                    route('admin.student-dossiers.index'),
                );
            }
        } catch (\Throwable $e) {
            $rapport->update([
                'status' => 'failed',
                'errors' => ['Génération massive impossible : réduisez le périmètre.'],
                'duration_ms' => (int) ((microtime(true) - $debut) * 1000),
            ]);

            throw $e;
        }
    }
}
