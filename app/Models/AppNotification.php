<?php

namespace App\Models;

use App\Modules\Schedule\Models\Teacher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Centre de notifications (§4-6) : un destinataire exactement
 * (user back-office, étudiant OU enseignant), jamais de PII superflue.
 */
class AppNotification extends Model
{
    protected $table = 'notifications';

    protected $fillable = [
        'user_id', 'etudiant_id', 'teacher_id', 'type', 'titre', 'message',
        'data', 'lien', 'read_at', 'priorite',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['data' => 'array', 'read_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Etudiant, $this>
     */
    public function etudiant(): BelongsTo
    {
        return $this->belongsTo(Etudiant::class);
    }

    /**
     * @return BelongsTo<Teacher, $this>
     */
    public function enseignant(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'teacher_id');
    }

    public function marquerLue(): void
    {
        if ($this->read_at === null) {
            $this->update(['read_at' => now()]);
        }
    }
}
