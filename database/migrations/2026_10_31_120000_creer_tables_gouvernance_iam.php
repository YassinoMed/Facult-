<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LOOP 43-BIS — gouvernance IAM : tables ADDITIVES et RÉVERSIBLES.
 *
 * Aucune colonne n'est ajoutée aux tables existantes et aucun rôle /
 * permission n'est déplacé : `App\Support\Permissions` reste la source
 * canonique des CODES. Ces tables stockent uniquement :
 *  - des profils d'habilitation personnalisés (sous-ensemble borné) ;
 *  - des demandes et approbations (quatre yeux) ;
 *  - des affectations / habilitations / délégations bornées dans le temps ;
 *  - un journal append-only avec chaîne d'empreintes ;
 *  - des accès d'urgence et des campagnes de recertification ;
 *  - un compteur de version par utilisateur (invalidation de cache).
 *
 * Compatible SQLite (tests) et MySQL (production). Les montants, durées
 * et approbateurs restent dans `config/iam.php` — aucune valeur
 * institutionnelle n'est inventée ici.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Profils d'habilitation : jamais des rôles système, toujours un
        // sous-ensemble de permissions administrables.
        Schema::create('iam_profils', function (Blueprint $table) {
            $table->id();
            $table->string('code', 80)->unique();
            $table->string('nom', 150);
            $table->text('description')->nullable();
            $table->foreignId('faculte_id')->nullable()->constrained('facultes')->nullOnDelete();
            $table->string('statut', 20)->default('brouillon'); // brouillon|soumis|actif|suspendu|archive
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('cree_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approuve_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approuve_le')->nullable();
            $table->timestamp('expire_le')->nullable();
            $table->timestamps();

            $table->index(['statut', 'faculte_id']);
        });

        Schema::create('iam_profil_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profil_id')->constrained('iam_profils')->cascadeOnDelete();
            $table->string('permission_code', 150);
            $table->timestamps();

            $table->unique(['profil_id', 'permission_code']);
        });

        // Demandes : tout acte sensible passe par une demande explicite,
        // approuvée par un AUTRE acteur (jamais d'auto-approbation).
        Schema::create('iam_demandes', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            $table->string('type', 40); // affectation_profil|attribution_role|delegation|activation_profil
            $table->string('statut', 20)->default('soumise'); // soumise|approuvee|rejetee|annulee|expiree
            $table->foreignId('initiateur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('profil_id')->nullable()->constrained('iam_profils')->nullOnDelete();
            $table->foreignId('faculte_id')->nullable()->constrained('facultes')->nullOnDelete();
            $table->json('payload')->nullable();
            $table->text('motif');
            $table->timestamp('decide_le')->nullable();
            $table->timestamps();

            $table->index(['statut', 'type']);
            $table->index(['cible_user_id', 'statut']);
        });

        Schema::create('iam_approbations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('demande_id')->constrained('iam_demandes')->cascadeOnDelete();
            $table->foreignId('approbateur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision', 20); // approuve|rejete
            $table->text('commentaire')->nullable();
            $table->timestamp('decide_le');
            $table->timestamps();

            // Une seule décision par approbateur et par demande.
            $table->unique(['demande_id', 'approbateur_id']);
        });

        // Affectations : un profil (ou un rôle) accordé à un compte pour
        // une fenêtre bornée. `version_profil` empêche qu'une modification
        // ultérieure du profil élargisse silencieusement l'accès accordé.
        Schema::create('iam_affectations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('profil_id')->nullable()->constrained('iam_profils')->nullOnDelete();
            $table->string('role', 40)->nullable();
            $table->foreignId('faculte_id')->nullable()->constrained('facultes')->nullOnDelete();
            $table->unsignedInteger('version_profil')->nullable();
            $table->string('statut', 20)->default('active'); // active|revoquee|expiree
            $table->timestamp('debut_le');
            $table->timestamp('expire_le')->nullable();
            $table->text('motif')->nullable();
            $table->foreignId('demande_id')->nullable()->constrained('iam_demandes')->nullOnDelete();
            $table->foreignId('cree_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('revoque_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoque_le')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'statut', 'expire_le']);
            $table->index(['profil_id', 'statut']);
        });

        // Habilitations directes : permission UNITAIRE bornée. Jamais une
        // permission réservée (IAM) ni une permission fédérée en lecture seule.
        Schema::create('iam_habilitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('permission_code', 150);
            $table->foreignId('faculte_id')->nullable()->constrained('facultes')->nullOnDelete();
            $table->string('statut', 20)->default('active'); // active|revoquee|expiree
            $table->timestamp('debut_le');
            $table->timestamp('expire_le')->nullable();
            $table->text('motif')->nullable();
            $table->foreignId('demande_id')->nullable()->constrained('iam_demandes')->nullOnDelete();
            $table->foreignId('cree_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('revoque_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoque_le')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'permission_code', 'statut']);
        });

        // Délégations : le délégant doit détenir DIRECTEMENT la permission
        // (pas de chaîne de délégations). Le moteur revérifie à chaque
        // évaluation que le délégant la détient toujours.
        Schema::create('iam_delegations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delegant_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('delegataire_id')->constrained('users')->cascadeOnDelete();
            $table->string('permission_code', 150);
            $table->foreignId('faculte_id')->nullable()->constrained('facultes')->nullOnDelete();
            $table->string('statut', 20)->default('active'); // active|revoquee|expiree
            $table->timestamp('debut_le');
            $table->timestamp('fin_le');
            $table->text('motif')->nullable();
            $table->foreignId('demande_id')->nullable()->constrained('iam_demandes')->nullOnDelete();
            $table->foreignId('cree_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('revoque_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoque_le')->nullable();
            $table->timestamps();

            $table->index(['delegataire_id', 'statut', 'fin_le']);
            $table->index(['delegant_id', 'permission_code']);
        });

        // Journal IAM append-only : aucune mise à jour, aucune suppression
        // applicative. `empreinte` chaîne chaque entrée à la précédente.
        Schema::create('iam_evenements', function (Blueprint $table) {
            $table->id();
            $table->string('type', 80);
            $table->foreignId('acteur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('objet_type', 80)->nullable();
            $table->unsignedBigInteger('objet_id')->nullable();
            $table->json('avant')->nullable();
            $table->json('apres')->nullable();
            $table->string('adresse_ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('empreinte', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['type', 'created_at']);
            $table->index(['cible_user_id', 'created_at']);
            $table->index(['objet_type', 'objet_id']);
        });

        // Urgence / break-glass : accès temporaire, borné en heures,
        // bénéficiaire ≠ déclencheur, revue indépendante obligatoire.
        Schema::create('iam_urgences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('declencheur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('beneficiaire_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('permission_code', 150);
            $table->foreignId('faculte_id')->nullable()->constrained('facultes')->nullOnDelete();
            $table->text('motif');
            $table->string('statut', 20)->default('active'); // active|revoquee|expiree
            $table->timestamp('debut_le');
            $table->timestamp('fin_le');
            $table->foreignId('approuve_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approuve_le')->nullable();
            $table->foreignId('revue_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revue_le')->nullable();
            $table->text('revue_commentaire')->nullable();
            $table->timestamps();

            $table->index(['beneficiaire_id', 'statut', 'fin_le']);
            $table->index(['statut', 'approuve_le']);
        });

        // Recertification : campagne indépendante, un bénéficiaire ne peut
        // jamais certifier son propre accès.
        Schema::create('iam_recertifications', function (Blueprint $table) {
            $table->id();
            $table->string('campagne', 80);
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('objet_type', 30); // affectation|habilitation|delegation
            $table->unsignedBigInteger('objet_id');
            $table->string('statut', 20)->default('a_faire'); // a_faire|confirmee|retiree|expiree
            $table->foreignId('assigne_a_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('echeance_le')->nullable();
            $table->foreignId('decide_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decide_le')->nullable();
            $table->text('commentaire')->nullable();
            $table->timestamps();

            $table->unique(['campagne', 'objet_type', 'objet_id'], 'iam_recert_unicite');
            $table->index(['statut', 'echeance_le']);
        });

        // Compteur de version par compte : invalide le cache d'évaluation
        // dès qu'un acte de gouvernance est exécuté (multi-processus).
        Schema::create('iam_versions_autorisation', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamp('calcule_le')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('iam_versions_autorisation');
        Schema::dropIfExists('iam_recertifications');
        Schema::dropIfExists('iam_urgences');
        Schema::dropIfExists('iam_evenements');
        Schema::dropIfExists('iam_delegations');
        Schema::dropIfExists('iam_habilitations');
        Schema::dropIfExists('iam_affectations');
        Schema::dropIfExists('iam_approbations');
        Schema::dropIfExists('iam_demandes');
        Schema::dropIfExists('iam_profil_permissions');
        Schema::dropIfExists('iam_profils');
    }
};
