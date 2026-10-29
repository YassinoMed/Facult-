<?php

namespace App\Modules\SimulationTemporelle\Models;

use App\Models\Faculte;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Définition de scénario temporel VERSIONNÉE (descripteurs déclaratifs). */
class ScenarioTemporel extends Model
{
    protected $table = 'scenarios_temporels';

    protected $fillable = [
        'code', 'libelle', 'domaine', 'faculte_id', 'criteres',
        'contraintes', 'objectifs', 'version', 'cree_par',
    ];

    protected function casts(): array
    {
        return ['criteres' => 'array', 'contraintes' => 'array', 'objectifs' => 'array'];
    }

    public function faculte(): BelongsTo
    {
        return $this->belongsTo(Faculte::class);
    }

    public function executions(): HasMany
    {
        return $this->hasMany(ExecutionTemporelle::class);
    }

    public function creePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par');
    }
}
