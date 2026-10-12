<?php

namespace App\Modules\Enseignement\Models;

use App\Models\AnneeUniversitaire;
use App\Models\Matiere;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Enseignement (§7) : une ECUE dispensée avec un type, des volumes,
 * des enseignants et des groupes, pour une année. L'ECUE = contenu,
 * l'enseignement = manière de dispenser (§3).
 */
class Enseignement extends Model
{
    public const STATUTS = ['draft', 'planned', 'validated', 'active', 'completed', 'cancelled', 'archived'];

    public const TRANSITIONS = [
        'draft' => ['planned', 'cancelled'],
        'planned' => ['draft', 'validated', 'cancelled'],
        'validated' => ['active', 'cancelled'],
        'active' => ['completed', 'cancelled'],
        'completed' => ['archived'],
        'cancelled' => ['archived'],
        'archived' => [],
    ];

    /** Statuts verrouillés : modification sensible uniquement. */
    public const VERROUILLES = ['validated', 'active', 'completed', 'archived'];

    protected $fillable = [
        'code', 'matiere_id', 'type_id', 'annee_universitaire_id', 'semestre_id',
        'programme_id', 'volume_cm', 'volume_td', 'volume_tp',
        'volume_projet', 'volume_autre', 'statut', 'description', 'cree_par',
    ];

    public function matiere(): BelongsTo
    {
        return $this->belongsTo(Matiere::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(TypeEnseignement::class, 'type_id');
    }

    public function anneeUniversitaire(): BelongsTo
    {
        return $this->belongsTo(AnneeUniversitaire::class);
    }

    public function semestre(): BelongsTo
    {
        return $this->belongsTo(SemestreLmd::class, 'semestre_id');
    }

    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }

    public function affectations(): HasMany
    {
        return $this->hasMany(AffectationEnseignement::class);
    }

    public function enseignants(): BelongsToMany
    {
        return $this->belongsToMany(Teacher::class, 'affectations_enseignement', 'enseignement_id', 'teacher_id')
            ->withPivot(['role', 'volume_heures', 'statut'])->withTimestamps();
    }

    public function groupes(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'enseignement_groupe', 'enseignement_id', 'groupe_id')
            ->withPivot('sous_groupe_id')->withTimestamps();
    }

    public function salles(): BelongsToMany
    {
        return $this->belongsToMany(Room::class, 'enseignement_salle', 'enseignement_id', 'room_id')
            ->withTimestamps();
    }

    /**
     * Volume total prévu (§8, règle centralisée).
     */
    public function volumeTotal(): int
    {
        return (int) $this->volume_cm + (int) $this->volume_td
            + (int) $this->volume_tp + (int) $this->volume_projet
            + (int) $this->volume_autre;
    }

    public function volumeAffecte(): float
    {
        return (float) $this->affectations()->where('statut', 'active')->sum('volume_heures');
    }

    public function estVerrouille(): bool
    {
        return in_array($this->statut, self::VERROUILLES, true);
    }

    public function peutTransiterVers(string $cible): bool
    {
        return in_array($cible, self::TRANSITIONS[$this->statut] ?? [], true);
    }
}
