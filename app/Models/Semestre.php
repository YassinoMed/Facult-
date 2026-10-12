<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Semestre extends Model
{
    protected $fillable = [
        'inscription_academique_id',
        'numero',
        'moyenne',
        'credits_obtenus',
        'credits_total',
        'decision',
    ];

    protected $casts = [
        'moyenne' => 'decimal:2',
    ];

    public function inscriptionAcademique(): BelongsTo
    {
        return $this->belongsTo(InscriptionAcademique::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }

    public function etudiant()
    {
        return $this->hasOneThrough(Etudiant::class, InscriptionAcademique::class);
    }

    /**
     * Libellé court : « S1 » / « S2 ».
     */
    public function getLibelleAttribute(): string
    {
        return 'S'.$this->numero;
    }
}
