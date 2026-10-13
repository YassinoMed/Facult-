# Centre d'administration — Rapport final (Phase A, 13/10/2026)

## 1. Ce qui a été fait

**Audit uniquement — zéro code modifié** (règle absolue respectée). 9 domaines inspectés au
code (routes, contrôleurs, services, modèles, migrations, policies, permissions, vues,
config), doublons identifiés, 7 livrables produits :

1. `admin-center-inventaire.md` — état réel par domaine (✅/◐/❌/⛔) avec preuves fichier:ligne
2. `admin-center-specification.md` — fonctionnel (S1-S9, UX transverse)
3. `admin-center-architecture.md` — sources de vérité uniques, flux normalisés, invariants
4. `admin-center-matrice-permissions.md` — matrice réelle + candidats analysés (tous ⛔)
5. `admin-center-plan-boucles.md` — AC-00→AC-07, portes de décision
6. `admin-center-plan-tests.md` — plan + critères d'acceptation
7. le présent rapport

## 2. Constats clés (faits mesurés)

- Le « centre d'administration » existe **déjà à ~80 %** : paramétrage versionné (mature, LOOP
  B→E), comptes complets (CRUD+statut+reset+sessions), RBAC immuable à 5 rôles, audit central
  + export, notifications (templates versionnés + préférences + dispatcher + file), diagnostics
  (maintenance/système/Sentinel), intégrations (tokens CLI, webhooks HMAC).
- **Le seul vrai gap fonctionnel** : l'envoi d'e-mail réel — `MAIL_MAILER=log`, zéro `Mail::`
  dans l'app, mais **l'architecture d'accueil existe** (`CanalNotificationExterneInterface` +
  driver simule + job + file) : il faut brancher un driver SMTP, pas construire un système.
- **Zéro doublon à créer** : chaque fonctionnalité du cahier a un mécanisme existant à réutiliser
  (modèles = `NotificationTemplate` ; sessions = `SessionsController` ; historique =
  `parametre_versions` ; diagnostic = 3 surfaces existantes à agréger par navigation).
- Risques de sécurité pré-existants documentés qui **doivent** précéder l'élargissement de la
  surface : **SEC-12 (P1, XSS maquettes)**, AUTH-01 (P2, sessions/reset), + CI rouge (Pint ×9,
  `APP_KEY`).

## 3. Décisions ouvertes (registre `decisions-humaines.md` — à trancher AVANT implémentation)

| ID | Décision | Reco |
|---|---|---|
| DH-AC-01 | Driver SMTP réel (interface existante) + périmètre PII des e-mails | OUI, borné |
| DH-AC-02 | Permissions `settings.*` granulaires (history/restore/diagnostics) | Refuser (redondant) |
| DH-AC-03 | `users.*` / `roles.manage` / `permissions.assign` | **Refus ferme** (matrice fermée = anti-escalade) |
| DH-AC-04 | Porteur de la matrice lecture seule | `configuration.manage` |
| DH-AC-05 | Porteurs mail (test envoie, journal, clés non-secrètes) | super_admin + `history.view` |
| DH-AC-06 | Invitations de comptes | Hors périmètre (besoin non prouvé) |
| DH-18 (existant) | MFA/2FA | En attente (inchangé) |

## 4. Limites de cet audit

- État mesuré **au 13/10** sur un arbre en co-édition active (settings LOOP E extension, chantier
  archives) — les preuves sont datées ; toute divergence future doit être re-mesurée.
- Middleware exact de quelques pages tertiaires citées de seconde main (vues/sous-pages) — à
  confirmer ligne à ligne en boucle d'implémentation (aucun impact sur les conclusions).
- Aucun test exécuté sur MySQL dev ; les preuves MySQL sont celles des courses gated sur scratch
  (12-13/10), reproductibles.

## 5. Verdict : **`PASS_WITH_RESERVATIONS`**

- **PASS** : la Phase A est complète, traçable et honnête — chaque domaine audité au code,
  doublons identifiés, plan de boucles + tests + architecture cohérents avec les conventions
  vérifiées du projet (non-duplication, scope→404, append-only, secrets hors base).
- **Réserves** : (1) les décisions DH-AC-01→06 ci-dessus conditionnent l'implémentation ;
  (2) AC-00 (SEC-12 P1 + AUTH-01 P2 + CI verte) est un prérequis bloquant ;
  (3) arbre en mouvement (co-édition) — re-mesure à l'entrée de chaque boucle.

## 6. Prochaine étape (EN ATTENTE DE VALIDATION EXPLICITE)

Ordre proposé : **AC-00** (correctifs bloquants + CI verte) → **AC-01** (navigation, zéro
permission) → AC-02→AC-07 selon le plan. Conformément au cahier et à la règle
« auto pass + confirmation + auto correct » : les phases d'audit et de documentation se sont
enchaînées automatiquement ; **aucune implémentation ne commencera sans votre confirmation**,
et les micro-correctifs sans ambiguïté (typo de doc, append registre) restent les seuls
« auto-corrects » autorisés en attendant.

*Aucune modification de code, de permission, de rôle, de valeur métier ou de secret n'a été
effectuée durant cette phase.*
