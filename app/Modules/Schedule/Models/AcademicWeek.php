<?php

namespace App\Modules\Schedule\Models;

use App\Models\AnneeUniversitaire;
use App\Modules\Referentiel\Models\SemestreLmd;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Semaine académique / pédagogique avec parité et découpage temporel.
 */
class AcademicWeek extends Model
{
    protected $table = 'academic_weeks';

    public const TYPES = ['cours', 'vacances', 'revision', 'examens', 'rattrapage'];

    public const PARITES = ['toutes', 'paire', 'impaire'];

    protected $fillable = [
        'annee_universitaire_id',
        'semestre',
        'semestre_lmd_id',
        'numero_semaine',
        'code',
        'nom',
        'date_debut',
        'date_fin',
        'type',
        'parite',
        'actif',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_debut' => 'date',
            'date_fin' => 'date',
            'semestre' => 'integer',
            'numero_semaine' => 'integer',
            'actif' => 'boolean',
        ];
    }

    public function anneeUniversitaire(): BelongsTo
    {
        return $this->belongsTo(AnneeUniversitaire::class);
    }

    public function semestreLmd(): BelongsTo
    {
        return $this->belongsTo(SemestreLmd::class);
    }

    public function estOuvree(): bool
    {
        return in_array($this->type, ['cours', 'rattrapage'], true);
    }

    public function estVacances(): bool
    {
        return $this->type === 'vacances';
    }

    public function contientDate(string $date): bool
    {
        $d = Carbon::parse($date)->format('Y-m-d');
        $debut = $this->date_debut->format('Y-m-d');
        $fin = $this->date_fin->format('Y-m-d');

        return $d >= $debut && $d <= $fin;
    }

    public function pariteCalculee(): string
    {
        return ($this->numero_semaine % 2 === 0) ? 'paire' : 'impaire';
    }

    public function correspondAParite(string $pariteDemandee): bool
    {
        if ($pariteDemandee === 'toutes' || $this->parite === 'toutes') {
            return true;
        }

        $effective = ($this->parite !== 'toutes') ? $this->parite : $this->pariteCalculee();

        return $effective === $pariteDemandee;
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActif(Builder $query): Builder
    {
        return $query->where('actif', true);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeCours(Builder $query): Builder
    {
        return $query->where('type', 'cours');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopePourAnnee(Builder $query, int $anneeId): Builder
    {
        return $query->where('annee_universitaire_id', $anneeId);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopePourSemestre(Builder $query, int $semestre): Builder
    {
        return $query->where('semestre', $semestre);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopePourParite(Builder $query, string $parite): Builder
    {
        if ($parite === 'toutes') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($parite) {
            $q->where('parite', 'toutes')
                ->orWhere('parite', $parite);
        });
    }
}
