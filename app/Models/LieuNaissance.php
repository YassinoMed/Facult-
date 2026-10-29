<?php

namespace App\Models;

use App\Services\Identity\ArabicNormalizer;
use Illuminate\Database\Eloquent\Model;

/**
 * Correspondance contrôlée des lieux de naissance (§14) :
 * arabic_name (affichage) | latin_name | official_name | country.
 *
 * La recherche s'effectue sur la clé arabe NORMALISÉE : toutes les
 * variantes d'écriture (diacritiques, tatweel, alef…) mènent au même
 * lieu — jamais à une traduction automatique non vérifiée.
 */
class LieuNaissance extends Model
{
    protected $table = 'lieux_naissance';

    protected $fillable = [
        'arabic_name',
        'arabic_name_key',
        'latin_name',
        'official_name',
        'country',
    ];

    /**
     * Enregistre (ou met à jour) un lieu avec sa clé normalisée.
     */
    public static function enregistrer(
        string $arabe,
        string $latin,
        ?string $officiel = null,
        string $pays = 'Tunisie',
    ): self {
        return static::updateOrCreate(
            ['arabic_name_key' => ArabicNormalizer::normaliser($arabe)],
            [
                'arabic_name' => $arabe,
                'latin_name' => $latin,
                'official_name' => $officiel ?? $latin,
                'country' => $pays,
            ],
        );
    }

    /**
     * Résout un lieu arabe (ou toute variante d'écriture) vers son
     * nom latin officiel — NULL si absent de la table.
     */
    public static function resoudreNomLatin(string $arabe): ?string
    {
        $cle = ArabicNormalizer::normaliser($arabe);

        if ($cle === '') {
            return null;
        }

        return static::query()
            ->where('arabic_name_key', $cle)
            ->value('official_name');
    }

    /**
     * Résout un lieu latin (ex: Sfax, Tunis, Sousse) vers son nom arabe officiel.
     */
    public static function resoudreNomArabe(string $latin): ?string
    {
        $latin = trim(mb_strtolower($latin));

        if ($latin === '') {
            return null;
        }

        return static::query()
            ->whereRaw('LOWER(latin_name) = ?', [$latin])
            ->orWhereRaw('LOWER(official_name) = ?', [$latin])
            ->value('arabic_name');
    }
}
