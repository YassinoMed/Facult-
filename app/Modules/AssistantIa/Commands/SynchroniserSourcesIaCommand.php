<?php

namespace App\Modules\AssistantIa\Commands;

use App\Modules\AssistantIa\Models\AiDocumentIndex;
use App\Modules\AssistantIa\Services\RegistreSourcesConnaissances;
use Illuminate\Console\Command;

class SynchroniserSourcesIaCommand extends Command
{
    /**
     * Signature de la commande console.
     *
     * @var string
     */
    protected $signature = 'assistant-ia:sync-sources
                            {--forcer : Forcer la réindexation complète même sans modification d’empreinte}
                            {--init : Initialiser et enregistrer l’ensemble des sources Markdown du projet}';

    /**
     * Description de la commande console.
     *
     * @var string
     */
    protected $description = 'Synchronise et indexe l’ensemble des sources documentaires Markdown dans le RAG UniRegistre AI';

    /**
     * Exécute la commande console.
     */
    public function handle(RegistreSourcesConnaissances $registre): int
    {
        $this->info('========================================================================');
        $this->info('  UniRegistre AI — Synchronisation du Knowledge Hub & Index RAG');
        $this->info('========================================================================');

        if ($this->option('init')) {
            $this->comment('-> Enregistrement et découverte de toutes les sources Markdown...');
            $sources = $registre->initialiserToutesLesSources();
            $this->info("   [OK] {$sources->count()} sources de connaissances enregistrées.");
        }

        $sources = $registre->sourcesActives();
        if ($sources->isEmpty()) {
            $this->warn('Aucune source active trouvée. Utilisez l’option --init pour enregistrer les sources fondamentales et étendues.');

            return self::FAILURE;
        }

        $forcer = (bool) $this->option('forcer');
        $this->comment("-> Synchronisation de {$sources->count()} sources actives (forcer = ".($forcer ? 'OUI' : 'NON').')...');

        $lignesTableau = [];
        $totalDocs = 0;
        $totalMorceaux = 0;
        $nbSucces = 0;
        $nbErreurs = 0;

        foreach ($sources as $source) {
            $resultat = $registre->synchroniserSource($source, $forcer);

            if ($resultat['succes']) {
                $nbSucces++;
                $totalDocs += $resultat['documents'];
                $totalMorceaux += $resultat['morceaux'];
                $statutAffichage = '<info>SUCCÈS</info>';
            } else {
                $nbErreurs++;
                $statutAffichage = '<error>ERREUR</error>';
            }

            $lignesTableau[] = [
                $source->id,
                $source->nom,
                $source->type,
                $source->origine,
                $resultat['documents'],
                $resultat['morceaux'],
                $statutAffichage,
            ];
        }

        $this->table(
            ['ID', 'Source', 'Type', 'Origine', 'Documents', 'Fragments RAG', 'Statut'],
            $lignesTableau
        );

        $totalEnBase = AiDocumentIndex::count();
        $totalObsoletes = AiDocumentIndex::where('statut_document', 'obsolete')->count();

        $this->info('------------------------------------------------------------------------');
        $this->info("Sources traitées : {$sources->count()} ({$nbSucces} succès, {$nbErreurs} erreurs)");
        $this->info("Documents traités lors de ce passage : {$totalDocs}");
        $this->info("Fragments RAG en base documentaire  : {$totalEnBase} (dont {$totalObsoletes} obsolètes déclassés)");
        $this->info('========================================================================');

        return self::SUCCESS;
    }
}
