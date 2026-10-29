<?php

namespace App\Jobs;

use App\Models\ImportReport;
use App\Models\User;
use App\Services\Exports\ListeEtudiantsService;
use App\Services\Exports\PopulationDataset;
use App\Services\Io\IoFactory;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Export différé des listes population (§2.7-2.11) : paramètres
 * sérialisables uniquement (jamais de Query Builder) — la requête est
 * reconstruite dans le job via ListeEtudiantsService (source unique).
 * Historique = ImportReport (pending/processing/completed/failed/expired),
 * notification via NotificationService, fichier privé expirant.
 */
class GeneratePopulationExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    /**
     * @param  array<string, mixed>  $filtres
     * @param  array<int, string>|null  $colonnes
     * @param  array<string, mixed>  $contexte  contexte scalaire du dataset
     */
    public function __construct(
        public readonly int $rapportId,
        public readonly int $userId,
        public readonly string $format,
        public readonly array $filtres,
        public readonly ?array $colonnes,
        public readonly ?string $regroupement,
        public readonly ?string $dataset = null,
        public readonly array $contexte = [],
    ) {}

    public function handle(ListeEtudiantsService $listes, NotificationService $notifications): void
    {
        $rapport = ImportReport::find($this->rapportId);

        if ($rapport === null || $rapport->status === 'expired') {
            return;
        }

        $debut = microtime(true);

        // Autorisation réévaluée à l'exécution (même convention que
        // ExecuterAffectationMasseLot) : le demandeur doit toujours
        // exister, être actif et conserver `data.export`. Sinon aucun
        // export sensible n'est produit et le rapport passe en échec
        // (message générique, sans PII ni détail de filtres).
        $utilisateur = User::find($this->userId);
        if ($utilisateur === null || ! $utilisateur->estActif() || ! $utilisateur->hasPermission('data.export')) {
            $rapport->update([
                'status' => 'failed',
                'errors' => ['Demandeur introuvable, désactivé ou permission data.export retirée.'],
                'duration_ms' => (int) ((microtime(true) - $debut) * 1000),
            ]);

            return;
        }

        $rapport->update(['status' => 'processing']);

        try {
            // Dataset nommé (§23) : mêmes filtres/colonnes résolus via le
            // registre, requête toujours reconstruite via la centrale.
            $filtres = $this->dataset !== null
                ? PopulationDataset::filtresPour($this->dataset, $this->contexte)
                : $this->filtres;
            $colonnes = $this->dataset !== null
                ? PopulationDataset::colonnesPour($this->dataset, $this->contexte)
                : $this->colonnes;

            $query = $listes->requete($filtres, $utilisateur);

            if ($this->regroupement !== null) {
                $resultat = $listes->sectionsRegroupees($query, $utilisateur, $this->colonnes, $this->regroupement);
                $lignes = [];

                foreach ($resultat['sections'] as $section) {
                    foreach ($section['lignes'] as $ligne) {
                        $lignes[] = ['section' => $section['titre']] + $ligne;
                    }
                }

                $jeu = [
                    'entetes' => array_merge(['section'], $resultat['entetes']),
                    'lignes' => $lignes,
                ];
            } else {
                $jeu = $listes->jeuDonnees($query, $utilisateur, $this->colonnes);
            }

            $contenu = IoFactory::serializerPour($this->format)->serialize($jeu['entetes'], $jeu['lignes']);
            $chemin = "listes/exports/{$rapport->id}.{$this->format}";
            Storage::disk('local')->put($chemin, $contenu);

            $rapport->update([
                'status' => 'completed',
                'total_rows' => count($jeu['lignes']),
                'valid_rows' => count($jeu['lignes']),
                'file_path' => $chemin,
                'filename' => 'liste-etudiants-'.now()->format('Ymd-His').'.'.$this->format,
                'duration_ms' => (int) ((microtime(true) - $debut) * 1000),
            ]);

            if ($utilisateur !== null) {
                $notifications->notifierUser(
                    $utilisateur,
                    'export.listes',
                    'Export terminé',
                    "Votre export {$this->format} (".count($jeu['lignes']).' lignes) est disponible.',
                    route('admin.listes.exports'),
                );
            }
        } catch (\Throwable $e) {
            $rapport->update([
                'status' => 'failed',
                'errors' => ['Export différé impossible : réessayez avec des filtres plus restrictifs.'],
                'duration_ms' => (int) ((microtime(true) - $debut) * 1000),
            ]);

            report($e);
        }
    }
}
