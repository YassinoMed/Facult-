<?php

namespace App\Modules\Evaluation\Models;

use App\Models\User;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JuryMembre extends Model
{
    protected $table = 'evaluation_jury_membres';

    public const FONCTIONS = ['president', 'membre', 'rapporteur', 'secretaire', 'responsable_programme'];

    protected $fillable = [
        'deliberation_id', 'user_id', 'teacher_id', 'fonction', 'present', 'signature_at',
    ];

    protected function casts(): array
    {
        return ['present' => 'boolean', 'signature_at' => 'datetime'];
    }

    public function deliberation(): BelongsTo
    {
        return $this->belongsTo(Deliberation::class, 'deliberation_id');
    }

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function enseignant(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'teacher_id');
    }
}
