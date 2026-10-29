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
        Schema::table('schedule_slots', function (Blueprint $table) {
            $table->foreignId('enseignement_id')->nullable()->after('matiere_id')->constrained('enseignements')->nullOnDelete();
            $table->foreignId('sous_groupe_id')->nullable()->after('room_id')->constrained('sous_groupes')->nullOnDelete();
            $table->string('semaine_parite', 20)->default('toutes')->after('day_of_week');
            $table->json('semaines_specifiques')->nullable()->after('semaine_parite');
            $table->json('equipements_requis')->nullable()->after('notes');

            $table->index(['semaine_parite', 'day_of_week'], 'idx_slots_parite_jour');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('schedule_slots', function (Blueprint $table) {
            $table->dropIndex('idx_slots_parite_jour');
            $table->dropConstrainedForeignId('enseignement_id');
            $table->dropConstrainedForeignId('sous_groupe_id');
            $table->dropColumn([
                'semaine_parite',
                'semaines_specifiques',
                'equipements_requis',
            ]);
        });
    }
};
