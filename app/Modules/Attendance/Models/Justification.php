<?php

namespace App\Modules\Attendance\Models;

use App\Models\Etudiant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Justification extends Model
{
    protected $table = 'attendance_justifications';

    public const STATUTS = ['pending', 'approved', 'rejected'];

    protected $fillable = [
        'presence_id', 'etudiant_id', 'motif', 'piece_chemin', 'piece_nom',
        'statut', 'commentaire_validation', 'valide_par', 'validee_a',
    ];

    protected function casts(): array
    {
        return ['validee_a' => 'datetime'];
    }

    public function presence(): BelongsTo
    {
        return $this->belongsTo(Presence::class, 'presence_id');
    }

    public function etudiant(): BelongsTo
    {
        return $this->belongsTo(Etudiant::class);
    }

    public function validePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'valide_par');
    }
}
