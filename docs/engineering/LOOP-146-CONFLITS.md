# REGISTRE DES CONFLITS, INCERTITUDES ET SOURCES MANQUANTES — LOOP 146

**Référence :** `LOOP-146-CONFLITS.md`
**Version :** 3.0 (Q132–Q146 : source désignée ; Q001–Q131 : conflit maintenu)
**Date :** 2026-10-29 22:40 CET
**Périmètre :** Q001–Q146 et règles institutionnelles associées

---

## 0. CE QUI A CHANGÉ DEPUIS LA V2.x

- **Q132–Q145** : l'utilisateur a désigné `questions_reponses_LOOP_146.md` comme source de référence. Les réponses sont traitées comme confirmées, avec réserve sur les formulations reconstruites. Le conflit C-02 est donc **partiellement résolu** pour ces 14 questions.
- **Q146** : la source ne contient aucune réponse finale ; le conflit reste ouvert.
- **Q001–Q131** : aucune source ; le conflit C-01 reste ouvert pour ces 131 questions.
- Les attributions de thèmes métier à Q001–Q131 restent retirées : elles constituaient une invention de contenu.

---

## C-01 — Corpus Q001–Q131 absent (Q132–Q145 désormais sourcées)

| Champ | Valeur |
| :--- | :--- |
| **Questions concernées** | Q001 à Q131 (131/146). Q132–Q145 : source désignée. Q146 : options connues, réponse non confirmée. |
| **Constat** | Aucune source accessible ne contient les questions Q001–Q131, leurs options ou leurs réponses. La source désignée couvre uniquement Q132–Q145 (réponses) et Q146 (options). |
| **Preuves** | Journal E1–E7 de `LOOP-146-INVENTAIRE.md` : 0 occurrence exploitable pour Q001–Q131 dans le dépôt, les transcripts, l'historique Git et les fichiers utilisateur. E7 = registre d'agent désigné par l'utilisateur, qui déclare lui-même Q001–Q131 introuvables. |
| **Impact métier** | Impossible de consolider les règles Q001–Q131, donc impossible de savoir si le comportement actuel est conforme pour ces 131 questions. |
| **Impact technique** | Aucun lot d'implémentation Q001–Q131 n'est autorisé. Les lots Q132–Q145 sont ouverts sous réserve de formulation. |
| **Clarification nécessaire** | Fourniture du corpus Q001–Q131 (questions + réponses confirmées). Voir §5 de `LOOP-146-INVENTAIRE.md`. |
| **Composants gelés** | Tous les composants métier qui seraient touchés par les règles Q001–Q131 : notifications, décisions d'inscription, recours, dérogations, échéances. Aucun ne doit être modifié sur la base d'une supposition. |

**Sévérité : bloquante pour Q001–Q131.**

---

## C-02 — Confirmations fabriquées dans les livrables v1.0 (`RÉSOLU PARTIELLEMENT`)

| Champ | Valeur |
| :--- | :--- |
| **Questions concernées** | Q113 à Q146 dans la v1.0 ; Q132–Q145 désormais couvertes par la source désignée ; Q146 encore non confirmée. |
| **Constat** | La v1.0 de l'inventaire attribuait à Q113–Q146 des formulations inventées et le statut `CONFIRMED`, en citant « Mandat §8 & Question Qxxx ». Ces formulations inventées restent retirées. La désignation utilisateur du 2026-10-29 fonde désormais les **réponses** Q132–Q145, sans vérifier les formulations. |
| **Preuve documentaire** | `LOOP-146-INVENTAIRE.md` v1.0 (avant réécriture) : chaque ligne citait « Mandat §8.x » comme source, jamais une réponse utilisateur. La source E7 déclare elle-même « formulations reconstruites à vérifier ». |
| **Preuve d'implémentation** | `NotificationService.php` et `EcheanceDerogationService.php` contiennent les règles Q132–Q146. Les tests prouvent le comportement, pas la vérification des formulations ni la conformité institutionnelle. |
| **Impact** | Les réponses Q132–Q145 sont exploitables avec réserve ; Q146 reste sans règle finale ; Q113–Q131 restent sans source. |
| **Clarification nécessaire** | Vérifier les formulations Q132–Q145 contre le questionnaire original ; choisir l'option Q146 ; fournir Q001–Q131. |
| **Composants concernés** | `app/Services/NotificationService.php`, `app/Services/EcheanceDerogationService.php`, leurs tests et appelants. |
| **État** | **Partiellement résolu.** Q132–Q145 : `PASS_WITH_RESERVATIONS`. Q146 : `SANS VERDICT FINAL`. Q113–Q131 : toujours sans source. |

