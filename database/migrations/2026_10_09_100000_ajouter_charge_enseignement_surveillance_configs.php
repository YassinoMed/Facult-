<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Intégration de la charge d'enseignement dans les contraintes configurables de surveillance.
 * Migration additive et réversible : par défaut actif_charge_enseignement = false pour préserver
 * le comportement antérieur à 100 %.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('surveillance_constraint_configs', function (Blueprint $table) {
            if (! Schema::hasColumn('surveillance_constraint_configs', 'actif_charge_enseignement')) {
                $table->boolean('actif_charge_enseignement')->default(false)->after('penalite_nouveau_jour');
            }
            if (! Schema::hasColumn('surveillance_constraint_configs', 'poids_charge_enseignement')) {
                $table->decimal('poids_charge_enseignement', 5, 2)->default(5.00)->after('actif_charge_enseignement');
            }
            if (! Schema::hasColumn('surveillance_constraint_configs', 'mode_calcul_charge_enseignement')) {
                $table->enum('mode_calcul_charge_enseignement', ['heures_brutes', 'hetd'])->default('heures_brutes')->after('poids_charge_enseignement');
            }
            if (! Schema::hasColumn('surveillance_constraint_configs', 'seuil_surcharge_enseignement')) {
                $table->decimal('seuil_surcharge_enseignement', 6, 1)->nullable()->after('mode_calcul_charge_enseignement');
            }
            if (! Schema::hasColumn('surveillance_constraint_configs', 'max_surveillances_enseignant')) {
                $table->unsignedSmallInteger('max_surveillances_enseignant')->nullable()->after('seuil_surcharge_enseignement');
            }
        });
    }

    public function down(): void
    {
        Schema::table('surveillance_constraint_configs', function (Blueprint $table) {
            $table->dropColumn([
                'actif_charge_enseignement',
                'poids_charge_enseignement',
                'mode_calcul_charge_enseignement',
                'seuil_surcharge_enseignement',
                'max_surveillances_enseignant',
            ]);
        });
    }
};
