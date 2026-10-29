<?php

namespace App\Console\Commands;

use App\Models\InscriptionAcademique;
use App\Modules\StudentDossiers\Services\ReutilisationDocumentsService;
use Illuminate\Console\Command;

/**
 * LOOP-UNIREGISTRE-DOCUMENTS-REUTILISABLES-01 : rattrapage idempotent
 * des inscriptions créées avant la mise en place de la réutilisation
 * automatique. Aucune écriture destructive, aucun fichier déplacé.
 */
class AssocierDocumentsInscriptions extends Command
{
    protected $signature = 'documents:associer-inscriptions
        {--etudiant= : Identifiant de l’étudiant}
        {--annee= : Identifiant de l’année universitaire}
        {--tout : Resynchronise aussi les inscriptions ouvertes déjà associées}
        {--dry-run : Simule sans écrire}';

    protected $description = 'Associe les pièces permanentes aux inscriptions académiques ouvertes existantes.';

    public function handle(ReutilisationDocumentsService $service): int
    {
        if (! config('modules.student-dossiers.active', false)) {
            $this->warn('Module StudentDossiers désactivé : aucune association créée.');

            return self::SUCCESS;
        }

        // Les années clôturées ne sont JAMAIS réécrites : le rattrapage ne
        // cible que les inscriptions encore ouvertes.
        $requete = InscriptionAcademique::query()
            ->with(['etudiant', 'anneeUniversitaire'])
            ->where('statut', 'en_cours');

        if ($etudiant = $this->option('etudiant')) {
            $requete->where('etudiant_id', (int) $etudiant);
        }

        if ($annee = $this->option('annee')) {
            $requete->where('annee_universitaire_id', (int) $annee);
        }

        $tout = (bool) $this->option('tout');

        if (! $tout) {
            // Backfill idempotent : une inscription déjà associée garde ses
            // décisions (aucune réécriture implicite de l'historique).
            $requete->whereDoesntHave('documentsInscription');
        }

        $dryRun = (bool) $this->option('dry-run');
        $traitees = 0;
        $reutilisees = 0;
        $erreurs = 0;

        $requete->chunkById(100, function ($inscriptions) use ($service, $dryRun, &$traitees, &$reutilisees, &$erreurs): void {
            foreach ($inscriptions as $inscription) {
                try {
                    $bilan = $dryRun
                        ? $service->etatInscription($inscription)
                        : $service->synchroniser($inscription);

                    $traitees++;
                    $reutilisees += count($bilan['reutilises'] ?? []);
                } catch (\Throwable $e) {
                    $erreurs++;
                    report($e);
                    $this->warn("Inscription #{$inscription->id} : échec de synchronisation.");
                }
            }
        });

        $this->info(sprintf(
            '%s : %d inscription(s) ouverte(s), %d pièce(s) réutilisée(s), %d erreur(s).',
            $dryRun ? 'Simulation' : 'Terminé',
            $traitees,
            $reutilisees,
            $erreurs,
        ));

        return $erreurs > 0 ? self::FAILURE : self::SUCCESS;
    }
}
