<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Historique append-only des paramètres (LOOP F) : chaque écriture
     * effective crée une version ; la restauration crée une version.
     * Aucun UPDATE/DELETE applicatif sur cette table (convention testée).
     */
    public function up(): void
    {
        Schema::create('parametre_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parametre_id')->constrained('parametres')->cascadeOnDelete();
            $table->text('valeur');
            $table->text('valeur_precedente')->nullable();
            $table->foreignId('acteur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('motif', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['parametre_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parametre_versions');
    }
};
