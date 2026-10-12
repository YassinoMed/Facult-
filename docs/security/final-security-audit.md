# Audit sécurité final (2026-09-30)

Vérifications réellement exécutées :

| Contrôle | Méthode | Résultat |
|---|---|---|
| Routes sans auth | `audit:routes-securite` | 0 critique, 21 revues (Gates contrôleur, convention) |
| IDOR / inter-faculté | tests (étudiants, documents, notes, finances, tâches, GED) | 404 avant Gate, PASS |
| Uploads | tests + `DocumentService` (MIME/signature/AV) | PASS |
| API | scopes, throttles, enveloppe, HMAC, drift OpenAPI | PASS |
| Sessions | fixation (regenerate), révocation, reset broker 60 min | PASS |
| Finances | centimes, transitions, idempotence, reçu post-confirmation | PASS |
| Headers | CSP/COOP/CORP/HSTS conditionnel, nosniff, SAMEORIGIN | test PASS |
| Mass assignment | `role`/`statut` non mass-assignables, `definirRole/Statut` | PASS |
| Rate limiting | login 5/min, imports 5/min, API 60/min | PASS |
| Secrets | aucun en logs (audit §24-25), tokens 1×, `.env` hors backup | PASS |

Voir aussi `docs/security/security-architecture.md` (menaces, données,
mesures) et `tests/Feature/Security/` (44 tests).

Risques résiduels assumés : pas de MFA (H2), CSP `unsafe-inline/eval`
(Alpine/Vite — build CSP dédié en backlog), `Teacher` sans compte
(notifications staff approximées, documenté).
