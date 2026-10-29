<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            if (! Schema::hasColumn('rooms', 'batiment_id')) {
                $table->foreignId('batiment_id')->nullable()->after('batiment')->constrained('batiments')->nullOnDelete();
            }
            if (! Schema::hasColumn('rooms', 'type_salle')) {
                $table->string('type_salle', 50)->nullable()->default('cours')->after('capacite');
            }
            if (! Schema::hasColumn('rooms', 'accessible_pmr')) {
                $table->boolean('accessible_pmr')->default(true)->after('type_salle');
            }
            if (! Schema::hasColumn('rooms', 'heure_ouverture')) {
                $table->time('heure_ouverture')->nullable()->default('08:00')->after('accessible_pmr');
            }
            if (! Schema::hasColumn('rooms', 'heure_fermeture')) {
                $table->time('heure_fermeture')->nullable()->default('19:00')->after('heure_ouverture');
            }
            if (! Schema::hasColumn('rooms', 'equipements_fixes')) {
                $table->json('equipements_fixes')->nullable()->after('heure_fermeture');
            }

            $table->index(['batiment_id', 'type_salle'], 'idx_rooms_batiment_type');
            $table->index(['accessible_pmr', 'actif'], 'idx_rooms_pmr_actif');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropConstrainedForeignId('batiment_id');
            $table->dropIndex('idx_rooms_batiment_type');
            $table->dropIndex('idx_rooms_pmr_actif');
            $table->dropColumn([
                'type_salle',
                'accessible_pmr',
                'heure_ouverture',
                'heure_fermeture',
                'equipements_fixes',
            ]);
        });
    }
};
