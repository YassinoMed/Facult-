<?php

namespace App\Modules\StudentDossiers\Models;

use App\Models\Etudiant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Module StudentDossiers (§1) : dossier administratif permanent, UN par
 * étudiant (UNIQUE etudiant_id). AUCUNE donnée personnelle dupliquée :
 * nom, CIN, contacts restent dans `etudiants`.
 */
class StudentDossier extends Model
{
    protected $table = 'student_dossiers';

    public const STATUTS = ['open', 'complete', 'archived'];

    protected $fillable = [
        'etudiant_id',
        'reference',
        'status',
    ];

    /**
     * @return BelongsTo<Etudiant, $this>
     */
    public function etudiant(): BelongsTo
    {
        return $this->belongsTo(Etudiant::class);
    }

    /**
     * @return HasMany<StudentDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(StudentDocument::class, 'student_dossier_id');
    }
}
