# ST-14 — Registre global de la campagne

**Campagne :** ST-14 — scénarios avancés du simulateur temporel
**Contrat commun :** `CatalogueScenarios::VERSION_CONTRAT = 1.0`
**Version moteur :** `ServiceSimulationTemporelle::VERSION_MOTEUR` (référence unique)
**Dernière mise à jour :** 2026-11-05
**Mode :** AUDIT_ONLY / SIMULATION / TESTING — données métier en lecture seule

> Une phase n'est marquée terminée qu'après production de sa preuve.
> `✅` = prouvé · `🟡` = partiel · `—` = non commencé · `⛔` = bloqué.

---

## 1. Légende des phases

| Phase | Preuve attendue |
|---|---|
| **A — Audit** | `00-audit.md` : services, formules, paramètres, tables, routes, permissions, tests, réutilisation |
| **B — Spécification** | `01-specification.md` + `02-formules.md` : objectif, hypothèses, variables, unités, contraintes, cas non applicables |
| **C — Conception** | `03-architecture.md` : contrat entrée/sortie, intégration, versionnement, déterminisme, erreurs |
| **D — Développement** | `04-implementation.md` : fichiers réels, absence de duplication |
| **E — Validation math.** | exemples de référence calculés hors moteur, cas limites, arrondis, conservation |
| **F — Sécurité** | `05-securite.md` : permissions, périmètre, throttling, idempotence, zéro mutation |
| **G — Tests** | `06-tests.md` + `07-resultats.md` : chiffres exacts, Pint, non-régression |
| **H — UX** | `08-guide-utilisation.md` : catalogue + écran, sans duplication |
| **I — Documentation** | dossier complet `ST-14/<CODE>/` |
| **J — Verdict** | `09-verdict.md` : PASS / PASS_WITH_RESERVATIONS / FAIL / BLOCKED_* |

---

## 2. Socle fondateur

| Code | Catégorie | Version | Statut | A | B | C | D | E | F | G | H | I | J |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| SOCLE-EFFECTIFS | fondation | 1 (formules 1.0) | `disponible` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | — | ✅ | ✅ **PASS_WITH_RESERVATIONS** |

**Preuves ST-14.00 (état à la clôture) :**
- Fichier : `app/Modules/SimulationTemporelle/Support/CatalogueScenarios.php`
- Tests : `tests/Feature/SimulationTemporelleCatalogueTest.php` — **10 tests / 277 assertions**
- Suite module : **62 tests / 589 assertions**, 0 échec
- Pint `--dirty` : OK
- Documentation : `ST-14/00-catalogue-scenarios.md`
- Route : `GET admin/simulation-temporelle/catalogue` (`can:simulation.view`)
- Réserves : ST14-R1 → ST14-R5 (voir verdict)

> Le fichier de tests catalogue compte désormais **12 tests / 292 assertions**
> après l'ouverture de SIM-01. Les chiffres 10/277 restent la preuve historique
> de la clôture ST-14.00.

---

## 2 bis. SIM-01 — Croissance des admissions

- **Version :** 1 (formules 1.0)
- **Statut :** `disponible`
- **Compatibilité confirmée :** `configuration` (audit du 2026-11-02)
- **Fichiers modifiés :**
  - `Services/ServiceSimulationTemporelle.php` (version moteur `1.1`,
    `admissions_par_periode`)
  - `Support/CatalogueScenarios.php` (`schemaSim01`, règles SIM-01)
  - `tests/Feature/SimulationTemporelleSim01Test.php` (créé)
  - `tests/Feature/SimulationTemporelleCatalogueTest.php` (2 tests ajoutés)
- **Tests :** **7 tests / 56 assertions** (SIM-01) + **12 tests / 292 assertions** (catalogue)
- **Non-régression :** 52 tests / 312 assertions inchangés
- **Suite module :** **71 tests / 660 assertions**, 0 échec
- **Pint :** OK
- **Écriture métier :** Non
- **Réserves :** SIM01-R1 → SIM01-R8 (voir `09-verdict.md`)
- **Preuves :** `ST-14/SIM-01/00-audit.md` → `09-verdict.md`
- **Verdict :** ✅ **PASS_WITH_RESERVATIONS** (2026-11-02)

---

## 2 ter. LOT 0 — Audit et stabilisation du socle

- **Statut :** ✅ **PASS_WITH_RESERVATIONS** (2026-11-02)
- **Preuve :** `ST-14/LOT-0/00-audit-socle.md`
- **Périmètre audité :** 27 fichiers PHP, 17 routes, 7 permissions,
  3 tables techniques, 2 vues, 3 fichiers de tests.
- **Tests exécutés :** **71 tests / 660 assertions**, 0 échec
  (`SimulationTemporelleTest` 52/312 + `SimulationTemporelleCatalogueTest`
  12/292 + `SimulationTemporelleSim01Test` 7/56).
- **Réconciliation :** ST-10 (périmètre antérieur), ST-11 et ST-12
  (`PASS_WITH_RESERVATIONS`), ST-14.00 et SIM-01
  (`PASS_WITH_RESERVATIONS`) — aucune contradiction bloquante.
