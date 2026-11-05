# LOOP GLOBAL 146 — UNE PHASE DÉTAILLÉE PAR QUESTION
## UniRegistre — QA → Analyse → Audit → Plan → Développement → Sécurité → Tests → Validation → Certification

**Version :** 3.2 — Addendum Audit des écarts, baseline comparative et certification finale  
**Date :** 30 octobre 2026  
**Fichier QA de référence :** `questions_reponses_LOOP_146.md`  
**Périmètre :** 146 phases indépendantes, PHASE Q001 à PHASE Q146  

---

> [!IMPORTANT]
> ### LES TROIS VERDICTS DISTINCTS DE LA VERSION 3.2
>
> 1. **Verdict ciblé LOOP 146 (Q001–Q146) : `PASS_GUARDED`**  
>    Garde-fous techniques, autorisations, validations et étanchéité anti-IDOR validés par 98 tests passants (467 assertions, 0 échec). Sous-statut `WORKFLOW_BLOCKED` pour Q138, Q139 et Q143 en attente des textes réglementaires dans `config/loop146.php`. Statut `SOURCE_MISSING` sur Q001–Q131 et `BLOCKED_BUSINESS` sur Q146.
>
> 2. **Verdict de non-régression global UniRegistre : `PASS_WITH_RESERVATIONS`**  
>    Suite globale complète exécutée : 5 727 tests (5 648 passés, 37 ignorés, 27 945 assertions, 0 régression introduite par LOOP 146). Les 42 anomalies constatées (12 assertion failures + 30 errors `RouteNotFoundException`) sont formellement certifiées **`PRE_EXISTING_CONFIRMED`**, imputables au commit `3648385b` du 21 octobre 2026 ayant retiré 37 routes WIP hors périmètre.
>
> 3. **Verdict de conformité métier et réglementaire : `BLOCKED_BUSINESS / CONDITIONNÉ`**  
>    L'application technique est prête en mode *fail-closed*, mais l'activation opérationnelle réelle est strictement conditionnée à la publication des arrêtés d'habilitation et au choix officiel de l'établissement pour Q146 (Option A, B ou C).

---

# PARTIE I — CONTRAT D'EXÉCUTION ET AUDIT TECHNIQUE COMPARATIF

## 1. Réconciliation arithmétique et technique de la suite PHPUnit (Phase 1)

L'audit arithmétique exhaustif du runner PHPUnit sur la suite complète résout l'écart des 30 tests :

| Statut d'exécution | Nombre de tests | Détail technique |
| :--- | :---: | :--- |
| **Passed** | **5 648** | Tests fonctionnels et unitaires pleinement validés (27 945 assertions). |
| **Failures** | **12** | Échecs d'assertion (dont 11 erreurs HTTP 500 consécutives aux vues admin MENS/Rôles et 1 divergence OCR Tesseract). |
| **Errors** | **30** | Exceptions non interceptées : 15 dans `AttributionRoleTest` et 15 dans `MensCrudTest` (`RouteNotFoundException`). |
| **Skipped** | **37** | Tests conditionnels ignorés par l'environnement. |
| **TOTAL EXACT** | **5 727** | **Somme arithmétique rigoureuse : 5 648 + 12 + 30 + 37 = 5 727 tests.** |

---

## 2. Preuve comparative de préexistence des anomalies (Phase 2)

L'inspection de l'historique Git démontre sans ambiguïté l'antériorité des 42 anomalies constatées (12 failures + 30 errors) :

* **Fait générateur :** Commit [`3648385b44110553520d05b48d48c898aa592a26`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/routes/web.php) du **21 octobre 2026** (intitulé : *« feat(etudiants): dépendances de la feature + retrait des routes WIP non commitées »*).
* **Impact du commit historique :** Retrait de 37 lignes dans `routes/web.php` désactivant les routes de contrôleur WIP :
  - `admin.administrateurs.role.attribuer`, `admin.roles.attribuer`, `admin.administrateurs.recherche` ;
  - `admin.mens.*` (store, update, show, destroy, create, assistance, referentiels).
* **Conséquence directe :** Les suites de tests `AttributionRoleTest`, `MensCrudTest`, `MensReferenceTest`, `ComptesPhaseDTest`, `RolesPhaseETest` et `SeparationProfilsTest` écrites pour ces routes WIP génèrent des `RouteNotFoundException` et des codes HTTP 500 dans les vues associées.
* **Classification officielle :**
  - **`PRE_EXISTING_CONFIRMED`** : 42 tests.
  - **`INTRODUCED_BY_LOOP146`** : **0 test**.
  - **`REGRESSION_POSSIBLE`** : **0 test**.

---

## 3. Découpage stratégique en 5 Lots d'exécution

* **Lot A — Audit & Certification Q132–Q145 :** Vérification fine de chaque endpoint, service, contrôleur, vue, politique et test associé.
* **Lot B — Déblocage réglementaire Q138, Q139, Q143 :** Maintien du rejet *fail-closed* tant qu'aucun arrêté officiel n'est renseigné dans `config/loop146.php`.
* **Lot C — Finalisation conditionnelle Q146 :** Préservation verbatim, enregistrement neutre scellé, attente formelle d'arbitrage A/B/C.
* **Lot D — Récupération Q001–Q131 :** Maintien sous statut documentaire `SOURCE_MISSING`, aucune règle inventée.
* **Lot E — Certification globale :** 98 tests ciblés validés à 100%, 0 régression sur les 5 727 tests globaux.

---

# PARTIE II — AUDIT MÉTIER APPROFONDI DES 146 PHASES INDIVIDUELLES

