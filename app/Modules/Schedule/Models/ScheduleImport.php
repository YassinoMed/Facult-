<?php

namespace App\Modules\Schedule\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Module Schedule (§20-21) : traçabilité des imports (jamais le
 * fichier source, jamais de PII superflue).
 */
class ScheduleImport extends Model
{
    protected $table = 'schedule_imports';

    public const STATUTS = ['pending', 'validated', 'completed', 'failed', 'cancelled'];

    protected $fillable = [
        'schedule_id', 'imported_by', 'filename', 'format', 'integration', 'status',
        'total_rows', 'successful_rows', 'failed_rows', 'warnings_count', 'errors',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_rows' => 'integer',
            'successful_rows' => 'integer',
            'failed_rows' => 'integer',
            'warnings_count' => 'integer',
            'errors' => 'array',
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
    public function importePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }
}
