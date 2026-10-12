<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 16 §16.4 : lot de génération en masse (critères, compteurs,
 * rapport, archive ZIP à durée limitée).
 */
class DocumentBatch extends Model
{
    protected $table = 'document_batches';

    protected $fillable = [
        'reference', 'type', 'criteres', 'statut', 'total', 'reussis',
        'echoues', 'ignores', 'rapport', 'archive_path', 'archive_expire_le', 'auteur_id',
    ];

    protected function casts(): array
    {
        return ['criteres' => 'array', 'rapport' => 'array', 'archive_expire_le' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auteur_id');
    }

    public function estTermine(): bool
    {
        return in_array($this->statut, ['termine', 'annule'], true);
    }
}
