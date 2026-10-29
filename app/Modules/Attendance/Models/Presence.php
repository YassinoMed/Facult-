<?php

namespace App\Modules\Attendance\Models;

use App\Models\Etudiant;
use App\Models\User;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Module Attendance — relevé de présence (§16/§32 : table dédiée
 * `presences`, aucune colonne ajoutée aux tables centrales).
 * Appel enseignant : rattachement `schedule_slot_id` (séance officielle),
 * auteur enseignant `teacher_id`, traçabilité `historique` (corrections
 * motivées, miroir `TeacherPresence::ajouterTrace`).
 */
class Presence extends Model
{
    protected $table = 'presences';

    public const STATUTS = ['present', 'absent', 'retard', 'excuse'];

    /** Statuts saisissables à l'appel (excusé = workflow justifications uniquement). */
    public const STATUTS_APPEL = ['present', 'absent', 'retard'];

    protected $fillable = [
        'etudiant_id',
        'date',
        'seance',
        'statut',
        'motif',
        'enregistre_par',
        'schedule_slot_id',
        'teacher_id',
        'historique',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['date' => 'date', 'historique' => 'array'];
    }

    /**
     * @return BelongsTo<Etudiant, $this>
     */
    public function etudiant(): BelongsTo
    {
        return $this->belongsTo(Etudiant::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function enregistrePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enregistre_par');
    }

    /**
     * @return BelongsTo<ScheduleSlot, $this>
     */
    public function seanceOfficielle(): BelongsTo
    {
        return $this->belongsTo(ScheduleSlot::class, 'schedule_slot_id');
    }

    /**
     * @return BelongsTo<Teacher, $this>
     */
    public function enseignantAuteur(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'teacher_id');
    }

    /**
     * Dernière justification déposée pour ce relevé (D-02) : relation
     * dédiée à l'affichage du lien de téléchargement sans requête par
     * ligne. Le contrôle d'accès reste intégralement serveur.
     *
     * @return HasOne<Justification, $this>
     */
    public function derniereJustification(): HasOne
    {
        return $this->hasOne(Justification::class, 'presence_id')->latestOfMany();
    }

    /**
     * Trace horodatée non destructive (corrections motivées).
     *
     * @param  array<string, mixed>  $details
     */
    public function ajouterTrace(string $action, ?string $motif, ?int $auteurId, array $details = []): void
    {
        $historique = $this->historique ?? [];

        $historique[] = [
            'date' => now()->toIso8601String(),
            'action' => $action,
            'motif' => $motif,
            'auteur_id' => $auteurId,
            'ip' => request()?->ip(),
            'details' => $details,
        ];

        $this->historique = $historique;
    }
}
