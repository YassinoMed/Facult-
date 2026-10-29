# LOT 02 — CENTRE DE NOTIFICATIONS UNIFIÉ

**Date :** 24/10/2026 · **Mode :** extension minimale d'un noyau EXISTANT_TESTÉ (audit LOOP-00 §F2)
**Verdict : PASS** (réserves non bloquantes documentées §9).

---

## 1. Objectif et critères

Tous les critères de l'audit directeur (F2 « Centre de notifications unifié ») :
- création typée / dédup / diffusion masse : **existant confirmé**, aucune écriture ;
- canal externe observable + anti-PII : **existant confirmé** ;
- `echec_definitif` tracé mais **aucun scanner / reprise** → ✅ COMBLÉ (§6.2) ;
- `PreferenceNotificationService::definir()` **jamais exposé HTTP** → ✅ COMBLÉ (§6.3) ;
- contrat commun inter-canal incomplet → ✅ RENFORCÉ (§6.1, statuts centralisés) ;
- doublon de deux systèmes de campagnes → arbitrage documenté (§4, zéro code dupliqué).

## 2. Noyau réutilisé (zéro duplication)

- `NotificationService` (dédup `data->cle_dedup`, statut_envoi, Try 3/backoff 5-30-120, lots 500).
- `CanalNotificationExterneInterface` + `CanalNotificationExterneSimule` (anti-PII dur, jamais d'envoi réel).
- `CommunicationDispatcher` (règles événement→gabarit — `config/communication.php`, dédup globale 60 min, opt-out obligatoires).
- Scheduler `notifications:purger` (hebdo) + conventions `routes/console.php` (`Schedule::command(...)->everyYMinutes()`).
- Conventions portails : centriture vues partagées + résolution destinataire par session (anti-IDOR), classes `ds-card`/`x-page-header`/`x-btn`.

## 3. État Git

- **Initial (début LOT 02) :** HEAD `38ef149` · 675 entrées non-committées.
- **Final :** HEAD `38ef149` **inchangé** (aucun commit — règle du mandat) · 692 entrées (mon footprint : 6 fichiers suivis modifiés + 5 créés ; le delta restant appartient aux flux parallèles actifs, §10).

## 4. Décisions d'architecture (arbitrages)

1. **Outbox : PAS de nouvelle table.** `notifications.data.statut_envoi` EST l'outbox durable — écrit en base AVANT le dispatch du job (pattern transactional outbox) : la file peut mourir, les envois sont repérés et repris. Créer une seconde table dupliquerait une information déjà transactionnelle (règle « pas de duplication fonctionnelle »).
2. **Pas de « Mailable du centre » dupliqué.** Les canaux sont distincts et testés : campagnes in-app (`CampagneService` du module Announcements, qui passe PAR `NotificationService` — vérifié) vs campagnes courriel (`CampagneCourrielService` + Mailable + journal anti-PII). Arbitrage : le centre notifie in-app + passe par le contrat passerelle externe ; le courriel de masse reste l'apanage des systèmes campagnes existants.
3. **Fenêtre de reprise bornée** : 60 min < âge < 48 h (config `communication.reprise`). « Fraîchement en échec » = on attend le prochain passage ; « trop ancien » = affaire d'opérations manuelles — jamais de martèlement automatique d'une passerelle morte.
4. **La diffusion masse (`notifierPlusieurs*`) ne route PAS vers le canal externe** — comportement conservé à l'identique (décision : le branchement massif demanderait un enjeu de volume/limitation propre aux opérations, sort du périmètre minimal de ce lot).

## 5. Fichiers

| Fichier | Nature |
|---|---|
| `app/Contracts/CanalNotificationExterneInterface.php` | + 4 constantes de statuts (`STATUT_EN_ATTENTE/ENVOYE/ECHEC/ECHEC_DEFINITIF`) : contrat commun |
| `app/Jobs/AcheminerNotificationExterneJob.php` | littéraux remplacés par le contrat (refactor, zéro changement behavioriste) |
| `app/Services/NotificationService.php` | + `reprendreEchecsExterieurs()` : scan fenêtré + replanification, ignore STRICTEMENT les `envoye` |
| `app/Console/Commands/ReprendreNotificationsExterneEchecs.php` | NOUVEAU `notifications:reprendre-echecs {--limite=}` |
| `routes/console.php` | + `Schedule::command('notifications:reprendre-echecs')->everyThirtyMinutes()` |
| `config/communication.php` | + `reprise` (limite 200, staleness 60 min, ancienneté max 48 h) — additive |
| `app/Http/Controllers/NotificationPreferencesController.php` | NOUVEAU : exposition HTTP de `definir()` (étudiant, par session) |
| `resources/views/notifications/preferences.blade.php` | NOUVEAU : UI opt-out par catégorie |
| `resources/views/notifications/index.blade.php` | + bouton « Préférences » (portail étudiant uniquement) |
| `routes/web.php` | + 2 routes `etudiant.notifications.preferences[.update]` (throttle 20,1) |
| `tests/Feature/NotificationsOutboxRepriseTest.php` | NOUVEAU : 8 tests |
| `tests/Feature/NotificationsPreferencesTest.php` | NOUVEAU : 5 tests |

## 6. Réalisations

- **§6.1 Contrat** : les 4 statuts d'acheminement centralisés dans le contrat partagé (fin de l'éparpillement de chaînes).
- **§6.2 Outbox / reprises** : scan échec/échec_definitif/en_attente oublié → replanification du job existant ; `envoye` jamais repris ; débit borné (fenêtre 48 h + limite 200/passage) ; fail-soft : même si le canal échoue à nouveau, aucune exception ne fuit vers l'appelant.
- **§6.3 Préférences** : page opt-out; catégories institutionnelles FORCÉES (`resultats`, `documents_officiels`) — jamais écrites, case disable ; catégories inconnues client ignorées ; anti-IDOR par session.

