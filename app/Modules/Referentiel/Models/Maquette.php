<?php

namespace App\Modules\Referentiel\Models;

use App\Models\AnneeUniversitaire;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Maquette / plan d'études versionnée (§18-19) : programme × année,
 * statuts contrôlés, verrouillée dès approbation.
 */
class Maquette extends Model
{
    public const STATUTS = ['draft', 'review', 'approved', 'active', 'archived'];

    /** Transitions autorisées (source => cibles). */
    public const TRANSITIONS = [
        'draft' => ['review', 'archived'],
        'review' => ['draft', 'approved', 'archived'],
        'approved' => ['active', 'archived'],
        'active' => ['archived'],
        'archived' => [],
    ];

    protected $fillable = [
        'programme_id', 'annee_universitaire_id', 'version', 'statut',
        'date_effet', 'auteur_id', 'validee_par', 'date_validation',
        'version_precedente_id', 'commentaire',
    ];

    protected function casts(): array
    {
        return ['date_effet' => 'date', 'date_validation' => 'datetime'];
    }

    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }

    public function anneeUniversitaire(): BelongsTo
    {
        return $this->belongsTo(AnneeUniversitaire::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auteur_id');
    }

    public function versionPrecedente(): BelongsTo
    {
        return $this->belongsTo(self::class, 'version_precedente_id');
    }

    public function versionsSuivantes(): HasMany
    {
        return $this->hasMany(self::class, 'version_precedente_id');
    }

    public function unitesEnseignement(): BelongsToMany
    {
        return $this->belongsToMany(UniteEnseignement::class, 'maquette_ue', 'maquette_id', 'ue_id')
            ->withPivot('semestre_id')->withTimestamps();
    }

    public function estVerrouillee(): bool
    {
        return in_array($this->statut, ['approved', 'active', 'archived'], true);
    }

    public function peutTransiterVers(string $cible): bool
    {
        return in_array($cible, self::TRANSITIONS[$this->statut] ?? [], true);
    }
}
