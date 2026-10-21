<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OptionScrutin extends Model
{
    use HasFactory;

    protected $fillable = [
        'question_scrutin_id',
        'libelle',
        'code',
        'ordre',
        'est_texte_libre',
    ];

    protected $casts = [
        'question_scrutin_id' => 'integer',
        'est_texte_libre' => 'boolean',
    ];

    public function question(): BelongsTo
    {
        return $this->belongsTo(QuestionScrutin::class);
    }

    /**
     * Vérifier si cette option concerne un texte libre
     */
    public function estTexteLibre(): bool
    {
        return $this->est_texte_libre;
    }
}
