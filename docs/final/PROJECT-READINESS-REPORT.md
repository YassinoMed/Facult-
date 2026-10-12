# Project Readiness Report — Phases 25–32

## 1-3. Architecture / Fonctionnalités / Modules

Plateforme CORE + 11 modules (Attendance, StudentDossiers, OnlineRequests,
Referentiel, Enseignement, Evaluation, Announcements, Calendar, Tasks,
Schedule, Finances). Nouveautés 25–32 : PWA (manifest, icônes, SW, page
hors-ligne), salles localisées (campus/bâtiment/étage/faculté), reset mot de
passe (broker existant), sessions actives, CI, sauvegarde vérifiée + DR,
`/admin/system`, audit routes, docs security/operations/final.

## 4-7. Sécurité / Performance / Accessibilité / Mobile

- Sécurité : throttles, 404-avant-Gate, centimes INTEGER, enveloppe API
  additive, `audit:routes-securite` sans critique, headers CSP/HSTS.
- Performance : 300+ tests en ~15 s, `cursor()`, pagination, cache versionné.
- Accessibilité : skip-link, rôles ARIA, labels, focus clavier, `x-modal`/`x-tabs`.
- Mobile : drawer, grilles responsives, cartes `<md`, touch 44px, PWA installable.

## 8-9. API / Interopérabilité

v1 Sanctum + scopes, enveloppe `{message,code,request_id}`, OpenAPI + test de
dérive, DataExchange (16 entités), webhooks HMAC. Pas de portail étudiant API.

## 10-11. Data Governance / Observability

Anomalies persistées + lineage `source_systeme`, normalisation partagée,
`X-Request-ID` global, readiness 200/503, alerting ops avec cooldown, logs
sans PII, purge 90 j.

## 12. Backup / DR

`sauvegarde:creer` (mysqldump/sqlite + zip + manifest sha256 + rétention),
`--verifier`, échec explicite, `docs/operations/disaster-recovery.md`
(RPO/RTO cibles à valider, procédures, secrets hors sauvegarde).

## 13. CI/CD

`.github/workflows/ci.yml` (Pint + tests + build), `pint.json`, script
`composer lint`. Pas de branch protection côté repo (non-git ici).

## 14. Tests (audit final 2026-09-30)

Suite complète `vendor/bin/phpunit` : **732 tests, 731 passés, 1 esquivé
(OCR, dépendance système), 0 échec**, 2780 assertions. Parallèle `artisan
test` limité par mémoire runners (128 Mo) — cause environnementale connue,
série `--no-coverage` fait foi. Matrice : Auth, Students, Teaching,
Planning, Exams, Grades, Documents, Finance, API, Security, Data Quality —
tous PASS (voir `docs/audit/feature-matrix.md`).

## 15. UX

Design System unique, cockpit + BI + système, palette Ctrl+K, statuts
textuels (jamais couleur seule).

## 16. Documentation

`docs/audit/phases-25-32-audit.md`, `docs/security/security-architecture.md`,
`docs/operations/disaster-recovery.md`, OpenAPI, AGENTS.md.

## 17. Risques résiduels

- R1 : SW seulement en HTTPS (voulu) ; HTTP local = pas de PWA.
- R2 : backup exige mysqldump + espace disque + copie externe (non vérifiable ici).
- R3 : `Teacher` sans `faculte_id` direct ni `user_id` (jointure département).
- R4 : scoping mono-faculté par utilisateur (pas de multi-rattachement).
- R5 : CSP `unsafe-inline/eval` requis par Alpine/Vite (build CSP dédié en backlog).

## 18. Dette technique

- `StatistiquesController` vs `BIService` : chevauchement assumé (fonctions différentes).
- `GUIDE-DEPLOY §8-14` : complété partiellement par DR + CI (annexe deploy.sh absente).
- Vues `show` Blade longues (fiche étudiant) : extraction différée.

## 19. Fonctionnalités volontairement non implémentées

MFA/TOTP (dépendance externe), portail étudiant API, webhooks sortants,
`Idempotency-Key`, provider de paiement réel, Elasticsearch/Redis/Kafka,
application native, Data Warehouse, graphiques chart-lib, digest/opt-out,
fusion auto de doublons, suppression auto de documents.

## 20. Recommandations de maintenance

Exécuter `audit:routes-securite` à chaque release, surveiller
`supervision:controler`, tester `sauvegarde:creer --verifier` mensuellement,
relire `docs/audit/phases-25-32-audit.md` avant toute Phase 33+.

## 21. Statut final (audit 2026-09-30)

Basé exclusivement sur vérifications exécutées : suite 732/731, routes 0
critique, qualité 20 anomalies non critiques, sécurité PASS, backup testé
dry-run. **READY WITH CONDITIONS** (backup prod + RPO/RTO signés, MFA à
décider avant exposition externe).
