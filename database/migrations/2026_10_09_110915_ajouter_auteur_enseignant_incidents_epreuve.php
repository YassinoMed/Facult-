<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Espace Enseignant (Phase 8) : traçabilité des signalements d'incidents
 * déposés par un enseignant depuis son portail (miroir `created_by`
 * réservé aux comptes back-office, qui reste NULL dans ce cas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluation_incidents', function (Blueprint $table) {
            $table->foreignId('teacher_id')->nullable()->after('created_by')->constrained('teachers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('evaluation_incidents', function (Blueprint $table) {
            $table->dropForeign(['teacher_id']);
            $table->dropColumn('teacher_id');
        });
    }
};
