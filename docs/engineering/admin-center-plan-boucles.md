# Centre d'administration — Plan de migration par petites boucles (13/10/2026)

> Chaque boucle : périmètre minimal · tests AVANT (RED quand applicable) · preuve
> avant/après · critères de sortie · **portes de décision explicites**. Aucune boucle
> ne démarre sans validation (règle du cahier). Priorité : sécurité > intégrité >
> blocages opérationnels > confort.

## AC-00 — Prérequis bloquants (AVANT toute extension de surface admin)
- **AC-00a · Correctif SEC-12 (P1)** : XSS stockée maquettes — assainissement/échappement du rendu. *Sans ce fix, élargir la surface admin accroît l'exposition à l'escalade.* Tests : payload XSS stocké neutralisé + régressions maquettes (19).
- **AC-00b · Correctif AUTH-01 (P2)** : purge `sessions` au reset/changement mdp + kill-switch super_admin. Tests : cookie volé invalidé au reset ; self-scope préservé ; dernier admin actif jamais verrouillé dehors.
- **AC-00c · CI verte** : re-passe Pint (DH-11) + `APP_KEY` de test (DH-12) — le gate doit être vert pour valider chaque boucle suivante.
**Critères de sortie AC-00** : tests ciblés verts, suite complète 0 échec, CI `main` verte.

## AC-01 — Page d'accueil « Centre d'administration » + matrice lecture seule
- Périmètre : nav + vue d'accueil (9 rubriques, liens `@can`), matrice générée de `Permissions::ROLES` (lecture seule). **0 permission créée, 0 route métier déplacée.**
- Décision : DH-AC-04 (porteur de la matrice — reco `configuration.manage`).
- Tests : rendu par rôle (visible/masqué), invité → login, aucun bouton d'édition, matrice fidèle au code (test de dérive : compte de permissions du catalogue).
- Sortie : navigation cohérente vérifiée, CI verte.

## AC-02 — Fix AUTH-02 (P3, même boucle que AC-00b si approuvé ensemble)
- Bcrypt fantôme sur le login admin (miroir des voies étudiant/enseignant). Tests : timing uniformisé (test comportemental existant à étendre), messages génériques inchangés.

## AC-03 — Comptes : complétions (S3)
- 3.2 : onglet « sécurité » sur fiche administrateur = filtre audit existant (`history.view`). Tests : IDOR inter-comptes impossible (filtre acteur), aucun secret affiché.
- 3.3 : invitations ⛔ **hors périmètre jusqu'à décision DH-AC-06**.
- Régression : `AdministrateurController` (gardes dernier super-admin), `ArchiveAuthorizationTest`.

## AC-04 — Messagerie : fondations sans envoi réel
- Migration `mail_tentatives` (append-only, destinataire haché, erreur expurgée) + driver `CanalNotificationExterneSmtp` **dérivé du simule** (mêmes gardes PII, statuts) + branchement via `MAIL_MAILER` (défaut : simule — aucun comportement changé tant que l'env est `log`/`simule`).
- Tests : unitaires driver (succès/échec/retry, injection d'erreurs), intégration file `notifications`, **zéro secret/PII dans les tentatives et logs** (`Log::listen`), faux positifs refusés.
- Décision : DH-AC-05 (env réel hors dev : paramétrage exploitation).
- Sortie : en dev, comportement IDENTIQUE à aujourd'hui (preuve : suite complète inchangée).

## AC-05 — Messagerie : surface admin
- Test de connexion + e-mail de test (destinataire = compte appelant, super_admin, `throttle:5,1`), diagnostic santé (config résolue OUI/NON, dernier résultat), suivi des tentatives (liste + relance manuelle bornée).
- Clés non-secrètes `mail.*` au registre ⛔ après décision (reco : `mail.expediteur_nom` seule).
- Tests : autorisation (super_admin vs 403/404), échec SMTP = erreur affichée (jamais « envoyé »), throttle, journal expurgé, relance idempotente.

## AC-06 — Audit : filtre par compte (S8)
- Extension du centre audit (join acteur) + lien depuis la fiche compte. Tests : scoping, `history.view` inchangé, export CSV cohérent avec filtre.

## AC-07 — Intégrations + santé consolidée (S7/S9)
- Page read-only intégrations (tokens : jamais le secret ; webhooks : OUI/NON) + cartes santé (maintenance/système/sentinel/mail) en sous-rubriques. Tests : aucun secret rendu, permissions par carte, régression sentinel (29).

## Ordre imposé & dépendances
```
AC-00 (P1/P2/CI) ─ AC-01 (nav, 0 permission) ─ AC-02 ─ AC-03 ─ AC-04 ─ AC-05 ─ AC-06 ─ AC-07
                        ⛔ DH-AC-04             ⛔ DH-AC-06    ⛔ DH-AC-05
```
Chaque boucle : `vendor/bin/pint`, tests ciblés, suite complète, CI verte sur `main`, commit dédié horodaté (le dépôt existe — plus aucune boucle « sans diff »).

## Ce qui n'est PAS planifié (assumé)
MFA/2FA (⛔ DH-18) · invitations (⛔ DH-AC-06) · permissions candidates (⛔ matrice) · portée facultaire des paramètres (interdite) · refonte des surfaces existantes (maintenance/système/sentinel/gabarits/audit/comptes) — extensions uniquement.
