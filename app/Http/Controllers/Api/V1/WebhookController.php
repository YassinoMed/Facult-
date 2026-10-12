<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\IntegrationLog;
use App\Services\Io\WebhookVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API v1 — Réception de webhooks externes (§53-54).
 *
 * Sécurité obligatoire avant TOUT traitement :
 *   X-Signature  : base64(HMAC-SHA256(corps, secret)) — jamais d'URL nue ;
 *   X-Timestamp  : fenêtre anti-rejeu ;
 *   X-Request-ID : unicité sur 24 h (anti-replay) ;
 *   payload      : event en liste blanche, validé strictement.
 */
class WebhookController extends Controller
{
    private const EVENEMENTS_AUTORISES = [
        'student.registered',
        'student.updated',
        'grade.submitted',
        'ping',
    ];

    public function __construct(private readonly WebhookVerifier $verifier) {}

    public function receive(Request $request): JsonResponse
    {
        $verification = $this->verifier->verifier($request);

        if (! $verification['valide']) {
            IntegrationLog::tracer(
                integration: 'webhook',
                direction: 'webhook',
                operation: 'webhook.rejected',
                status: 'failed',
                httpStatus: 401,
                errorCode: 'hmac_invalid',
                requestId: $verification['request_id'] ?: null,
            );

            return response()->json(['message' => $verification['raison']], 401);
        }

        $payload = $request->json()->all();

        if (! is_array($payload) || ! isset($payload['event']) || ! in_array($payload['event'], self::EVENEMENTS_AUTORISES, true)) {
            IntegrationLog::tracer(
                integration: 'webhook',
                direction: 'webhook',
                operation: 'webhook.rejected',
                status: 'failed',
                httpStatus: 422,
                errorCode: 'payload_invalid',
                requestId: $verification['request_id'],
            );

            return response()->json(['message' => 'Payload invalide : « event » manquant ou non autorisé.'], 422);
        }

        // Traitement accepté : journalisation de l'échange (empreinte seule, jamais le contenu)
        IntegrationLog::tracer(
            integration: 'webhook',
            direction: 'webhook',
            operation: 'webhook.'.$payload['event'],
            status: 'completed',
            httpStatus: 202,
            contenu: $request->getContent(),
            requestId: $verification['request_id'],
            externalReference: (string) ($payload['external_reference'] ?? '') ?: null,
        );

        return response()->json([
            'message' => 'Webhook accepté et journalisé.',
            'request_id' => $verification['request_id'],
        ], 202);
    }
}
