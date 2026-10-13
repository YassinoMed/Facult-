<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Verrouillage secondaire (LOOP N) : code PIN haché par compte +
     * paramètres (insert-if-missing, jamais d'écrasement).
     */
    public function up(): void
    {
        Schema::create('verrouillage_pins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('pin_hash', 255);
            $table->unsignedInteger('echecs')->default(0);
            $table->timestamp('bloque_until')->nullable();
            $table->timestamps();
        });

        foreach (self::PARAMETRES as $parametre) {
            if (! DB::table('parametres')->where('cle', $parametre['cle'])->exists()) {
                DB::table('parametres')->insert(
                    $parametre + ['created_at' => now(), 'updated_at' => now()]
                );
            }
        }
    }

    /**
     * @var array<int, array<string, string>>
     */
    private const PARAMETRES = [
        [
            'cle' => 'verrouillage.actif', 'valeur' => '1', 'type' => 'booleen',
            'categorie' => 'securite',
            'description' => 'Verrouillage secondaire après inactivité (si PIN défini).',
        ],
        [
            'cle' => 'verrouillage.delai_minutes', 'valeur' => '15', 'type' => 'entier',
            'categorie' => 'securite',
            'description' => 'Minutes d’inactivité avant verrouillage (5 à 480).',
        ],
        [
            'cle' => 'verrouillage.preavis_secondes', 'valeur' => '60', 'type' => 'entier',
            'categorie' => 'securite',
            'description' => 'Avertissement avant verrouillage, en secondes (0 à 300).',
        ],
        [
            'cle' => 'verrouillage.pin_longueur', 'valeur' => '6', 'type' => 'entier',
            'categorie' => 'securite',
            'description' => 'Longueur du code PIN (6 à 8 chiffres).',
        ],
        [
            'cle' => 'verrouillage.tentatives_max', 'valeur' => '5', 'type' => 'entier',
            'categorie' => 'securite',
            'description' => 'Tentatives avant temporisation (3 à 10).',
        ],
        [
            'cle' => 'verrouillage.temporisation_secondes', 'valeur' => '30', 'type' => 'entier',
            'categorie' => 'securite',
            'description' => 'Base de temporisation progressive en secondes (10 à 300).',
        ],
    ];

    public function down(): void
    {
        Schema::dropIfExists('verrouillage_pins');
        DB::table('parametres')
            ->whereIn('cle', array_column(self::PARAMETRES, 'cle'))
            ->delete();
    }
};
