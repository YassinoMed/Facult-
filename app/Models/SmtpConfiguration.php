<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Configuration SMTP nommée (ORM Phase C) : le mot de passe est chiffré
 * au repos (cast `encrypted`, clé `APP_KEY`) et masqué en sérialisation.
 * Une seule configuration `actif` à la fois (convention vérifiée par test).
 */
class SmtpConfiguration extends Model
{
    protected $table = 'smtp_configurations';

    protected $fillable = [
        'nom',
        'hote',
        'port',
        'chiffrement',
        'identifiant',
        'mot_de_passe',
        'expediteur',
        'nom_expediteur',
        'actif',
    ];

    protected $hidden = [
        'mot_de_passe',
    ];

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'actif' => 'boolean',
            'mot_de_passe' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        // Unicité de l'activation : activer l'une désactive les autres.
        static::saving(function (self $configuration): void {
            if ($configuration->actif) {
                static::where('id', '!=', $configuration->id ?? 0)->update(['actif' => false]);
            }
        });
    }
}
