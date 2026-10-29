<?php

namespace App\Modules\Schedule\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modèle ScheduleVersion : instantané immuable et auditable d'un planning publié ou sauvegardé,
 * permettant la comparaison structurelle (diff) et le rollback sécurisé.
 */
class ScheduleVersion extends Model
{
    protected $table = 'schedule_versions';

    public const STATUT_PUBLIEE = 'publiee';

    public const STATUT_ARCHIVEE = 'archivee';

    public const STATUT_BROUILLON_SNAPSHOT = 'brouillon_snapshot';

    protected $fillable = [
        'schedule_id',
        'numero_version',
        'code_version',
        'statut',
        'snapshot_slots_json',
        'note_version',
        'nb_slots',
        'score_qualite',
        'published_by',
        'published_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'numero_version' => 'integer',
            'nb_slots' => 'integer',
            'score_qualite' => 'integer',
            'snapshot_slots_json' => 'array',
            'published_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function publieur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function estPubliee(): bool
    {
        return $this->statut === self::STATUT_PUBLIEE;
    }

    public function estArchivee(): bool
    {
        return $this->statut === self::STATUT_ARCHIVEE;
    }
}
