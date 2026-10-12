<?php

namespace App\Modules\OnlineRequests\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Module OnlineRequests : pièce justificative (disque privé + SHA-256).
 */
class RequestAttachment extends Model
{
    protected $table = 'request_attachments';

    protected $fillable = [
        'online_request_id',
        'original_filename',
        'storage_path',
        'mime_type',
        'extension',
        'size',
        'version',
        'sha256',
        'uploaded_by',
    ];

    protected $hidden = ['storage_path'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    /**
     * @return BelongsTo<OnlineRequest, $this>
     */
    public function demande(): BelongsTo
    {
        return $this->belongsTo(OnlineRequest::class, 'online_request_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function televersePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