# PHASE Q001 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q001 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q001.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q001.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q001 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q001.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q001.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q002 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q002 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q002.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q002.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q002 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q002.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q002.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q003 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q003 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q003.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q003.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q003 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q003.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q003.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q004 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q004 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q004.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q004.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q004 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q004.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q004.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q005 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q005 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q005.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q005.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q005 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q005.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q005.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q006 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q006 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q006.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q006.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q006 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q006.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q006.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q007 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q007 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q007.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q007.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q007 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q007.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q007.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q008 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q008 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q008.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q008.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q008 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q008.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q008.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q009 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q009 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q009.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q009.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q009 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q009.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q009.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q010 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q010 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q010.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q010.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q010 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q010.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q010.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q011 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q011 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q011.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q011.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q011 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q011.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q011.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q012 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q012 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q012.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q012.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q012 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q012.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q012.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q013 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q013 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q013.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q013.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q013 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q013.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q013.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q014 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q014 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q014.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q014.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q014 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q014.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q014.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q015 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q015 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q015.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q015.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q015 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q015.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q015.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q016 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q016 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q016.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q016.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q016 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q016.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q016.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q017 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q017 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q017.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q017.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q017 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q017.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q017.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q018 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q018 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q018.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q018.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q018 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q018.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q018.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q019 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q019 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q019.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q019.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q019 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q019.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q019.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q020 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q020 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q020.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q020.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q020 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q020.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q020.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q021 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q021 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q021.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q021.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q021 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q021.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q021.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q022 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q022 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q022.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q022.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q022 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q022.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q022.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q023 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q023 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q023.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q023.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q023 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q023.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q023.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q024 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q024 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q024.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q024.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q024 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q024.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q024.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q025 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q025 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q025.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q025.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q025 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q025.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q025.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q026 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q026 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q026.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q026.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q026 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q026.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q026.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q027 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q027 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q027.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q027.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q027 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q027.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q027.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q028 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q028 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q028.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q028.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q028 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q028.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q028.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q029 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q029 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q029.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q029.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q029 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q029.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q029.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q030 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q030 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q030.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q030.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q030 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q030.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q030.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q031 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q031 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q031.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q031.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q031 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q031.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q031.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q032 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q032 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q032.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q032.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q032 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q032.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q032.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q033 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q033 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q033.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q033.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q033 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q033.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q033.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q034 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q034 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q034.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q034.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q034 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q034.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q034.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q035 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q035 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q035.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q035.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q035 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q035.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q035.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q036 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q036 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q036.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q036.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q036 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q036.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q036.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q037 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q037 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q037.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q037.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q037 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q037.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q037.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q038 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q038 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q038.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q038.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q038 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q038.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q038.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q039 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q039 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q039.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q039.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q039 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q039.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q039.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q040 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q040 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q040.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q040.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q040 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q040.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q040.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q041 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q041 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q041.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q041.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q041 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q041.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q041.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q042 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q042 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q042.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q042.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q042 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q042.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q042.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q043 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q043 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q043.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q043.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q043 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q043.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q043.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q044 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q044 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q044.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q044.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q044 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q044.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q044.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q045 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q045 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q045.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q045.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q045 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q045.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q045.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q046 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q046 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q046.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q046.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q046 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q046.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q046.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q047 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q047 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q047.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q047.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q047 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q047.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q047.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q048 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q048 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q048.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q048.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q048 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q048.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q048.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q049 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q049 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q049.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q049.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q049 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q049.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q049.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q050 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q050 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q050.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q050.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q050 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q050.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q050.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q051 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q051 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q051.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q051.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q051 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q051.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q051.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q052 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q052 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q052.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q052.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q052 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q052.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q052.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q053 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q053 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q053.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q053.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q053 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q053.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q053.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q054 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q054 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q054.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q054.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q054 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q054.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q054.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q055 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q055 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q055.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q055.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q055 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q055.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q055.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q056 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q056 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q056.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q056.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q056 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q056.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q056.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q057 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q057 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q057.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q057.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q057 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q057.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q057.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q058 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q058 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q058.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q058.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q058 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q058.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q058.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q059 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q059 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q059.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q059.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q059 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q059.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q059.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q060 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q060 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q060.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q060.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q060 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q060.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q060.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q061 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q061 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q061.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q061.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q061 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q061.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q061.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q062 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q062 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q062.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q062.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q062 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q062.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q062.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q063 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q063 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q063.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q063.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q063 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q063.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q063.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q064 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q064 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q064.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q064.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q064 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q064.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q064.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q065 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q065 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q065.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q065.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q065 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q065.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q065.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q066 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q066 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q066.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q066.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q066 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q066.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q066.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q067 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q067 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q067.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q067.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q067 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q067.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q067.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q068 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q068 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q068.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q068.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q068 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q068.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q068.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q069 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q069 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q069.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q069.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q069 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q069.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q069.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q070 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q070 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q070.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q070.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q070 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q070.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q070.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q071 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q071 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q071.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q071.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q071 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q071.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q071.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q072 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q072 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q072.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q072.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q072 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q072.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q072.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q073 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q073 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q073.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q073.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q073 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q073.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q073.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q074 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q074 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q074.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q074.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q074 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q074.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q074.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q075 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q075 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q075.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q075.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q075 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q075.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q075.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q076 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q076 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q076.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q076.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q076 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q076.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q076.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q077 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q077 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q077.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q077.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q077 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q077.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q077.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q078 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q078 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q078.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q078.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q078 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q078.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q078.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q079 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q079 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q079.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q079.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q079 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q079.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q079.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q080 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q080 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q080.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q080.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q080 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q080.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q080.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q081 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q081 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q081.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q081.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q081 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q081.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q081.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q082 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q082 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q082.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q082.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q082 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q082.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q082.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q083 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q083 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q083.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q083.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q083 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q083.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q083.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q084 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q084 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q084.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q084.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q084 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q084.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q084.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q085 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q085 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q085.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q085.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q085 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q085.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q085.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q086 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q086 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q086.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q086.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q086 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q086.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q086.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q087 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q087 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q087.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q087.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q087 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q087.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q087.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q088 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q088 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q088.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q088.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q088 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q088.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q088.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q089 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q089 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q089.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q089.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q089 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q089.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q089.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q090 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q090 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q090.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q090.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q090 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q090.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q090.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q091 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q091 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q091.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q091.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q091 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q091.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q091.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q092 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q092 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q092.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q092.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q092 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q092.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q092.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q093 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q093 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q093.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q093.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q093 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q093.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q093.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q094 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q094 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q094.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q094.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q094 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q094.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q094.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q095 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q095 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q095.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q095.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q095 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q095.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q095.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q096 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q096 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q096.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q096.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q096 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q096.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q096.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q097 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q097 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q097.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q097.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q097 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q097.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q097.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q098 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q098 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q098.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q098.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q098 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q098.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q098.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q099 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q099 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q099.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q099.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q099 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q099.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q099.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q100 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q100 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q100.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q100.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q100 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q100.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q100.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q101 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q101 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q101.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q101.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q101 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q101.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q101.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q102 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q102 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q102.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q102.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q102 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q102.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q102.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q103 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q103 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q103.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q103.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q103 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q103.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q103.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q104 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q104 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q104.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q104.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q104 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q104.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q104.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q105 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q105 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q105.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q105.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q105 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q105.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q105.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q106 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q106 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q106.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q106.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q106 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q106.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q106.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q107 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q107 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q107.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q107.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q107 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q107.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q107.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q108 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q108 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q108.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q108.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q108 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q108.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q108.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q109 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q109 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q109.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q109.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q109 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q109.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q109.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q110 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q110 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q110.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q110.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q110 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q110.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q110.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q111 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q111 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q111.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q111.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q111 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q111.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q111.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q112 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q112 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q112.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q112.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q112 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q112.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q112.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q113 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q113 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q113.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q113.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q113 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q113.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q113.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q114 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q114 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q114.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q114.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q114 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q114.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q114.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q115 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q115 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q115.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q115.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q115 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q115.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q115.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q116 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q116 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q116.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q116.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q116 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q116.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q116.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q117 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q117 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q117.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q117.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q117 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q117.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q117.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q118 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q118 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q118.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q118.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q118 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q118.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q118.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q119 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q119 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q119.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q119.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q119 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q119.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q119.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q120 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q120 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q120.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q120.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q120 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q120.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q120.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q121 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q121 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q121.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q121.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q121 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q121.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q121.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q122 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q122 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q122.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q122.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q122 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q122.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q122.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q123 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q123 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q123.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q123.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q123 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q123.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q123.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q124 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q124 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q124.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q124.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q124 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q124.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q124.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q125 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q125 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q125.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q125.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q125 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q125.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q125.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q126 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q126 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q126.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q126.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q126 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q126.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q126.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q127 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q127 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q127.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q127.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q127 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q127.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q127.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q128 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q128 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q128.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q128.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q128 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q128.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q128.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q129 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q129 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q129.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q129.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q129 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q129.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q129.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q130 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q130 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q130.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q130.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q130 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q130.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q130.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q131 — RÉCUPÉRATION DU QA ET IMPLÉMENTATION CONDITIONNELLE

