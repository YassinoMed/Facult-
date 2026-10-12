<?php

namespace App\Modules\Enseignement\Models;

use App\Models\User;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Affectation d'un enseignant à un enseignement (§10-12, §16).
 */
class AffectationEnseignement extends Model
{
    protected $table = 'affectations_enseignement';

    public const ROLES = ['responsable', 'principal', 'enseignant', 'intervenant', 'vacataire'];

    protected $fillable = [
        'enseignement_id', 'teacher_id', 'role', 'volume_heures',
        'date_debut', 'date_fin', 'statut', 'motif', 'auteur_id',
    ];

    protected function casts(): array
    {
        return ['volume_heures' => 'decimal:1', 'date_debut' => 'date', 'date_fin' => 'date'];
    }

    public function enseignement(): BelongsTo
    {
        return $this->belongsTo(Enseignement::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auteur_id');
    }
}
