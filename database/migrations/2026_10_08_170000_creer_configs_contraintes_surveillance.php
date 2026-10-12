<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surveillance_constraint_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('faculte_id')->nullable()->constrained('facultes')->cascadeOnDelete();
            $table->foreignId('annee_universitaire_id')->nullable()->constrained('annees_universitaires')->cascadeOnDelete();
            $table->foreignId('session_examen_id')->nullable()->constrained('evaluation_sessions')->cascadeOnDelete();
            // Dimensionnement (défauts = constantes historiques du moteur).
            $table->unsignedTinyInteger('min_surveillants')->default(2);
            $table->unsignedInteger('ratio_etudiants')->default(50);
            $table->unsignedTinyInteger('nb_gardes')->default(1);
            $table->unsignedTinyInteger('max_surveillants')->nullable();
            // Rôles et pondérations de présence
            $table->decimal('poids_surveillance_active', 4, 2)->default(1.0);
            $table->decimal('poids_garde_reserve', 4, 2)->default(0.5);
            // Équilibrage SOFT (défauts = poids historiques).
            $table->unsignedTinyInteger('max_jours')->default(3);
            $table->string('mode_limite', 10)->default('souple');
            $table->boolean('actif_charge_totale')->default(true);
            $table->decimal('poids_charge_totale', 6, 2)->default(10);
            $table->boolean('actif_charge_jour')->default(true);
            $table->decimal('poids_charge_jour', 6, 2)->default(15);
            $table->boolean('actif_gardes')->default(true);
            $table->decimal('poids_gardes', 6, 2)->default(8);
            $table->boolean('actif_limite_jours')->default(true);
            $table->decimal('penalite_depassement_jours', 6, 2)->default(35);
            $table->boolean('actif_dispersion')->default(true);
            $table->decimal('penalite_dispersion', 6, 2)->default(10);
            $table->boolean('actif_nouveau_jour')->default(true);
            $table->decimal('penalite_nouveau_jour', 6, 2)->default(2);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['faculte_id', 'annee_universitaire_id', 'session_examen_id'], 'idx_scc_scope');
        });

        Schema::create('surveillance_constraint_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('config_id')->constrained('surveillance_constraint_configs')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('params');
            $table->foreignId('auteur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->index(['config_id', 'version']);
        });

        Schema::table('surveillance_assignment_runs', function (Blueprint $table) {
            $table->json('config_snapshot')->nullable()->after('result_summary');
        });
    }

    public function down(): void
    {
        Schema::table('surveillance_assignment_runs', function (Blueprint $table) {
            $table->dropColumn('config_snapshot');
        });
        Schema::dropIfExists('surveillance_constraint_revisions');
        Schema::dropIfExists('surveillance_constraint_configs');
    }
};
