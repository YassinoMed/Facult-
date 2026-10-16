<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Groupe enregistré de destinataires (LOOP-MAIL-CAMPAIGNS-01).
 * Supporte le mode dynamique (critères de filtrage résolus à la volée)
 * et le mode liste fixe (membres enregistrés).
 */
class GroupeDestinataires extends Model
{
    protected $table = 'groupes_destinataires';

    public const TYPE_DYNAMIQUE_ETUDIANTS = 'dynamique_etudiants';

    public const TYPE_DYNAMIQUE_ENSEIGNANTS = 'dynamique_enseignants';

    public const TYPE_LISTE_FIXE = 'liste_fixe';

    public const TYPES = [
        self::TYPE_DYNAMIQUE_ETUDIANTS,
        self::TYPE_DYNAMIQUE_ENSEIGNANTS,
        self::TYPE_LISTE_FIXE,
    ];

    public const AUDIENCE_ETUDIANTS = 'etudiants';

    public const AUDIENCE_ENSEIGNANTS = 'enseignants';

    public const AUDIENCE_MIXTE = 'mixte';

    public const AUDIENCES = [
        self::AUDIENCE_ETUDIANTS,
        self::AUDIENCE_ENSEIGNANTS,
        self::AUDIENCE_MIXTE,
    ];

    protected $fillable = [
        'nom',
        'description',
        'type',
        'audience',
        'criteres',
        'membres_fixes',
        'est_archive',
        'auteur_id',
    ];

    protected function casts(): array
    {
        return [
            'criteres' => 'array',
            'membres_fixes' => 'array',
            'est_archive' => 'boolean',
        ];
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auteur_id');
    }

    public function campagnes(): HasMany
    {
        return $this->hasMany(CampagneCourriel::class, 'groupe_enregistre_id');
    }

    public function estDynamique(): bool
    {
        return in_array($this->type, [self::TYPE_DYNAMIQUE_ETUDIANTS, self::TYPE_DYNAMIQUE_ENSEIGNANTS], true);
    }

    public function estListeFixe(): bool
    {
        return $this->type === self::TYPE_LISTE_FIXE;
    }
}
