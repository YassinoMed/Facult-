<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluation_candidats', function (Blueprint $table) {
            $table->foreignId('salle_id')->nullable()->after('inscription_pedagogique_id')->constrained('rooms')->nullOnDelete();
            $table->string('place', 50)->nullable()->after('salle_id');
            $table->index(['epreuve_id', 'salle_id']);
        });
    }

    public function down(): void
    {
        Schema::table('evaluation_candidats', function (Blueprint $table) {
            $table->dropForeign(['salle_id']);
            $table->dropIndex(['epreuve_id', 'salle_id']);
            $table->dropColumn(['salle_id', 'place']);
        });
    }
};
