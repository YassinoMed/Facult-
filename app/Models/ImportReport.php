<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rapport d'import/export — traçabilité complète de chaque échange :
 * compteurs, erreurs, avertissements, durée, statut, mode (§40).
 */
class ImportReport extends Model
{
    protected $fillable = [
        'user_id',
        'integration',
        'direction',
        'type',
        'format',
        'filename',
        'file_hash',
        'file_path',
        'external_reference',
        'mode',
        'status',
        'total_rows',
        'valid_rows',
        'duplicate_rows',
        'error_rows',
        'errors',
        'warnings',
        'preview',
        'duration_ms',
        'expires_at',
    ];

    protected $casts = [
        'errors' => 'array',
        'warnings' => 'array',
        'preview' => 'array',
        'total_rows' => 'integer',
        'valid_rows' => 'integer',
        'duplicate_rows' => 'integer',
        'error_rows' => 'integer',
        'duration_ms' => 'integer',
        'expires_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getReferenceAttribute(): string
    {
        return sprintf('EXCH-%s-%05d', $this->created_at?->format('Ymd') ?? now()->format('Ymd'), $this->id);
    }

    public function getStatutLibelleAttribute(): string
    {
        return match ($this->status) {
            'pending' => 'En attente de confirmation',
            'processing' => 'Traitement en cours',
            'completed' => $this->error_rows > 0 || $this->duplicate_rows > 0 ? 'Terminé avec avertissements' : 'Terminé',
            'failed' => 'Échoué (rollback complet)',
            'cancelled' => 'Annulé',
            'expired' => 'Expiré (fichier supprimé)',
            default => $this->status,
        };
    }

    public function estExpire(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
