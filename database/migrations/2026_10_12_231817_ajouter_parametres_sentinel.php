<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Graines `sentinel.*` (E, point 3) : valeurs strictement égales aux
     * défauts codés des lecteurs — aucun changement de comportement,
     * rend les seuils pilotables depuis l'UI paramètres.
     */
    private const PARAMETRES = [
        [
            'cle' => 'sentinel.file_echecs_seuil', 'valeur' => '5', 'type' => 'entier',
            'categorie' => 'sentinel',
            'description' => 'Échecs de file déclenchant une alerte Sentinel.',
        ],
        [
            'cle' => 'sentinel.file_attente_seuil', 'valeur' => '1000', 'type' => 'entier',
            'categorie' => 'sentinel',
            'description' => 'Travaux en attente déclenchant une alerte Sentinel.',
        ],
        [
            'cle' => 'sentinel.notif_cooldown_minutes', 'valeur' => '60', 'type' => 'entier',
            'categorie' => 'sentinel',
            'description' => 'Délai minimal entre deux notifications Sentinel (minutes).',
        ],
    ];

    public function up(): void
    {
        // Idempotence stricte : n'insère que les clés absentes (LOOP J).
        foreach (self::PARAMETRES as $parametre) {
            if (! DB::table('parametres')->where('cle', $parametre['cle'])->exists()) {
                DB::table('parametres')->insert(
                    $parametre + ['created_at' => now(), 'updated_at' => now()]
                );
            }
        }
    }

    public function down(): void
    {
        DB::table('parametres')
            ->whereIn('cle', array_column(self::PARAMETRES, 'cle'))
            ->delete();
    }
};
