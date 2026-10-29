<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 11 — Centre de services IT.
 *
 * Trois tables ADDITIVES et RÉVERSIBLES. Aucune colonne ajoutée aux
 * tables centrales.
 *
 * - `it_categories` : taxonomie IT et cibles de service par priorité.
 *   Le catalogue inséré est un ensemble de valeurs par défaut
 *   MODIFIABLES, pas une norme métier.
 * - `it_tickets` : métadonnées IT d'une demande. Le cycle de vie, le
 *   statut, l'affectation et la clôture restent portés par `tasks`
 *   (via `task_id`) : aucune machine à états parallèle.
 * - `it_evenements` : journal des événements propres à l'IT (création,
 *   escalade). Les transitions restent journalisées par TaskService.
 *
 * Un ticket sans `task_id` (module Tasks indisponible ou tâche purgée)
 * est explicitement « workflow indisponible » : aucune transition ne lui
 * est inventée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('it_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('libelle', 120);
            $table->string('description', 255)->nullable();
            // Cibles de service en JOURS OUVRÉS (jamais des heures calendaires).
            $table->unsignedSmallInteger('sla_jours_p1')->default(1);
            $table->unsignedSmallInteger('sla_jours_p2')->default(2);
            $table->unsignedSmallInteger('sla_jours_p3')->default(5);
            $table->string('priorite_defaut', 2)->default('P3');
            $table->boolean('actif')->default(true);
            $table->string('origine', 20)->default('defaut'); // defaut | saisi
            $table->timestamps();

            $table->index('actif');
        });

        Schema::create('it_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->unique();
            $table->string('cle_unique', 80)->unique();
            $table->foreignId('task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->foreignId('demandeur_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('faculte_id')->nullable()->constrained('facultes')->nullOnDelete();
            $table->foreignId('categorie_id')->constrained('it_categories')->restrictOnDelete();
            $table->string('priorite', 2)->default('P3');
            $table->string('sujet', 180);
            $table->text('description')->nullable();
            $table->timestamp('echeance_sla');
            $table->timestamp('escalade_le')->nullable();
            $table->timestamps();

            $table->index(['faculte_id', 'priorite']);
            $table->index(['categorie_id', 'priorite']);
            $table->index('echeance_sla');
        });

        Schema::create('it_evenements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('it_tickets')->cascadeOnDelete();
            $table->string('type', 30); // cree | escalade | assigne | transition
            $table->string('cle_idempotence', 64)->nullable()->unique();
            $table->string('de', 20)->nullable();
            $table->string('vers', 20)->nullable();
            $table->text('message')->nullable();
            $table->foreignId('auteur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['ticket_id', 'created_at']);
        });

        // Catalogue proposé : valeurs par défaut MODIFIABLES, jamais normatives.
        $maintenant = now();
        $defauts = [
            ['code' => 'compte', 'libelle' => 'Comptes et authentification', 'description' => 'Mot de passe, MFA, accès aux comptes.', 'sla_jours_p1' => 1, 'sla_jours_p2' => 2, 'sla_jours_p3' => 3, 'priorite_defaut' => 'P2'],
            ['code' => 'reseau', 'libelle' => 'Réseau et Wi-Fi', 'description' => 'Connectivité, VPN, couverture sans fil.', 'sla_jours_p1' => 1, 'sla_jours_p2' => 2, 'sla_jours_p3' => 3, 'priorite_defaut' => 'P2'],
            ['code' => 'poste', 'libelle' => 'Poste de travail', 'description' => 'Ordinateur, périphériques, système.', 'sla_jours_p1' => 1, 'sla_jours_p2' => 3, 'sla_jours_p3' => 5, 'priorite_defaut' => 'P3'],
            ['code' => 'logiciel', 'libelle' => 'Logiciels métier', 'description' => 'Applications et modules de la plateforme.', 'sla_jours_p1' => 1, 'sla_jours_p2' => 3, 'sla_jours_p3' => 5, 'priorite_defaut' => 'P3'],
            ['code' => 'materiel', 'libelle' => 'Matériel', 'description' => 'Imprimantes, scanners, équipements.', 'sla_jours_p1' => 1, 'sla_jours_p2' => 5, 'sla_jours_p3' => 10, 'priorite_defaut' => 'P3'],
            ['code' => 'autre', 'libelle' => 'Autre demande', 'description' => 'Demande hors des catégories ci-dessus.', 'sla_jours_p1' => 2, 'sla_jours_p2' => 5, 'sla_jours_p3' => 10, 'priorite_defaut' => 'P3'],
        ];

        foreach ($defauts as $defaut) {
            DB::table('it_categories')->insert($defaut + [
                'actif' => true,
                'origine' => 'defaut',
                'created_at' => $maintenant,
                'updated_at' => $maintenant,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('it_evenements');
        Schema::dropIfExists('it_tickets');
        Schema::dropIfExists('it_categories');
    }
};
