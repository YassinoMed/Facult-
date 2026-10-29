<?php

namespace App\Modules\Schedule\Models;

use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * PHASE 5 (Smart Timetables) — Scénario comparatif d'emploi du temps.
 * Permet d'explorer, évaluer et comparer plusieurs variantes d'optimisation
 * avant d'en retenir une sur le planning officiel.
 */
class TimetableScenario extends Model
{
    protected $table = 'timetable_scenarios';

    public const STATUT_BROUILLON = 'brouillon';

    public const STATUT_EVALUE = 'evalue';

    public const STATUT_RETENU = 'retenu';

    public const STATUT_REJETE = 'rejete';

    public const STATUTS = [
        self::STATUT_BROUILLON,
        self::STATUT_EVALUE,
        self::STATUT_RETENU,
        self::STATUT_REJETE,
    ];

    protected $fillable = [
        'schedule_id',
        'code',
        'nom',
        'statut',
        'score_qualite',
        'nb_conflits_durs',
        'creneaux_json',
        'metriques_json',
        'parametres_optimisation',
        'auteur_id',
    ];

    protected function casts(): array
    {
        return [
            'score_qualite' => 'integer',
            'nb_conflits_durs' => 'integer',
            'creneaux_json' => 'array',
            'metriques_json' => 'array',
            'parametres_optimisation' => 'array',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auteur_id');
    }

    public function scopeRetenu(Builder $query): Builder
    {
        return $query->where('statut', self::STATUT_RETENU);
    }

    public function estValide(): bool
    {
        return $this->nb_conflits_durs === 0;
    }

    /**
     * Applique les créneaux de ce scénario sur le planning cible.
     * Le planning reste strictement en statut « brouillon » pour validation humaine.
     */
    public function appliquerSurPlanning(?int $auteurId = null): Schedule
    {
        if ($this->schedule->estVerrouille() || $this->schedule->statut === 'publie') {
            throw new DomainException("Impossible d'appliquer un scénario sur un planning verrouillé ou publié.");
        }

        return DB::transaction(function () use ($auteurId) {
            $schedule = Schedule::whereKey($this->schedule_id)->lockForUpdate()->firstOrFail();

            // 1. Suppression des créneaux actuels non annulés
            $schedule->slots()->where('status', '!=', 'cancelled')->delete();

            // 2. Instanciation des créneaux du scénario
            $creneaux = $this->creneaux_json ?? [];
            foreach ($creneaux as $data) {
                $slot = ScheduleSlot::create([
                    'schedule_id' => $schedule->id,
                    'matiere_id' => $data['matiere_id'],
                    'enseignement_id' => $data['enseignement_id'] ?? null,
                    'teacher_id' => $data['teacher_id'] ?? null,
                    'room_id' => $data['room_id'] ?? null,
                    'day_of_week' => $data['day_of_week'],
                    'start_time' => $data['start_time'],
                    'end_time' => $data['end_time'],
                    'type' => $data['type'] ?? 'course',
                    'status' => 'scheduled',
                    'sous_groupe_id' => $data['sous_groupe_id'] ?? null,
                    'semaine_parite' => $data['semaine_parite'] ?? 'toutes',
                    'semaines_specifiques' => $data['semaines_specifiques'] ?? null,
                    'equipements_requis' => $data['equipements_requis'] ?? null,
                ]);

                if (! empty($data['co_enseignants_ids'])) {
                    $slot->coEnseignants()->sync($data['co_enseignants_ids']);
                }

                ScheduleChange::create([
                    'schedule_slot_id' => $slot->id,
                    'changed_by' => $auteurId,
                    'change_type' => 'created',
                    'new_values' => $slot->toArray(),
                    'reason' => "Application du scénario « {$this->nom} »",
                ]);
            }

            // 3. Mise à jour des statuts des scénarios
            self::where('schedule_id', $schedule->id)->update(['statut' => self::STATUT_REJETE]);
            $this->update(['statut' => self::STATUT_RETENU]);

            return $schedule->fresh(['slots']);
        });
    }
}
