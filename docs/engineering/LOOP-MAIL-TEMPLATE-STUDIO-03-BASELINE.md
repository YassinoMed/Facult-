# LOOP-MAIL-TEMPLATE-STUDIO-03-BASELINE — Inventaire, critères et risques

**Date :** 14/10/2026 · **Phase :** LAN (zéro changement de code à ce stade)

## 1. Documents relus
- `LOOP-MAIL-00-BASELINE.md` (127 l.) : 6 Notifications mail, 0 Mailable maison,
  sujets littéraux, OTP synchrones, `mail.logs.view` sans UI (depuis comblé par le cockpit).
- `LOOP-MAIL-ENTERPRISE-REPORT.md` : `CourrielService` (catalogue 7 modèles, validation,
  idempotence, reprise encadrée), `CourrielMessage` (HTML+texte, en-têtes `X-Courriel-*`),
  journal passif, cockpit `admin.courriels.*`, permissions `mail.retry`/`mail.send`.
- `LOOP-MAIL-OPERATIONAL-02-REPORT.md` : `PASS_WITH_RESERVATIONS`, envoi réel Gmail OK
  vers boîte autorisée, échec+reprise contrôlés, workers prod et SPF/DKIM/DMARC de
  production en réserve.

## 2. Contrats à préserver (re-vérifiés dans le dépôt)
- `CourrielService::envoyer/prévisualiser/valider/réessayer`, statuts `Courriel`,
  catalogue `config/courriels.php` (7 entrées, `message_manuel` seul `emissible_cockpit`).
- OTP : envoi synchrone direct via `notify()`, `reemissible=false`, reprise = nouveau parcours.
- Permissions `mail.logs.view|retry|send|settings.manage|test.send` (matrice, Gates auto).
- `notification_templates` (table) = gabarits **notifications applicatives** (`{nom}` échappés),
  distincts des e-mails — **ne pas fusionner**.
- Worktree partagé : 108 entrées modifiées (chantiers parallèles) — ne toucher que les
  fichiers du studio (+ ajouts), jamais écraser hors périmètre.

## 3. Décisions d'architecture (issues de l'audit)
- Schéma JSON versionné (`schema_version`), validé **côté serveur** (validateur dédié),
  rendu par composants contrôlés (tables + styles inline), texte brut miroir.
- Variables : registre **en code** (`config/courriels.php` étendu ou `config/variables-courriel.php`),
  `{{nom}}` uniquement, pas de Blade/PHP/fonction/env, pas de `code`/`token` au registre.
- Modèles système (7 du catalogue) : **verrouillés, lecture seule** dans le studio.
- 4 tables : `themes_courriel`, `modeles_courriel`, `versions_modele_courriel` (immuables),
  `journal_modeles` (validations/publications). Aucune duplication du journal `courriels`.
- Éditeur : `Alpine.data` **inline** dans la vue (précédent `create.blade.php`) — pas de
  modification de `resources/js/app.js` (chantier parallèle actif dessus). DnD natif HTML5
  + boutons clavier, pile undo/redo cliente, indicateur sale + `beforeunload`.
- Aperçu : endpoint serveur débouncé → JSON `{html, texte}` → `<iframe sandbox="">` + onglet
  texte ; données fictives fixes (jamais de dossier réel).
- Envoi de test : permission `mail.test.modele`, allowlist `COURRIEL_TEST_ALLOWLIST`
  (défaut vide = désactivé), throttle, journal masqué, **jamais de modèle système/OTP**.
- 5 permissions : `mail.modeles.view|gerer|publier|themes`, `mail.test.modele` (super_admin auto).

## 4. Critères d'acceptation (mesurables)
1. CRUD brouillon + duplication + archivage ; suppression définitive d'un modèle utilisé interdite.
2. Cycle BROUILLON→À_VALIDER→PUBLIÉ→ARCHIVÉ, verrou système, publication atomique, rollback = nouvelle version, comparatif, conflit optimiste (409/422).
3. Schéma invalide / variable inconnue / URL non-https / XSS (`<script>`, `on*`, `javascript:`) rejetés côté serveur (tests).
4. Rendu HTML (tables/inline, responsive) + texte brut cohérent ; aperçu isolé sandbox, sans écriture/envoi.
5. Thèmes CRUD + application au rendu ; images : URL https + extensions allowlist (upload différé, documenté).
6. Envoi de test : allowlist, confirmation, throttle, journal masqué, OTP impossibles.
7. 7 modèles catalogue + OTP + idempotence + reprise + cockpit : verts (non-régression).
8. Migrations SQLite OK + retour arrière (`migrate:rollback` ciblé) ; perfs mesurées (sauvegarde, aperçu, requêtes).
9. Réserves explicites : navigateur réel, clients mail (matrice), SMTP réel du studio.

## 5. Risques
- Worktree partagé : régressions croisées → rejouer les suites mail avant/après chaque incrément.
- `iframe[srcdoc]` + Alpine : échapper correctement (JSON) ; jamais de `{!! !!}` sur contenu admin.
- Taille JSON schéma : plafond (ex. 200 Ko) contre les gros documents.
- DnD natif mobile : boutons monter/descendre obligatoires (a11y).
- Envois de test : allowlist vide par défaut ; tests en `array`/fake uniquement.
