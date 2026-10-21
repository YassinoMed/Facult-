<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuestionScrutin extends Model
{
    use HasFactory;

    protected $fillable = [
        'scrutin_id',
        'libelle',
        'type',
        'ordre',
        'consignes',
        'obligatoire',
        'nb_options_min',
        'nb_options_max',
    ];

    protected $casts = [
        'scrutin_id' => 'integer',
        'type' => 'integer',
        'ordre' => 'integer',
        'obligatoire' => 'boolean',
        'nb_options_min' => 'integer',
        'nb_options_max' => 'integer',
    ];

    public function scrutin(): BelongsTo
    {
        return $this->belongsTo(Scrutin::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(OptionScrutin::class)->orderBy('ordre');
    }

    public function reponses(): HasMany
    {
        return $this->hasMany(ReponseScrutin::class);
    }

    /**
     * Obtenir le type humain de la question
     */
    public function getTypeAttribute($value)
    {
        return match ($value) {
            0 => 'Choix unique',
            1 => 'Choix multiple',
            2 => 'Oui/Non',
            3 => 'Texte libre',
            4 => 'Classement',
            default => 'Inconnu',
        };
    }

    /**
     * Vérifier si la question est du type choix multiple
     */
    public function estChoixMultiple(): bool
    {
        return $this->type === 1;
    }

    /**
     * Vérifier si la question est du type oui/non
     */
    public function estOuiNon(): bool
    {
        return $this->type === 2;
    }

    /**
     * Vérifier si la question est du type texte libre
     */
    public function estTexteLibre(): bool
    {
        return $this->type === 3;
    }

    /**
     * Vérifier si la question est du type classement
     */
    public function estClassement(): bool
    {
        return $this->type === 4;
    }
}
