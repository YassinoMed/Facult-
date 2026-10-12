<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluation_surveillants', function (Blueprint $table) {
            $table->unsignedBigInteger('active_teacher_id')
                ->virtualAs("CASE WHEN statut <> 'annule' THEN teacher_id ELSE NULL END")
                ->nullable()
                ->after('teacher_id');

            $table->unsignedBigInteger('active_user_id')
                ->virtualAs("CASE WHEN statut <> 'annule' THEN user_id ELSE NULL END")
                ->nullable()
                ->after('user_id');

            $table->unique(['epreuve_id', 'active_teacher_id'], 'uniq_active_teacher_epreuve');
            $table->unique(['epreuve_id', 'active_user_id'], 'uniq_active_user_epreuve');
        });
    }

    public function down(): void
    {
        Schema::table('evaluation_surveillants', function (Blueprint $table) {
            $table->dropUnique('uniq_active_teacher_epreuve');
            $table->dropUnique('uniq_active_user_epreuve');
            $table->dropColumn(['active_teacher_id', 'active_user_id']);
        });
    }
};
