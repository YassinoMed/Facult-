<?php

namespace App\Modules\Attendance\Models;

use App\Models\User;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pointage brut d'un enseignant (badgeuse, terminal, portail, saisie manuelle, QR code).
 *
 * Règle d'intégrité : aucun pointage brut n'est silencieusement supprimé,
 * même s'il ne correspond à aucune séance planifiée.
 */
class TeacherPointage extends Model
{
    protected $table = 'teacher_pointages';

    public const TYPES = ['entree', 'sortie', 'presence'];

    public const SOURCES = ['badgeuse', 'manuel', 'terminal', 'portail', 'qr', 'autre'];

    protected $fillable = [
        'teacher_id',
        'date',
        'horodatage',
        'type',
        'source',
        'terminal_ip',
        'notes',
        'enregistre_par',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'horodatage' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Teacher, $this>
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'teacher_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function enregistrePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enregistre_par');
    }
}
