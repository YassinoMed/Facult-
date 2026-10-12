<?php

namespace App\Modules\Evaluation\Models;

use App\Models\User;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Surveillance extends Model
{
    protected $table = 'evaluation_surveillants';

    public const ROLES = ['principal', 'adjoint', 'surveillant', 'reserve'];

    public const STATUTS = ['assigne', 'confirme', 'remplace', 'annule'];

    public const PRESENCES = ['present', 'retard', 'absent', 'absent_justifie'];

    protected $fillable = [
        'epreuve_id', 'teacher_id', 'user_id', 'role', 'salle_id', 'statut', 'presence',
        'heure_arrivee', 'heure_depart', 'motif_absence', 'observations', 'remplace_surveillance_id',
    ];

    protected function casts(): array
    {
        return [];
    }

    public function epreuve(): BelongsTo
    {
        return $this->belongsTo(Epreuve::class, 'epreuve_id');
    }

    public function enseignant(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'teacher_id');
    }

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function salle(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'salle_id');
    }

    public function remplaceSurveillance(): BelongsTo
    {
        return $this->belongsTo(self::class, 'remplace_surveillance_id');
    }

    public function remplacant(): HasOne
    {
        return $this->hasOne(self::class, 'remplace_surveillance_id');
    }

    public function libelleSurveillant(): string
    {
        if ($this->enseignant !== null) {
            return $this->enseignant->nomComplet();
        }

        return $this->utilisateur?->name ?? '—';
    }

    public function libelleRole(): string
    {
        return match ($this->role) {
            'principal' => 'Principal',
            'adjoint' => 'Adjoint',
            'reserve' => 'Garde / Réserve',
            default => 'Surveillant',
        };
    }

    public function estActif(): bool
    {
        return in_array($this->statut, ['assigne', 'confirme'], true);
    }
}
