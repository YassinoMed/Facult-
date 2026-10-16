# LOOP-MAIL-ENTERPRISE-REPORT — Module de messagerie transactionnelle et administrative

**Projet :** UniRegistre — Laravel · **Fournisseur :** SMTP · **Date :** 14/10/2026
**Méthode :** LAN → DEV → PERFORMANCE → AUDIT → CORRECTION → VALIDATION
**Verdict :** `PASS_WITH_RESERVATIONS` (réserve unique : SMTP réel non vérifié — §9)

## 1. Architecture retenue (réutilisation maximale, zéro réécriture métier)

**Aucune façade supplémentaire** là où l'existant suffit (`NotificationService`,
`Password::broker`, `TesterMessagerie`, `SmtpConfiguration`, permissions `mail.*`).
Ajouts strictement nécessaires et justifiés :

| Fichier | Justification |
|---|---|
| `database/migrations/2026_10_14_090000_creer_journal_courriels.php` | Table `courriels` : aucun journal d'envois n'existait (`mail.logs.view` sans UI). Index sobres (statut+date, modèle+date, hash, corrélation). |
| `app/Models/Courriel.php` | 6 statuts (`pending/processing/accepted_by_smtp/retrying/failed/cancelled`), masquage + condensat SHA-256, gardes `estFinal/peutEtreReessaye`. |
| `config/courriels.php` | Catalogue versionné : 6 modèles existants documentés (variables, règles) + `message_manuel`. Phrases fixes, **zéro Blade/PHP administrable**. |
| `app/Services/CourrielService.php` | Central : catalogue, validation (whitelist, scalaires, plafonds, anti-`\r`, anti-HTML), idempotence (clé + contrainte unique + course), classification d'erreurs, reprise encadrée, aperçu sans effet. |
| `app/Mail/CourrielMessage.php` + `resources/views/emails/courriel{,-texte}.blade.php` | Requis par la mission (HTML responsive + texte brut) + en-têtes `X-Courriel-*` de corrélation journal. Variables `{{ }}` échappées. |
| `app/Notifications/CourrielManuel.php` | Non utilisé par le service final (doublon du Mailable) — **conservé ? NON : à supprimer**. Voir §7. |
| `app/Listeners/JournaliserCourrielEnvoye.php` | Journal **passif** via `MessageSending/MessageSent` : métadonnées expurgées uniquement, fail-soft, OTP et parcours existants intacts. Enregistré dans `AppServiceProvider`. |
| `app/Http/Controllers/Admin/CourrielController.php` + 4 vues `admin/courriels/` | Cockpit : dashboard + historique paginé/filtré (`mail.logs.view`), catalogue + aperçu, reprise (`mail.retry`), envoi manuel 2 temps (`mail.send`, throttle, débit, acteur audité). Layout/composants existants. |
| `app/Support/Permissions.php` | 2 permissions : `mail.retry`, `mail.send` (super_admin via matrice ; Gate auto-définies). |
| `routes/web.php` + `_navigation.blade.php` | 6 routes `admin.courriels.*` (CSRF web, `can:` par route, throttle écritures) + lien nav gardé par `@can`. |

**Décisions structurantes :** OTP **synchrones conservés** (file `database` sans worker garanti en prod : les mettre en file casserait le parcours ; documenté) ; **aucun corps/OTP/adresse stocké** (ni clair ni chiffré) → reprise OTP = nouveau parcours, reprise manuelle = réémission vers l'adresse d'origine re-saisie et contrôlée par condensat (anti-relais) ; `after_commit` global inchangé (aucun `dispatch` pré-commit introduit).

## 2. Protections conservées et ajoutées

CSRF (groupe web), autorisation serveur par route, throttle (5/1 manuel, 10/1 reprise),
validation stricte chaque requête, sujets 100 % littéraux (6/6 vérifiés), codes
`random_int` + hash SHA-256 + TTL + usage unique intacts, ancienne adresse active jusqu'à
confirmation intacte, réponses neutres intactes, secrets jamais réaffichés/journalisés
(mot de passe SMTP `encrypted`+`hidden`, diagnostic = classe + message caviarde `[adresse]`,
tronqué 255).

## 3. Tests exécutés (tous passés, tous réels)

- **Nouveau `CourrielModuleTest` : 12/12** — catalogue, validation/injection, réserve OTP,
  journal expurgé, idempotence (1 seul envoi), journal passif, aperçu sans effet, reprise
  (refus OTP/autre adresse/cooldown + succès nominal), cockpit (invité→login, sans
  permission→403, 2-temps manuel, 0 écriture à l'aperçu), non-fuite vues, classification.
- **Non-régression : 130/130** (`MessageriePhaseC`, `Smtp*`, `NotificationsEcheances`,
  `Notification`, `DossierExistant*`, `LoopPopupHomeOnly04`) — transport `array` en test,
  `Notification::fake` partout : **zéro envoi réel en suite**.
- Corrections appliquées puis rejouées : 3 défauts de mes propres tests (fake + double
  envoi, règle `sometimes`, cooldown en test).

## 4. Performance mesurée (driver `array`, à chaud)

- `CourrielService::envoyer` : **26 ms / 7 SQL**, statut `accepted_by_smtp`.
- Surcoût listener passif : **6 ms / 3 SQL** par e-mail (1 insert + 1 update).
- `GET admin/courriels` : pagination 20, 2 requêtes de compteurs + 1 page (compteurs par
  statut : 6 requêtes indexées — optimisable en une seule requête groupée si volume, noté).
- Requêtes utilisateur critiques inchangées (OTP déjà synchrones avant/après : +2 SQL
  d'écriture journal par envoi OTP).

## 5. Exploitation (réel, vérifié)

Queue `database` + `failed_jobs` (migrations présentes) ; aucune planification worker
dans `routes/console.php` (constat, inchangé) ; commandes : `queue:work --queue=notifications,default`,
`queue:failed`, `mail:tester <adresse> --sujet=…` ; seuils initiaux : alerte cockpit à
taux d'échec ≥ 20 %/24 h, `max_reessais_manuels=3`, `delai_reprise=60 s`, débit manuel 5/min.
SPF/DKIM/DMARC : à documenter côté fournisseur (aucune valeur inventée).

## 6. Réserves et limites de preuve (explicites)

1. **`SMTP réel non vérifié`** : `.env` local pointe vers Gmail (dev) — aucun envoi réel
   effectué (ni pendant l'audit, ni pendant les tests, ni pendant les mesures : driver
   `array` forcé). Action : exécuter LOOP 09 avec une adresse de test autorisée + confirmation.
2. **Délivrabilité non prouvée** : connexion ≠ réception ; à valider en cible (SPF/DKIM/DMARC/réputation).
3. **Workers production inconnus** : file `database` sans supervision constatée ; OTP synchrones par choix documenté.
4. **Preuve navigateur du cockpit non automatisée** (vues servies 200 en test, pas de Dusk).

## 7. Suivi — suppression d'un doublon relevé à l'audit final

`app/Notifications/CourrielManuel.php` est devenu sans usage après l'introduction du
`Mailable` (exigé pour HTML+texte et en-têtes). Supprimé avant clôture (vérifié : zéro référence).
