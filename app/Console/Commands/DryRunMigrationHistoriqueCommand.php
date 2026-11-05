<?php

namespace App\Console\Commands;

use App\Models\Etudiant;
use App\Services\Acquis\SimulationMigrationHistoriqueService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * LOOP 41 — Dry-run reproductible de migration historique des acquis.
 *
 * Contrat strict :
 * - LECTURE SEULE : aucune écriture, aucune migration, aucune sanction.
 * - Aucune donnée personnelle en sortie : le CIN, le nom et le prénom sont
 *   remplacés par une empreinte tronquée (identification technique sans PII).
 * - Le périmètre est explicite (faculté, limite, décalage) et le rapport
 *   porte les contrôles d'intégrité avant/après exécution.
 *
 * Le rapport produit sert de preuve pour `LOOP-41-DRY-RUN.md` et pour la
 * constitution du périmètre pilote (LOOP 42).
 */
class DryRunMigrationHistoriqueCommand extends Command
{
    protected $signature = 'acquis:dry-run-migration
                            {--faculte= : Restreindre à une faculté (identifiant)}
                            {--programme= : Restreindre à un programme (code)}
                            {--limite=0 : Nombre maximal de dossiers (0 = tous)}
                            {--decalage=0 : Décalage de pagination}
                            {--dossiers : Inclure le détail par dossier (sans PII)}
                            {--json : Sortie JSON exploitable par la CI}';

    protected $description = 'Exécute le dry-run de migration historique des acquis en lecture seule stricte (aucune écriture, aucune PII).';

    /**
     * Tables académiques dont l'intégrité est vérifiée avant/après.
     *
     * @var array<int, string>
     */
    private const TABLES_SURVEILLEES = [
        'etudiants',
        'inscriptions_academiques',
        'notes',
        'matieres',
        'unites_enseignement',
        'maquettes',
        'evaluation_deliberations',
        'evaluation_decisions',
        'historique_academique',
        'parcours_dispenses',
        'parcours_equivalences',
        'parcours_plans',
        'archives_etudiants',
    ];

