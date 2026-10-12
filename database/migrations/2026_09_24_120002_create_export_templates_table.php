<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Modèles d'export réutilisables (§79) : périmètre + colonnes + format
     * enregistrés par utilisateur.
     */
    public function up(): void
    {
        Schema::create('export_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained('users')
                ->onDelete('cascade');
            $table->string('name', 100);
            $table->string('type', 30);          // students|grades|subject|group|averages|filiere|stats
            $table->string('profil', 30)->nullable();
            $table->string('format', 10);        // csv|xlsx|json|xml
            $table->json('filters')->nullable();
            $table->json('columns')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_templates');
    }
};
