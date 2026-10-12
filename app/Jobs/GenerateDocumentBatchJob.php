<?php

namespace App\Jobs;

use App\Models\DocumentBatch;
use App\Models\Etudiant;
use App\Services\Documents\DocumentGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 16 §16.4 : génération en masse par lots (un document à la
 * fois, mémoire bornée). Idempotent : un étudiant ne reçoit pas deux
 * fois le même document dans un lot relancé. Reprise : relance
 * uniquement les échecs.
 */
class GenerateDocumentBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public function __construct(
        public readonly int $lotId,
        public readonly array $etudiantIds = [],
        public readonly bool $repriseEchecsUniquement = false,
    ) {
        $this->onQueue('documents');
    }

    public function handle(DocumentGenerator $generateur): void
    {
        $lot = DocumentBatch::findOrFail($this->lotId);

        if ($lot->statut === 'annule') {
            return;
        }

        $lot->update(['statut' => 'en_cours']);

        $rapport = $lot->rapport ?? ['succes' => [], 'echecs' => [], 'ignores' => []];
        $dejaTraites = array_merge($rapport['succes'] ?? [], $rapport['ignores'] ?? []);

        if ($this->repriseEchecsUniquement) {
            $dejaTraites = array_merge($dejaTraites, []);
        }

        foreach ($this->etudiantIds as $etudiantId) {
            if (in_array($etudiantId, $dejaTraites, true)) {
                $lot->increment('ignores');

                continue;
            }

            try {
                $etudiant = Etudiant::find($etudiantId);

                if ($etudiant === null) {
                    $rapport['echecs'][] = ['etudiant_id' => $etudiantId, 'raison' => 'Étudiant introuvable.'];
                    $lot->increment('echoues');

                    continue;
                }

                // Complétude via Check : données incomplètes → ignoré
                // rapporté, jamais d'échec global pour une ligne (§16.6/9).
                if (trim((string) $etudiant->nom) === '' || trim((string) $etudiant->prenom) === '' || $etudiant->date_naissance === null) {
                    $rapport['ignores'][] = ['etudiant_id' => $etudiantId, 'raison' => 'Données incomplètes (nom/prénom/naissance).'];
                    $lot->increment('ignores');

                    continue;
                }

                $inscription = $etudiant->inscriptionsAcademiques()->latest('id')->first();

                if ($inscription === null) {
                    $rapport['ignores'][] = ['etudiant_id' => $etudiantId, 'raison' => 'Aucune inscription.'];
                    $lot->increment('ignores');

                    continue;
                }

                $generateur->generer($etudiant, $lot->type, [
                    'inscription_id' => $inscription->id,
                    'lot_id' => $lot->id,
                ], $lot->auteur_id);

                $rapport['succes'][] = $etudiantId;
                $lot->increment('reussis');
            } catch (\DomainException $e) {
                $rapport['echecs'][] = ['etudiant_id' => $etudiantId, 'raison' => $e->getMessage()];
                $lot->increment('echoues');
            }

            // Libération mémoire : un document à la fois (§16.4).
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }

        $lot->update(['rapport' => $rapport]);

        if (($lot->reussis + $lot->echoues + $lot->ignores) >= $lot->total) {
            $lot->update(['statut' => 'termine']);
        }

        unset($rapport);
    }
}