    public function handle(SimulationMigrationHistoriqueService $service): int
    {
        $avant = $this->empreinteTables();

        $requete = Etudiant::query()
            ->when($this->option('faculte') !== null, fn ($q) => $q->where('faculte_id', (int) $this->option('faculte')))
            ->when($this->option('programme') !== null, function ($q): void {
                $code = (string) $this->option('programme');
                $q->whereHas('programmeRef', fn ($p) => $p->where('code', $code));
            })
            ->orderBy('id');

        $decalage = max(0, (int) $this->option('decalage'));
        if ($decalage > 0) {
            $requete->skip($decalage);
        }

        $limite = max(0, (int) $this->option('limite'));
        if ($limite > 0) {
            $requete->take($limite);
        }

        $repartition = [
            'MIGRABLE_AUTOMATIQUEMENT_SOUS_REGLE_CONFIRME' => 0,
            'MIGRABLE_APRES_VALIDATION' => 0,
            'REVUE_HUMAINE_REQUISE' => 0,
            'SOURCE_INSUFFISANTE' => 0,
            'BLOQUE_DONNEES' => 0,
            'HORS_PERIMETRE' => 0,
        ];
        $anomaliesParType = [];
        $avertissementsParType = [];
        $statutsRegle = [];
        $dossiersAvecCredits = 0;
        $creditsTotalReconnus = 0;
        $dossiers = [];
        $total = 0;
        $totalAnomalies = 0;
        $totalAvertissements = 0;
        $erreurs = 0;

        foreach ($requete->cursor() as $etudiant) {
            $total++;

            try {
                $simulation = $service->simulerDossierEtudiant($etudiant);
            } catch (Throwable $e) {
                $erreurs++;
                if ($this->option('dossiers')) {
                    $dossiers[] = [
                        'reference_technique' => $this->referenceTechnique($etudiant),
                        'statut_migration' => 'BLOQUE_DONNEES',
                        'anomalies' => ['ERREUR_EXECUTION'],
                        'justification' => 'Le dossier n’a pas pu être simulé (erreur technique).',
                    ];
                }

                continue;
            }

            $statut = $simulation['statut_migration'];
            $repartition[$statut] = ($repartition[$statut] ?? 0) + 1;
            $totalAnomalies += count($simulation['anomalies']);
            $totalAvertissements += count($simulation['avertissements'] ?? []);

            foreach ($simulation['anomalies'] as $anomalie) {
                $cle = $this->normaliserAnomalie($anomalie);
                $anomaliesParType[$cle] = ($anomaliesParType[$cle] ?? 0) + 1;
            }

            foreach (($simulation['avertissements'] ?? []) as $avertissement) {
                $cle = $this->normaliserAnomalie($avertissement);
                $avertissementsParType[$cle] = ($avertissementsParType[$cle] ?? 0) + 1;
            }

            $statutRegle = (string) ($simulation['resultat_calcule']['statut_regle'] ?? 'INDETERMINE');
            $statutsRegle[$statutRegle] = ($statutsRegle[$statutRegle] ?? 0) + 1;

            $credits = (int) ($simulation['resultat_calcule']['total_credits_reconnus'] ?? 0);
            if ($credits > 0) {
                $dossiersAvecCredits++;
                $creditsTotalReconnus += $credits;
            }

            if ($this->option('dossiers')) {
                $dossiers[] = [
                    'reference_technique' => $this->referenceTechnique($etudiant),
                    'statut_migration' => $statut,
                    'anomalies' => array_map(fn (string $a): string => $this->normaliserAnomalie($a), $simulation['anomalies']),
                    'avertissements' => array_map(fn (string $a): string => $this->normaliserAnomalie($a), $simulation['avertissements'] ?? []),
                    'justification' => $simulation['justification'],
                    'statut_regle' => $simulation['resultat_calcule']['statut_regle'] ?? null,
                    'regime' => $simulation['resultat_calcule']['regime_cible'] ?? null,
                    'programme_code' => $simulation['destination_theorique']['programme_code'] ?? null,
                    'empreinte_source' => $simulation['empreinte_source'],
                    'empreinte_resultat' => $simulation['empreinte_resultat'],
                ];
            }
        }

        $apres = $this->empreinteTables();
        $integrite = $avant === $apres;

        $rapport = [
            'loop' => 'LOOP 41',
            'mode_execution' => 'LECTURE_SEULE_STRICTE',
            'version_moteur' => 'SimulationMigrationHistoriqueService@loop41',
            'execute_le' => now()->toIso8601String(),
            'environnement' => app()->environment(),
            'connexion' => [
                'driver' => DB::connection()->getDriverName(),
                'base' => DB::connection()->getDatabaseName(),
            ],
            'perimetre' => [
                'faculte_id' => $this->option('faculte') !== null ? (int) $this->option('faculte') : null,
                'programme_code' => $this->option('programme'),
                'limite' => $limite,
                'decalage' => $decalage,
            ],
            'totaux' => [
                'dossiers_simules' => $total,
                'anomalies_detectees' => $totalAnomalies,
                'avertissements_detectes' => $totalAvertissements,
                'erreurs_execution' => $erreurs,
                'dossiers_avec_credits_a_transferer' => $dossiersAvecCredits,
                'credits_total_reconnus' => $creditsTotalReconnus,
            ],
            'repartition_statuts' => $repartition,
            'repartition_statuts_regle' => $statutsRegle,
            'anomalies_par_type' => $anomaliesParType,
            'avertissements_par_type' => $avertissementsParType,
            'controle_integrite' => [
                'tables' => self::TABLES_SURVEILLEES,
                'avant' => $avant,
                'apres' => $apres,
                'identique' => $integrite,
                'ecritures_effectuees' => $integrite ? 0 : null,
            ],
            'dossiers' => $dossiers,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($rapport, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $integrite ? self::SUCCESS : self::FAILURE;
        }

        $this->info('UniRegistre — Dry-run de migration historique (LOOP 41)');
        $this->line(sprintf('Environnement : %s / %s', $rapport['environnement'], $rapport['connexion']['driver']));
        $this->line(sprintf('Dossiers simulés : %d', $total));
        $this->line(sprintf('Anomalies détectées : %d', $totalAnomalies));
        $this->line(sprintf('Avertissements : %d', $totalAvertissements));
        $this->line(sprintf('Erreurs techniques : %d', $erreurs));
        $this->newLine();

        foreach ($repartition as $statut => $nombre) {
            $this->line(sprintf('  %-48s %6d', $statut, $nombre));
        }

        if ($anomaliesParType !== []) {
            $this->newLine();
            $this->line('Anomalies par type :');
            foreach ($anomaliesParType as $type => $nombre) {
                $this->line(sprintf('  %-60s %6d', $type, $nombre));
            }
        }

        if ($avertissementsParType !== []) {
            $this->newLine();
            $this->line('Avertissements par type :');
            foreach ($avertissementsParType as $type => $nombre) {
                $this->line(sprintf('  %-60s %6d', $type, $nombre));
            }
        }

        $this->newLine();
        $this->line(sprintf('Intégrité des tables académiques : %s', $integrite ? 'INCHANGÉE' : 'MODIFIÉE'));

        if ($this->option('dossiers')) {
            $this->newLine();
            foreach ($dossiers as $dossier) {
                $this->line(sprintf('  %s  %-44s %s', $dossier['reference_technique'], $dossier['statut_migration'], $dossier['justification']));
            }
        }

        if (! $integrite) {
            $this->error('Le dry-run a modifié au moins une table académique : arrêt immédiat.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Empreinte structurelle (nombre de lignes) des tables académiques.
     *
     * @return array<string, int>
     */
    private function empreinteTables(): array
    {
        $empreinte = [];

        foreach (self::TABLES_SURVEILLEES as $table) {
            try {
                $empreinte[$table] = (int) DB::table($table)->count();
            } catch (Throwable) {
                $empreinte[$table] = -1;
            }
        }

        return $empreinte;
    }

    /**
     * Référence technique non nominative : ni CIN, ni nom, ni prénom.
     */
    private function referenceTechnique(Etudiant $etudiant): string
    {
        return 'DRYRUN-ETU-'.substr(hash('sha256', $etudiant->id.'|'.(string) $etudiant->cin), 0, 16);
    }

    /**
     * Réduit une anomalie à une catégorie stable (les compteurs sont retirés)
     * afin de permettre l'agrégation sans perdre la nature du constat.
     */
    private function normaliserAnomalie(string $anomalie): string
    {
        $normalisee = preg_replace('/\d+/', 'N', $anomalie) ?? $anomalie;

        return mb_substr(trim($normalisee), 0, 180);
    }
}
