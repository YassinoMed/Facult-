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
        if (! Schema::hasTable('timetable_constraints')) {
            Schema::create('timetable_constraints', function (Blueprint $table) {
                $table->id();
                $table->foreignId('faculte_id')->nullable()->constrained('facultes')->nullOnDelete();
                $table->string('code', 60);
                $table->string('nom', 120);
                $table->string('categorie', 20)->default('hard'); // hard, soft
                $table->text('description')->nullable();
                $table->boolean('actif')->default(true);
                $table->unsignedSmallInteger('poids')->default(5); // 1-100 pour contraintes souples
                $table->json('parametres')->nullable(); // configuration fine (ex: seuils, durées max)
                $table->timestamps();

                $table->unique(['faculte_id', 'code'], 'uniq_constraint_faculte_code');
                $table->index(['categorie', 'actif'], 'idx_constraints_cat_actif');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('timetable_constraints');
    }
};