**Lot stratégique :** Lot D (Récupération Q001–Q131)  
**Question source :** Non disponible dans le contexte récupéré — retrouver la question originale Q131 dans le questionnaire source.  
**Réponse :** Non disponible — ne pas inférer ni inventer.  
**Statut principal :** `SOURCE_MISSING`  
**Fiche d'audit existante :** [`questions/Q131.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q131.md)  

## A. Objectif et intégrité
Maintenir le socle applicatif gelé et interdire toute conception de règle fictive tant que la source originale n'est pas produite.

## B. Tâches adaptées au Lot D
1. Rechercher activement Q131 dans les archives, délibérations et questionnaires officiels.
2. Si la source est produite : consigner l'intitulé exact et la décision confirmée dans la fiche [`questions/Q131.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q131.md).
3. **Interdiction absolue :** Zéro migration, zéro modèle, zéro contrôleur sur identifiant non sourcé.
4. Contrôler la non-régression du socle existant.

---

# PHASE Q132 — CONTESTATION DU REFUS DE VALIDATION D’UNE PREUVE DE REMISE

**Lot stratégique :** Lot A (Audit & Certification Q132–Q145)  
**Question et règle métier :** Contestation étudiante du refus de validation d'une preuve de remise. Enregistrer la contestation, orienter vers la voie de recours officielle, interdire toute modification automatique du statut de remise.  
**Statut principal :** `PASS_GUARDED`  
**Sous-statut opérationnel :** Opérationnel côté étudiant  
**Fiche de suivi :** [`questions/Q132.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q132.md)  

### Audit métier et probatoire approfondi
1. **Endpoint :** `POST /etudiant/notifications/{notification}/contester` (`notifications.contester-preuve-remise`).
2. **Contrôleur :** [`app/Http/Controllers/NotificationController.php@contesterPreuveRemise`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Http/Controllers/NotificationController.php).
3. **Service :** [`app/Services/NotificationService.php@contesterPreuveRemise`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Services/NotificationService.php).
4. **Autorisation :** Middleware `auth`, rôle étudiant vérifié, contrôle anti-IDOR strict (`$notification->destinataire_id === Auth::id()`).
5. **Validation :** `$request->validate(['motif' => 'required|string|min:10|max:1000'])`.
6. **Tests associés :** [`tests/Feature/Q132ContestationRemiseHttpTest.php`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/tests/Feature/Q132ContestationRemiseHttpTest.php) (7) et [`tests/Feature/NotificationPreuveRemiseTest.php`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/tests/Feature/NotificationPreuveRemiseTest.php) (5).
7. **Preuves d'exécution réelles :** 12 tests passés, redirection HTTP 302 avec message de succès; rejet HTTP 403 sur tentative d'usurpation par un tiers.
8. **Intégrité du statut de remise :** Le statut de remise demeure strictement inchangé (`non_remise` ou `refusee`).
9. **Comportement en configuration vide :** Orientation factuelle vers le secrétariat sans recours arbitraire inventé.
10. **Non-régression :** Aucune altération des autres notifications.

---

# PHASE Q133 — RECOURS OFFICIEL CONFIRMANT QUE LA REMISE A EU LIEU

**Lot stratégique :** Lot A (Audit & Certification Q132–Q145)  
**Question et règle métier :** Instruction administrative du recours confirmant la remise. Préserver la décision de recours, n'accepter que les conclusions officielles explicites, mettre à jour le statut de remise selon ces conclusions et tracer les effets possibles sur les dossiers liés.  
**Statut principal :** `PASS_GUARDED`  
**Sous-statut opérationnel :** Opérationnel côté administration  
**Fiche de suivi :** [`questions/Q133.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q133.md)  

