<?php

namespace App\Modules\Evaluation\Models;

use App\Models\Etudiant;
use App\Models\InscriptionPedagogique;
use App\Models\User;
use App\Modules\Schedule\Models\Room;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Candidat extends Model
{
    protected $table = 'evaluation_candidats';

    /** Absence explicite : jamais un simple 0 (§10). */
    public const STATUTS = ['present', 'absent', 'absent_justifie', 'absent_injustifie', 'excuse', 'retard'];

    protected $fillable = [
        'epreuve_id', 'etudiant_id', 'inscription_pedagogique_id', 'salle_id', 'place', 'statut', 'justification', 'decide_par',
    ];

    public function epreuve(): BelongsTo
    {
        return $this->belongsTo(Epreuve::class, 'epreuve_id');
    }

    public function etudiant(): BelongsTo
    {
        return $this->belongsTo(Etudiant::class);
    }

    public function inscriptionPedagogique(): BelongsTo
    {
        return $this->belongsTo(InscriptionPedagogique::class, 'inscription_pedagogique_id');
    }

    public function decidePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decide_par');
    }

    public function salle(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'salle_id');
    }

    public function salleEffective(): ?Room
    {
        return $this->salle ?? $this->epreuve?->salle;
    }

    public function note(): HasOne
    {
        return $this->hasOne(NoteEpreuve::class, 'candidat_id');
    }

    public function estAbsent(): bool
    {
        return $this->statut !== 'present';
    }
}
