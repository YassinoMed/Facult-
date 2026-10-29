<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LOOP-INSCRIPTION-UNIQUE-ANNUELLE-01 — garde de déploiement de la
 * contrainte UNIQUE (étudiant, année universitaire) sur les inscriptions
 * académiques.
 *
 * La contrainte `uniq_inscription_etudiant_annee` est créée par
 * `2026_09_24_100005_create_inscriptions_academiques_table` et n'a jamais
 * été supprimée : cette migration VÉRIFIE la base réelle (dérive possible
 * sur un environnement historique) et la (re)pose si elle manque.
 *
 * Le schéma réel ne comporte PAS de suppression logique sur cette table
 * (aucun `deleted_at`) : la contrainte UNIQUE simple est donc la forme
 * adaptée — une ligne supprimée est physiquement supprimée et libère la
 * paire (étudiant, année).
 *
 * En cas de doublons préalables, la migration échoue AVANT toute DDL en
 * les nommant de façon anonymisée (identifiants, jamais de PII) : la
 * résolution est manuelle, aucune donnée n'est modifiée ici.
 */
return new class extends Migration
{
    /** @var array<int, string> */
    private const COLONNES_CIBLE = ['etudiant_id', 'annee_universitaire_id'];

    public function up(): void
    {
        if ($this->contraintePresente()) {
            return; // Contrainte déjà en place (cas nominal) : rien à faire.
        }

        $doublons = DB::table('inscriptions_academiques')
            ->select('etudiant_id', 'annee_universitaire_id')
            ->groupBy('etudiant_id', 'annee_universitaire_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($doublons->isNotEmpty()) {
            throw new RuntimeException(
                'Unicité inscription annuelle : '.$doublons->count().' paire(s) (étudiant, année) en doublon — '
                .'exemples : '.$doublons->take(5)->map(
                    fn ($d) => "#{$d->etudiant_id}/#{$d->annee_universitaire_id}"
                )->implode(', ')
                .'. Résolution manuelle requise avant de poser la contrainte (aucune donnée modifiée).'
            );
        }

        Schema::table('inscriptions_academiques', function (Blueprint $table) {
            $table->unique(self::COLONNES_CIBLE, 'uniq_inscription_etudiant_annee');
        });
    }

    /**
     * Jamais de suppression automatique : la contrainte est une garantie
     * métier structurelle présente depuis la création de la table.
     */
    public function down(): void {}

    private function contraintePresente(): bool
    {
        foreach (Schema::getIndexes('inscriptions_academiques') as $index) {
            if (! ($index['unique'] ?? false)) {
                continue;
            }

            $colonnes = array_values($index['columns'] ?? []);
            sort($colonnes);

            $attendues = self::COLONNES_CIBLE;
            sort($attendues);

            if ($colonnes === $attendues) {
                return true;
            }
        }

        return false;
    }
};