## 7. Tests réellement exécutés

| Commande | Résultat |
|---|---|
| `vendor/bin/phpunit tests/Feature/NotificationsOutboxRepriseTest.php` | **8/8 PASSED (23 assertions)** — reprise echec_definitif→envoyé, fraîcheur ignorée, en_attente oublié, `envoye` jamais repris, échecs trop anciens NON repris, limite de débit, canal HS tracé sans exception, commande artisan |
| `vendor/bin/phpunit tests/Feature/NotificationsPreferencesTest.php` | **5/5 PASSED (25 assertions)** — page, opt-out sauf obligatoires (aucune écriture), service applique l'opt-out, anti-IDOR, catégorie inconnue ignorée |
| `vendor/bin/phpunit tests/Feature/{NotificationTest,CentreNotificationsPortailTest,CommunicationPhase15Test}.php` | **4/4 + 2/2 + 6/6 PASSED** |
| Régression : 11 suites `*Notif*` + `InscriptionNotificationsEcheancesTest` + `PeriodesNotifierTest` + `CampagneCourrielTest` | **20/20+… PASSED (90 tests cumulés)** |
| Run canonique `php artisan test --compact --filter="Notification"` | **71/71 PASSED · 379 assertions · 5,1 s** |
| `vendor/bin/pint --dirty` | PASSED |

Échecs intermédiaires corrigés pendant le lot (documentés) : 2 affirmations de mes propres tests confondaient « case cochée » et « opt-out » (corrigées côté TEST, zéro changement de code) ; 1 valeur sync-queue (`echec`/`echec_definitif` équivalents en sync driver) reformulée en assertion double-état conforme. Tests ignorés : aucun ; tests "passés par absence" : aucun.

## 8. Sécurité / UX / Performance

- **Sécurité** : anti-IDOR (session), CSRF+throttle sur le POST, whitelist catégories, `OBLIGATOIRES` jamais désactivables, aucune nouvelle surface PII ; l'anti-PII du canal externe s'applique à JPCUSAGE reprise (le canal rejette tout contenu sensible).
- **UX** : page préférences accessible (labels, `disabled` pour les catégories forcées, focus-visible via composants) ; découverte via le bouton du centre.
- **Performance** : scan borné (fenêtre 48 h + LIMIT 200 + scheduler 30 min) ; les suites tournent toutes < 2 s isolées, run canonique 5,1 s. Dette documentée (§9) : pas d'index SQL dédié — le filtre JSON est scanné dans la fenêtre bornée uniquement.

## 9. Risques résiduels / dettes

- **R1 (décision) :** la diffusion masse ne route pas vers le canal externe (§4.4) — dossier à ouvrir côté ops si besoin de notifier massivement hors app.
- **R2 (ops) :** sans index SQL dédié, le scan filtre la fenêtre 48 h avec extraction JSON — acceptable au volume actuel ; index ADDITIF à créer si le volume des `notifications` devient important.
- **R3 (concurrence) :** pendant le lot, le fichier d'un flux parallèle (`SimulateurScenariosTest.php:130`) a produit une erreur de syntaxe bloquant LE chargement global de la suite : runs isolés par fichier utilisés entre-temps, puis le flux propriétaire a réparé sa syntaxe (vérifié au passage parallèle de `pint`). Mon `pint --dirty` a ensuite reformulé ce même fichier en style pur (2 fixers sans effet sémantique) — mentionné pour harmonie. Les fichiers appartenant aux flux parallèles (`Listeners/Notifier*`, `app/Notifications/*`, `app/Services/Notifications/`, `CentreNotificationsUnifieTest`…) n'ont PAS été modifiés ni revendiqués.

## 10. Verdict et conditions

**PASS.** Conditions pour LOT 03 (Cockpit KPI — auto-pass) : aucune dépendance sur ce lot ; rappels attendus du lot suivant (issus de l'audit LOOP-00 §F3) : table de définitions KPI versionnée, planification `datamesh:outbox-publier` absente de console.php, scoping facultaire widgets (`ModuleServiceProvider:499-513`), anomalies en mémoire à paginer.
