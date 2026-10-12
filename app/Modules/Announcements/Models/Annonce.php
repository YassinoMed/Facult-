<?php

namespace App\Modules\Announcements\Models;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Annonce extends Model
{
    protected $table = 'annonces';

    public const TYPES = ['information', 'urgent', 'academic', 'exam', 'administrative', 'event', 'maintenance'];

    public const STATUTS = ['draft', 'scheduled', 'published', 'expired', 'archived'];

    public const AUDIENCES = ['tous', 'etudiants', 'enseignants', 'personnel', 'programme', 'groupe'];

    protected $fillable = [
        'titre', 'contenu', 'type', 'statut', 'priorite', 'epinglee',
        'publiee_a', 'expire_a', 'lien', 'faculte_id', 'programme_id',
        'groupe_id', 'audience', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'epinglee' => 'boolean',
            'publiee_a' => 'datetime',
            'expire_a' => 'datetime',
        ];
    }

    public function faculte(): BelongsTo
    {
        return $this->belongsTo(Faculte::class);
    }

    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }

    public function groupe(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'groupe_id');
    }

    public function lectures(): HasMany
    {
        return $this->hasMany(AnnonceLecture::class, 'annonce_id');
    }

    public function estVisible(): bool
    {
        if ($this->statut !== 'published') {
            return false;
        }

        $maintenant = now();

        if ($this->publiee_a !== null && $this->publiee_a->isFuture()) {
            return false;
        }

        return $this->expire_a === null || $this->expire_a->isFuture();
    }

    /**
     * Portée étudiant (audit sécurité 04/10/2026) : miroir du filtre
     * d'index — audience étendue + faculté. Sans ce garde-fou, le show
     * par ID lisait les annonces réservées au personnel/enseignants ou
     * ciblant une autre faculté.
     */
    public function visiblePourEtudiant(Etudiant $etudiant): bool
    {
        if (! $this->estVisible()) {
            return false;
        }

        if ($this->faculte_id !== null && $etudiant->faculte_id !== null
            && (int) $this->faculte_id !== (int) $etudiant->faculte_id) {
            return false;
        }

        return match (true) {
            in_array($this->audience, ['tous', 'etudiants'], true) => true,
            $this->audience === 'programme' => $this->programme_id !== null
                && (int) $this->programme_id === (int) $etudiant->programme_id,
            $this->audience === 'groupe' => $this->groupe_id !== null
                && (int) $this->groupe_id === (int) $etudiant->groupe_id,
            // personnel / enseignants : jamais visibles côté étudiant.
            default => false,
        };
    }

    /**
     * Portée enseignant (miroir étudiant) : audiences `tous` et
     * `enseignants` + faculté. Sans accusé de lecture (AnnonceLecture est
     * lié aux étudiants uniquement).
     */
    public function visiblePourEnseignant(Teacher $enseignant): bool
    {
        if (! $this->estVisible()) {
            return false;
        }

        $faculteId = $enseignant->faculteId();

        if ($this->faculte_id !== null && $faculteId !== null
            && (int) $this->faculte_id !== (int) $faculteId) {
            return false;
        }

        return in_array($this->audience, ['tous', 'enseignants'], true);
    }
}
