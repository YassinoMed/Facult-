<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Périmètre de faculté pour les administrateurs (profil admin_faculte) :
     * - faculte_id : faculté de rattachement. NULL = accès global
     *   (super_admin, admin_scolarite, agent_scolarite, consultation).
     *   Un admin_faculte sans faculté est refusé au back-office.
     * - actif : statut du compte — un compte désactivé est refusé à la
     *   connexion même avec des identifiants valides (§10).
     *
     * L'isolation est TOUJOURS re-vérifiée côté serveur (policies +
     * requêtes scopées) : jamais depuis un paramètre client.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('faculte_id')
                ->nullable()
                ->constrained('facultes')
                ->nullOnDelete()
                ->after('role');
            $table->boolean('actif')->default(true)->after('faculte_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('faculte_id');
            $table->dropColumn('actif');
        });
    }
};
