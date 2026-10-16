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
        // La colonne teacher_id existe déjà dans la table notifications
        // On vérifie son existence avant de l'ajouter
        if (! Schema::hasColumn('notifications', 'teacher_id')) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->foreignId('teacher_id')->nullable()->after('etudiant_id')->constrained('teachers')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        // On ne supprime pas la colonne teacher_id si elle a été créée par une migration antérieure
        // Mais on peut la retirer si nécessaire
        if (Schema::hasColumn('notifications', 'teacher_id')) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->dropColumn('teacher_id');
            });
        }
    }
};
