<?php

namespace App\Models;

use App\Modules\Referentiel\Models\Departement;
use App\Modules\Referentiel\Models\Domaine;
use App\Modules\Referentiel\Models\Programme;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Faculte extends Model
{
    use HasFactory;

    protected $table = 'facultes';

    protected $fillable = [
        'nom',
        'code',
        'sigle',
        'description',
        'adresse',
        'telephone',
        'email',
        'site_web',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function etudiants(): HasMany
    {
        return $this->hasMany(Etudiant::class);
    }

    public function departements(): HasMany
    {
        return $this->hasMany(Departement::class);
    }

    public function domaines(): HasMany
    {
        return $this->hasMany(Domaine::class);
    }

    public function programmes(): HasMany
    {
        return $this->hasMany(Programme::class);
    }

    public function etudiantsCount(): int
    {
        return $this->etudiants()->count();
    }
}
