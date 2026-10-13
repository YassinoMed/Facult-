# Centre d'administration — Spécification fonctionnelle (13/10/2026)

> Déduite de l'inventaire (Phase A). Convention : **conserver l'existant** (§référence),
> **compléter** les écarts, **refuser** tout doublon. Stack : Blade/Tailwind + composants
> `ds-*` existants — aucune nouvelle bibliothèque.

## S1 — Paramétrage général (existant : conserver + 3 complétions)

1. **Nav « centre d'administration »** : page d'accueil regroupant les 9 rubriques (liens vers l'existant + nouvelles), permissions respectées par lien (`@can`), recherche locale. *Ne déplace rien.*
2. **Confirmation des changements sensibles** : `motif` requis (validation `required_if`) pour les clés marquées `sensibilite != 'publique'` dans `SettingDefinition` — réutilise le champ existant.
3. **Comparaison avant application** : bandeau « Valeur actuelle → proposée » côté client (Alpine, `data-initiale` déjà posé) ; le no-op reste refusé côté serveur (comportement actuel).
4. **État de configuration** : badge par catégorie (« par défaut » / « personnalisé ») dérivé de `valeur === défaut` — calcul serveur, zéro écriture.

## S2 — Messagerie (le seul vrai chantier neuf — borné)

1. **Driver SMTP réel** `CanalNotificationExterneSmtp implements CanalNotificationExterneInterface` (l'interface et le job existent) — activé par `MAIL_MAILER` Laravel standard ; **secrets en env uniquement** (`.env`), jamais en `parametres` ni `parametre_versions`.
2. **Paramètres NON-SECRETS pilotables** via le registre existant (clés à décider, ex. `mail.expediteur_nom`) — refus de tout champ mot-de-passe/token (garde `SettingDefinition` : type interdit + revue).
3. **Test de connexion + e-mail de test** : action dédiée (super_admin + `throttle:5,1`), destinataire = e-mail du compte appelant (jamais libre), résultat **prouvé** (échec d'envoi = erreur affichée, jamais « envoyé » sans preuve — miroir du canal simule), trace audit sans corps ni PII.
4. **Suivi des tentatives** : table append-only `mail_tentatives` (notification_id, destinataire **haché**, statut, erreur expurgée, tentative n) alimentée par le driver ; UI dernier-état + relance manuelle (job existant, `tries` bornés).
5. **Journal expurgé** : regex de scrub étendue (mot de passe/token/clé/PII), corps jamais persisté.
6. **Diagnostic** : carte santé (config résolue OUI/NON sans valeurs, dernier succès, échecs 24 h).

## S3 — Comptes et utilisateurs (existant : 3 complétions)

1. **Fix AUTH-01 (P2, prioritaire)** : purge `sessions` au reset/changement de mot de passe (les 2 callbacks) + kill-switch super_admin sur les sessions d'un compte (`SessionsController` étendu, garde dernier admin actif).
2. **Historique de sécurité par compte** : onglet sur la fiche administrateur = filtre de l'audit central existant (`history.view`) — **aucune nouvelle table**.
3. **Invitations** ⛔ : à décider (création directe existante + reset initialement affiché une fois couvrent le besoin actuel) — pas d'implémentation sans approbation.

## S4 — Rôles et autorisations (existant : 1 complétion, 0 création)

1. **Matrice lecture seule** : page générée de `Permissions::ROLES` × catalogue (rendu table, aucun bouton d'édition, badge par rôle) + par policy : rôle/permission/action/module/périmètre/risque/test (rendu documenté dans la matrice dédiée).
2. **Aucune permission créée, aucun rôle modifié** sans décision explicite (cf. matrice candidats — toutes ⛔ à ce stade).

## S5 — Sécurité (existant : 2 complétions, 1 ⛔)

1. **Politique mots de passe visible** (lecture seule, valeur de code) — pas de paramétrage en base sans décision.
2. **Événements de sécurité par compte** (déporté S3.2, même source).
3. **MFA** ⛔ DH-18 — hors périmètre tant que non approuvé (aucune affirmation d'existence).

## S6 — Notifications (existant : 2 complétions)

1. Suivi des tentatives e-mail (S2.4, source unique).
2. Préférences : UI existante conservée ; aucun nouveau canal tant que S2 n'est pas livré ET approuvé.

## S7 — Services et intégrations (existant : 1 complétion)

1. **Page « intégrations »** : état read-only (tokens : compte, scopes masqués, expiration — jamais le secret ; webhooks : config résolue OUI/NON ; DataExchange : définitions actives). Gestion des tokens reste **CLI par design** (documenté).

## S8 — Audit, historique, restauration (existant : 1 complétion)

1. Filtre « par compte » sur le centre audit existant (join `AuditLog.acteur`, `history.view` inchangé) + le filtre par compte de S3.2 pointe dessus.

## S9 — Diagnostics (existant : 1 complétion)

1. **Consolidation** : la nav S1.1 expose les 3 surfaces existantes (maintenance, système, sentinel) en sous-rubriques — zéro nouveau moteur ; + carte santé mail (S2.6).

## UX transverse (Phase H)

Composants existants obligatoires : `x-page-header`, `ds-card`, `x-btn` (+ anti double-clic `x-bind:disabled`), `status-badge`, `@error` sacs nommés, `aria-invalid`, états de chargement, confirmations (`confirm` natif ou `x-confirm`), secrets **toujours masqués** (`****`, jamais de réaffichage), responsive `sm:`+ existant, a11y (labels `sr-only`, `role=alert`). Interdits : nouvelle lib JS, `{!! !!}` sur donnée utilisateur, autopromotion de fonctionnalité non testée.
