<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Phase 15 §15.1 : gabarits de notification versionnés
        // (titre + message à variables {{nom}}, jamais de HTML brut
        // saisi sans assainissement côté rendu).
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60)->unique();
            $table->string('titre', 150);
            $table->string('message', 500);
            $table->unsignedInteger('version')->default(1);
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });

        $modeles = [
            ['inscription.valide', 'Inscription validée', 'Votre inscription {annee} est passée au statut « {statut} ».'],
            ['inscription.ajourne', 'Inscription mise à jour', 'Votre inscription {annee} est passée au statut « {statut} ».'],
            ['inscription.abandon', 'Inscription mise à jour', 'Votre inscription {annee} est passée au statut « {statut} ».'],
            ['inscription.reinscrite', 'Réinscription confirmée', 'Votre réinscription pour l’année {annee} est confirmée.'],
            ['inscription.progression', 'Inscription créée', 'Votre inscription pour l’année {annee} a été créée (niveau {niveau}).'],
            ['inscription.transferee', 'Transfert enregistré', 'Votre transfert vers {programme} a été enregistré (dossier conservé).'],
            ['deliberation.publiee', 'Résultats publiés', 'Les résultats de votre délibération sont publiés (consultez votre dossier).'],
        ];

        foreach ($modeles as [$code, $titre, $message]) {
            DB::table('notification_templates')->insert([
                'code' => $code, 'titre' => $titre, 'message' => $message,
                'version' => 1, 'actif' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_templates');
    }
};
