<?php

namespace App\Models;

use App\Modules\Schedule\Models\Teacher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Grade enseignant configurable (§12) : jamais codé en dur.
 */
class GradeEnseignant extends Model
{
    protected $table = 'grades_enseignants';

    protected $fillable = ['code', 'nom', 'ordre', 'actif'];

    protected function casts(): array
    {
        return ['actif' => 'boolean'];
    }

    public function enseignants(): HasMany
    {
        return $this->hasMany(Teacher::class, 'grade_id');
    }
}
