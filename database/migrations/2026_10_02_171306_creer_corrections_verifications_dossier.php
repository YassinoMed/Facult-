<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LOOP vérificateur §15-16 : corrections assistées avec validation
 * (proposee → approuvee/rejetee → appliquee, 4-eyes) + historique des
 * vérifications (verification_id, verdict, score — §13).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dossier_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etudiant_id')->constrained('etudiants')->cascadeOnDelete();
            $table->string('champ', 60);
            $table->text('ancienne_valeur')->nullable();
            $table->text('nouvelle_valeur')->nullable();
            $table->string('regle', 80)->nullable();
            $table->string('motif', 500);
            $table->string('statut', 20)->default('proposee')->index();
            $table->foreignId('propose_par')->constrained('users')->cascadeOnDelete();
            $table->foreignId('decide_par')->nullable()->constrained('users')->nullOnDelete();
            $table->string('motif_decision', 500)->nullable();
            $table->timestamp('decide_le')->nullable();
            $table->timestamp('appliquee_le')->nullable();
            $table->timestamps();
            $table->index(['etudiant_id', 'statut']);
        });

        Schema::create('dossier_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etudiant_id')->constrained('etudiants')->cascadeOnDelete();
            $table->string('verdict', 20)->index();
            $table->unsignedSmallInteger('score');
            $table->unsignedSmallInteger('regles_verifiees')->default(0);
            $table->unsignedSmallInteger('regles_reussies')->default(0);
            $table->unsignedSmallInteger('anomalies_count')->default(0);
            $table->float('duree_ms')->nullable();
            $table->string('source', 20)->default('web');
            $table->foreignId('auteur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['etudiant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dossier_verifications');
        Schema::dropIfExists('dossier_corrections');
    }
};
