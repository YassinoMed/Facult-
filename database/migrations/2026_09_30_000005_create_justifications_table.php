<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 — Justifications d'absence (§36) : dépôt étudiant,
 * validation back-office, pièce justificative optionnelle.
 * L'absence (Presence) n'est jamais supprimée silencieusement :
 * une justification acceptée bascule le statut vers `excuse`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_justifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('presence_id')->constrained('presences')->cascadeOnDelete();
            $table->foreignId('etudiant_id')->constrained('etudiants')->cascadeOnDelete();
            $table->text('motif');
            $table->string('piece_chemin', 500)->nullable();
            $table->string('piece_nom', 255)->nullable();
            $table->string('statut', 20)->default('pending');
            $table->text('commentaire_validation')->nullable();
            $table->foreignId('valide_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validee_a')->nullable();
            $table->timestamps();
            $table->index(['presence_id', 'statut']);
            $table->index(['etudiant_id', 'statut']);
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->unsignedTinyInteger('priorite')->default(0)->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropColumn('priorite');
        });
        Schema::dropIfExists('attendance_justifications');
    }
};
