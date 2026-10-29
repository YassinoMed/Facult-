<?php

namespace App\Modules\Schedule\Models;

use App\Models\Matiere;
use App\Modules\Attendance\Models\TeacherPresence;
use App\Modules\Enseignement\Models\Enseignement;
use App\Modules\Enseignement\Models\SousGroupe;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Module Schedule : créneau hebdomadaire récurrent. Matière = modèle
 * `Matiere` du cœur réutilisé (aucune table course créée).
 */
class ScheduleSlot extends Model
{
    protected $table = 'schedule_slots';

    public const JOURS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    public const TYPES = ['course', 'td', 'tp', 'exam', 'meeting', 'workshop', 'other'];

    public const STATUTS = ['scheduled', 'cancelled', 'modified', 'completed'];

    public const PARITES = ['toutes', 'paire', 'impaire', 'specifiques'];

    protected $fillable = [
        'schedule_id', 'matiere_id', 'enseignement_id', 'teacher_id', 'room_id', 'sous_groupe_id',
        'day_of_week', 'semaine_parite', 'semaines_specifiques',
        'start_time', 'end_time', 'type', 'status', 'notes', 'equipements_requis',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'semaines_specifiques' => 'array',
            'equipements_requis' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Schedule, $this>
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    /**
     * @return BelongsTo<Matiere, $this>
     */
    public function matiere(): BelongsTo
    {
        return $this->belongsTo(Matiere::class);
    }

    /**
     * @return BelongsTo<Enseignement, $this>
     */
    public function enseignement(): BelongsTo
    {
        return $this->belongsTo(Enseignement::class);
    }

    /**
     * @return BelongsTo<SousGroupe, $this>
     */
    public function sousGroupe(): BelongsTo
    {
        return $this->belongsTo(SousGroupe::class);
    }

    /**
     * @return BelongsTo<Teacher, $this>
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * @return HasMany<ScheduleSlotTeacher, $this>
     */
    public function teachersPivot(): HasMany
    {
        return $this->hasMany(ScheduleSlotTeacher::class, 'schedule_slot_id');
    }

    /**
     * @return BelongsToMany<Teacher, $this>
     */
    public function coEnseignants(): BelongsToMany
    {
        return $this->belongsToMany(Teacher::class, 'schedule_slot_teachers', 'schedule_slot_id', 'teacher_id')
            ->withPivot(['role', 'volume_heures'])
            ->withTimestamps();
    }

    /**
     * @return array<int, int>
     */
    public function tousEnseignantsIds(): array
    {
        $ids = [];
        if ($this->teacher_id !== null) {
            $ids[] = $this->teacher_id;
        }

        $coIds = $this->teachersPivot()->pluck('teacher_id')->all();

        return array_values(array_unique(array_merge($ids, $coIds)));
    }

    /**
     * @return HasMany<ScheduleException, $this>
     */
    public function exceptions(): HasMany
    {
        return $this->hasMany(ScheduleException::class, 'schedule_slot_id');
    }

    /**
     * @return HasMany<ScheduleChange, $this>
     */
    public function changes(): HasMany
    {
        return $this->hasMany(ScheduleChange::class, 'schedule_slot_id')->latest();
    }

    /**
     * @return HasMany<TeacherPresence, $this>
     */
    public function presences(): HasMany
    {
        return $this->hasMany(TeacherPresence::class, 'schedule_slot_id');
    }

    public function dureeMinutes(): int
    {
        $debut = Carbon::createFromFormat('H:i:s', strlen($this->start_time) === 5 ? $this->start_time.':00' : $this->start_time);
        $fin = Carbon::createFromFormat('H:i:s', strlen($this->end_time) === 5 ? $this->end_time.':00' : $this->end_time);

        return (int) abs($debut->diffInMinutes($fin));
    }

    public function dureeHeures(): float
    {
        return round($this->dureeMinutes() / 60, 2);
    }

    public function chevauche(string $debut, string $fin): bool
    {
        return $this->start_time < $fin && $debut < $this->end_time;
    }

    /**
     * Deux créneaux ne sont en conflit que si leurs semaines/parités se recoupent.
     */
    public function conflitSemainesAvec(?string $autreParite, ?array $autreSemaines = null): bool
    {
        $p1 = $this->semaine_parite ?? 'toutes';
        $p2 = $autreParite ?? 'toutes';

        // 'toutes' chevauche n'importe quelle semaine
        if ($p1 === 'toutes' || $p2 === 'toutes') {
            return true;
        }

        // Alternance quinzaine disjointe
        if (($p1 === 'paire' && $p2 === 'impaire') || ($p1 === 'impaire' && $p2 === 'paire')) {
            return false;
        }

        // Cas des semaines spécifiques explicites
        if ($p1 === 'specifiques' && $p2 === 'specifiques') {
            $s1 = (array) ($this->semaines_specifiques ?? []);
            $s2 = (array) ($autreSemaines ?? []);

            return array_intersect($s1, $s2) !== [];
        }

        return true;
    }

    /**
     * Vérifie si la salle possède les équipements requis par ce créneau.
     *
     * @return array<int, string> Liste des équipements manquants
     */
    public function conflitEquipementsAvec(?Room $salle): array
    {
        $requis = (array) ($this->equipements_requis ?? []);
        if ($requis === [] || $salle === null) {
            return [];
        }

        $disponibles = (array) ($salle->equipements_fixes ?? []);
        $manquants = array_diff($requis, $disponibles);

        return array_values($manquants);
    }
}
