<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Journal des intégrations (§55) : un enregistrement par échange
     * (API, import, export, webhook). Aucun payload complet : uniquement
     * hash + métadonnées techniques — jamais de données personnelles.
     */
    public function up(): void
    {
        Schema::create('integration_logs', function (Blueprint $table) {
            $table->id();
            $table->string('integration', 50);                 // api | web | webhook | external-x
            $table->string('direction', 20);                  // api | import | export | webhook
            $table->string('operation', 100);                  // ex : api.students.index
            $table->enum('status', ['pending', 'processing', 'completed', 'failed', 'cancelled'])->default('completed');
            $table->string('external_reference', 100)->nullable();
            $table->string('request_id', 64)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('error_code', 50)->nullable();
            $table->string('payload_hash', 64)->nullable();    // SHA-256 du payload (jamais le contenu)
            $table->timestamp('created_at')->nullable();

            $table->index(['integration', 'direction']);
            $table->index(['status', 'created_at']);
            $table->index('request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_logs');
    }
};