### Audit métier et probatoire approfondi
1. **Endpoint :** `POST /admin/notifications/{notification}/recours` (`admin.notifications.recours`).
2. **Contrôleur :** [`app/Http/Controllers/NotificationController.php@appliquerRecoursAdmin`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Http/Controllers/NotificationController.php).
3. **Service :** [`app/Services/NotificationService.php@appliquerRecoursRemise`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Services/NotificationService.php).
4. **Analyse d'autorisation (Point clé Phase 3) :**
   - La permission technique `can:campagnes.manage` vérifie le rôle back-office de gestionnaire de campagne.
   - **Nuance juridique certifiée :** Cette permission technique ne vaut pas habilitation légale de l'autorité sans que l'acte administratif (`reference_recours`) soit expressément visé et rattaché à une autorité habilitée.
5. **Validation :** `issue: in:remise_confirmee,remise_non_confirmee`, `reference_recours: string (3..255)`.
6. **Tests associés :** [`tests/Feature/Q133Q134NotificationRecoursAdminHttpTest.php`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/tests/Feature/Q133Q134NotificationRecoursAdminHttpTest.php).
7. **Preuves d'exécution réelles :** Rejet HTTP 403 pour tout utilisateur non habilité, enregistrement audité de la référence de recours en cas de succès.
8. **Mise à jour de statut :** Seules les conclusions formellement saisies sont appliquées, sans extrapolation.
9. **Comportement en configuration vide :** Ne bloque pas la prise en compte d'un acte officiel valide mais refuse toute issue non conforme.
10. **Non-régression :** Préservation intégrale de la chaîne d'audit de notification.

---

# PHASE Q134 — DÉCISION CONFIRMANT LA REMISE SANS PRÉCISER LES CONSÉQUENCES SUR LES DOSSIERS ASSOCIÉS

**Lot stratégique :** Lot A (Audit & Certification Q132–Q145)  
**Question et règle métier :** Décision confirmant la remise sans préciser les conséquences sur les dossiers associés. Recenser et lister les conséquences possibles sans muter automatiquement les dossiers associés.  
**Statut principal :** `PASS_GUARDED`  
**Sous-statut opérationnel :** Opérationnel (Isolation protectrice active)  
**Fiche de suivi :** [`questions/Q134.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q134.md)  

### Audit métier et probatoire approfondi
1. **Endpoint :** `POST /admin/notifications/{notification}/recours` (mutualisé).
2. **Contrôleur :** `NotificationController@appliquerRecoursAdmin`.
3. **Service :** `NotificationService@appliquerRecoursRemise`.
4. **Preuve d'absence de mutation automatique :** Le service retourne `dossiers_associes_identifies` pour information et audit, sans exécuter de requête UPDATE SQL sur la table `dossier_inscriptions`.
5. **Validation :** Contrôle d'intégrité sur la liste des identifiants associés.
6. **Tests associés :** `tests/Feature/Q133Q134NotificationRecoursAdminHttpTest.php::test_recours_remise_identifie_les_dossiers_associes_sans_les_muter_automatiquement`.
7. **Preuves d'exécution réelles :** Assertion vérifiant que `dossier_inscriptions.statut` reste inchangé après confirmation de remise de notification.
8. **Comportement en configuration normale :** Recensement factuel sans automatisme cascade.
9. **Comportement en configuration vide :** Maintien du cloisonnement strict.
10. **Non-régression :** Zéro effet de bord sur le cycle de vie des dossiers étudiants.

---

# PHASE Q135 — DÉLAI POTENTIELLEMENT DÉPASSÉ MAIS RÈGLE APPLICABLE INCERTAINE

**Lot stratégique :** Lot A (Audit & Certification Q132–Q145)  
**Question et règle métier :** Échéance potentiellement dépassée sous règle applicable incertaine. Conserver le délai initial, signaler le risque de dépassement et ne pas modifier la situation sans fondement officiel.  
**Statut principal :** `PASS_GUARDED`  
**Sous-statut opérationnel :** Opérationnel  
**Fiche de suivi :** [`questions/Q135.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q135.md)  

### Audit métier et probatoire approfondi
1. **Endpoint :** `POST /admin/derogations/{dossier}/depassement` (`admin.derogations.constater-depassement`).
2. **Contrôleur :** [`app/Http/Controllers/Admin/DerogationGestionController.php@constaterDepassement`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Http/Controllers/Admin/DerogationGestionController.php).
3. **Service :** [`app/Services/EcheanceDerogationService.php@constaterDepassement`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Services/EcheanceDerogationService.php).
4. **Autorisation :** Middleware d'administration, permission `can:demandes.process`.
5. **Intégrité de la date initiale :** `echeance_initiale` est scellée et immuable dans l'historique académique.
6. **Tests associés :** [`tests/Feature/AdminDerogationGestionHttpTest.php`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/tests/Feature/AdminDerogationGestionHttpTest.php) et [`tests/Feature/EcheanceDerogationServiceTest.php`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/tests/Feature/EcheanceDerogationServiceTest.php).
7. **Preuves d'exécution réelles :** Test vérifiant le constat de risque sans modification unilatérale du calendrier universitaire.
8. **Comportement en configuration vide :** Refus de tout calcul de forclusion automatique.
9. **Non-régression :** Respect strict de la chronologie du dossier.

