<?php

namespace App\Modules\Schedule\Models;

use App\Models\AnneeUniversitaire;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Module Schedule : emploi du temps = groupe × année × semestre (1/2).
 */
class Schedule extends Model
{
    protected $table = 'schedules';

    protected $fillable = ['annee_universitaire_id', 'semestre', 'groupe_id', 'nom', 'starts_at', 'ends_at', 'actif', 'statut', 'publie_a'];

    public const STATUTS = ['brouillon', 'relecture', 'valide', 'publie', 'verrouille', 'archive'];

    public const TRANSITIONS = [
        'brouillon' => ['relecture', 'valide'],
        'relecture' => ['brouillon', 'valide'],
        'valide' => ['publie', 'brouillon'],
        'publie' => ['verrouille', 'brouillon'],
        'verrouille' => ['archive', 'brouillon'],
        'archive' => [],
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['semestre' => 'integer', 'actif' => 'boolean', 'starts_at' => 'date', 'ends_at' => 'date', 'publie_a' => 'datetime'];
    }

    public function peutTransiterVers(string $cible): bool
    {
        return in_array($cible, self::TRANSITIONS[$this->statut ?? 'brouillon'] ?? [], true);
    }

    public function estVerrouille(): bool
    {
        return in_array($this->statut, ['verrouille', 'archive'], true);
    }

    /**
     * @return BelongsTo<AnneeUniversitaire, $this>
     */
    public function anneeUniversitaire(): BelongsTo
    {
        return $this->belongsTo(AnneeUniversitaire::class);
    }

    /**
     * @return BelongsTo<Group, $this>
     */
    public function groupe(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'groupe_id');
    }

    /**
     * @return HasMany<ScheduleSlot, $this>
     */
    public function slots(): HasMany
    {
        return $this->hasMany(ScheduleSlot::class, 'schedule_id');
    }
}
