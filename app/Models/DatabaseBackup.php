<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registre des sauvegardes database (LOOP BACKUP-01) : métadonnées
 * d'exploitation uniquement. Aucun contenu SQL, aucun secret —
 * les fichiers restent hors webroot, jamais servis directement.
 */
class DatabaseBackup extends Model
{
    public const STATUTS = ['RUNNING', 'SUCCESS', 'FAILED', 'VERIFICATION_FAILED', 'RESTORE_TEST_FAILED', 'DELETED'];

    public const TYPES = ['MANUAL', 'SCHEDULED', 'PRE_DEPLOYMENT', 'PRE_MIGRATION'];

    protected $table = 'database_backups';

    protected $fillable = [
        'uuid', 'filename', 'disk', 'path', 'database_name', 'connexion', 'size_bytes',
        'checksum', 'checksum_algorithm', 'compression', 'status', 'type',
        'started_at', 'completed_at', 'duration_ms', 'verified_at',
        'restore_tested_at', 'restore_test_status', 'error_code', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'verified_at' => 'datetime',
            'restore_tested_at' => 'datetime',
        ];
    }

    public function createur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function estValidePourRestauration(): bool
    {
        return $this->status === 'SUCCESS'
            && $this->restore_test_status === 'SUCCESS';
    }

    public function ageSecondes(): ?int
    {
        if ($this->completed_at === null) {
            return null;
        }

        return max(0, now()->diffInSeconds($this->completed_at));
    }
}
