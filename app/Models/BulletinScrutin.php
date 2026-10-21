<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BulletinScrutin extends Model
{
    use HasFactory;

    protected $fillable = [
        'participation_scrutin_id',
        'contenus_reponses',
    ];

    protected $casts = [
        'participation_scrutin_id' => 'integer',
        'contenus_reponses' => 'json',
    ];

    public function participation(): BelongsTo
    {
        return $this->belongsTo(ParticipationScrutin::class);
    }

    /**
     * Obtenir la réponse pour une question donnée
     *
     * @param  string|int  $questionId
     * @return mixed|null
     */
    public function getReponsePourQuestion($questionId)
    {
        $questionId = (string) $questionId;

        return data_get($this->contenus_reponses, $questionId);
    }

    /**
     * Vérifier si une réponse existe pour une question
     */
    public function aReponsePourQuestion($questionId): bool
    {
        return ! is_null($this->getReponsePourQuestion($questionId));
    }
}
