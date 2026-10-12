<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Référence MENS : recopie du fichier officiel (CIN + naissance =
 * identifiants, jamais le nom seul). Source de préremplissage, jamais
 * d'écrasement silencieux du dossier local (divergences détectées,
 * arbitrage administratif explicite).
 */
class MensReference extends Model
{
    protected $table = 'mens_references';

    protected $fillable = [
        'cin_normalise', 'cin_original', 'date_naissance',
        'nom', 'prenom', 'nom_ar', 'prenom_ar', 'sexe', 'nationalite',
        'matricule_national', 'etablissement', 'annee',
        'programme_code', 'niveau',
        'source_hash', 'imported_by', 'imported_at',
    ];

    protected function casts(): array
    {
        return ['date_naissance' => 'date', 'imported_at' => 'datetime'];
    }

    /**
     * Normalisation CIN non destructive (§7) : trim + espaces
     * internes réduites + majuscules. L'original est conservé.
     */
    public static function normaliserCin(string $cin): string
    {
        return mb_strtoupper((string) preg_replace('/\s+/', ' ', trim($cin)));
    }

    /**
     * Empreinte du contenu canonique (détecte tout changement MENS).
     *
     * @param  array<string, mixed>  $canonique
     */
    public static function empreinte(array $canonique): string
    {
        ksort($canonique);

        return hash('sha256', json_encode($canonique, JSON_UNESCAPED_UNICODE));
    }
}
