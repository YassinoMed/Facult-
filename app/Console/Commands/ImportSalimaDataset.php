<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Enseignement\Models\TypeEnseignement;
use App\Modules\Evaluation\Models\TypeEvaluation;
use App\Services\DataExchange\DataImportService;
use App\Services\DataExchange\DefinitionRegistry;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;

/**
 * Commande d'importation du jeu de données complet SALIMA 2026-2027 (§2/§41-42).
 *
 * Exécute l'importation ordonnée des 32 fichiers CSV selon le graphe
 * strict des dépendances LMD via le moteur unifié DataExchange.
 */
class ImportSalimaDataset extends Command
{
    protected $signature = 'salima:import 
                            {--mode=upsert : Mode d\'importation (creer ou upsert)}
                            {--path= : Chemin personnalisé du dossier contenant les CSV}';

    protected $description = 'Importe le jeu complet de données académiques et administratives SALIMA (32 fichiers CSV).';

    /**
     * Matrice ordonnée des fichiers SALIMA et de leurs entités DataExchange.
     *
     * @var array<string, string>
     */
    private const FICHIERS_SALIMA = [
        '01_annees_universitaires.csv' => 'annees-universitaires',
        '02_etablissements.csv' => 'facultes',
        '03_facultes.csv' => 'facultes',
        '04_departements.csv' => 'departements',
        '05_domaines.csv' => 'domaines',
        '06_mentions.csv' => 'mentions',
        '07_specialites.csv' => 'specialites',
        '08_parcours.csv' => 'programmes',
        '09_diplomes.csv' => 'type-diplomes',
        '10_niveaux.csv' => 'niveaux',
        '11_semestres.csv' => 'semestres',
        '12_ues.csv' => 'ues',
        '13_ecues.csv' => 'ecues',
        '14_matieres.csv' => 'matieres',
        '15_enseignants.csv' => 'enseignants',
        '16_batiments.csv' => 'batiments',
        '17_salles.csv' => 'salles',
        '18_groupes.csv' => 'groupes',
        '19_etudiants.csv' => 'etudiants',
        '20_inscriptions.csv' => 'inscriptions-academiques',
        '21_enseignements.csv' => 'enseignements',
        '22_affectations_enseignants.csv' => 'affectations-enseignants',
        '23_affectations_groupes.csv' => 'affectations-groupes',
        '24_affectations_salles.csv' => 'affectations-salles',
        '25_emplois_du_temps.csv' => 'emplois-du-temps',
        '26_evaluations.csv' => 'evaluations',
        '27_examens.csv' => 'examens',
        '28_notes.csv' => 'notes',
        '29_deliberations.csv' => 'deliberations',
        '30_decisions_jury.csv' => 'decisions-jury',
        '31_progressions.csv' => 'progressions',
        '32_documents_academiques.csv' => 'documents-academiques',
    ];

    public function handle(DataImportService $importService, DefinitionRegistry $definitions): int
    {
        $mode = $this->option('mode') ?: 'upsert';
        if (! in_array($mode, ['creer', 'upsert'], true)) {
            $this->error("Mode invalide « {$mode} ». Utilisez « creer » ou « upsert ».");

            return self::FAILURE;
        }

        $dossier = $this->option('path')
            ?: base_path('salima_import_csv_2026_2027');

        if (! is_dir($dossier)) {
            $this->error("Le dossier « {$dossier} » est introuvable.");

            return self::FAILURE;
        }

        $admin = User::whereNull('faculte_id')->where('role', 'super_admin')->first()
            ?? User::first();

        if ($admin === null) {
            $this->error("Aucun utilisateur administrateur disponible pour exécuter l'importation.");

            return self::FAILURE;
        }

        $this->info("Démarrage de l'importation SALIMA 2026-2027 (mode: {$mode}, auteur: {$admin->email})...");

        // Configuration préalable des modalités requises par les ECUE
        foreach (['CM', 'CM_TD', 'TD', 'TP', 'CM_TP'] as $code) {
            TypeEvaluation::updateOrCreate(
                ['code' => $code],
                ['libelle' => $code, 'actif' => true]
            );
            TypeEnseignement::firstOrCreate(
                ['code' => $code],
                ['nom' => $code, 'actif' => true]
            );
        }

        $resultats = [];
        $nbSucces = 0;
        $nbEchecs = 0;

        foreach (self::FICHIERS_SALIMA as $nomFichier => $entite) {
            $cheminFichier = "{$dossier}/{$nomFichier}";

            if (! file_exists($cheminFichier)) {
                $this->warn("Fichier manquant : {$nomFichier}");
                $resultats[] = [$nomFichier, $entite, 0, 'Fichier manquant'];
                $nbEchecs++;

                continue;
            }

            $uploadedFile = new UploadedFile(
                $cheminFichier,
                $nomFichier,
                'text/csv',
                null,
                true
            );

            try {
                $analyse = $importService->analyser($uploadedFile, $entite, $admin->id);
                $lignesValides = $analyse['resultat']->valides;

                if (count($lignesValides) === 0) {
                    $this->error("Aucune ligne valide dans {$nomFichier}.");
                    $resultats[] = [$nomFichier, $entite, 0, 'Aucune ligne valide'];
                    $nbEchecs++;

                    continue;
                }

                $definition = $definitions->import($entite);
                $import = $importService->executer(
                    $definition,
                    $entite,
                    $lignesValides,
                    $nomFichier,
                    'csv',
                    $mode,
                    $admin->id,
                    'cli'
                );

                $succesCount = count($lignesValides);
                $resultats[] = [$nomFichier, $entite, $succesCount, 'Succès'];
                $nbSucces++;
                $this->line("  ✓ {$nomFichier} ({$entite}) : {$succesCount} ligne(s)");
            } catch (\Throwable $e) {
                $this->error("Erreur lors de l'import de {$nomFichier} : {$e->getMessage()}");
                $resultats[] = [$nomFichier, $entite, 0, 'Erreur: '.$e->getMessage()];
                $nbEchecs++;
            }
        }

        $this->newLine();
        $this->table(['Fichier', 'Entité', 'Lignes', 'Statut'], $resultats);

        if ($nbEchecs > 0) {
            $this->warn("Importation terminée avec {$nbSucces} fichier(s) réussi(s) et {$nbEchecs} échec(s).");

            return self::FAILURE;
        }

        $this->info("Importation SALIMA 2026-2027 complétée avec succès ({$nbSucces}/".count(self::FICHIERS_SALIMA).' fichiers).');

        return self::SUCCESS;
    }
}
