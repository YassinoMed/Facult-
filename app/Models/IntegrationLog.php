<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Journal des intégrations (§55) — immuable (created_at uniquement).
 * Règle absolue : jamais de payload contenant des données personnelles
 * ou des secrets — uniquement hash + métadonnées techniques.
 */
class IntegrationLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'integration_logs';

    protected $fillable = [
        'integration',
        'direction',
        'operation',
        'status',
        'external_reference',
        'request_id',
        'http_status',
        'duration_ms',
        'error_code',
        'payload_hash',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'duration_ms' => 'integer',
        'http_status' => 'integer',
    ];

    /**
     * Trace un échange. $contenu (payload brut optionnel) n'est JAMAIS
     * stocké : seul son SHA-256 est conservé pour l'empreinte.
     */
    public static function tracer(
        string $integration,
        string $direction,
        string $operation,
        string $status = 'completed',
        ?string $contenu = null,
        ?int $httpStatus = null,
        ?int $durationMs = null,
        ?string $errorCode = null,
        ?string $requestId = null,
        ?string $externalReference = null,
    ): self {
        return static::create([
            'integration' => $integration,
            'direction' => $direction,
            'operation' => $operation,
            'status' => $status,
            'http_status' => $httpStatus,
            'duration_ms' => $durationMs,
            'error_code' => $errorCode,
            'request_id' => $requestId,
            'external_reference' => $externalReference,
            'payload_hash' => $contenu !== null ? hash('sha256', $contenu) : null,
            'created_at' => now(),
        ]);
    }
}