- **Nouvelles réserves :** `L0-R1` → `L0-R10` (voir audit).
- **Décision :** premier scénario fonctionnel non clôturé = **SIM-02**.

---

## 2 quater. SIM-02 — Baisse des admissions

- **Version :** 1 (formules 1.0)
- **Statut :** `disponible`
- **Compatibilité confirmée :** `configuration` (audit du 2026-11-02)
- **Fichiers modifiés :**
  - `Support/CatalogueScenarios.php` (`schemaSim02()`, règles `validerReglesSim02()`)
  - `tests/Feature/SimulationTemporelleSim02Test.php` (créé, 7 tests)
  - `tests/Feature/SimulationTemporelleCatalogueTest.php` (validation SIM-02)
- **Tests :** **7 tests / 56 assertions** (SIM-02) + **13 tests / 307 assertions** (catalogue)
- **Non-régression :** 52 tests / 312 assertions inchangés
- **Suite module :** **79 tests / 731 assertions**, 0 échec
- **Pint :** OK
- **Écriture métier :** Non
- **Réserves :** SIM02-R1 → SIM02-R5 (voir `09-verdict.md`)
- **Preuves :** `ST-14/SIM-02/00-audit.md` → `09-verdict.md`
- **Verdict :** ✅ **PASS_WITH_RESERVATIONS** (2026-11-05)

---

## 2 quinquies. SIM-03 — Gel des admissions

- **Version :** 1 (formules 1.0)
- **Statut :** `disponible`
- **Compatibilité confirmée :** `extension_temporelle` (audit du 2026-11-02)
- **Fichiers modifiés :**
  - `Services/ServiceSimulationTemporelle.php` (version moteur `1.2`, `duree_gel_annees`, interruption temporaire)
  - `Support/CatalogueScenarios.php` (`sim03()`, `schemaSim03()`, règles `validerReglesSim03()`)
  - `tests/Feature/SimulationTemporelleSim03Test.php` (créé, 7 tests)
  - `tests/Feature/SimulationTemporelleCatalogueTest.php` (test validation gel strict et durée)
- **Tests :** **7 tests / 53 assertions** (SIM-03) + **14 tests / 323 assertions** (catalogue)
- **Non-régression :** 52 tests historiques, 7 SIM-01, 7 SIM-02 inchangés
- **Suite module :** **87 tests / 800 assertions**, 0 échec
- **Pint :** OK
- **Écriture métier :** Non
- **Réserves :** SIM03-R1 → SIM03-R4 (voir `09-verdict.md`)
- **Preuves :** `ST-14/SIM-03/00-audit.md` → `09-verdict.md`
- **Verdict :** ✅ **PASS_WITH_RESERVATIONS** (2026-11-02)

---

## 2 sexies. SIM-04 — Admissions exceptionnelles

- **Version :** 1 (formules 1.0)
- **Statut :** `disponible`
- **Compatibilité confirmée :** `extension_temporelle` (audit du 2026-11-03)
- **Fichiers modifiés :**
  - `Services/ServiceSimulationTemporelle.php` (version moteur `1.3`,
    `pas_exceptionnel` + `admissions_exceptionnelles`, choc additif ponctuel,
    propagation dans la closure sensibilité/Monte-Carlo)
  - `Support/CatalogueScenarios.php` (`sim04()`, `schemaSim04()`, règles
    `validerReglesSim04()`, retrait de `planifies()`)
  - `tests/Feature/SimulationTemporelleSim04Test.php` (créé, 7 tests)
  - `tests/Feature/SimulationTemporelleCatalogueTest.php` (5 disponibles /
    40 planifiés, SIM-04 disponible, test des règles SIM-04)
- **Tests :** **7 tests / 54 assertions** (SIM-04) + **15 tests / 345 assertions** (catalogue)
- **Non-régression :** 87 tests / 800 assertions inchangés
- **Suite module :** **95 tests / 876 assertions**, 0 échec
- **Pint :** OK
- **Écriture métier :** Non
- **Réserves :** SIM04-R1 → SIM04-R8 (voir `09-verdict.md`)
- **Preuves :** `ST-14/SIM-04/00-audit.md` → `09-verdict.md`
- **Verdict :** ✅ **PASS_WITH_RESERVATIONS** (2026-11-03)

---

## 2 septies. SIM-05 — Croissance supérieure à la cible

- **Version :** 1 (formules 1.0)
- **Statut :** `disponible`
- **Compatibilité confirmée :** `analyse_nouvelle` (reclassée par l'audit du
  2026-11-03 — la cible constante `cible_total` ne modélise pas une
  trajectoire de croissance)
- **Fichiers modifiés :**
  - `Services/ServiceSimulationTemporelle.php` (version moteur `1.4`,
    `BORNE_TAUX_CIBLE_MIN`, validation `taux_cible_croissance`,
    `analyserCibleCroissance()`, `champsCibleCroissance()`, exposition dans
    `classement` / `non_conformes`)
  - `Support/CatalogueScenarios.php` (`sim05()`, `schemaSim05()`, règles
    `validerReglesSim05()`, retrait de `planifies()`)
  - `tests/Feature/SimulationTemporelleSim05Test.php` (créé, 7 tests)
  - `tests/Feature/SimulationTemporelleCatalogueTest.php` (6 disponibles /
    39 planifiés, SIM-05 disponible (index 5), test des règles SIM-05)
