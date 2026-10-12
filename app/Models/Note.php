<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Note académique — donnée critique.
 * Une seule note par (semestre, matière, session).
 * locked_at : verrouillée, correction réservée aux rôles supérieurs.
 */
class Note extends Model
{
    protected $fillable = [
        'etudiant_id',
        'inscription_academique_id',
        'semestre_id',
        'matiere_id',
        'note',
        'coefficient',
        'credits',
        'session',
        'observation',
        'locked_at',
    ];

    protected $casts = [
        'note' => 'decimal:2',
        'coefficient' => 'decimal:2',
        'locked_at' => 'datetime',
    ];

    public function etudiant(): BelongsTo
    {
        return $this->belongsTo(Etudiant::class);
    }

    public function inscriptionAcademique(): BelongsTo
    {
        return $this->belongsTo(InscriptionAcademique::class);
    }

    public function semestre(): BelongsTo
    {
        return $this->belongsTo(Semestre::class);
    }

    public function matiere(): BelongsTo
    {
        return $this->belongsTo(Matiere::class);
    }

    public function estVerrouillee(): bool
    {
        return $this->locked_at !== null;
    }
}
