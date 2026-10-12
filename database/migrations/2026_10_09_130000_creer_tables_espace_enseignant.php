<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Espace Enseignant : tables métier et extensions.
 *
 * 1. Extension `teachers` :
 *    - `user_id` (liaison optionnelle vers compte user existant) ;
 *    - `telephone` (coordonnée personnelle modifiable).
 * 2. Extension `users` : ajout du rôle optionnel `enseignant`.
 * 3. `teacher_documents` : documents privés/officiels rattachés à l'enseignant.
 * 4. `teacher_requests` : demandes administratives/pédagogiques en ligne.
 * 5. `teacher_reclamations` : réclamations et signalements d'anomalies.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Extension de la table teachers
        Schema::table('teachers', function (Blueprint $table) {
            if (! Schema::hasColumn('teachers', 'user_id')) {
                $table->foreignId('user_id')->nullable()->after('id')
                    ->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('teachers', 'telephone')) {
                $table->string('telephone', 30)->nullable()->after('email');
            }
        });

        // 2. Extension du rôle users (MySQL spécifique)
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('super_admin', 'admin_scolarite', 'agent_scolarite', 'consultation', 'admin_faculte', 'enseignant') NOT NULL DEFAULT 'consultation'");
        }

        // 3. Table des documents de l'enseignant
        if (! Schema::hasTable('teacher_documents')) {
            Schema::create('teacher_documents', function (Blueprint $table) {
                $table->id();
                $table->foreignId('teacher_id')->constrained('teachers')->cascadeOnDelete();
                $table->foreignId('faculte_id')->nullable()->constrained('facultes')->nullOnDelete();
                $table->string('titre', 255);
                $table->string('categorie', 50)->default('autre'); // administratif, pedagogique, convocation, attestation, decision, autre
                $table->string('nom_fichier', 255);
                $table->string('chemin', 500);
                $table->string('mime_type', 100);
                $table->unsignedBigInteger('taille')->default(0);
                $table->text('description')->nullable();
                $table->foreignId('publie_par')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['teacher_id', 'categorie']);
                $table->index('teacher_id');
                $table->index('faculte_id');
            });
        }

        // 4. Table des demandes administratives de l'enseignant
        if (! Schema::hasTable('teacher_requests')) {
            Schema::create('teacher_requests', function (Blueprint $table) {
                $table->id();
                $table->string('reference', 50)->unique();
                $table->foreignId('teacher_id')->constrained('teachers')->cascadeOnDelete();
                $table->foreignId('faculte_id')->nullable()->constrained('facultes')->nullOnDelete();
                $table->string('type_demande', 50); // conge, attestation, ordre_mission, materiel, regularisation, autre
                $table->string('objet', 255);
                $table->text('description');
                $table->string('statut', 30)->default('soumise'); // brouillon, soumise, en_cours, en_attente_complement, approuvee, refusee, annulee
                $table->text('reponse')->nullable();
                $table->string('piece_jointe_chemin', 500)->nullable();
                $table->string('piece_jointe_nom', 255)->nullable();
                $table->foreignId('traitee_par')->nullable()->constrained('users')->nullOnDelete();
                $table->dateTime('traitee_le')->nullable();
                $table->timestamps();

                $table->index(['teacher_id', 'statut']);
                $table->index('teacher_id');
                $table->index('faculte_id');
            });
        }

        // 5. Table des réclamations de l'enseignant
        if (! Schema::hasTable('teacher_reclamations')) {
            Schema::create('teacher_reclamations', function (Blueprint $table) {
                $table->id();
                $table->string('reference', 50)->unique();
                $table->foreignId('teacher_id')->constrained('teachers')->cascadeOnDelete();
                $table->foreignId('faculte_id')->nullable()->constrained('facultes')->nullOnDelete();
                $table->string('categorie', 50); // emploi_du_temps, presence, surveillance, document, donnees_personnelles, autre
                $table->string('objet', 255);
                $table->text('description');
                $table->string('priorite', 20)->default('normale'); // normale, urgente
                $table->string('statut', 30)->default('ouverte'); // ouverte, en_cours, resolue, rejetee, fermee
                $table->text('reponse')->nullable();
                $table->string('piece_jointe_chemin', 500)->nullable();
                $table->string('piece_jointe_nom', 255)->nullable();
                $table->json('historique')->nullable();
                $table->foreignId('traitee_par')->nullable()->constrained('users')->nullOnDelete();
                $table->dateTime('traitee_le')->nullable();
                $table->timestamps();

                $table->index(['teacher_id', 'statut']);
                $table->index('teacher_id');
                $table->index('faculte_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_reclamations');
        Schema::dropIfExists('teacher_requests');
        Schema::dropIfExists('teacher_documents');

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('super_admin', 'admin_scolarite', 'agent_scolarite', 'consultation', 'admin_faculte') NOT NULL DEFAULT 'consultation'");
        }

        Schema::table('teachers', function (Blueprint $table) {
            if (Schema::hasColumn('teachers', 'user_id')) {
                $table->dropConstrainedForeignId('user_id');
            }
            if (Schema::hasColumn('teachers', 'telephone')) {
                $table->dropColumn('telephone');
            }
        });
    }
};