- **Tests :** **7 tests / 68 assertions** (SIM-05) + **16 tests / 369 assertions** (catalogue)
- **Non-régression :** 95 tests / 876 assertions inchangés
- **Suite module :** **103 tests / 968 assertions**, 0 échec
- **Pint :** OK
- **Écriture métier :** Non
- **Réserves :** SIM05-R1 → SIM05-R8 (voir `09-verdict.md`)
- **Preuves :** `ST-14/SIM-05/00-audit.md` → `09-verdict.md`
- **Verdict :** ✅ **PASS_WITH_RESERVATIONS** (2026-11-03)

---

## 2 octies. SIM-06 — Récupération démographique

- **Version :** 1 (formules 1.0)
- **Statut :** `disponible`
- **Compatibilité confirmée :** `extension_temporelle` (audit du 2026-11-03)
- **Fichiers modifiés :**
  - `Services/ServiceSimulationTemporelle.php` (version moteur `1.5`,
    `facteurRecuperation()`, `analyserRecuperation()`, branche creux de
    `simulerVariante`, snapshots enrichis, propagation sensibilité/MC,
    garde d'exécution, helpers `nombreSaisi`/`entierSaisi`,
    `champsCibleCroissance` → `champsSpecifiques`)
  - `Support/CatalogueScenarios.php` (`sim06()`, `schemaSim06()`, règles
    `validerReglesSim06()`, retrait de `planifies()`)
  - `tests/Feature/SimulationTemporelleSim06Test.php` (créé, 8 tests)
  - `tests/Feature/SimulationTemporelleCatalogueTest.php` (7 disponibles /
    38 planifiés, SIM-06 disponible (index 6), test des règles SIM-06)
- **Tests :** **8 tests / 87 assertions** (SIM-06) + **17 tests / 399 assertions** (catalogue)
- **Non-régression :** 103 tests / 968 assertions inchangés
- **Suite module :** **112 tests / 1085 assertions**, 0 échec
- **Pint :** OK
- **Écriture métier :** Non
- **Réserves :** SIM06-R1 → SIM06-R8 (voir `09-verdict.md`)
- **Preuves :** `ST-14/SIM-06/00-audit.md` → `09-verdict.md`
- **Verdict :** ✅ **PASS_WITH_RESERVATIONS** (2026-11-03)

---

## 2 nonies. SIM-07 — Amélioration de la réussite

- **Version :** 1 (formules 1.0)
- **Statut :** `disponible`
- **Compatibilité confirmée :** `analyse_nouvelle` (reclassée par l'audit du
  2026-11-04 — double jeu de taux déclaré + restitution des sortants,
  impossibles par simple configuration)
- **Fichiers modifiés :**
  - `Services/ServiceSimulationTemporelle.php` (version moteur `1.6`,
    capture des `sortants` (3ᵉ retour de `simulerVariante`), trajectoire de
    référence parallèle, `analyserReussite()`, `criteresAnalyses` pour
    OAT/MC, `champsSpecifiques()` étendu, validation SIM-07)
  - `Support/CatalogueScenarios.php` (`sim07()`, `schemaSim07()`, règles
    `validerReglesSim07()`, retrait de `planifies()`)
  - `tests/Feature/SimulationTemporelleSim07Test.php` (créé, 7 tests)
  - `tests/Feature/SimulationTemporelleCatalogueTest.php` (8 disponibles /
    37 planifiés, SIM-07 disponible (index 7), test des règles SIM-07)
- **Tests :** **7 tests / 70 assertions** (SIM-07) + **18 tests / 424 assertions** (catalogue)
- **Non-régression :** 112 tests / 1085 assertions inchangés
- **Suite module :** **120 tests / 1180 assertions**, 0 échec
- **Pint :** OK
- **Écriture métier :** Non
- **Réserves :** SIM07-R1 → SIM07-R9 (voir `09-verdict.md`)
- **Preuves :** `ST-14/SIM-07/00-audit.md` → `09-verdict.md`
- **Verdict :** ✅ **PASS_WITH_RESERVATIONS** (2026-11-04)

---

## 2 decies. SIM-08 — Dégradation de la réussite

- **Version :** 1 (formules 1.0)
- **Statut :** `disponible`
- **Compatibilité confirmée :** `analyse_nouvelle` (reclassée par l'audit du
  2026-11-04 — miroir de SIM-07, symétrie documentée)
- **Fichiers modifiés :**
  - `Services/ServiceSimulationTemporelle.php` (version moteur `1.7`,
    `$tauxDegrade`, garde SIM-07 étendue à l'exclusion mutuelle,
    `analyserReussite()` généralisé (`$cleVariation` + magnitude),
    boucle `$tauxSimule`, `criteresAnalyses` en `elseif`,
    `champsSpecifiques()` += `baisse_passage`, validation SIM-08)
  - `Support/CatalogueScenarios.php` (`sim08()`, `schemaSim08()`, règles
    `validerReglesSim08()`, retrait de `planifies()`)
  - `tests/Feature/SimulationTemporelleSim08Test.php` (créé, 7 tests)
  - `tests/Feature/SimulationTemporelleCatalogueTest.php` (9 disponibles /
    36 planifiés, SIM-08 disponible (index 8), test des règles SIM-08)
