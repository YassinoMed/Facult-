# LOOP GLOBAL MASTER — ACADÉMIQUE & EXAMENS · 02 · Corrections ACQ-RED-01

**Date :** 2026-11-02 · **Nature :** triage des 13 tests rouges identifiés au baseline (`01-baseline.md` §4)
**Règle appliquée :** aucune assertion de test modifiée ; aucune règle métier inventée ; corrections côté services uniquement, dérivées de contrats DÉJÀ documentés (LOOP 40/41/44) ou du référentiel existant.

---

## CORR-01 — Méthode `evaluerCouvertureReglementaire` absente (11 tests)

- **Symptôme :** `PiloteAcademiqueLoop42Test` — 9 erreurs (`Undefined array key 0`) + 2 échecs. `executerSimulation()` retournait `statut=EXECUTE` avec `resultats=[]`.
- **Cause racine :** `PilotAcademiqueService.php:318` appelle `ReglementationAcquisService::evaluerCouvertureReglementaire()` — méthode **absente** du service (10 méthodes publiques, aucune correspondante). Chaque dossier levait `BadMethodCallException`, capturée par le `try/catch` par dossier de `executerSimulation()` → comptée dans `erreurs_techniques`, jamais dans `resultats`.
- **Référence du contrat :** `docs/engineering/LOOP-41-RECONCILIATION.md` §ÉCAR-41-02 : « méthode additive `ReglementationAcquisService::evaluerCouvertureReglementaire()` qui distingue `FORMATION`, `REGIME`, `AUCUNE` et n'autorise l'automatisme que pour `FORMATION` confirmée ou absence confirmée » ; consommateur : `PilotAcademiqueService` (valeurs `'FORMATION'`/`'NATIONALE'`, `regle_formation_confirmee`).
- **Correction :** méthode **additive restaurée** dans `ReglementationAcquisService`, strictement dérivée des données existantes (`evaluerStatutRegle` + `resoudreRegime`) :
  - `FORMATION` (fiche confirmée — règle OU absence confirmée) → `applicable_automatiquement=true`, `revue_humaine_requise=false` ;
  - `NATIONALE` (régime national identifiable, fiche absente/expirée) → automatisme refusé, revue humaine requise ;
  - `AUCUNE` (ni fiche ni régime) → fail-closed, revue humaine requise.
- **Preuve :** `PiloteAcademiqueLoop42Test` **20/20** (85 assertions), dont le cas `LIC_INFO_STANDARD` (`ABSENCE_CONFIRMEE` au référentiel) qui exerce le niveau `FORMATION`.

## CORR-02 — Incertitude réglementaire bloquant la validation humaine (2 tests)

