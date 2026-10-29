<?php

namespace App\Modules\Schedule\Models;

use App\Models\Faculte;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PHASE 4 (Smart Timetables) — Traçabilité et historique immuable des générations
 * d'emplois du temps universitaires.
 */
class TimetableGeneration extends Model
{
    protected $table = 'timetable_generations';

    public const STATUT_EN_COURS = 'en_cours';

    public const STATUT_SUCCES = 'succes';

    public const STATUT_PARTIEL = 'partiel';

    public const STATUT_ECHEC = 'echec';

    public const STATUT_ANNULE = 'annule';

    public const STATUTS = [
        self::STATUT_EN_COURS,
        self::STATUT_SUCCES,
        self::STATUT_PARTIEL,
        self::STATUT_ECHEC,
        self::STATUT_ANNULE,
    ];

    protected $fillable = [
        'schedule_id',
        'faculte_id',
        'auteur_id',
        'statut',
        'nombre_seances_a_placer',
        'nombre_seances_placees',
        'nombre_iterations',
        'duree_ms',
        'score_qualite',
        'parametres',
        'statistiques',
        'erreurs',
        'instantané_avant',
    ];

    protected function casts(): array
    {
        return [
            'nombre_seances_a_placer' => 'integer',
            'nombre_seances_placees' => 'integer',
            'nombre_iterations' => 'integer',
            'duree_ms' => 'float',
            'score_qualite' => 'integer',
            'parametres' => 'array',
            'statistiques' => 'array',
            'erreurs' => 'array',
            'instantané_avant' => 'array',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    public function faculte(): BelongsTo
    {
        return $this->belongsTo(Faculte::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auteur_id');
    }

    public function scopeSucces(Builder $query): Builder
    {
        return $query->where('statut', self::STATUT_SUCCES);
    }

    public function estSucces(): bool
    {
        return $this->statut === self::STATUT_SUCCES;
    }

    public function estPartiel(): bool
    {
        return $this->statut === self::STATUT_PARTIEL;
    }

    public function estEchec(): bool
    {
        return $this->statut === self::STATUT_ECHEC;
    }

    public function tauxPlacement(): float
    {
        if ($this->nombre_seances_a_placer === 0) {
            return 100.0;
        }

        return round(($this->nombre_seances_placees / $this->nombre_seances_a_placer) * 100, 1);
    }
}
