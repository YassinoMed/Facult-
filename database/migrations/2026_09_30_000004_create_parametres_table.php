<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 — Paramètres centralisés (§48) : clé/valeur typée, UI
 * réservée, toute modification auditée. Jamais de secrets ici
 * (secrets = .env / vault, pas la base applicative).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parametres', function (Blueprint $table) {
            $table->id();
            $table->string('cle', 100)->unique();
            $table->text('valeur')->nullable();
            $table->string('type', 20)->default('texte');
            $table->string('categorie', 30)->default('etablissement');
            $table->text('description')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('categorie');
        });

        // Valeurs initiales saines : modifiables ensuite via l'UI auditée.
        DB::table('parametres')->insert([
            ['cle' => 'etablissement.nom', 'valeur' => 'Université', 'type' => 'texte', 'categorie' => 'etablissement', 'description' => 'Nom officiel de l’établissement.', 'created_at' => now(), 'updated_at' => now()],
            ['cle' => 'etablissement.fuseau', 'valeur' => 'Africa/Casablanca', 'type' => 'texte', 'categorie' => 'etablissement', 'description' => 'Fuseau horaire applicatif.', 'created_at' => now(), 'updated_at' => now()],
            ['cle' => 'academique.seuil_validation', 'valeur' => '10', 'type' => 'entier', 'categorie' => 'academique', 'description' => 'Moyenne minimale de validation (indicative — les règles versionnées du module Évaluation font foi).', 'created_at' => now(), 'updated_at' => now()],
            ['cle' => 'assiduite.seuil_alerte', 'valeur' => '5', 'type' => 'entier', 'categorie' => 'academique', 'description' => 'Absences injustifiées déclenchant une alerte scolarité.', 'created_at' => now(), 'updated_at' => now()],
            ['cle' => 'notifications.purge_jours', 'valeur' => '180', 'type' => 'entier', 'categorie' => 'notifications', 'description' => 'Ancienneté des notifications lues avant purge.', 'created_at' => now(), 'updated_at' => now()],
            ['cle' => 'interface.pagination', 'valeur' => '15', 'type' => 'entier', 'categorie' => 'interface', 'description' => 'Éléments par page (indicatif).', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('parametres');
    }
};
