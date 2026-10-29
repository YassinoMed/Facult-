<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReponseScrutin extends Model
{
    use HasFactory;

    protected $fillable = [
        'bulletin_scrutin_id',
        'question_scrutin_id',
        'option_scrutin_id',
        'texte_libre',
        'rang_classement',
    ];

    protected $casts = [
        'bulletin_scrutin_id' => 'integer',
        'question_scrutin_id' => 'integer',
        'option_scrutin_id' => 'integer',
        'rang_classement' => 'integer',
    ];

    public function bulletin(): BelongsTo
    {
        return $this->belongsTo(BulletinScrutin::class);
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(QuestionScrutin::class);
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(OptionScrutin::class);
    }

    /**
     * Obtenir le texte de la réponse (pour texte libre ou libellé d'option)
     */
    public function getTexteAttribute(): string
    {
        return $this->texte_libre
            ? $this->texte_libre
            : $this->option?->libelle ?? '';
    }

    /**
     * Vérifier si c'est une réponse de type texte libre
     */
    public function estTexteLibre(): bool
    {
        return ! is_null($this->texte_libre);
    }

    /**
     * Vérifier si c'est une réponse de type classement avec rang
     */
    public function aRangClassement(): bool
    {
        return ! is_null($this->rang_classement);
    }
}
