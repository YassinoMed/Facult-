<?php

namespace App\Modules\SimulationTemporelle\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** État agrégé à un pas simulé (compteurs par cohorte — zéro PII). */
class SnapshotExecution extends Model
{
    protected $table = 'snapshots_executions';

    protected $fillable = ['execution_temporelle_id', 'pas', 'temps_simule', 'etat'];

    protected function casts(): array
    {
        return ['etat' => 'array'];
    }

    public function execution(): BelongsTo
    {
        return $this->belongsTo(ExecutionTemporelle::class, 'execution_temporelle_id');
    }
}
