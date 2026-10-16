# LOOP-MAIL-OPERATIONAL-02-REPORT — Validation SMTP, délivrabilité et cockpit

**État initial :** `PASS_WITH_RESERVATIONS` (4 réserves, cf. `LOOP-MAIL-ENTERPRISE-REPORT.md` §6)
**Date :** 14/10/2026 · **Aucun changement de code du module mail** (correctifs
accueil hors périmètre mail : voir §6).

## 1. LAN — configuration réellement chargée (secrets jamais affichés)

| Clé | Valeur constatée |
|---|---|
| `mail.default` | `smtp` |
| `mail.mailers.smtp` | `smtp.gmail.com:587`, STARTTLS auto (port 587), `timeout: null` (défaut Symfony), `scheme: null` |
| Auth SMTP | identifiant + mot de passe **renseignés** (présence seule constatée) |
| `mail.from` | adresse Gmail = boîte de test autorisée |
| `queue.default` / `cache.default` | `database` / `database` |
| Workers locaux | **aucun** (`ps` vide) ; aucun `queue:work` planifié (normal : daemon, pas cron) |
| Worktree | sale (chantiers parallèles préexistants) — **aucune modification mail requise** : retour arrière = `git checkout`/`git stash` ciblé, migrations mail déjà appliquées et compatibles SQLite |

## 2. DEV — preuves exécutées

### 2.1 Connectivité + chiffrement (zéro identifiant, zéro envoi)
Handshake SMTP manuel (`fsockopen` + `EHLO` + `STARTTLS` + TLS vérifié `verify_peer/verify_peer_name`) :
`220 smtp.gmail.com ESMTP` → `STARTTLS offert : oui` → `220 Ready to start TLS` →
`TLS négocié vérifié : oui` → `AUTH LOGIN PLAIN XOAUTH2 …` → `221 closing` (premier essai
avorté sur bug de mon script : `stream_context_set_option` requis — corrigé, rejoué, OK).

### 2.2 Envoi réel autorisé (action explicite : adresse fournie par l'utilisateur)
`php artisan mail:tester usermyb@gmail.com --sujet="Test UniRegistre LOOP-MAIL-02"` →
`Transmis au pilote « smtp » pour envoi.` (aucune exception).
Journal passif : ligne n°8 `accepted_by_smtp`, modèle `hors_catalogue`,
destinataire `use******@gmail.com`, sujet exact, **adresse en clair absente du JSON**,
aucune entrée `mail.test_echec` en log. ⏳ **Réception + rendu à confirmer par l'utilisateur (§5).**

### 2.3 Échec contrôlé + reprise (SMTP mort `127.0.0.1:1` à chaud, destinataire `@example.com` : envoi réel impossible)
1. `confirmation_mise_a_jour` → `failed` / `categorie_erreur=connexion`, diagnostic expurgé
   (classe + `Connection refused`, sans secret ni adresse), fuite : non.
2. Reprise même adresse (condensat OK) → retentée (`reessais_manuels=1`), nouvel échec propre :
   **aucune boucle** (action manuelle unique, cooldown 60 s).
3. Ligne OTP `reemissible=false` → reprise **refusée** (« reprenez le parcours d'origine »).
4. Autre adresse que l'origine → **refusée** (anti-relais).

### 2.4 Workers
Aucun worker local en cours ; mécanique file prouvée par les tests (`sync` en test,
`AcheminerNotificationExterneJob` avec backoff, `failed_jobs` configuré). **Comportement
des workers en production : réserve maintenue** (environnement cible inconnu).

## 3. COCKPIT — validation (niveau HTTP, pas de navigateur disponible ici)

Suites rejouées et vertes : dashboard/compteurs/filtres/pagination (200 super_admin),
`403` sans permission (invité → login), aperçu **0 écriture**, envoi manuel 2 temps
(aperçu → confirmation exigée → `accepted` + acteur ; destinataire invalide → erreur + 0 ligne),
vues sans secret ni adresse. **Réserves maintenues :** rendu navigateur/mobile et erreurs
d'interface non vérifiables sans navigateur (aucun Dusk dans cet environnement).

## 4. AUDIT

- **SPF** : `gmail.com → v=spf1 redirect=_spf.google.com` (DNS public, vérifié `dig`).
  **DKIM/DMARC** : expéditeur `@gmail.com` via infra Google (signature Google, alignement
  natif) — preuve par en-têtes à la réception (cf. §5) ; **domaine de production inconnu :
  réserve maintenue** (aucune valeur inventée).
- Retry/reprise : politiques vérifiées §2.3 (plafond 3, cooldown, refus OTP/relais).
- OTP protégés : aucun test n'émet vers une vraie adresse (`array`/`fake`, drills vers
  `@example.com` ou SMTP mort) ; journaux sans OTP (lignes du drill contrôlées).
- Connexion ≠ réception : **délivrabilité non affirmée** tant que §5 n'est pas confirmé.

## 5. Réserves restantes et actions

1. **Réception du mail de test** — action : l'utilisateur confirme (reçu oui/non, rendu
   HTML + texte, `Authentication-Results` : spf/dkim/dmarc). Lève les réserves envoi + partie délivrabilité.
2. **Domaine expéditeur de production** — inconnu : SPF/DKIM/DMARC à qualifier en cible.
3. **Workers de production** — supervision à mettre en place au déploiement (`queue:work --queue=notifications,default`, `queue:failed`, alerte cockpit ≥ 20 %).
4. **Cockpit navigateur/mobile** — à valider avec navigateur (captures).

## Verdict : `PASS_WITH_RESERVATIONS`
Zéro défaut, zéro changement de code mail, 157/157 tests ciblés verts. Passage en `PASS`
dès confirmation §5 (point 1).
