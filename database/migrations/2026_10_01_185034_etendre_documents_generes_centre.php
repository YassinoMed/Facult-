<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 16 §16.2-16.3 : enrichit `generated_documents` (registre unique,
 * non dupliqué) : snapshot figé, code de vérification opaque,
 * numérotation officielle sans trou, cycle de vie complet, traçabilité.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generated_documents', function (Blueprint $table) {
            $table->string('numero', 60)->nullable()->unique()->after('reference');
            $table->string('code_verification', 64)->nullable()->unique()->after('numero');
            $table->json('snapshot_donnees')->nullable()->after('contenu_hash');
            $table->string('locale', 10)->default('fr')->after('snapshot_donnees');
            $table->foreignId('template_version_id')->nullable()->after('locale')
                ->constrained('document_template_versions')->nullOnDelete();
            $table->timestamp('emis_le')->nullable()->after('template_version_id');
            $table->foreignId('emis_par')->nullable()->after('emis_le')->constrained('users')->nullOnDelete();
            $table->timestamp('valide_jusquau')->nullable()->after('emis_par');
            $table->foreignId('remplace_id')->nullable()->after('valide_jusquau')
                ->constrained('generated_documents')->nullOnDelete();
            $table->foreignId('lot_id')->nullable()->after('remplace_id')
                ->constrained('document_batches')->nullOnDelete();
            $table->timestamp('revoque_le')->nullable()->after('lot_id');
            $table->foreignId('revoque_par')->nullable()->after('revoque_le')->constrained('users')->nullOnDelete();
            $table->string('motif_revocation', 500)->nullable()->after('revoque_par');
            $table->unsignedInteger('duplicatas')->default(0)->after('motif_revocation');
            $table->index(['type', 'statut']);
            $table->index(['code_verification']);
        });
    }

    public function down(): void
    {
        Schema::table('generated_documents', function (Blueprint $table) {
            $table->dropUnique(['numero']);
            $table->dropUnique(['code_verification']);
            $table->dropConstrainedForeignId('template_version_id');
            $table->dropConstrainedForeignId('emis_par');
            $table->dropConstrainedForeignId('remplace_id');
            $table->dropConstrainedForeignId('lot_id');
            $table->dropConstrainedForeignId('revoque_par');
            $table->dropIndex(['type', 'statut']);
            $table->dropIndex(['code_verification']);
            $table->dropColumn([
                'numero', 'code_verification', 'snapshot_donnees', 'locale',
                'emis_le', 'valide_jusquau', 'revoque_le', 'motif_revocation', 'duplicatas',
            ]);
        });
    }
};