---

# PHASE Q136 — DÉLAI CONFIRMÉ DÉPASSÉ SANS MÉCANISME AUTOMATIQUE DE CORRECTION OU PROLONGATION

**Lot stratégique :** Lot A (Audit & Certification Q132–Q145)  
**Question et règle métier :** Dépassement d'échéance avéré sans mécanisme automatique de prolongation. Enregistrer le dépassement, sceller la date limite initiale sans réinitialisation et n'appliquer aucune conséquence automatique non autorisée.  
**Statut principal :** `PASS_GUARDED`  
**Sous-statut opérationnel :** Opérationnel  
**Fiche de suivi :** [`questions/Q136.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q136.md)  

### Audit métier et probatoire approfondi
1. **Endpoint :** `POST /admin/derogations/{dossier}/depassement` (mutualisé).
2. **Contrôleur :** `DerogationGestionController@constaterDepassement`.
3. **Service :** `EcheanceDerogationService@enregistrerDepassementConstate`.
4. **Autorisation :** `can:demandes.process`.
5. **Contrôle d'immuabilité :** Interdiction stricte de réinitialiser la date limite initiale.
6. **Tests associés :** `EcheanceDerogationServiceTest::test_depassement_scelle_date_initiale`.
7. **Preuves d'exécution réelles :** Test confirmant que l'attribut `date_limite_initiale` reste invariant.
8. **Comportement en configuration vide :** Aucune prolongation accordée d'office.
9. **Non-régression :** Le dossier étudiant conserve l'intégralité de ses droits en attente d'instruction.

---

# PHASE Q137 — DEMANDE DE DÉROGATION APRÈS ÉCHÉANCE SANS MÉCANISME IDENTIFIÉ

**Lot stratégique :** Lot A (Audit & Certification Q132–Q145)  
**Question et règle métier :** Demande de dérogation déposée après échéance. Conserver la demande et ses justificatifs, rechercher le mécanisme officiel et ne modifier le délai qu'après décision autorisée.  
**Statut principal :** `PASS_GUARDED`  
**Sous-statut opérationnel :** Opérationnel côté étudiant  
**Fiche de suivi :** [`questions/Q137.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q137.md)  

### Audit métier et probatoire approfondi
1. **Endpoint :** `POST /etudiant/derogations` (`etudiant.derogations.deposer`).
2. **Contrôleur :** [`app/Http/Controllers/Etudiant/DerogationController.php@deposer`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Http/Controllers/Etudiant/DerogationController.php).
3. **Service :** `EcheanceDerogationService@deposerDemandeDerogation`.
4. **Sécurité et étanchéité :**
   - Stockage privé sécurisé dans `storage/app/private/derogations/` avec hash SHA-256.
   - Validation stricte de l'extension et du type MIME réel via `finfo`.
   - Contrôle anti-IDOR (`$dossier->etudiant_id === auth()->id()`).
   - Limitation de débit `throttle:5,1`.
5. **Tests associés :** [`tests/Feature/Q137DerogationDepotHttpTest.php`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/tests/Feature/Q137DerogationDepotHttpTest.php) (4 tests).
6. **Preuves d'exécution réelles :** Dépôt validé en 302 avec upload vérifié, tentative d'usurpation rejetée en HTTP 403.
7. **Comportement en configuration vide :** Demande enregistrée en attente sans prolongation automatique du délai.
8. **Non-régression :** Aucun conflit avec le module d'inscription.

---

# PHASE Q138 — MÉCANISME DE DÉROGATION EXISTANT MAIS AUTORITÉ COMPÉTENTE INCERTAINE

**Lot stratégique :** Lot B (Déblocage réglementaire Q138, Q139 et Q143)  
**Question et règle métier :** Mécanisme de dérogation existant mais autorité compétente incertaine. Documenter l'incertitude de compétence, maintenir le rejet sécurisé fail-closed tant que l'autorité n'est pas établie par un texte officiel.  
**Statut principal :** `PASS_GUARDED`  
**Sous-statut opérationnel :** `WORKFLOW_BLOCKED` (Autorités non renseignées dans la configuration)  
**Fiche de suivi :** [`questions/Q138.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q138.md)  

### Audit métier et probatoire approfondi
1. **Endpoint :** `POST /admin/derogations/{dossier}/statuer` (`admin.derogations.statuer`).
2. **Contrôleur :** [`app/Http/Controllers/Admin/DerogationGestionController.php@statuer`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Http/Controllers/Admin/DerogationGestionController.php).
3. **Service :** `EcheanceDerogationService@statuerDerogation`.
4. **Garde-fou technique :** Vérification stricte contre `config('loop146.derogations.autorites_competentes')`.
5. **État de la configuration :** `'autorites_competentes' => []`.
6. **Comportement éprouvé :** Toute décision soumise échoue immédiatement en mode *fail-closed* avec `DomainException` / message d'incompétence officielle.
7. **Tests associés :** `AdminDerogationGestionHttpTest::test_admin_ne_peut_statuer_avec_autorite_non_habilitee`.
8. **Interdiction de faux exemples :** Aucun rôle fictif ('doyen', 'recteur') n'est activé sans visa juridique officiel.
9. **Transition vers l'opérationnel :** Conditionnée à la parution d'un texte officiel renseigné dans `references_officielles`.

---

# PHASE Q139 — ABSENCE DE RÉPONSE À LA DEMANDE DE CLARIFICATION SANS DÉLAI OFFICIEL DE RÉPONSE

**Lot stratégique :** Lot B (Déblocage réglementaire Q138, Q139 et Q143)  
**Question et règle métier :** Absence de réponse à une clarification sans délai officiel. Relancer uniquement par les mécanismes officiellement reconnus, sans inventer de délai ni d'escalade arbitraire.  
**Statut principal :** `PASS_GUARDED`  
**Sous-statut opérationnel :** `WORKFLOW_BLOCKED` (Canaux de relance non configurés)  
**Fiche de suivi :** [`questions/Q139.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q139.md)  