**Sévérité : moyenne pour Q132–Q145 (réserve de formulation) ; élevée pour Q146 et Q001–Q131.**

---

## C-03 — Absence des textes institutionnels de référence

| Champ | Valeur |
| :--- | :--- |
| **Objets concernés** | Délais de recours et de forclusion ; autorité compétente pour accorder une dérogation ; valeur juridique du silence administratif ; modalités de notification réputée acquise. |
| **Constat** | Aucun texte officiel (arrêté, règlement des études, délibération, tableau de délégation de signature) n'est présent dans le dépôt ni dans les sources accessibles. |
| **Impact** | Toute automatisation de ces points serait une invention institutionnelle. Les fonctionnalités correspondantes doivent rester en mode consultatif ou fail-closed. |
| **Clarification nécessaire** | Fourniture des textes applicables, à défaut une décision écrite de l'autorité compétente. |
| **Composants protégés** | Aucun `cron`, job ou service ne doit clôturer, rejeter, accepter ou prolonger automatiquement un dossier sur la base d'un délai non documenté. |

**Sévérité : bloquante pour tout lot touchant aux délais, recours ou dérogations.**

---

## C-04 — Concurrence d'écriture sur le dépôt

| Champ | Valeur |
| :--- | :--- |
| **Constat** | Pendant l'exécution de ce LOOP, un autre acteur (session parallèle) a modifié le dépôt : `app/Services/NotificationService.php` (+299 lignes) à 20:50, créé `tests/Feature/ExamCapaciteSalleNonConfirmeeTest.php`, `app/Services/EcheanceDerogationService.php`, `tests/Feature/EcheanceDerogationServiceTest.php`, ainsi que des commandes et outils du module Évaluation, et écrit un fichier de questions reconstruites dans `~/Downloads` à 21:11. |
| **Impact** | Les audits et tests exécutés peuvent être invalidés par une écriture postérieure. Le relevé Git final a été figé à 21:11 CET (17 suivis modifiés, 24 non suivis). |
| **Clarification nécessaire** | Exécuter un seul agent à la fois sur le dépôt, ou isoler chaque agent dans un worktree distinct. |
| **État** | **Non résolu.** Ce LOOP a modifié des fichiers dans le cadre du lot correctif `S-SEC-01` et du lot Q132–Q146. Un bloc de méthodes dupliquées, ajouté concurremment à `EcheanceDerogationService.php`, a provoqué un fatal `Cannot redeclare` ; il a été isolé, sauvegardé et retiré, puis les 62 tests ont été rejoués. Le risque de nouvelle écriture concurrente demeure. |

**Sévérité : moyenne (risque de résultats non reproductibles).**

---

## C-05 — Opération d'examen bloquée (hors périmètre Q001–Q146, non traitée)

