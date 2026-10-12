<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B1 (dette « notifications période ») : gabarits par défaut des
 * événements `periode.ouverte` et `periode.bientot_fermee` + état
 * d'annonce par période (idempotence de `periodes:avertir`, même
 * pattern que `used_at`).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            ['periode.ouverte', 'Inscriptions ouvertes', "La période d'inscription {nom} est ouverte (année {annee}) jusqu'au {date_fermeture} — connectez-vous à votre espace pour vous inscrire ou vous réinscrire."],
            ['periode.bientot_fermee', 'Inscriptions : clôture proche', "La période d'inscription {nom} (année {annee}) se clôture le {date_fermeture} — finalisez votre dossier sans attendre."],
        ] as [$code, $titre, $message]) {
            if (DB::table('notification_templates')->where('code', $code)->doesntExist()) {
                DB::table('notification_templates')->insert([
                    'code' => $code, 'titre' => $titre, 'message' => $message,
                    'version' => 1, 'actif' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        Schema::table('registration_periods', function (Blueprint $table) {
            $table->timestamp('avertie_ouverture_at')->nullable()->after('used_at');
            $table->timestamp('avertie_fermeture_at')->nullable()->after('avertie_ouverture_at');
        });
    }

    public function down(): void
    {
        Schema::table('registration_periods', function (Blueprint $table) {
            $table->dropColumn(['avertie_ouverture_at', 'avertie_fermeture_at']);
        });

        DB::table('notification_templates')->whereIn('code', ['periode.ouverte', 'periode.bientot_fermee'])->delete();
    }
};
