# AUDIT INITIAL DU DÉPÔT — LOOP 146

**Référence :** `LOOP-146-AUDIT-INITIAL.md`
**Version :** 2.0 (correction d'intégrité — annule la v1.0)
**Date :** 2026-10-29
**Nature :** audit en lecture seule, aucun code modifié

---

## 1. ÉTAT DE L'ENVIRONNEMENT (CONSTATÉ)

| Élément | Valeur |
| :--- | :--- |
| Branche | `main` |
| HEAD | `a8b3f0b` |
| Fichiers suivis modifiés | 12 |
| Fichiers non suivis | 18 |
| PHP | 8.5.11 |
| Environnement de test | `APP_ENV=testing`, SQLite `:memory:` (`phpunit.xml:22,42-43`) |
| Architecture | Laravel modulaire CORE + MODULES : `config/modules.php`, `app/Modules` (26 modules), `ModuleRegistry` (`app/Providers/ModuleServiceProvider.php:206,229`) |

### Travaux locaux préservés (non touchés par ce LOOP)

Fichiers suivis modifiés au moment de l'audit :

```
app/Modules/AssistantIa/Services/FiltreSecuriteIa.php
app/Modules/Evaluation/Controllers/ExamDecisionCapaciteController.php
app/Modules/Evaluation/Controllers/GroupeVirtuelExamenController.php
app/Modules/Evaluation/Routes/web.php
app/Modules/Evaluation/Services/ConsultationExportGroupeVirtuelService.php
app/Modules/Evaluation/Services/ExamDecisionCapacitePermutationService.php
app/Providers/ModuleServiceProvider.php
app/Services/NotificationService.php          ← modifié par un acteur concurrent à 20:50
resources/views/modules/evaluation/groupes-virtuels/index.blade.php
resources/views/modules/evaluation/groupes-virtuels/show.blade.php
routes/console.php
tests/Feature/AssistantIa/OutilsMetierEtDeveloppeurTest.php
```

Fichiers non suivis notables : commandes `VerifierDerivesCapacitesCommand`, `AuditerGroupesVirtuelsCommand`, outils `AssistantIa/Tools/Metier/*`, vues `groupes-virtuels/anomalies|blocage.blade.php`, tests `ExamGroupesVirtuelsLoopGlobalTest`, `LoopGlobalUniRegistreAmeliorationsTest`, `ExamCapaciteSalleNonConfirmeeTest`.

> **Réserve majeure :** `app/Services/NotificationService.php` a été modifié (+299 lignes) pendant l'audit, par une session parallèle. Les constats ci-dessous décrivent l'état observé avant cette écriture. Le code ajouté a ensuite reçu un test (`tests/Feature/NotificationPreuveRemiseTest.php`, 20:53, 9 tests verts), mais il reste **sans justification métier** : il cite des questions qui n'existent pas.

---

## 2. MÉTHODE

1. Lecture du code des domaines visés par le mandat : notifications, historique, échéances, dérogations, recours.
2. Vérification des protections réellement exécutées (routes, middleware, contrôleurs), et non des seuls noms de classes.
3. Exécution des suites de tests existantes sur le domaine notification.
4. Aucune modification de fichier applicatif, aucune migration, aucune commande destructive.

---

## 3. RÉSULTATS PAR CONTRÔLE

| ID | Contrôle | Statut | Preuve |
| :--- | :--- | :--- | :--- |
| `A-01` | Disponibilité du corpus Q001–Q146 | `BLOCKED_ENVIRONMENT` | Journal E1–E6 : 0 source. Voir `LOOP-146-INVENTAIRE.md`. |
| `A-02` | Modèle de preuve de remise (accusé, consultation signée) | `NOT_EXECUTED` | Aucun champ ni modèle de remise avant 20:50. Le code ajouté concurremment n'est pas validé. |
| `A-03` | Un envoi est-il présenté comme une remise ? | `PASS_WITH_RESERVATIONS` | `Courriel::STATUT_ACCEPTE_SMTP = 'accepted_by_smtp'` (`app/Models/Courriel.php:21`). Réserve : `ResultatNotification` renvoie `statut: 'envoye'` dès la mise en file (`CentreNotificationsUnifieService.php:151-157`), ce qui peut être lu à tort comme une livraison. |
| `A-04` | Traçabilité des échecs d'envoi | `PASS` | `CanalNotificationExterneInterface:16-22` ; reprise `NotificationService::reprendreEchecsExterieurs()` (`:117-144`) ; planification `routes/console.php:20`. |
| `A-05` | Isolation des notifications étudiant (anti-IDOR) | `PASS` | `NotificationController::lireEtudiant()` compare `etudiant_id` à la session puis `abort(404)` (`app/Http/Controllers/NotificationController.php:75`). Idem admin (`:41`) et enseignant (`:109`). |
| `A-06` | Autorisation des gabarits et campagnes | `PASS` | `can:configuration.manage` (`routes/web.php:676-681`) ; `can:campagnes.view` / `can:campagnes.manage` (`routes/web.php:752-755`). |
| `A-07` | Idempotence des notifications | `PASS` | Clé de déduplication contrôlée avant création (`CentreNotificationsUnifieService.php:62-73`), indexée sur `data->cle_dedup` (`:196-209`). Test : `NotificationsOutboxRepriseTest`, `CentreNotificationsUnifieTest`. |
| `A-08` | Historique : conservation de l'acte antérieur | `PASS_WITH_RESERVATIONS` | `AuditService::tracer()` enregistre `ancienne_valeur` et `nouvelle_valeur`, acteur, IP (`app/Services/AuditService.php:33-46`) ; journal déclaré en écriture seule (`:9-16`). Réserve : rien au niveau base ne bloque un `UPDATE` direct. |
| `A-09` | Décision automatique sur silence administratif | `PASS` | Aucune occurrence fonctionnelle de « silence » dans `app/`. Aucun job ne statue à la place de l'administration. |
| `A-10` | Échéances : écrasement ou forclusion automatique | `PASS_WITH_RESERVATIONS` | `RappelerEcheancesInscription` notifie seulement (`app/Console/Commands/RappelerEcheancesInscription.php:24-49`). Réserves : aucune trace d'audit spécifique du rappel. |
| `A-11` | Dérogations : contrôle de l'autorité compétente | `PASS_WITH_RESERVATIONS` | Champ texte libre `derogation_administrative` (`app/Http/Requests/InscriptionRequest.php:98`), tracé et signalé (`app/Services/EligibiliteParcoursService.php:99-153`). Réserve : **aucun contrôle d'habilitation** ; le champ peut être renseigné sans référence d'acte. |
| `A-12` | Recours : workflow canonique et effets de bord | `NOT_EXECUTED` | Aucun service de recours dédié. Les occurrences de « recours » relèvent d'autres domaines (réclamations enseignants, admission). Rien à auditer. |
| `A-13` | Concurrence d'écriture sur le dépôt | `FAIL` | `app/Services/NotificationService.php` modifié pendant l'audit (20:50). Deux acteurs écrivent simultanément. Résultats non reproductibles. |
| `A-14` | Absence de commit / push / opération destructive | `PASS` | Aucune commande Git d'écriture exécutée ; aucune migration ; aucune commande `db:*` destructrice. |

---

## 4. ANOMALIES DÉTAILLÉES

### ANO-01 — Corpus métier absent (bloquante)

- **Composant :** documentation et sources.
- **Observé :** 0 question retrouvée sur 146.
- **Risque :** toute implémentation serait une invention.
- **Exigence concernée :** §2 et §10 du mandat.
- **Reproduction :** commandes E1–E6 de `LOOP-146-INVENTAIRE.md`.

### ANO-02 — Absence de modèle de preuve de remise

- **Composant :** `app/Services/NotificationService.php`, `app/Models/AppNotification.php`.
- **Observé :** seuls des états d'acheminement existent (`en_attente`, `envoye`, `echec`, `echec_definitif`) ; aucun état de remise.
- **Risque :** impossible de distinguer juridiquement un envoi d'une remise, ni de conserver une preuve tardive.
- **Exigence concernée :** `MAND-01`, `MAND-04`, `MAND-07`, `MAND-08`.
- **Reproduction :** `rg -n 'remise_confirmee' app/` (0 résultat avant 20:50).

### ANO-03 — Sémantique ambiguë du retour `statut: 'envoye'`

- **Composant :** `CentreNotificationsUnifieService::distribuer()` (`:151-157`).
- **Observé :** le retour vaut `envoye` immédiatement après la mise en file, avant tout retour de la passerelle.
- **Risque :** un appelant peut traiter ce statut comme une livraison.
- **Exigence concernée :** `MAND-01`.
- **Reproduction :** lecture des lignes 145-157 ; `NotificationService::acheminerExterne()` ne fait que `dispatch()` (`:77-84`).

### ANO-04 — Dérogation sans contrôle d'habilitation

- **Composant :** `app/Http/Requests/InscriptionRequest.php:98`, `app/Services/EligibiliteParcoursService.php:99-153`.
- **Observé :** un texte libre suffit à déclencher une dérogation, avec avertissement mais sans vérification de l'autorité.
- **Risque :** incompétence de l'auteur de l'acte.
- **Exigence concernée :** `MAND-14`.
- **Reproduction :** lecture des lignes citées.

### ANO-05 — Écriture concurrente pendant l'audit

- **Composant :** dépôt Git.
- **Observé :** `app/Services/NotificationService.php` +299 lignes à 20:50, citant « LOOP 146 (Q114...) ».
- **Risque :** constats et tests invalidés ; règle métier codée sans source.
- **Exigence concernée :** §1.1 (préservation) et §13 (arrêt sur décision manquante).
- **Reproduction :** `git diff app/Services/NotificationService.php` ; `ls -la` (mtime 20:50).

---

## 5. CE QUE L'AUDIT NE PROUVE PAS

- Il ne prouve pas que le comportement actuel est conforme aux attentes de l'établissement : aucune attente n'est documentée.
- Il ne prouve pas l'absence de faille ailleurs dans l'application : le périmètre est limité aux domaines du mandat.
- Il ne valide pas le code ajouté concurremment à 20:50.