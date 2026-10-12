<?php

namespace App\Modules\Tasks\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskEvenement extends Model
{
    protected $table = 'task_evenements';

    public $timestamps = false;

    protected $fillable = ['task_id', 'action', 'commentaire', 'auteur_id', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function tache(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auteur_id');
    }
}
