<?php

namespace App\Modules\Tasks\Models;

use App\Models\Faculte;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Task extends Model
{
    protected $table = 'tasks';

    public const STATUTS = ['pending', 'in_progress', 'waiting', 'completed', 'cancelled', 'expired'];

    public const PRIORITES = ['critical', 'high', 'normal', 'low'];

    /** Transitions serveur (le frontend ne décide jamais). */
    public const TRANSITIONS = [
        'pending' => ['in_progress', 'waiting', 'completed', 'cancelled'],
        'in_progress' => ['waiting', 'completed', 'cancelled'],
        'waiting' => ['in_progress', 'completed', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
        'expired' => ['in_progress', 'completed', 'cancelled'],
    ];

    protected $fillable = [
        'titre', 'description', 'source', 'cle_unique', 'statut', 'priorite',
        'echeance_a', 'lien', 'data', 'faculte_id', 'assigne_a', 'created_by', 'terminee_a',
    ];

    protected function casts(): array
    {
        return ['data' => 'array', 'echeance_a' => 'datetime', 'terminee_a' => 'datetime'];
    }

    public function faculte(): BelongsTo
    {
        return $this->belongsTo(Faculte::class);
    }

    public function assigne(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigne_a');
    }

    public function evenements(): HasMany
    {
        return $this->hasMany(TaskEvenement::class, 'task_id')->latest('id');
    }

    public function estActive(): bool
    {
        return in_array($this->statut, ['pending', 'in_progress', 'waiting', 'expired'], true);
    }

    public function peutTransiterVers(string $cible): bool
    {
        return in_array($cible, self::TRANSITIONS[$this->statut] ?? [], true);
    }
}
