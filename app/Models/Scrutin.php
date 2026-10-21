<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Scrutin extends Model
{
    use HasFactory;

    protected $fillable = [
        'titre',
        'description',
        'etat',
        'date_debut',
        'date_fin',
        'resultats_autorises',
        'type_mode',
        'nb_reponses_max',
        'nb_reponses_min',
    ];

    protected $casts = [
        'date_debut' => 'datetime',
        'date_fin' => 'datetime',
        'resultats_autorises' => 'boolean',
        'type_mode' => 'integer',
        'nb_reponses_max' => 'integer',
        'nb_reponses_min' => 'integer',
    ];

    public function questions(): HasMany
    {
        return $this->hasMany(QuestionScrutin::class)->orderBy('ordre');
    }

    public function participations(): HasMany
    {
        return $this->hasMany(ParticipationScrutin::class);
    }

    public function bulletins(): HasMany
    {
        return $this->hasMany(BulletinScrutin::class);
    }

    /**
     * Vérifier si le scrutin est éligible au vote
     */
    public function estEligibleAuVote(): bool
    {
        return $this->etat === 'ouvert'
            && now() >= $this->date_debut
            && now() <= $this->date_fin;
    }

    /**
     * Vérifier si le scrutin est en période de prévisualisation
     */
    public function estEnPreparation(): bool
    {
        return $this->etat === 'brouillon'
            || $this->etat === 'programme';
    }

    /**
     * Obtenir le type humain du scrutin
     */
    public function getTypeModeAttribute($value)
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
     * Obtenir l'état humain du scrutin
     */
    public function getEtatAttribute($value)
    {
        return match ($value) {
            'brouillon' => 'Brouillon',
            'programme' => 'Programmé',
            'ouvert' => 'Ouvert',
            'clos' => 'Clos',
            'archive' => ' Archivé',
            default => 'Inconnu',
        };
    }
}
