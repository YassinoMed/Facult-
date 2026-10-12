# Production readiness — Référentiel LMD (checklist)

Date : 12/10/2026 · Décision de déploiement : **aux responsables habilités** (jamais à l'outil).
Statuts : `PASS` (preuve) · `FAIL` (échec) · `NOT_RUN` · `BLOCKED` (autorisation/dépendance) · `NOT_APPLICABLE`.

| Contrôle | Statut | Preuve / réserve |
|---|---|---|
| Migrations (garde active-unique appliquée dev) | PASS | `migrations` + `SHOW INDEX` (LOOP 0) ; no-op sqlite prouvé par suites |
| Concurrence (locks + deadlock rejouable + bascule) | PASS | scratch MySQL 9.2 : 2/2 + 1/1 ; skips documentés hors MySQL |
| Idempotence import (token unique, upsert scopé, file non rejouée) | PASS | 14 tests Boucle10 + 222 périmètre import |
| Sécurité (auth, gates, scope, IDOR/BOLA, API 401, PII hors logs) | PASS | 41 tests + `audit:routes-securite` sans alerte neuve |
| Suite complète | PASS | 3007 tests, 0 échec (12/10, journal `tmp/tmpdir/suite-complete-20261012.log`) ; 14 skips gates env |
| Pint / routes | PASS | vert ; 787 routes |
| Sauvegarde (`sauvegarde:creer --verifier`, chiffrement, planification) | PASS | `SauvegardeChiffrementTest` vert (12/10) ; exercice de restauration réel : **PASS (12/10)** — drill exécuté : `BACKUP_ROUNDTRIP=1` mysqldump sur scratch dédiée → manifest + --verifier → import base vierge → parité schéma+données (1903 tables, ~20 s), scratchs supprimées |
| Drill de restauration | PASS (12/10) | `BackupRoundtripTest` 1/1 en réel — aucune donnée réelle touchée |
| Concurrence (locks + deadlock rejouable + bascule) | PASS | re-vérifié 12/10 : `MaquetteRaceMysqlTest` 4/4 + `ScheduleRaceMysqlTest` 2/2 sur scratchs MySQL 9.2 fraîches (ancienne scratch schedule purgée après staleness constatée — hygiène, pas un défaut produit) |
| Migrations | PASS (12/10) | `migrate:status` : 142 Ran, **0 pending** |
| Rotation/masquage logs (`?q=`, PII) | BLOCKED | recommandation ops en suspens (rotation access logs) |
| Secrets/config prod | BLOCKED | valeurs côté exploitant (`.env.example` seul en dépôt) ; rien à afficher ici |
| Activation maquettes / migration standalone / notifs | BLOCKED | arbitrages ARB-001…009 requis (`docs/pedagogie/registre-arbitrages-lmd.md`) |
| Contenus pédagogiques S2–S6 | BLOCKED | ARB-001/002, sans contenu supposé |

**Décision laissée aux responsables : pas de GO tant que BLOCKED subsistent.**
