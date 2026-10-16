<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ParticipationScrutin extends Model
{
    use HasFactory;

    protected $fillable = [
        'etudiant_id',
        'scrutin_id',
        'jeton_unicite',
        'etat',
        'critères_version',
    ];

    protected $casts = [
        'etudiant_id' => 'integer',
        'scrutin_id' => 'integer',
        'etat' => 'string',
        'critères_version' => 'json',
    ];

    public function etudiant(): BelongsTo
    {
        return $this->belongsTo(Etudiant::class);
    }

    public function scrutin(): BelongsTo
    {
        return $this->belongsTo(Scrutin::class);
    }

    public function bulletin(): HasOne
    {
        return $this->hasOne(BulletinScrutin::class);
    }

    /**
     * Vérifier si la participation est valide
     */
    public function estValide(): bool
    {
        return $this->etat === 'valide';
    }

    /**
     * Obtenir le jeton pseudonymisé
     */
    public function getJetonUniciteMasquéAttribute(): string
    {
        if (strlen($this->jeton_unicite) <= 8) {
            return '——-';
        }
        return mb_substr($this->jeton_unicite, 0, 4).'****'.mb_substr($this->jeton_unicite, -4);
    }
}