- **Tests :** **7 tests / 73 assertions** (SIM-08) + **19 tests / 450 assertions** (catalogue)
- **Non-régression :** 120 tests / 1180 assertions inchangés (SIM-07 inclus)
- **Suite module :** **128 tests / 1279 assertions**, 0 échec
- **Pint :** OK (premier passage)
- **Écriture métier :** Non
- **Réserves :** SIM08-R1 → SIM08-R9 (voir `09-verdict.md`)
- **Preuves :** `ST-14/SIM-08/00-audit.md` → `09-verdict.md`
- **Verdict :** ✅ **PASS_WITH_RESERVATIONS** (2026-11-04)

---

## 2 undecies. SIM-09 — Hausse du redoublement

- **Version :** 1 (formules 1.0)
- **Statut :** `disponible`
- **Compatibilité confirmée :** `analyse_nouvelle` (reclassée par l'audit du
  2026-11-05 — descripteur d'axe générique, pas une simple configuration)
- **Fichiers modifiés :**
  - `Services/ServiceSimulationTemporelle.php` (version moteur `1.8`,
    helper `aVariationGenerique()`, gardes d'exécution génériques,
    descripteur commun `$reussiteTaux` / `$reussiteCle` / `$reussiteVariation`,
    validation de définition générique, `champsSpecifiques()` +=
    `hausse_redoublement`, `baisse_redoublement`, `hausse_abandon`,
    `baisse_abandon`, OAT/MC recentrés sur le jeu modifié)
  - `Support/CatalogueScenarios.php` (`sim09()`, `schemaSim09()`, règles
    `validerReglesSim09()`, branche `validerParametres`, retrait de
    `planifies()`)
  - `tests/Feature/SimulationTemporelleSim09Test.php` (créé, 7 tests)
  - `tests/Feature/SimulationTemporelleCatalogueTest.php` (10 disponibles /
    35 planifiés, SIM-09 disponible (index 9), test des règles SIM-09)
- **Tests :** **7 tests / 83 assertions** (SIM-09) + **20 tests / 485 assertions** (catalogue)
- **Non-régression :** 128 tests / 1279 assertions inchangés (SIM-07 et SIM-08 inclus)
- **Suite module :** **136 tests / 1397 assertions**, 0 échec
- **Pint :** OK (premier passage)
- **Écriture métier :** Non
- **Réserves :** SIM09-R1 → SIM09-R9 (voir `09-verdict.md`)
- **Preuves :** `ST-14/SIM-09/00-audit.md` → `09-verdict.md`
- **Verdict :** ✅ **PASS_WITH_RESERVATIONS** (2026-11-05)

---

## 2 duodecies. SIM-10 — Réduction du redoublement

- **Version :** 1 (formules 1.0)
- **Statut :** `disponible`
- **Compatibilité confirmée :** `analyse_nouvelle` (reclassée par l'audit du
  2026-11-05 — même famille de justification que SIM-07/08/09 : double
  trajectoire et analyse d'un axe déclaré, non réalisables par configuration)
- **Fichiers modifiés :**
  - `Support/CatalogueScenarios.php` (`sim10()`, `schemaSim10()`, règles
    `validerReglesSim10()`, branche `validerParametres`, ajout à la liste
    des définitions, retrait de `planifies()`)
  - `tests/Feature/SimulationTemporelleSim10Test.php` (créé, 7 tests)
  - `tests/Feature/SimulationTemporelleCatalogueTest.php` (11 disponibles /
    34 planifiés, SIM-10 disponible (index 10), test des règles SIM-10)
- **Moteur :** **aucun changement** — `ServiceSimulationTemporelle` reste en
  version `1.8` ; le descripteur générique et le sens `baisse` livrés par
  SIM-09 sont réutilisés tels quels
- **Tests :** **7 tests / 85 assertions** (SIM-10) + **21 tests / 520 assertions** (catalogue)
- **Non-régression :** 136 tests / 1397 assertions inchangés (SIM-07 → SIM-09 inclus)
- **Suite module :** **144 tests / 1517 assertions**, 0 échec
- **Pint :** OK (premier passage)
- **Écriture métier :** Non
- **Réserves :** SIM10-R1 → SIM10-R9 (voir `09-verdict.md`)
- **Preuves :** `ST-14/SIM-10/00-audit.md` → `09-verdict.md`
- **Verdict :** ✅ **PASS_WITH_RESERVATIONS** (2026-11-05)

---

## 2 terdecies. SIM-11 — Hausse de l'abandon

- **Version :** 1 (formules 1.0)
- **Statut :** `disponible`
- **Compatibilité confirmée :** `analyse_nouvelle` (reclassée par l'audit du
  2026-11-05 — même famille de justification que SIM-07/08/09/10 : double
  trajectoire et analyse d'un axe déclaré, non réalisables par configuration)
- **Fichiers modifiés :**
  - `Support/CatalogueScenarios.php` (`sim11()`, `schemaSim11()`, règles
    `validerReglesSim11()`, branche `validerParametres`, ajout à la liste
    des définitions, retrait de `planifies()`)
  - `tests/Feature/SimulationTemporelleSim11Test.php` (créé, 7 tests)
  - `tests/Feature/SimulationTemporelleCatalogueTest.php` (12 disponibles /
    33 planifiés, SIM-11 disponible (index 11), test des règles SIM-11)
- **Moteur :** **aucun changement** — `ServiceSimulationTemporelle` reste en
  version `1.8` ; le descripteur générique et le sens `hausse` livrés par
  SIM-09 sont réutilisés tels quels
- **Point structurel :** `abandon` n'est pas réinjecté ; une hausse absorbée
  par le slack est **exactement neutre** (cas B : écarts `[0,0]`, effet `0`).
  Aucun effet n'est attribué au seul `abandon` ; aucune ligne de sensibilité
  `taux_abandon` n'est inventée.
- **Tests :** **7 tests / 88 assertions** (SIM-11) + **22 tests / 555 assertions** (catalogue)
- **Non-régression :** 144 tests / 1517 assertions inchangés (SIM-07 → SIM-10 inclus)
- **Suite module :** **152 tests / 1640 assertions**, 0 échec
- **Pint :** OK
- **Écriture métier :** Non
- **Incident :** 6/7 au premier passage (écart de calcul manuel du cas C,
  corrigé : `[60,38,32]`, écarts `[-3,-3]`) ; aucune assertion affaiblie
- **Réserves :** SIM11-R1 → SIM11-R11 (voir `09-verdict.md`)
- **Preuves :** `ST-14/SIM-11/00-audit.md` → `09-verdict.md`
- **Verdict :** ✅ **PASS_WITH_RESERVATIONS** (2026-11-05)

---

## 2 quaterdecies. SIM-12 — Réduction de l'abandon

- **Version :** 1 (formules 1.0)
- **Statut :** `disponible`
- **Compatibilité confirmée :** `analyse_nouvelle` (reclassée par l'audit du
  2026-11-05 — même famille de justification que SIM-07/08/09/10/11 : double
  trajectoire et analyse d'un axe déclaré, non réalisables par configuration)
- **Fichiers modifiés :**
  - `Support/CatalogueScenarios.php` (`sim12()`, `schemaSim12()`, règles
    `validerReglesSim12()`, branche `validerParametres`, ajout à la liste
    des définitions, retrait de `planifies()`)
  - `tests/Feature/SimulationTemporelleSim12Test.php` (créé, 7 tests)
  - `tests/Feature/SimulationTemporelleCatalogueTest.php` (13 disponibles /
    32 planifiés, SIM-12 disponible (index 12), test des règles SIM-12)
- **Moteur :** **aucun changement** — `ServiceSimulationTemporelle` reste en
  version `1.8` ; le descripteur générique et le sens `baisse` livrés par
  SIM-09 sont réutilisés tels quels
- **Point structurel :** `abandon` n'est pas réinjecté ; une baisse absorbée
  par le slack est **exactement neutre** (cas B : écarts `[0,0]`, effet `0`).
  Aucun effet n'est attribué au seul `abandon` ; aucune ligne de sensibilité
  `taux_abandon` n'est inventée.
- **Tests :** **7 tests / 88 assertions** (SIM-12) + **23 tests / 590 assertions** (catalogue)
- **Non-régression :** 152 tests / 1640 assertions inchangés (SIM-01 → SIM-11 inclus)
- **Suite module :** **160 tests / 1763 assertions**, 0 échec
- **Pint :** OK (premier passage)
- **Écriture métier :** Non
- **Incident :** aucun — 7/7 dès le premier passage
- **Réserves :** SIM12-R1 → SIM12-R12 (voir `09-verdict.md`)
- **Preuves :** `ST-14/SIM-12/00-audit.md` → `09-verdict.md`
- **Verdict :** ✅ **PASS_WITH_RESERVATIONS** (2026-11-05)

---

## 3. Les sept familles — état de couverture

| # | Famille | Scénarios | État | Preuves / réserves |
|---|---|---|---|---|
| 1 | Démographie et admissions | SIM-01 → SIM-06 | **Complète** (SIM-01 → SIM-06 clôturés) | `SIM-01/09-verdict.md`, `SIM-02/09-verdict.md`, `SIM-03/09-verdict.md`, `SIM-04/09-verdict.md`, `SIM-05/09-verdict.md`, `SIM-06/09-verdict.md` |
| 2 | Réussite, redoublement, abandon | SIM-07 → SIM-13 | **Partielle** (SIM-07 → SIM-12 clôturés ; SIM-13 reste) | `SIM-07/09-verdict.md`, `SIM-08/09-verdict.md`, `SIM-09/09-verdict.md`, `SIM-10/09-verdict.md`, `SIM-11/09-verdict.md`, `SIM-12/09-verdict.md` ; moteur à taux globaux |
| 3 | Cohortes et progression LMD | SIM-14 → SIM-19 | **NOT_STARTED** | `L0-R8` (règles LMD non validées) |
| 4 | Capacités et infrastructures | SIM-20 → SIM-25 | **NOT_STARTED** | `L0-R7` (capacité par défaut 35) |
| 5 | Budgets et ressources | SIM-26 → SIM-30 | **NOT_STARTED** | `L0-R6` (aucun coût validé) |
| 6 | Crise et résilience | CR-01 → CR-06 | **NOT_STARTED** | dépend de A + C |
| 7 | Sensibilité et incertitude | UNC-01 → UNC-08 | **NOT_STARTED** | OAT/MC/Pareto existants, non contractualisés |

> `NOT_STARTED` ≠ indisponible techniquement : une capacité du moteur ne
> devient un scénario qu'après contrat, tests et verdict.

---

## 3 bis. Scénarios stratégiques A / B / C

| Scénario | Familles | État | Dépendances | Verdict |
|---|---|---|---|---|
| A — Pression démographique et saturation | 1, 3, 4, 5, 7 | `NOT_STARTED` | SIM-02→06, SIM-14→19, SIM-20→25, SIM-26→30, UNC | — |
| B — Amélioration de la réussite académique | 2, 3, 4, 5, 7 | `NOT_STARTED` | SIM-07→13, SIM-14→19, SIM-20→25, SIM-26→30, UNC | — |
| C — Crise et plan de résilience | 2, 4, 5, 6, 7 | `NOT_STARTED` | SIM-07→13, SIM-20→25, SIM-26→30, CR-01→06, UNC | — |

Aucun scénario transversal n'est opérationnel : l'intégration stratégique
ne démarre qu'après clôture individuelle des familles mobilisées.

---

## 4. Registre des 44 scénarios

Colonnes de suivi : **A** audit · **B** spécification · **C** conception ·
**D** développement · **E** validation mathématique · **F** sécurité ·
**G** tests · **H** UX · **I** documentation · **J** verdict.

### LOT A — Admissions, démographie et réussite (SIM-01 → SIM-13)

| Code | Nom | Catégorie | Compatibilité préliminaire | Dépendances | A | B | C | D | E | F | G | H | I | J |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| SIM-01 | Croissance des admissions | admissions_demographie | configuration (confirmée) | SOCLE-EFFECTIFS | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ **PASS_WITH_RESERVATIONS** |
| SIM-02 | Baisse des admissions | admissions_demographie | configuration (confirmée) | SOCLE-EFFECTIFS | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ **PASS_WITH_RESERVATIONS** |
| SIM-03 | Gel des admissions | admissions_demographie | extension_temporelle (confirmée) | SOCLE-EFFECTIFS | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ **PASS_WITH_RESERVATIONS** |
| SIM-04 | Admissions exceptionnelles | admissions_demographie | extension_temporelle (confirmée) | SOCLE-EFFECTIFS | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ **PASS_WITH_RESERVATIONS** |
| SIM-05 | Croissance supérieure à la cible | admissions_demographie | analyse_nouvelle (confirmée) | SOCLE-EFFECTIFS | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ **PASS_WITH_RESERVATIONS** |
| SIM-06 | Récupération démographique | admissions_demographie | extension_temporelle (confirmée) | SOCLE-EFFECTIFS | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ **PASS_WITH_RESERVATIONS** |
| SIM-07 | Amélioration de la réussite | reussite_flux | analyse_nouvelle (confirmée) | SOCLE-EFFECTIFS | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ **PASS_WITH_RESERVATIONS** |
| SIM-08 | Dégradation de la réussite | reussite_flux | analyse_nouvelle (confirmée) | SOCLE-EFFECTIFS | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ **PASS_WITH_RESERVATIONS** |
| SIM-09 | Hausse du redoublement | reussite_flux | analyse_nouvelle (confirmée) | SOCLE-EFFECTIFS | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ **PASS_WITH_RESERVATIONS** |
| SIM-10 | Réduction du redoublement | reussite_flux | analyse_nouvelle (confirmée) | SOCLE-EFFECTIFS | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ **PASS_WITH_RESERVATIONS** |
| SIM-11 | Hausse de l'abandon | reussite_flux | analyse_nouvelle (confirmée) | SOCLE-EFFECTIFS | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ **PASS_WITH_RESERVATIONS** |
| SIM-12 | Réduction de l'abandon | reussite_flux | analyse_nouvelle (confirmée) | SOCLE-EFFECTIFS | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ **PASS_WITH_RESERVATIONS** |
| SIM-13 | Crise académique combinée | reussite_flux | configuration | SIM-07→12 | — | — | — | — | — | — | — | — | — | — |

### LOT B — Cohortes et progression LMD (SIM-14 → SIM-19)

| Code | Nom | Catégorie | Compatibilité préliminaire | Dépendances | A | B | C | D | E | F | G | H | I | J |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| SIM-14 | Blocage en L1 | cohortes_lmd | extension_par_niveau | — | — | — | — | — | — | — | — | — | — | — |
| SIM-15 | Blocage en L2 | cohortes_lmd | extension_par_niveau | SIM-14 | — | — | — | — | — | — | — | — | — | — |
| SIM-16 | Flux déséquilibrés entre niveaux | cohortes_lmd | extension_par_niveau | SIM-14, SIM-15 | — | — | — | — | — | — | — | — | — | — |
| SIM-17 | Cohorte exceptionnelle | cohortes_lmd | extension_cohorte | — | — | — | — | — | — | — | — | — | — | — |
| SIM-18 | Double génération | cohortes_lmd | extension_cohorte | SIM-17 | — | — | — | — | — | — | — | — | — | — |
| SIM-19 | Rééquilibrage des niveaux | cohortes_lmd | extension_par_niveau | SIM-14→16 | — | — | — | — | — | — | — | — | — | — |

### LOT C — Capacités et infrastructures (SIM-20 → SIM-25)

| Code | Nom | Catégorie | Compatibilité préliminaire | Dépendances | A | B | C | D | E | F | G | H | I | J |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| SIM-20 | Saturation des capacités | capacites_infrastructures | extension_capacite | — | — | — | — | — | — | — | — | — | — | — |
| SIM-21 | Extension des capacités | capacites_infrastructures | extension_capacite | SIM-20 | — | — | — | — | — | — | — | — | — | — |
| SIM-22 | Perte temporaire de capacité | capacites_infrastructures | extension_capacite | SIM-20 | — | — | — | — | — | — | — | — | — | — |
| SIM-23 | Création théorique de groupes | capacites_infrastructures | extension_capacite | SIM-20 | — | — | — | — | — | — | — | — | — | — |
| SIM-24 | Réduction de capacité par groupe | capacites_infrastructures | extension_capacite | SIM-20 | — | — | — | — | — | — | — | — | — | — |
| SIM-25 | Répartition déséquilibrée des groupes | capacites_infrastructures | extension_repartition | SIM-20 | — | — | — | — | — | — | — | — | — | — |

### LOT D — Budget et ressources (SIM-26 → SIM-30)

| Code | Nom | Catégorie | Compatibilité préliminaire | Dépendances | A | B | C | D | E | F | G | H | I | J |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| SIM-26 | Budget constant | budget_ressources | extension_budgetaire | — | — | — | — | — | — | — | — | — | — | — |
| SIM-27 | Réduction budgétaire | budget_ressources | extension_budgetaire | SIM-26 | — | — | — | — | — | — | — | — | — | — |
| SIM-28 | Coût supplémentaire par groupe | budget_ressources | extension_budgetaire | SIM-26 | — | — | — | — | — | — | — | — | — | — |
| SIM-29 | Investissement de capacité | budget_ressources | extension_budgetaire | SIM-21, SIM-28 | — | — | — | — | — | — | — | — | — | — |
| SIM-30 | Croissance sous contrainte budgétaire | budget_ressources | extension_budgetaire | SIM-26→29 | — | — | — | — | — | — | — | — | — | — |

### LOT E — Crises et résilience (CR-01 → CR-06)

| Code | Nom | Catégorie | Compatibilité préliminaire | Dépendances | A | B | C | D | E | F | G | H | I | J |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| CR-01 | Choc négatif sur les admissions | crises_resilience | extension_temporelle | SIM-02, SIM-04 | — | — | — | — | — | — | — | — | — | — |
| CR-02 | Crise de réussite et de rétention | crises_resilience | configuration | SIM-09, SIM-11, SIM-13 | — | — | — | — | — | — | — | — | — | — |
| CR-03 | Admissions élevées et réussite dégradée | crises_resilience | configuration | SIM-01, SIM-08 | — | — | — | — | — | — | — | — | — | — |
| CR-04 | Réduction importante des capacités | crises_resilience | extension_capacite | SIM-22, SIM-24 | — | — | — | — | — | — | — | — | — | — |
| CR-05 | Retour progressif après une crise | crises_resilience | extension_temporelle | SIM-06, CR-01 | — | — | — | — | — | — | — | — | — | — |
| CR-06 | Crises successives | crises_resilience | extension_temporelle | CR-01→05 | — | — | — | — | — | — | — | — | — | — |

### LOT F — Sensibilité et incertitude (UNC-01 → UNC-08)

| Code | Nom | Catégorie | Compatibilité préliminaire | Dépendances | A | B | C | D | E | F | G | H | I | J |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| UNC-01 | Sensibilité combinée | incertitude_analyses | analyse_nouvelle | SIM-07→11 | — | — | — | — | — | — | — | — | — | — |
| UNC-02 | Stress testing | incertitude_analyses | analyse_nouvelle | SIM-13, CR-01→04 | — | — | — | — | — | — | — | — | — | — |
| UNC-03 | Analyse de robustesse | incertitude_analyses | analyse_nouvelle | UNC-02 | — | — | — | — | — | — | — | — | — | — |
| UNC-04 | Comparaison probabiliste | incertitude_analyses | analyse_nouvelle | UNC-02 | — | — | — | — | — | — | — | — | — | — |
| UNC-05 | Détection des seuils de rupture | incertitude_analyses | analyse_nouvelle | SIM-20, CR-01 | — | — | — | — | — | — | — | — | — | — |
| UNC-06 | Analyse de récupération | incertitude_analyses | analyse_nouvelle | SIM-06, CR-05 | — | — | — | — | — | — | — | — | — | — |
| UNC-07 | Dépendances et interactions | incertitude_analyses | analyse_nouvelle | UNC-01, UNC-04 | — | — | — | — | — | — | — | — | — | — |
| UNC-08 | Stabilité de la frontière de Pareto | incertitude_analyses | analyse_nouvelle | SIM-30, UNC-03 | — | — | — | — | — | — | — | — | — | — |

### Campagne d'intégration

| Code | Nom | A | B | C | D | E | F | G | H | I | J |
|---|---|---|---|---|---|---|---|---|---|---|---|
| ST-14.45 | Intégration globale (INT-001 → INT-015) | — | — | — | — | — | — | — | — | — | — |

> ST-14.45 ne peut démarrer qu'après clôture individuelle des 44 LOOP.

---

## 5. Dépendances structurantes

```
SOCLE-EFFECTIFS (ST-14.00, disponible)
   │
   ├── LOT A : SIM-01 → SIM-13 (config + extension_temporelle)
   │      └── SIM-09/SIM-11 → SIM-10/SIM-12 → SIM-13
   │
   ├── LOT B : SIM-14 → SIM-15 → SIM-16 → SIM-19
   │      └── SIM-17 → SIM-18 (identité de cohorte)
   │
   ├── LOT C : SIM-20 → SIM-21/SIM-22/SIM-23/SIM-24/SIM-25
   │      └── SIM-20 → LOT D (coûts par groupe)
   │
   ├── LOT D : SIM-26 → SIM-27/SIM-28 → SIM-29 → SIM-30
   │
   ├── LOT E : CR-01..CR-06 (dépendent de A + C)
   │
   ├── LOT F : UNC-01..UNC-08 (dépendent de A→E)
   │
   └── ST-14.45 : intégration (dépend de tout)
```

**Contraintes de séquencement :**

- Un seul LOOP fonctionnel à la fois.
- Les audits peuvent être parallélisés uniquement s'ils ne touchent pas les
  mêmes services ; le développement reste strictement séquentiel.
- Un scénario `BLOCKED_BUSINESS` ne bloque pas les scénarios indépendants.
- Aucune extension du moteur sans mesure d'impact sur les scénarios existants.

---

## 6. État Git et protections

| Élément | État |
|---|---|
| HEAD | `a8b3f0b` (merge préexistant du 2026-10-29) |
| Branche | `main` |
| Fichiers suivis modifiés | 66 |
| Fichiers suivis supprimés | 1 (`config/cache.php`, hors périmètre ST-14) |
| Entrées non suivies (`git status`) | 211 |
| Entrées WIP totales | **278** |
| Commits créés | **Aucun** |
| Push / branche / PR | **Aucun** |
| Corrections ST-11 / ST-12 | Non commitées, préservées |
| Docs ST-11 / ST-12 | Non trackées, préservées |
| Fichiers ST-14 | Nouveaux, non trackés |
| Données métier | **Aucune écriture** |
| Migrations destructives | **Aucune** |

---

## 7. Totaux de référence

| Périmètre | Tests | Assertions |
|---|---|---|
| Suite historique ST-11/ST-12 | 52 | 312 |
| ST-14.00 — catalogue (à la clôture) | 10 | 277 |
| SIM-01 — croissance | 7 | 56 |
| SIM-02 — baisse | 7 | 56 |
| SIM-03 — gel des admissions | 7 | 53 |
| SIM-04 — admissions exceptionnelles | 7 | 54 |
| SIM-05 — croissance supérieure à la cible | 7 | 68 |
| SIM-06 — récupération démographique | 8 | 87 |
| SIM-07 — amélioration de la réussite | 7 | 70 |
| SIM-08 — dégradation de la réussite | 7 | 73 |
| SIM-09 — hausse du redoublement | 7 | 83 |
| SIM-10 — réduction du redoublement | 7 | 85 |
| SIM-11 — hausse de l'abandon | 7 | 88 |
| SIM-12 — réduction de l'abandon | 7 | 88 |
| Catalogue courant (ST-14.00 + SIM-01→12) | 23 | 590 |
| **Total module Simulation temporelle** | **160** | **1763** |

Référence non directement comparable : ST-10 (64 tests / 342 assertions,
périmètre antérieur différent).

---

## 8. Modèle de fiche par scénario (à remplir à chaque LOOP)

```markdown
### <CODE> — <Nom>

- **Version :** <n> (formules <x.y>)
- **Statut :** planifie | disponible | bloque_business
- **Compatibilité confirmée :** <clé> (audit du <date>)
- **Fichiers modifiés :** <liste>
- **Tests :** <n> tests / <m> assertions (fichier)
- **Non-régression :** <résultat>
- **Pint :** <résultat>
- **Écriture métier :** Non
- **Réserves :** <IDs>
- **Preuves :** <chemins docs>
- **Verdict :** <verdict> (<date>)
```
