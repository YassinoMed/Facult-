# API — sécurité (Phase 11 §11.13-11.16, §11.34)

Sanctum Bearer (90j, préfixe, révocable) ; jamais de 2e login.
Scopes : contrat testé (toute route authentifiée porte `scope:`,
sauf health/ready/webhook-HMAC). Périmètre relu en base, 404
anti-BOLA (jamais 403 révélateur). Throttles dédiés (api 60,
imports 5, exports 10, webhooks 30). Idempotence : clés naturelles
+ `Idempotency-Key` (POST imports/exports, TTL 24h, rejoué signalé).
Uploads : MIME réel + signatures + 10 Mo + antivirus GED.
