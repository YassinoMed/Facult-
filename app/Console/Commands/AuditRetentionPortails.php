<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit de rétention des portails — STRICTEMENT EN LECTURE SEULE.
 *
 * La commande ne supprime, n'anonymise et n'archive rien. Elle inventorie
 * les volumes, les dépendances et les échéances explicitement enregistrées
 * (`retention_until`) afin qu'une décision métier/juridique puisse être
 * prise sur des faits. Les durées non décidées restent `null` : aucune
 * valeur n'est inventée.
 */
class AuditRetentionPortails extends Command
{
    protected $signature = 'portails:audit-retention
                            {--json : Sortie JSON exploitable par un audit externe}
                            {--simulate : Rappelle le mode lecture seule (aucune suppression)}';

    protected $description = 'Audite la rétention (volumes, dépendances, échéances) sans rien supprimer ni anonymiser.';

    public function handle(): int
    {
        $ressources = (array) config('retention.ressources', []);
        $durees = (array) config('retention.durees', []);
        $resultats = [];

        foreach ($ressources as $cle => $definition) {
            $table = (string) ($definition['table'] ?? $cle);

            if (! Schema::hasTable($table)) {
                $resultats[$cle] = [
                    'ressource' => $cle,
                    'libelle' => $definition['libelle'] ?? $cle,
                    'table' => $table,
                    'disponible' => false,
                    'total' => 0,
                    'dependances' => [],
                    'motif_indisponible' => 'table absente',
                ];

                continue;
            }

            $total = (int) DB::table($table)->count();
            $supprimesLogiquement = Schema::hasColumn($table, 'deleted_at')
                ? (int) DB::table($table)->whereNotNull('deleted_at')->count()
                : 0;

            $echeanceDepassee = null;
            if (isset($definition['colonne_echeance'])
                && Schema::hasColumn($table, (string) $definition['colonne_echeance'])) {
                $echeanceDepassee = (int) DB::table($table)
                    ->whereNotNull((string) $definition['colonne_echeance'])
                    ->where((string) $definition['colonne_echeance'], '<', now()->toDateString())
                    ->count();
            }

            $colonneDate = (string) ($definition['colonne_date'] ?? 'created_at');
            $plusAncien = Schema::hasColumn($table, $colonneDate)
                ? DB::table($table)->min($colonneDate)
                : null;
            $plusRecent = Schema::hasColumn($table, $colonneDate)
                ? DB::table($table)->max($colonneDate)
                : null;

            $dependances = [];
            foreach ((array) ($definition['dependances'] ?? []) as $dependance) {
                $tableDependance = (string) ($dependance['table'] ?? '');
                $colonne = (string) ($dependance['colonne'] ?? '');

                if ($tableDependance === '' || $colonne === ''
                    || ! Schema::hasTable($tableDependance)
                    || ! Schema::hasColumn($tableDependance, $colonne)) {
                    continue;
                }

                $dependances[$tableDependance] = (int) DB::table($tableDependance)
                    ->whereIn($colonne, DB::table($table)->select('id'))
                    ->count();
            }

            $resultats[$cle] = [
                'ressource' => $cle,
                'libelle' => (string) ($definition['libelle'] ?? $cle),
                'table' => $table,
                'disponible' => true,
                'total' => $total,
                'supprimes_logiquement' => $supprimesLogiquement,
                'echeance_explicite_depassee' => $echeanceDepassee,
                'plus_ancien' => $plusAncien,
                'plus_recent' => $plusRecent,
                'classe' => (string) ($definition['classe'] ?? 'non_classee'),
                'action_proposee' => (string) ($definition['action'] ?? 'revue_manuelle'),
                'duree_jours' => $durees[(string) ($definition['classe'] ?? '')] ?? null,
                'dependances' => $dependances,
            ];
        }

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'lecture_seule' => true,
                'suppression_effectuee' => false,
                'genere_le' => now()->toIso8601String(),
                'ressources' => $resultats,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->warn('MODE LECTURE SEULE — aucune suppression, anonymisation ou archivage n’est effectué.');

        $lignes = [];
        foreach ($resultats as $resultat) {
            if (($resultat['disponible'] ?? false) !== true) {
                $lignes[] = [
                    $resultat['libelle'], 'indisponible', '—', '—', '—', '—',
                ];

                continue;
            }

            $dependances = [];
            foreach ($resultat['dependances'] as $table => $nombre) {
                $dependances[] = $table.'='.$nombre;
            }

            $lignes[] = [
                $resultat['libelle'],
                $resultat['total'],
                $resultat['classe'],
                $resultat['action_proposee'],
                $resultat['duree_jours'] === null ? 'non décidée' : $resultat['duree_jours'].' j',
                $resultat['echeance_explicite_depassee'] === null
                    ? ($dependances === [] ? '—' : implode(', ', $dependances))
                    : 'échéance dépassée : '.$resultat['echeance_explicite_depassee']
                        .($dependances === [] ? '' : ' ; '.implode(', ', $dependances)),
            ];
        }

        $this->table(
            ['Ressource', 'Total', 'Classe', 'Action proposée', 'Durée', 'Dépendances / échéance'],
            $lignes,
        );

        $this->line('Aucune durée non décidée n’est appliquée. Les preuves académiques et financières ne sont jamais purgées automatiquement.');

        return self::SUCCESS;
    }
}
