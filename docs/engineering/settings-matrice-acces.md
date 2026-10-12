# SETTINGS — Matrice d'accès globale / facultaire (LOOP A, 12/10/2026)

## 1. État existant vérifié

| Périmètre | Mécanisme | Preuve |
|---|---|---|
| Global `parametres` | `settings.view` (lecture) / `settings.manage` (écriture), **super_admin seul** (`Permissions.php:206-207`, matrice `:281`, absents `:437`) | `ParametrePolicy`, routes `web.php:920-923` |
| Facultaire | **Aucun mécanisme générique** : isolation via policies métier (`peutAccederFaculte`, scope→404) par ressource | `ArchivePolicy`, `EvaluationPolicy::dansPerimetre`, `FaculteScopeEcritureTest` (19) |
| Individuel | Session/utilisateur (`user_preferences`, `notification_preferences`) | Tables + portails |
| Modules | `auth, admin, module:{nom}` → 404 si inactif | `EnsureModuleEnabled.php:18`, `bootstrap/app.php` |

## 2. Décisions de surcharge (Phase 3)

- **A (global, sans surcharge)** : `etablissement.*`, seuils indicatifs,
  purge, pagination, `sauvegarde.plan.*`, throttles, timeouts, secrets → G.
- **B (surcharge F autorisée)** : **AUCUNE prouvée** — statut actuel : zéro.
  Conditions d'admission en B : règle métier écrite + policy scopée +
  tests IDOR A/B + clés `cle:faculte:{id}` isolées + invalidation par périmètre.
- **C (déploiement, NC)** : secrets, connexions, `SESSION/SANCTUM/DB/MAIL`,
  `MODULE_*` (flags, cf. doc dépendances), binaires OCR.
- **D (secrets)** : `APP_KEY`, `*_PASSWORD`, `*_SECRET`, `SAUVEGARDE_CLE`,
  `INTEGRATION_WEBHOOK_SECRET` — coffre ops, jamais `parametres` (garde :
  `ParametreService` docblock + log sans secret).
- **E (module existant)** : règles évaluation, contraintes surveillances,
  périodes, gabarits, matrice pièces, maquettes — SETTINGS = lecture/délégation.
- **F (humain/réglementaire)** : ARB-001…009, activation modules sensibles,
  hors-ligne et IA (NO-GO sans réapprobation, DEC-05).

## 3. Règles d'isolation (à faire respecter en LOOP B+)

- Une valeur F ne lit/écrit que son périmètre (`faculte_id` serveur, jamais
  paramètre client) ; lecture F croisée = 404 (convention `FaculteScopeEcritureTest`).
- Sécurité non affaiblissable vers le bas : throttles, policies, CSRF, scopes
  Sanctum **hors SETTINGS** (aucun écran kill-switch, §D mission).
- Concurrence : `lockForUpdate` + 409 + audit avant/après (pattern
  `CorrectionService`, `Assignment*`) — à exiger pour toute écriture SETTINGS.
- Journal : acteur, clé, portée, date, résultat, corrélation (`X-Request-ID`
  si disponible) ; valeurs sensibles masquées ; refus tracés sans PII
  (convention LOOP 11).
