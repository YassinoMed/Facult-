# Architecture de sécurité

## Actifs & menaces

| Actif | Menaces | Contrôles implémentés |
|---|---|---|
| Comptes back-office | brute force, fixation, vol session | throttle 5/min, `session()->regenerate()`, sessions chiffrées+HTTPS, révocation (`admin/sessions`), reset broker 60 min |
| Tokens API | vol, abus, rejeu | Sanctum 90j, scopes moindres, préfixe, révocation CLI, throttle par usage, anti-replay webhooks (HMAC+timestamp+24h cache) |
| Dossiers/notes/résultats | IDOR/BOLA, fuite faculté | `peutAccederFaculte()` + 404 avant Gate, policies, scoping serveur (60+ sites), tests IDOR |
| Finances | trop-perçu, double paiement, reçu fictif | centimes INTEGER, statuts + transitions, idempotence `reference_externe`, reçu après confirmation seule |
| Uploads | polyglotte, MIME menteur, malware | extension+MIME finfo+signature, 10 Mo, UUID, disque privé, hook AV (Null par défaut = tracé), jamais d'URL publique |
| Web (XSS/CSRF/SQLi) | injection | Blade échappé, CSRF, Eloquent + `Recherche::contient`, CSP (unsafe-inline/eval documentés : Alpine/Vite) |

## Données sensibles

| Donnée | Propriétaire | Accès | Conservation |
|---|---|---|---|
| CIN, contacts | étudiant | périmètre faculté, `archives.view` | dossier actif + archives |
| Notes/résultats | jury/scolarité | `notes.view`, étudiant = ses publiées | historique immuable |
| Finances | scolarité | `finances.view/manage/confirm` (agent exclu) | factures jamais supprimées |
| Documents privés | propriétaire | téléchargement contrôlé + audit | expiration → `expired`, destruction manuelle |
| Tokens/secrets | intégration | super_admin + CLI, affiché 1× | 90j, révocables |
| Logs | exploitant | super_admin (maintenance), sans PII/OCR/secrets | purge 90j (`maintenance:purger-logs`) |

## Non promis

2FA/TOTP, WAF, chiffrement colonne, signature qualifiée, conformité réglementaire
automatique — voir backlog du rapport final.
