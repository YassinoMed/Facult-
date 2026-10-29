<?php

namespace App\Modules\AiAssistant\Console;

use App\Modules\AiAssistant\Contracts\RechercheDocumentaire;
use App\Modules\AiAssistant\Services\RechercheIndexPartage;
use Illuminate\Console\Command;

/**
 * Commande d'indexation (Lot 6) : `ai-assistant:reindexer`.
 *
 * Reconstruit la base documentaire à partir de la liste EXPLICITE de
 * la configuration (aucune découverte automatique). Les fichiers
 * contenant des empreintes de PII/secrets sont sautés ; les morceaux
 * obsolètes passent en statut retire (soft-delete, réversible).
 */
final class ReindexerDocumentaire extends Command
{
    protected $signature = 'ai-assistant:reindexer {--d|details : afficher le détail}';

    protected $description = 'Réindexe la base documentaire explicite du module AI-ASSISTANT (aucune PII, aucun secret).';

    public function handle(RechercheDocumentaire $recherche): int
    {
        $insertions = $recherche->reindexer();

        if ($recherche instanceof RechercheIndexPartage) {
            $etat = $recherche->diagnostic();

            $this->info("Réindexage terminé : {$insertions} morceau(x) inséré(s).");
            $this->line("Sources actives : {$etat['actives']} — chunks actifs : {$etat['chunks']}.");
        } else {
            $this->info("Réindexage terminé : {$insertions} morceau(x) inséré(s).");
        }

        return self::SUCCESS;
    }
}
