<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rapports d'import/export : traçabilité complète de chaque échange
     * (§40) — compteurs, erreurs, avertissements, durée, statut.
     */
    public function up(): void
    {
        Schema::create('import_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('integration', 50)->default('web');      // web | api | external-x
            $table->enum('direction', ['import', 'export'])->default('import');
            $table->string('type', 30);                             // students | grades | records
            $table->string('format', 10);                           // csv | json | xml | xlsx
            $table->string('filename', 255)->nullable();
            $table->string('file_path', 500)->nullable();          // stockage privé (jamais public/)
            $table->string('external_reference', 100)->nullable(); // idempotence côté système source
            $table->enum('mode', ['atomique', 'partiel'])->nullable();
            $table->enum('status', ['pending', 'processing', 'completed', 'failed', 'cancelled'])->default('pending');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('duplicate_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);
            $table->json('errors')->nullable();
            $table->json('warnings')->nullable();
            $table->json('preview')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['direction', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_reports');
    }
};