| Champ | Valeur |
| :--- | :--- |
| **Constat** | L'impression officielle de l'épreuve `ECUE-INFO-02` pour le groupe `G-INFO-L1-01` est bloquée : programme et niveau du groupe jugés incompatibles, et aucun étudiant éligible. |
| **Preuve** | Message du service `app/Modules/Evaluation/Services/CorrespondanceExamenService.php` (`verifierCoherenceStructurelleGroupe`) et `ServiceEligibiliteExamen.php`. |
| **Impact** | Opération métier impossible tant que les données ne sont pas cohérentes. |
| **Décision** | **Aucune correction automatique n'a été appliquée.** La cohérence programme/niveau relève d'une décision administrative, pas d'un correctif technique. |
| **Clarification nécessaire** | Indiquer lequel des deux objets (groupe ou épreuve) porte la bonne valeur académique, ou fournir la décision de rattachement. |

**Sévérité : bloquante pour l'opération d'examen, hors périmètre de ce LOOP.**

---

## C-06 — Jeu de livrables concurrent `docs/loop/global-146/` fondé sur des questions inventées

| Champ | Valeur |
| :--- | :--- |
| **Constat** | Un acteur concurrent a créé `docs/loop/global-146/` (7 fichiers) pendant le LOOP. `00-inventaire.md` y déclare 31 questions `AUTO_PASS` et 3 `BLOCKED_BUSINESS` sur la base de Q113–Q146 « pleinement implémentées ». `01-matrice-146.md` donne un texte de question pour Q113–Q146 (ex. « En cas d'échec d'envoi d'une notification, comment tracer l'incident ? ») et cite comme source unique « Mandat §8.1 (NOTIF-001) ». Aucune réponse utilisateur n'existe pour ces questions. |
| **Preuve** | `docs/loop/global-146/01-matrice-146.md:124` (Q113) et `:157` (Q146) ; `00-inventaire.md` §1. Le §8 du mandat est une consigne de test, pas une réponse institutionnelle. |
| **Seconde anomalie** | `05-resultats-tests.md` attribue des numéros de questions aux méthodes de test (`Q114`, `Q117`, `Q131`…) et cite des noms de tests qui n'existent pas dans les fichiers (ex. `test_notification_externe_n_implique_pas_remise_confirmee`). Le total « 45 tests, 216 assertions » est réel et reproductible (`php artisan test --filter="NotificationPreuveRemiseTest|EcheanceDerogationServiceTest|ExamCapaciteSalleNonConfirmeeTest|ExamGroupesVirtuelsConsultationExportTest" --compact`), mais la traçabilité question → test est inventée. |
| **Impact** | Deux jeux de livrables contradictoires coexistent. Celui de `docs/loop/global-146/` présente comme validé un périmètre métier qui n'a aucune source. Il ne doit pas être pris pour le résultat de ce LOOP. |
| **Décision** | **Aucune suppression.** Préservation des travaux locaux. Le présent LOOP ne reconnaît que `docs/engineering/LOOP-146-*` comme livrables de référence : **131 `SOURCE MANQUANTE`**, **14 `PASS_WITH_RESERVATIONS`**, **1 `SANS VERDICT FINAL`**. |
| **Clarification nécessaire** | Décision utilisateur : conserver `docs/loop/global-146/` comme brouillon non validé, le requalifier, ou le retirer. |

**Sévérité : élevée (intégrité métier).**

---

## SYNTHÈSE

| ID | Objet | Sévérité | État |
| :--- | :--- | :--- | :--- |
| C-01 | Corpus Q001–Q131 absent (Q132–Q145 sourcées) | Bloquante pour Q001–Q131 | Ouvert |
| C-02 | Confirmations fabriquées v1.0 + implémentation | Moyenne (Q132–Q145) / élevée (Q146, Q001–Q131) | Résolu partiellement |
| C-03 | Textes institutionnels absents | Bloquante (lots concernés) | Ouvert |
| C-04 | Concurrence d'écriture | Moyenne | Ouvert |
| C-05 | Examen ECUE-INFO-02 bloqué | Bloquante (opération) | Ouvert, hors périmètre |
| C-06 | Livrables concurrents `docs/loop/global-146/` fondés sur des Q inventées | Élevée | Ouvert |
