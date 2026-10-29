<?php

namespace App\Modules\Schedule\Models;

use App\Models\Batiment;
use App\Models\Faculte;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Module Schedule : salle.
 */
class Room extends Model
{
    protected $table = 'rooms';

    protected $fillable = [
        'code', 'nom', 'capacite', 'min_surveillants', 'ratio_surveillants', 'actif',
        'campus', 'batiment', 'batiment_id', 'etage', 'faculte_id',
        'type_salle', 'accessible_pmr', 'heure_ouverture', 'heure_fermeture', 'equipements_fixes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'actif' => 'boolean',
            'capacite' => 'integer',
            'min_surveillants' => 'integer',
            'ratio_surveillants' => 'integer',
            'accessible_pmr' => 'boolean',
            'equipements_fixes' => 'array',
        ];
    }

    /**
     * @return HasMany<ScheduleSlot, $this>
     */
    public function slots(): HasMany
    {
        return $this->hasMany(ScheduleSlot::class, 'room_id');
    }

    public function faculte(): BelongsTo
    {
        return $this->belongsTo(Faculte::class);
    }

    public function batimentRelation(): BelongsTo
    {
        return $this->belongsTo(Batiment::class, 'batiment_id');
    }

    public function localisation(): string
    {
        $nomBatiment = $this->batimentRelation?->nom ?? $this->batiment;

        return implode(' · ', array_filter([$this->campus, $nomBatiment, $this->etage])) ?: '—';
    }

    public function estOuvertePlage(string $debut, string $fin): bool
    {
        // Normalisation minute : les attributs stockés peuvent porter des
        // secondes ('09:00:00') alors que les appelants comparent en 'H:i'
        // — sinon une épreuve commençant exactement à l'heure d'ouverture
        // serait déclarée fermée ('09:00' < '09:00:00').
        $ouverture = $this->heure_ouverture !== null ? substr((string) $this->heure_ouverture, 0, 5) : null;
        $fermeture = $this->heure_fermeture !== null ? substr((string) $this->heure_fermeture, 0, 5) : null;

        if ($ouverture !== null && $debut < $ouverture) {
            return false;
        }

        if ($fermeture !== null && $fin > $fermeture) {
            return false;
        }

        return true;
    }

    public function estAmphitheatre(): bool
    {
        return $this->type_salle === 'amphitheatre'
            || stripos((string) $this->nom, 'amphi') !== false
            || stripos((string) $this->code, 'amphi') !== false;
    }
}
