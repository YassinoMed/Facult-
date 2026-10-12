<?php

namespace App\Modules\Schedule\Models;

use App\Models\GradeEnseignant;
use App\Models\TeacherDocument;
use App\Models\TeacherReclamation;
use App\Models\TeacherRequest;
use App\Models\User;
use App\Modules\Attendance\Models\TeacherPointage;
use App\Modules\Attendance\Models\TeacherPresence;
use App\Modules\Enseignement\Models\AffectationEnseignement;
use App\Modules\Evaluation\Models\Surveillance;
use App\Modules\Referentiel\Models\Departement;
use App\Modules\Referentiel\Models\Specialite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Module Schedule : enseignant (entité propre, sans compte `users`).
 * Phase 2 : matricule, grade, département, spécialité, bureau (§11-13).
 * Espace Enseignant : mot de passe propre (session dédiée
 * `enseignant_auth_id`, miroir `etudiants`) — jamais de rôle back-office.
 */
class Teacher extends Model
{
    protected $table = 'teachers';

    protected $hidden = ['password'];

    protected $fillable = [
        'nom', 'prenom', 'email', 'password', 'actif',
        'matricule', 'grade_id', 'departement_id', 'specialite_id',
        'bureau', 'telephone', 'user_id', 'date_embauche', 'statut',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['actif' => 'boolean', 'date_embauche' => 'date', 'password' => 'hashed'];
    }

    public function nomComplet(): string
    {
        return trim($this->prenom.' '.$this->nom);
    }

    /**
     * Périmètre facultaire de l'enseignant (via son département).
     * NULL = rattachement transversal (comme les salles globales).
     */
    public function faculteId(): ?int
    {
        return $this->departement?->faculte_id;
    }

    public function getNomCompletAttribute(): string
    {
        return $this->nomComplet();
    }

    public function grade(): BelongsTo
    {
        return $this->belongsTo(GradeEnseignant::class);
    }

    public function departement(): BelongsTo
    {
        return $this->belongsTo(Departement::class);
    }

    public function specialite(): BelongsTo
    {
        return $this->belongsTo(Specialite::class);
    }

    /**
     * Phase 3 : affectations d'enseignement.
     */
    public function affectationsEnseignement(): HasMany
    {
        return $this->hasMany(AffectationEnseignement::class, 'teacher_id');
    }

    /**
     * @return HasMany<ScheduleSlot, $this>
     */
    public function slots(): HasMany
    {
        return $this->hasMany(ScheduleSlot::class, 'teacher_id');
    }

    public function surveillances(): HasMany
    {
        return $this->hasMany(Surveillance::class, 'teacher_id');
    }

    /**
     * @return HasMany<TeacherPointage, $this>
     */
    public function pointages(): HasMany
    {
        return $this->hasMany(TeacherPointage::class, 'teacher_id');
    }

    /**
     * @return HasMany<TeacherPresence, $this>
     */
    public function presencesSeances(): HasMany
    {
        return $this->hasMany(TeacherPresence::class, 'teacher_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(TeacherDocument::class, 'teacher_id');
    }

    public function demandes(): HasMany
    {
        return $this->hasMany(TeacherRequest::class, 'teacher_id');
    }

    public function reclamations(): HasMany
    {
        return $this->hasMany(TeacherReclamation::class, 'teacher_id');
    }
}
