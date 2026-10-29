<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Réinitialisation de mot de passe des portails étudiant/enseignant.
 *
 * Table dédiée (et non `password_reset_tokens`, réservée au broker
 * back-office `users`) : les deux portails ont leurs propres sessions et
 * peuvent partager une même adresse e-mail avec un compte back-office.
 * Seul le HASH du token est stocké ; le token en clair ne vit que dans
 * l'e-mail et l'URL à usage unique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_password_reset_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('guard', 20);
            $table->string('email', 255);
            $table->string('token_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->unique(['guard', 'email']);
            $table->index(['guard', 'token_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_password_reset_tokens');
    }
};
