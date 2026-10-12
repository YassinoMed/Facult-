<?php

namespace App\Modules\OnlineRequests\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Module OnlineRequests : PDF générés pour les demandes approuvées.
 * Registre dédié — aucune dépendance vers StudentDossiers.
 */
class OnlineRequestDocument extends Model
{
    protected $table = 'online_request_documents';

    protected $fillable = [
        'reference',
        'online_request_id',
        'type',
        'version',
        'storage_path',
        'sha256',
        'generated_by',
    ];

    protected $hidden = ['storage_path'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['version' => 'integer'];
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
    public function generePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