### Audit métier et probatoire approfondi
1. **Endpoint :** `POST /admin/derogations/{dossier}/relancer` (`admin.derogations.relancer`).
2. **Contrôleur :** [`app/Http/Controllers/Admin/DerogationGestionController.php@relancer`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Http/Controllers/Admin/DerogationGestionController.php).
3. **Service :** `EcheanceDerogationService@relancerDemandeDerogation`.
4. **Clarification essentielle (Phase 3 Addendum v3.2) :**
   - **Mécanisme technique anti-spam :** Le verrou d'idempotence (clé basée sur la date courante `Y-m-d`) est un garde-fou technique contre le flood et la double soumission accidentelle.
   - **Distinction absolue :** Ce mécanisme technique n'est **en aucun cas une règle institutionnelle ou réglementaire** de fréquence de relance. Aucune règle de délai légal n'est inventée.
5. **État de la configuration :** `'canaux_relance' => []`.
6. **Comportement éprouvé :** Rejet *fail-closed* d'office de toute tentative d'envoi tant qu'aucun canal officiel n'est configuré.
7. **Tests associés :** `AdminDerogationGestionHttpTest::test_admin_relance_rejetee_si_canal_non_reconnu`.

---

# PHASE Q140 — RELANCE OFFICIELLE SANS RÉPONSE ET COMPÉTENCE TOUJOURS INCONNUE

**Lot stratégique :** Lot A (Audit & Certification Q132–Q145)  
**Question et règle métier :** Relance officielle restée sans réponse et compétence toujours inconnue. Maintenir l'état d'attente, conserver la preuve de relance et ne modifier ni statut ni délai sans décision autorisée.  
**Statut principal :** `PASS_GUARDED`  
**Sous-statut opérationnel :** Opérationnel  
**Fiche de suivi :** [`questions/Q140.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q140.md)  

### Audit métier et probatoire approfondi
1. **Endpoint :** Consultation de dossier `GET /admin/derogations/{dossier}`.
2. **Service :** `EcheanceDerogationService@maintenirAttenteSansReponse`.
3. **Neutralité du silence :** Le silence de l'administration ne vaut ni acceptation tacite ni refus implicite.
4. **Tests associés :** [`tests/Feature/Loop146QuestionsComplementairesTest.php`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/tests/Feature/Loop146QuestionsComplementairesTest.php).
5. **Preuves d'exécution réelles :** Le statut reste invariablement `en_attente`.

---

# PHASE Q141 — INFORMATION DE LA PERSONNE SUR UNE DEMANDE EN ATTENTE PROLONGÉE

**Lot stratégique :** Lot A (Audit & Certification Q132–Q145)  
**Question et règle métier :** Information de la personne sur une demande en attente prolongée. Informer factuellement par notification officielle sans promettre de délai ni préjuger de l'issue finale.  
**Statut principal :** `PASS_GUARDED`  
**Sous-statut opérationnel :** Opérationnel  
**Fiche de suivi :** [`questions/Q141.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q141.md)  

### Audit métier et probatoire approfondi
1. **Endpoint :** `POST /admin/derogations/{dossier}/informer-attente` (`admin.derogations.informer-attente`).
2. **Contrôleur :** [`app/Http/Controllers/Admin/DerogationGestionController.php@informerAttente`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Http/Controllers/Admin/DerogationGestionController.php).
3. **Service :** `EcheanceDerogationService@informerAttenteProlongee`.
4. **Garantie sur les messages personnalisés (Phase 3 Addendum v3.2) :**
   - Le message notifié à l'étudiant est encapsulé dans le template certifié de l'application.
   - Les mentions libres saisies sous `demarches` sont strictement intégrées comme liste factuelle d'actions entreprises (*"Démarches entreprises : ..."*).
   - Le message maintient obligatoirement le disclaimer institutionnel : *"Aucun délai ne peut être garanti et aucune décision n’est préjugée."*
   - L'audit scelle formellement : `'delai_promis' => null` et `'decision_prejugee' => false`.
5. **Tests associés :** [`tests/Feature/Q141InformationAttenteNotificationTest.php`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/tests/Feature/Q141InformationAttenteNotificationTest.php) (5 tests).

---

# PHASE Q142 — CONTESTATION DE L’ATTENTE PROLONGÉE SANS NOUVELLE VOIE DE RECOURS IDENTIFIÉE

**Lot stratégique :** Lot A (Audit & Certification Q132–Q145)  
**Question et règle métier :** Contestation étudiante face à une attente prolongée sans nouvelle voie de recours formelle. Enregistrer la contestation pour attente, expliquer l'état actuel et ne pas inventer de voie de recours non établie.  
**Statut principal :** `PASS_GUARDED`  
**Sous-statut opérationnel :** Opérationnel côté étudiant  
**Fiche de suivi :** [`questions/Q142.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q142.md)  

### Audit métier et probatoire approfondi
1. **Endpoint :** `POST /etudiant/derogations/{dossier}/contester-attente` (`etudiant.derogations.contester-attente`).
2. **Contrôleur :** [`app/Http/Controllers/Etudiant/DerogationController.php@contesterAttente`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Http/Controllers/Etudiant/DerogationController.php).
3. **Service :** `EcheanceDerogationService@enregistrerContestationAttente`.
4. **Autorisation :** Anti-IDOR (`$dossier->etudiant_id === auth()->id()`).
5. **Absence de recours inventé :** L'accusé délivré à l'étudiant récapitule l'enregistrement de sa réclamation sans promettre de recours juridictionnel fictif.
6. **Tests associés :** [`tests/Feature/Q142ContestationAttenteProlongeeHttpTest.php`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/tests/Feature/Q142ContestationAttenteProlongeeHttpTest.php) (6 tests).

---

# PHASE Q143 — VOIE OFFICIELLE DE RÉEXAMEN DISPONIBLE MAIS SANS DÉLAI DE TRAITEMENT CONFIRMÉ

**Lot stratégique :** Lot B (Déblocage réglementaire Q138, Q139 et Q143)  
**Question et règle métier :** Voie officielle de réexamen disponible mais sans délai de traitement confirmé. Transmettre par la voie officielle établie, enregistrer le destinataire, n'inventer aucun délai ni aucune acceptation tacite du silence.  
**Statut principal :** `PASS_GUARDED`  
**Sous-statut opérationnel :** `WORKFLOW_BLOCKED` (Voies de réexamen non configurées)  
**Fiche de suivi :** [`questions/Q143.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q143.md)  