- **Symptôme :** `DecisionAcademiqueWorkflowTest` — auto-approbation non détectée (message réglementaire au lieu de « Auto-approbation strictement interdite ») et approbation par second acteur impossible (« contrôles non conformes [Incertitude réglementaire … LIC-ECO non répertoriée] »).
- **Cause racine :** `controlerPlan` traitait TOUTE anomalie comme bloquante — y compris l'incertitude réglementaire dont le motif dit explicitement « aucune décision d'exclusion automatique n'est prise. Dossier transmis pour revue humaine obligatoire » (Gate G40, LOOP 40). La validation humaine — précisément la revue exigée — était donc impossible : **contradiction interne**, aucun chemin de résolution de `revue_humaine_requise` n'existant dans l'application (vérifié par grep : seuls le producteur et le référentiel consomment ce champ).
- **Correction :** `ValidationApprobationPlanService::controlerPlan` distingue désormais :
  - `avertissements_revue_humaine` (nouvelle clé de retour) : incertitudes réglementaires — **non bloquantes**, jamais masquées (la validation par l'acteur autorisé, avec motif historisé, EST la revue) ;
  - `erreurs_bloquantes` : inchangées (délibération non publiée, anomalies de données, prérequis, droits épuisés).
- **Preuve :** `DecisionAcademiqueWorkflowTest` **14/14** (87 assertions) — 4 yeux déclenché sur clause sensible (dispense), second acteur approuve un dossier à règle non confirmée.

## CORR-03 — Incertitude réglementaire empoisonnant `est_validable` (1 test)

- **Symptôme :** `IndustrialisationAcquisWorkflowTest::test_phase6_durcissement_campagne_masse_reprise_apres_interruption_et_verrou` — première campagne : `succes=0` (log : `DomainException: Impossible de valider le plan… résultats antérieurs invalides ou non délibérés`).
- **Cause racine :** `PlanRedoublementService::simulerPlan` ajoutait le motif `BLOQUE_REGLEMENTATION_INCONNUE` à `anomalies` → `est_validable=false` → garde de `confirmerPlan` → échec de la campagne pour toute formation non répertoriée (cas réel fréquent : la matrice ne couvre qu'un sous-ensemble curé de formations).
- **Correction :** routage structurel dans `simulerPlan` :
  - `statut_droit === 'BLOQUE_REGLEMENTATION_INCONNUE'` → **nouvelle clé `revue_humaine`** (non bloquante, Gate G40) ;
  - tout autre refus (`LIMITE_DEPASSEE`, statuts « à vérifier ») → **reste dans `anomalies`** (bloquant, conservateur).
  - `est_validable` conserve sa sémantique (anomalies bloquantes + délibération publiée).
- **Frontière vérifiée avant correction :** `CertificationLoop39RegimesAcademiquesTest` phase 4 (droits épuisés) exige « Épuisement des droits » dans `anomalies` + `est_validable=false` → préservé (statut `LIMITE_DEPASSEE` non déplacé).
- **Preuve :** test ciblé **1/1** (6 assertions) ; `ApiAcquisCampagneSecuriteTest` (voie API de campagne) **43/43** avec 3 autres suites transverses.

---

## Résultats consolidés (sorties réelles)

| Suite | Avant | Après |
|---|---:|---:|
| `tests/Feature/Acquis/` (12 suites) | 188/201 (13 rouges) | **201/201 — 1773 assertions** |
| Transverses (`PilotageInteropTest`, `ApiAcquisCampagneSecuriteTest`, `DeliberationPhase6Test`, `AcademicProgressionTest`) | — | **43/43 — 141 assertions** |
| Portails simulation + campagne (`MaSimulationParcoursTest`, `PortailSimulationParcoursTest`, `LoopCampagneAcquisExamenTest`) | — | **44 passés / 30 ignorés documentés** |
| Pint (`PlanRedoublementService`, `ValidationApprobationPlanService`, `ReglementationAcquisService`) | — | **PASS** |

## Fichiers modifiés (3 services + documents)

| Fichier | Changement |
|---|---|
| `app/Services/Acquis/ReglementationAcquisService.php` | Restauration additive de `evaluerCouvertureReglementaire()` (+ import `Throwable`) |
| `app/Services/Acquis/ValidationApprobationPlanService.php` | `controlerPlan` : `avertissements_revue_humaine` (non bloquants) vs `erreurs_bloquantes` |
| `app/Services/Acquis/PlanRedoublementService.php` | `simulerPlan` : clé `revue_humaine` (incertitude) — `anomalies` conservées pour les refus confirmés |

Aucun test modifié. Aucun commit/push/branche. WIP des sessions concurrentes non touché.

## Réserves (non bloquantes)

| ID | Gravité | Description | Décision attendue |
|---|---|---|---|
| RES-01 | INFO | La « revue humaine » réglementaire n'a pas de file dédiée : elle s'exerce via la validation administrative (avec 4 yeux si clause sensible). | Si une file de revue dédiée est souhaitée → décision institutionnelle |
| RES-02 | FAIBLE | Les statuts `DEROGATION_A_VERIFIER` / `DECISION_EXCEPTIONNELLE_A_VERIFIER` restent bloquants (choix conservateur, statu quo). | Trancher leur traitement (revue vs blocage) |
| RES-03 | INFO | Incident de méthode perdue : `evaluerCouvertureReglementaire` n'était couverte que par la suite Pilote (aucun test unitaire direct du contrat). | Ajouter des tests unitaires du contrat de couverture (proposé en `03` LOT A) |

**Verdict de ce triage : ACQ-RED-01 RÉSOLU** (13/13 tests reverdis, zéro assertion affaiblie, frontières métier préservées).
