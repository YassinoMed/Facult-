<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PHASE A (Smart Timetables) — paramètres du futur moteur de génération
 * (Phase B) : pondérations des préférences d'optimisation et garde-fous
 * de calcul. Défauts sûrs : aucune publication automatique n'existe — la
 * génération produira toujours un BROUILLON soumis à validation humaine.
 *
 * Idempotente (n'insère que les clés absentes, patron LOOP J) et
 * réversible. Catégorie `planning` (Parametre::CATEGORIES).
 */
return new class extends Migration
{
    private const PARAMETRES = [
        [
            'cle' => 'planning.generation.poids_trous', 'valeur' => '5', 'type' => 'entier',
            'categorie' => 'planning',
            'description' => 'Pondération de la minimisation des trous horaires (0 = désactivé, 10 = maximal).',
        ],
        [
            'cle' => 'planning.generation.poids_equilibre_jours', 'valeur' => '5', 'type' => 'entier',
            'categorie' => 'planning',
            'description' => 'Pondération de l’équilibre de la charge entre les jours (0-10).',
        ],
        [
            'cle' => 'planning.generation.poids_continuite', 'valeur' => '3', 'type' => 'entier',
            'categorie' => 'planning',
            'description' => 'Pondération de la continuité des séances d’une même matière (0-10).',
        ],
        [
            'cle' => 'planning.generation.duree_max_sequence_minutes', 'valeur' => '240', 'type' => 'entier',
            'categorie' => 'planning',
            'description' => 'Durée maximale d’une séquence continue (minutes) — contrainte pédagogique.',
        ],
        [
            'cle' => 'planning.generation.iteration_max', 'valeur' => '50000', 'type' => 'entier',
            'categorie' => 'planning',
            'description' => 'Garde-fou anti-boucle du moteur de génération (itérations maximales).',
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
