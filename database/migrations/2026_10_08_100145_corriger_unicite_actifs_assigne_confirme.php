<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LOOP VALIDATION ADVERSARIALE §18 — l'unicité SQL des gardes actives
 * doit couvrir EXACTEMENT assigne/confirme (gardes réellement
 * occupantes), comme les gardes applicatives (assigner, principal,
 * simuler). Un remplace/annule est de l'historique : il ne doit jamais
 * bloquer une réaffectation (sinon le remplacement garde→principal,
 * exigé métier, échoue en 500 SQL).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE evaluation_surveillants MODIFY active_teacher_id BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN statut IN ('assigne','confirme') THEN teacher_id ELSE NULL END) VIRTUAL");
            DB::statement("ALTER TABLE evaluation_surveillants MODIFY active_user_id BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN statut IN ('assigne','confirme') THEN user_id ELSE NULL END) VIRTUAL");

            return;
        }

        Schema::table('evaluation_surveillants', function (Blueprint $table) {
            $table->dropUnique('uniq_active_teacher_epreuve');
            $table->dropUnique('uniq_active_user_epreuve');
            $table->dropColumn(['active_teacher_id', 'active_user_id']);
        });

        Schema::table('evaluation_surveillants', function (Blueprint $table) {
            $table->unsignedBigInteger('active_teacher_id')
                ->virtualAs("CASE WHEN statut IN ('assigne','confirme') THEN teacher_id ELSE NULL END")
                ->nullable()
                ->after('teacher_id');
            $table->unsignedBigInteger('active_user_id')
                ->virtualAs("CASE WHEN statut IN ('assigne','confirme') THEN user_id ELSE NULL END")
                ->nullable()
                ->after('user_id');
            $table->unique(['epreuve_id', 'active_teacher_id'], 'uniq_active_teacher_epreuve');
            $table->unique(['epreuve_id', 'active_user_id'], 'uniq_active_user_epreuve');
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE evaluation_surveillants MODIFY active_teacher_id BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN statut <> 'annule' THEN teacher_id ELSE NULL END) VIRTUAL");
            DB::statement("ALTER TABLE evaluation_surveillants MODIFY active_user_id BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN statut <> 'annule' THEN user_id ELSE NULL END) VIRTUAL");

            return;
        }

        Schema::table('evaluation_surveillants', function (Blueprint $table) {
            $table->dropUnique('uniq_active_teacher_epreuve');
            $table->dropUnique('uniq_active_user_epreuve');
            $table->dropColumn(['active_teacher_id', 'active_user_id']);
        });

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
};
