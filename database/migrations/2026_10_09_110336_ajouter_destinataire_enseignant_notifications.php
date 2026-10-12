<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Espace Enseignant (Phase 11) : troisième destinataire des
 * notifications (enseignant), en plus de `user_id` et `etudiant_id`.
 * Un seul destinataire par ligne (convention existante conservée).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->foreignId('teacher_id')->nullable()->after('etudiant_id')->constrained('teachers')->cascadeOnDelete();
            $table->index(['teacher_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropForeign(['teacher_id']);
            $table->dropColumn('teacher_id');
        });
    }
};
