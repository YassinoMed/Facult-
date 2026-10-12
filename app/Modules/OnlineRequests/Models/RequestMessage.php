<?php

namespace App\Modules\OnlineRequests\Models;

use App\Models\Etudiant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fil de discussion d'une demande (Phase 9 §61) : messages de
 * l'administration et réponses de l'étudiant, historisés et audités.
 * Contenu textuel seul — les fichiers restent des pièces versionnées.
 */
class RequestMessage extends Model
{
    protected $table = 'request_messages';

    protected $fillable = [
        'online_request_id', 'user_id', 'etudiant_id', 'message',
    ];

    public function demande(): BelongsTo
    {
        return $this->belongsTo(OnlineRequest::class, 'online_request_id');
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function etudiant(): BelongsTo
    {
        return $this->belongsTo(Etudiant::class, 'etudiant_id');
    }

    public function estAdministration(): bool
    {
        return $this->user_id !== null;
    }
}