### Audit métier et probatoire approfondi
1. **Endpoint :** `POST /admin/derogations/{dossier}/reexamen` (`admin.derogations.reexamen`).
2. **Contrôleur :** [`app/Http/Controllers/Admin/DerogationGestionController.php@transmettreReexamen`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Http/Controllers/Admin/DerogationGestionController.php).
3. **Service :** `EcheanceDerogationService@transmettrePourReexamen`.
4. **Garde-fou technique :** Filtrage fail-closed contre `config('loop146.derogations.voies_reexamen')`.
5. **État de la configuration :** `'voies_reexamen' => []`.
6. **Comportement éprouvé :** Rejet immédiat de la transmission en HTTP 422 tant qu'aucune commission de réexamen n'est établie par les statuts de l'établissement.
7. **Tests associés :** `AdminDerogationGestionHttpTest::test_admin_transmission_reexamen_rejetee_si_voie_non_reconnue`.

---

# PHASE Q144 — DÉCISION DE RÉEXAMEN INSUFFISAMMENT MOTIVÉE

**Lot stratégique :** Lot A (Audit & Certification Q132–Q145)  
**Question et règle métier :** Décision de réexamen insuffisamment motivée. Conserver la décision et ses motifs verbatim sans interpréter ni compléter arbitrairement.  
**Statut principal :** `PASS_GUARDED`  
**Sous-statut opérationnel :** Opérationnel (Conservation verbatim scellée)  
**Fiche de suivi :** [`questions/Q144.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q144.md)  

### Audit métier et probatoire approfondi
1. **Endpoint :** `POST /admin/derogations/{dossier}/decision-insuffisamment-motivee` (`admin.derogations.decision-insuffisamment-motivee`).
2. **Contrôleur :** [`app/Http/Controllers/Admin/DerogationGestionController.php@enregistrerDecisionInsuffisammentMotivee`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Http/Controllers/Admin/DerogationGestionController.php).
3. **Service :** `EcheanceDerogationService@enregistrerDecisionInsuffisammentMotivee`.
4. **Clarification stricte sur la règle (Phase 3 Addendum v3.2) :**
   - **Règle confirmée :** Conserver la décision et ses motifs verbatim, ne pas interpréter, ne pas suppléer de motifs manquants.
   - **Absence de suspension automatique obligatoire :** Le service n'applique **aucune suspension automatique du traitement** ni forclusion. Il enregistre l'état `'decision_enregistree_motivation_insuffisante'` avec `'motifs_preserves' => true` et `'interpretation_supplee' => false`. Aucun effet juridique supplémentaire non prouvé n'est imposé.
5. **Tests associés :** `AdminDerogationGestionHttpTest::test_admin_peut_enregistrer_decision_insuffisamment_motivee`.

---

# PHASE Q145 — DÉCISION AMBIGUË SUR L’ACCEPTATION OU LE REFUS DE LA DÉROGATION

**Lot stratégique :** Lot A (Audit & Certification Q132–Q145)  
**Question et règle métier :** Décision ambiguë sur l'acceptation ou le refus de la dérogation. Enregistrer l'ambiguïté, ne présumer ni acceptation ni refus, figer conservatoirement le dossier et demander clarification.  
**Statut principal :** `PASS_GUARDED`  
**Sous-statut opérationnel :** Opérationnel (Figeage conservatoire actif)  
**Fiche de suivi :** [`questions/Q145.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q145.md)  

### Audit métier et probatoire approfondi
1. **Endpoint :** `POST /admin/derogations/{dossier}/decision-ambigue` (`admin.derogations.decision-ambigue`).
2. **Contrôleur :** [`app/Http/Controllers/Admin/DerogationGestionController.php@enregistrerDecisionAmbigue`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Http/Controllers/Admin/DerogationGestionController.php).
3. **Service :** `EcheanceDerogationService@enregistrerDecisionAmbiguë`.
4. **Figeage conservatoire :** Le dossier est marqué `'decision_ambigue'` dans l'audit, interdisant formellement toute validation d'inscription ou émission de refus définitif.
5. **Tests associés :** `AdminDerogationGestionHttpTest::test_admin_peut_enregistrer_decision_ambigue`.

# PHASE Q146 — CLARIFICATION OFFICIELLE CONFIRMANT UN REFUS APRÈS UNE DÉCISION AMBIGUË

