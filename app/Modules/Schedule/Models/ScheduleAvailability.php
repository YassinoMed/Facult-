<?php

namespace App\Modules\Schedule\Models;

use App\Models\AnneeUniversitaire;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PHASE A (Smart Timetables) — disponibilité/indisponibilité déclarée
 * d'une ressource (enseignant, salle ou groupe) : récurrence hebdomadaire
 * (jour + plage horaire), portée année universitaire optionnelle et
 * fenêtre de dates effective optionnelle.
 *
 * `indisponible` = contrainte DURE (conflit bloquant) ;
 * `souhaitee` = préférence non bloquante (moteur de génération, Phase B).
 *
 * Exclusivité de ressource : exactement un de teacher_id / room_id /
 * group_id renseigné (validé côté requêtes HTTP).
 */
class ScheduleAvailability extends Model
{
    protected $table = 'schedule_availabilities';

    public const TYPES = ['indisponible', 'souhaitee'];

    /** Liste canonique des jours — jamais dupliquée (référence ScheduleSlot). */
    public const JOURS = ScheduleSlot::JOURS;

    /** Libellés français (affichage et messages de conflit). */
    public const JOURS_FR = [
        'monday' => 'lundi', 'tuesday' => 'mardi', 'wednesday' => 'mercredi',
        'thursday' => 'jeudi', 'friday' => 'vendredi', 'saturday' => 'samedi',
        'sunday' => 'dimanche',
    ];

    protected $fillable = [
        'annee_universitaire_id', 'teacher_id', 'room_id', 'group_id',
        'day_of_week', 'start_time', 'end_time', 'type',
        'effective_from', 'effective_to', 'reason', 'cree_par',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_to' => 'date'];
    }

    public function anneeUniversitaire(): BelongsTo
    {
        return $this->belongsTo(AnneeUniversitaire::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par');
    }

    /**
     * Chevauchement strict de plages (fin == début = OK) — même sémantique
     * que ScheduleSlot::chevauche.
     */
    public function chevauche(string $debut, string $fin): bool
    {
        return $this->start_time < $fin && $debut < $this->end_time;
    }

    /**
     * Plage en clair : « lundi 08:00–12:00 ».
     */
    public function plageHumaine(): string
    {
        $jour = self::JOURS_FR[$this->day_of_week] ?? $this->day_of_week;

        return $jour.' '.substr((string) $this->start_time, 0, 5).'–'.substr((string) $this->end_time, 0, 5);
    }

    /**
     * Libellé humain de la ressource concernée.
     */
    public function libelleRessource(): string
    {
        if ($this->teacher_id !== null) {
            return 'Enseignant '.($this->teacher?->nomComplet() ?? '#'.$this->teacher_id);
        }

        if ($this->room_id !== null) {
            return 'Salle '.($this->room?->code ?? '#'.$this->room_id);
        }

        return 'Groupe '.($this->group?->code ?? '#'.$this->group_id);
    }

    /**
     * La fenêtre effective de la plage recouvre-t-elle la période du
     * planning ? Conservateur : sans période de planning connue, ou sans
     * fenêtre déclarée, la plage s'applique.
     */
    public function couvrePeriode(?string $debutPlanning, ?string $finPlanning): bool
    {
        if ($debutPlanning === null && $finPlanning === null) {
            return true;
        }

        if ($this->effective_from === null && $this->effective_to === null) {
            return true;
        }

        $debutPlage = $this->effective_from?->format('Y-m-d') ?? '0000-01-01';
        $finPlage = $this->effective_to?->format('Y-m-d') ?? '9999-12-31';

        return $debutPlage <= ($finPlanning ?? '9999-12-31')
            && $finPlage >= ($debutPlanning ?? '0000-01-01');
    }

    /**
     * @param  Builder<ScheduleAvailability>  $query
     * @return Builder<ScheduleAvailability>
     */
    public function scopeIndisponibles(Builder $query): Builder
    {
        return $query->where('type', 'indisponible');
    }
}
