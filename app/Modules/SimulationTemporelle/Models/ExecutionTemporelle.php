<?php

namespace App\Modules\SimulationTemporelle\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Exécution TRAÇABLE et IDEMPOTENTE (UNIQUE scénario+hash_entrees+graine). */
class ExecutionTemporelle extends Model
{
    protected $table = 'executions_temporelles';

    protected $fillable = [
        'scenario_temporel_id', 'statut', 'hash_entrees', 'hash_resultat',
        'graine', 'version_moteur', 'nb_variantes', 'horizon_annees',
        'resultat', 'duree_ms', 'cree_par',
    ];

    protected function casts(): array
    {
        return ['resultat' => 'array'];
    }

    public function scenario(): BelongsTo
    {
        return $this->belongsTo(ScenarioTemporel::class, 'scenario_temporel_id');
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(SnapshotExecution::class, 'execution_temporelle_id');
    }

    public function creePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par');
    }
}