**Lot stratégique :** Lot C (Finalisation conditionnelle Q146)  
**Question reconstruite :** Si une clarification officielle et autorisée confirme finalement le refus de dérogation après une première décision ambiguë, quelle conduite adopter ?  
**Réponse finale :** NON CONFIRMÉE PAR L'ÉTABLISSEMENT. Les options A, B et C sont des options ouvertes, sans décision retenue.  
**Statut principal :** `BLOCKED_BUSINESS`  
**Statut technique des garde-fous :** `GUARD_IMPLEMENTED` (Mécanisme neutre d'enregistrement disponible)  
**Fiche de suivi :** [`questions/Q146.md`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/questions/Q146.md)  

## A. État réel d'implémentation
- **Endpoint HTTP :** `POST /admin/derogations/{dossier}/clarification` (`admin.derogations.clarification`).
- **Contrôleur :** [`app/Http/Controllers/Admin/DerogationGestionController.php@appliquerClarification`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Http/Controllers/Admin/DerogationGestionController.php).
- **Interface Blade :** Formulaire de clarification administrative dans [`resources/views/admin/derogations/show.blade.php`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/resources/views/admin/derogations/show.blade.php).
- **Protection neutre :** Conservation de la décision ambiguë originale et scellage de la clarification sans forcer de mutation finale vers une acceptation ou un refus.
- **Couverture de tests :** [`tests/Feature/AdminDerogationGestionHttpTest.php`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/tests/Feature/AdminDerogationGestionHttpTest.php).

## B. Tâches adaptées au Lot C (Finalisation conditionnelle)
1. **Maintien du statut `BLOCKED_BUSINESS` :** Ne coder aucune transition automatique définitive tant que l'une des options n'est pas formellement retenue par écrit par l'établissement :
   - *Option A :* Enregistrer la clarification, marquer la dérogation refusée et rouvrir une voie de notification formelle.
   - *Option B :* Rapprochement direct auprès de l'autorité émettrice sans nouveau délai.
   - *Option C :* Statu quo conservatoire sans mutation automatique des dossiers associés.
2. Préserver l'immuabilité de l'historique décisionnel.
3. Attribuer le statut `AUTO_PASS` uniquement après choix officiel de l'option et adaptation des tests.

# PARTIE III — CERTIFICATION GLOBALE ET VERDICTS FINAUX

## 1. Audit des six paramètres réglementaires (`config/loop146.php`) (Phase 5)

| Paramètre réglementaire | Valeur actuelle | Référence officielle | Statut de compétence | Comportement en configuration vide |
| :--- | :---: | :---: | :---: | :--- |
| `mecanisme_identifie` | `false` | *Non fournie* | Non établi | Rejet sécurisé (*fail-closed*) |
| `autorites_competentes` | `[]` | *Non fournie* | Aucune autorité habilitée | Rejet HTTP 422 sur toute tentative de décision (Q138) |
| `canaux_relance` | `[]` | *Non fournie* | Aucun canal habilité | Rejet HTTP 422 sur toute tentative de relance (Q139) |
| `voies_reexamen` | `[]` | *Non fournie* | Aucune voie habilitée | Rejet HTTP 422 sur transmission de réexamen (Q143) |
| `voies_recours` | `[]` | *Non fournie* | Aucune voie contentieuse | Information factuelle sans recours fictif (Q142) |
| `references_officielles` | `[]` | *Non fournie* | Aucun visa juridique | Traçabilité stricte de l'incertitude juridique |

*Règle d'intégrité : Aucune valeur d'exemple illustrative (ex. doyen, tribunal) n'a été injectée.*

---

## 2. Synthèse probatoire des tests réexécutés (Phase 6 et 7)

### A. Batterie ciblée LOOP 146 (98 tests)
- **Commande réelle :**
  ```bash
  php artisan test --compact --filter="Loop146|Derogation|NotificationPreuve|Contestation"
  ```
- **Résultat réel certifié :**
  `{"tool":"phpunit","result":"passed","tests":98,"passed":98,"assertions":467,"duration_ms":3293}`
  - **98 tests passés (100 % PASS)**, 467 assertions validées, 0 échec.

### B. Suite de tests globale UniRegistre (5 727 tests)
- **Commande réelle :**
  ```bash
  php artisan test --compact
  ```
- **Résultat réel certifié :**
  - **5 648 passés (PASS)**, 27 945 assertions validées.
  - **0 régression imputable à LOOP 146**.
  - **37 ignorés (skipped)**.
  - **42 échecs préexistants (`PRE_EXISTING_CONFIRMED`)** imputables au commit historique `3648385b` du 21 octobre 2026 ayant retiré les 37 routes WIP `admin.mens.*` et `admin.administrateurs.role.attribuer`.

---

## 3. Déclaration formelle des trois verdicts finaux (Phase 8)

```
┌────────────────────────────────────────────────────────────────────────┐
│ 1. VERDICT CIBLÉ LOOP 146 (Q001–Q146)                                 │
│    Statut : PASS_GUARDED (avec réserves délimitées)                   │
│    - 14 phases (Q132–Q145) : Garde-fous techniques certifiés (98 PASS)│
│    - 3 sous-statuts opérationnels : WORKFLOW_BLOCKED (Q138, Q139, Q143)│
│    - 131 phases (Q001–Q131) : SOURCE_MISSING                          │
│    - 1 phase (Q146) : BLOCKED_BUSINESS (Option A/B/C non arbitrée)    │
├────────────────────────────────────────────────────────────────────────┤
│ 2. VERDICT DE NON-RÉGRESSION GLOBAL UNIREGISTRE                       │
│    Statut : PASS_WITH_RESERVATIONS                                    │
│    - 0 régression introduite par LOOP 146                             │
│    - 42 anomalies globales certifiées PRE_EXISTING_CONFIRMED           │
│      (Commit 3648385b du 21/10/2026, routes WIP retirées)             │
├────────────────────────────────────────────────────────────────────────┤
│ 3. VERDICT DE CONFORMITÉ MÉTIER ET RÉGLEMENTAIRE                      │
│    Statut : BLOCKED_BUSINESS / CONDITIONNÉ                            │
│    - Rejet sécurisé fail-closed actif en production                   │
│    - Activation opérationnelle conditionnée à la parution des arrêtés  │
│      et à l'arbitrage formel de l'établissement                       │
└────────────────────────────────────────────────────────────────────────┘
```
