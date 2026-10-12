<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Découplage architectural : DB_RELEASE (SemVer logique) vs DB_SCHEMA (état technique migrations).
 *
 * Ajoute les colonnes `db_release` et `db_schema` sur la table `releases`.
 * Migre rétroactivement les releases existantes pour supprimer l'artefact 1.0.93
 * sans perte d'historique.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Structure de table
        Schema::table('releases', function (Blueprint $table) {
            if (! Schema::hasColumn('releases', 'db_release')) {
                $table->string('db_release', 50)->nullable()->after('db_version');
            }
            if (! Schema::hasColumn('releases', 'db_schema')) {
                $table->json('db_schema')->nullable()->after('db_release');
            }
        });

        // 2. Rétrocompatibilité : migration des données existantes
        try {
            $nombreMigrations = DB::table('migrations')->count();
            $derniereMigration = DB::table('migrations')->orderByDesc('id')->value('migration');
            $schemaTechnique = [
                'applied' => $nombreMigrations,
                'pending' => 0,
                'last' => $derniereMigration,
                'texte' => "{$nombreMigrations} migrations appliquées",
            ];

            // Identifier les releases créées avec l'ancienne logique
            $releases = DB::table('releases')->get();

            foreach ($releases as $rel) {
                $updates = [];

                // Si la release a été créée avec version = 1.0.93 (confusion migration count / SemVer)
                if ($rel->version === '1.0.93') {
                    $existeUnZero = DB::table('releases')->where('version', '1.0.0')->exists();
                    if (! $existeUnZero) {
                        $updates['version'] = '1.0.0';
                        $updates['app_version'] = '1.0.0';
                    }
                    $updates['db_version'] = '1.0.0';
                    $updates['db_release'] = '1.0.0';
                    $updates['dev_version'] = '1.0.0-dev.1';
                    $updates['prod_version'] = '1.0.0';
                    $updates['notes'] = ($rel->notes ? $rel->notes.' — ' : '').'Convertie de 1.0.93 vers 1.0.0 (découplage DB_RELEASE et DB_SCHEMA)';
                } else {
                    $updates['db_release'] = $rel->db_release ?? ($rel->db_version === '1.0.93' ? '1.0.0' : $rel->db_version);
                    if ($rel->db_version === '1.0.93') {
                        $updates['db_version'] = '1.0.0';
                    }
                }

                $updates['db_schema'] = json_encode($schemaTechnique, JSON_UNESCAPED_UNICODE);

                DB::table('releases')->where('id', $rel->id)->update($updates);
            }

            // Mettre à jour version_components si nécessaire
            DB::table('version_components')
                ->where('composant', 'db')
                ->where('version', '1.0.93')
                ->update(['version' => '1.0.0']);

            // Tracer dans audit_versioning
            DB::table('audit_versioning')->insert([
                'action' => 'migration_architecture_db_release',
                'entite' => 'system',
                'entite_id' => null,
                'ancienne_valeur' => json_encode(['db_version_ancien' => '1.0.93']),
                'nouvelle_valeur' => json_encode([
                    'db_release' => '1.0.0',
                    'db_schema' => "{$nombreMigrations} migrations appliquées",
                ]),
                'environnement' => config('versioning.environnement', 'local'),
                'created_at' => now(),
            ]);
        } catch (Throwable) {
            // Fail-soft pour environnements de test vierges
        }
    }

    public function down(): void
    {
        Schema::table('releases', function (Blueprint $table) {
            if (Schema::hasColumn('releases', 'db_schema')) {
                $table->dropColumn('db_schema');
            }
            if (Schema::hasColumn('releases', 'db_release')) {
                $table->dropColumn('db_release');
            }
        });
    }
};
