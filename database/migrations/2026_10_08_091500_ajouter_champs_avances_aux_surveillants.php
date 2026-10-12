<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluation_surveillants', function (Blueprint $table) {
            $table->foreignId('remplace_surveillance_id')->nullable()->after('observations')
                ->constrained('evaluation_surveillants')->nullOnDelete();
            $table->time('heure_arrivee')->nullable()->after('presence');
            $table->time('heure_depart')->nullable()->after('heure_arrivee');
            $table->string('motif_absence', 255)->nullable()->after('heure_depart');
            $table->index(['teacher_id', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::table('evaluation_surveillants', function (Blueprint $table) {
            $table->dropForeign(['remplace_surveillance_id']);
            $table->dropIndex(['teacher_id', 'statut']);
            $table->dropColumn(['remplace_surveillance_id', 'heure_arrivee', 'heure_depart', 'motif_absence']);
        });
    }
};
