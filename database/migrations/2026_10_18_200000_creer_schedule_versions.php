<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('schedule_versions')) {
            Schema::create('schedule_versions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('schedule_id')->constrained('schedules')->cascadeOnDelete();
                $table->unsignedInteger('numero_version');
                $table->string('code_version', 50);
                $table->string('statut', 30)->default('publiee'); // publiee, archivee, brouillon_snapshot
                $table->json('snapshot_slots_json'); // Instantané immuable de tous les créneaux du planning
                $table->string('note_version', 500)->nullable();
                $table->unsignedSmallInteger('nb_slots')->default(0);
                $table->unsignedSmallInteger('score_qualite')->nullable();
                $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
                $table->dateTime('published_at')->nullable();
                $table->timestamps();

                $table->unique(['schedule_id', 'numero_version'], 'uniq_sched_version_num');
                $table->index(['schedule_id', 'statut'], 'idx_sched_versions_statut');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedule_versions');
    }
};
