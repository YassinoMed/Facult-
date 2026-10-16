<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Parametre extends Model
{
    protected $table = 'parametres';

    public const TYPES = ['texte', 'entier', 'booleen', 'liste'];

    public const CATEGORIES = [
        'etablissement', 'securite', 'academique', 'inscription', 'presences',
        'sauvegardes', 'sentinel', 'notifications', 'interface', 'evaluation',
        'documents', 'demandes', 'import_export',
    ];

    protected $fillable = ['cle', 'valeur', 'type', 'categorie', 'description', 'updated_by'];

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * @return HasMany<ParametreVersion>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(ParametreVersion::class)->latest('id');
    }

    public function valeurTypee(): mixed
    {
        return match ($this->type) {
            'entier' => (int) $this->valeur,
            'booleen' => in_array(mb_strtolower((string) $this->valeur), ['1', 'vrai', 'true', 'oui'], true),
            'liste' => array_values(array_filter(array_map('trim', explode(',', (string) $this->valeur)))),
            default => (string) $this->valeur,
        };
    }
}
