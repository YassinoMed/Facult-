# LOOP GLOBAL MASTER — ACADÉMIQUE & EXAMENS · 14 · Validation fonctionnelle

**Date :** 2026-11-03  
**Objet :** vérifier la chaîne fonctionnelle de bout en bout, maillon par maillon  
**Principe :** aucune preuve n'est déduite d'une intention ; chaque maillon est adossé à une exécution réelle

---

## 1. Chaîne fonctionnelle cible

```
Notes  →  Délibération  →  Acquis  →  Éligibilité  →  Planification
                                                        ↓
                    Cycle de vie  ←  Publication  ←  Gouvernance
                                                        ↓
                                        Capacité  →  Groupes virtuels
```

Chaque maillon est porté par un **service canonique unique**. Aucun maillon
ne réimplémente la règle d'un autre. La validation vérifie trois propriétés
par maillon :

1. la règle est appliquée par le service propriétaire ;
2. le refus est explicite (jamais une autorisation implicite) ;
3. aucune donnée officielle n'est écrite par une simulation.

---

## 2. Matrice de validation par maillon

| # | Maillon | Service propriétaire | Preuve exécutée | Résultat | Statut |
|---|---|---|---|---|---|
| 1 | **Notes** | Contrôleurs / services d'évaluation ; `NoteEpreuveHistorique` | `Loop05NotesDeliberationsVerificationTest`, `DeliberationPhase6Test`, `NotesHistoriqueAppendOnlyTest` | **15 / 15** tests · **68** assertions | ✅ PASS |
| 2 | **Délibération** | Préparation, contrôles, verrouillage, publication des résultats | mêmes suites | inclus dans les 15 / 15 | ✅ PASS |
| 3 | **Acquis / capitalisation** | `CalculAcquisPedagogiquesService`, `ReglementationAcquisService`, `GestionnaireCreditsService`, `MoteurDecisionAcademiqueService` | `tests/Feature/Acquis/` (13 fichiers) | **201 / 201** tests · **1 773** assertions | ✅ PASS |
| 4 | **Éligibilité** | `ServiceEligibiliteExamen` | `EligibiliteExamenTest`, `InscriptionEligibilite*` | **32 / 32** tests · **150** assertions | ✅ PASS |
| 5 | **Planification** | `ExamScheduleEngineService`, `ConflitEpreuveService`, `ExamRoomAssignmentService` | 9 suites ciblées | **143 / 143** tests · **656** assertions | ✅ PASS |
| 6 | **Capacité** | `ExamDecisionCapacitePermutationService` | inclus dans les 143 | ✅ dont `ExamCapaciteSalleNonConfirmeeTest` | ✅ PASS_WITH_RESERVATIONS |
| 7 | **Groupes virtuels** | `GroupeVirtuelExamenService`, `ConsultationExportGroupeVirtuelService` | inclus dans les 143 | ✅ dont les 3 suites `ExamGroupe*` | ✅ PASS |
| 8 | **Gouvernance** | `ExamGouvernanceValidationService` | 5 suites ciblées | **76 / 76** tests · **345** assertions | ✅ PASS |
| 9 | **Publication** | `ExamPublicationOrchestratorService` | inclus dans les 76 | ✅ scellement SHA-256 | ✅ PASS |
| 10 | **Cycle de vie** | `ExamenService` | inclus dans les 76 | ✅ transitions verrouillées + journal | ✅ PASS |
| 11 | **Sécurité transverse** | Policies, IAM, middleware | `IamPermissionsEtCycleDeVieTest`, `ExamGouvernanceSecuriteConcurrenceTest`, `MigrationHistoriqueDryRunLoop41Test` | **27 / 27** tests · **101** assertions | ✅ PASS_WITH_RESERVATIONS |

> **Rappel :** ces suites ne sont **pas disjointes**. Les totaux ne doivent
> pas être additionnés. Chaque ligne prouve un périmètre, pas un volume
> cumulé.

---

## 3. Propriétés vérifiées par maillon

### 3.1 Notes et délibération

- Une note hors bornes est **rejetée sans écriture**.
- Un étudiant non convoqué est **rejeté**.
- Une note verrouillée ne peut plus être validée.
- L'historique des notes est **append-only** : aucune correction rétroactive
  par `UPDATE`.
- La validation de délibération est horodatée ; une modification postérieure
  est **signalée** (drapeau temporel), et une revalidation efface ce drapeau.
- L'isolation facultaire de l'historique est testée.
- Le portail étudiant **masque** les résultats non publiés.

### 3.2 Acquis et capitalisation

- Capitalisation UE / ECUE selon le régime réglementaire, **fail-closed**.
- Compensation semestrielle, seuil de compétence éliminatoire, contrôle
  continu exclusif, meilleure note au rattrapage.
- Résultats provisoires non délibérés ⇒ **blocage** de l'acquis.
- Absence injustifiée ⇒ blocage de la compensation.
- Rachat discrétionnaire du jury : **aucun automatisme logiciel**.
- Non-duplication des crédits ECTS ; réinscription ciblée exclut les acquis.
- Formation inconnue (`LIC-ECO`) ⇒ **revue humaine non bloquante**, jamais
  une autorisation implicite (`G40`).

### 3.3 Éligibilité

- Inscriptions actives + cohérence programme ⇒ éligible ; sinon décision
  **explicable**.
- L'appartenance à un groupe **ne suffit pas** sans inscription pédagogique.
- Inscription inactive, étudiant suspendu ⇒ non éligible.
- Données insuffisantes ⇒ `anomalie`, jamais `eligible`.
- Session de rattrapage non validée ⇒ `anomalie` explicite.
- Les décisions de population sont **explicables** et traçables.

### 3.4 Planification et capacité

- Les conflits (salle, horaire, groupe, surveillant) sont détectés **avant**
  toute planification et sont **bloquants**.
- Une simulation n'écrit **jamais** le planning officiel.
- Une capacité inconnue **bloque** ; elle n'est jamais supposée suffisante.
- Le ratio de capacité est **configurable** (`EXAM_CAPACITE_RATIO`, défaut
  `0.50`) — paramètre, pas règle institutionnelle.
- Les groupes virtuels agrègent plusieurs groupes sources sans jamais les
  fusionner si incompatibles, et **ne réaffectent aucun étudiant**.
- La consultation et l'export sont soumis au périmètre facultaire.

### 3.5 Gouvernance, publication et cycle de vie

- Circuit `soumis → certifié → approuvé ×2 → publié`, non contournable.
- Auto-approbation interdite ; **personnes et rôles distincts**.
- Séparation par rôle active **par défaut**.
- Modification après certification ⇒ approbation et publication **bloquées**.
- Gel institutionnel ⇒ dérogation **motivée et habilitée** exigée.
- Publication scellée par empreinte **SHA-256**.
- Journal de gouvernance **immuable** ; rejet motivé conservé.
- Transitions de cycle de vie : états inconnus refusés, sauts d'états
  refusés, transitions répétées **idempotentes**, `published` interdit en un
  clic sans gouvernance.
- État terminal `archived` : aucune transition possible.
- Appel direct du service : mêmes règles que la route HTTP.

---

## 4. Preuves d'exécution du 2026-11-03

```json
{"tool":"phpunit","result":"passed","tests":201,"passed":201,"assertions":1773,"duration_ms":9114}
{"tool":"phpunit","result":"passed","tests":32,"passed":32,"assertions":150,"duration_ms":1958}
{"tool":"phpunit","result":"passed","tests":143,"passed":143,"assertions":656,"duration_ms":11584}
{"tool":"phpunit","result":"passed","tests":76,"passed":76,"assertions":345,"duration_ms":3996}
{"tool":"phpunit","result":"passed","tests":27,"passed":27,"assertions":101,"duration_ms":1541}
{"tool":"phpunit","result":"passed","tests":15,"passed":15,"assertions":68,"duration_ms":1562}
```

Les six exécutions sont vertes, sans échec. Aucune assertion n'a été
affaiblie, aucun test n'a été marqué `skipped` pour verdir ces périmètres.

---

## 5. Limite majeure : absence d'E2E unique

### 5.1 Constat

Il n'existe **aucun test unique** qui parcourt toute la chaîne
`notes → délibération → acquis → éligibilité → planification → capacité →
groupes virtuels → gouvernance → publication → cycle de vie` sur un même jeu
de données.

Le seul candidat transversal, `tests/Feature/LoopCampagneAcquisExamenTest`,
a été exécuté le 2026-11-03 :

```json
{"tool":"phpunit","result":"passed","tests":36,"passed":6,"assertions":25,"duration_ms":1771,"skipped":30}
```

### 5.2 Lecture honnête de ce résultat

- **6 tests passent** : ce sont les phases méta (`audit`, `matrice`,
  `exécution`, `sécurité`, `validation`, `verdict`).
- **30 tests sont ignorés** avec le motif explicite
  `Fixtures FST programme 21 cohorte 2025-2026` : les scénarios métier
  `ACQ-01…10`, `EXM-01…12`, `CRO-01…08` **ne s'exécutent pas**.
- Les deux assertions métier de la phase C sont minimales
  (`assertArrayHasKey('etudiant_id')`, `assertNotNull($decision)`) et deux
  sous-tests sont `assertTrue(true)`.
- **Conclusion :** cette suite ne prouve **pas** la chaîne. Elle ne doit
  jamais être comptée comme un E2E réussi. Les 30 ignorés ne sont pas des
  succès.

### 5.3 Conséquence sur le verdict

La validation fonctionnelle est **forte maillon par maillon** et **absente
comme preuve intégrée**. C'est la réserve centrale de ce LOOP : chaque
contrat est prouvé isolément, mais aucune exécution ne démontre que les
maillons se transmettent correctement les données dans un parcours complet.

---

## 6. Autres limites

| Limite | Description | Impact |
|---|---|---|
| `pcntl` absent | Les scénarios de concurrence multi-processus ne s'exécutent pas localement | La concurrence réelle n'est pas prouvée |
| `lockForUpdate` no-op SQLite | Les verrous MySQL ne sont pas testés | Les garanties de verrouillage restent théoriques localement |
| 30 tests transversaux ignorés | Fixtures FST absentes | Pas d'E2E métier |
| Données de production | Aucune donnée réelle utilisée | Les volumétries réelles ne sont pas éprouvées |
| Ratios non validés | `EXAM_CAPACITE_RATIO = 0.50`, 40 places (ST-10) | Les résultats de capacité restent indicatifs |

---

## 7. Ce que la validation autorise

| Usage | Autorisé ? | Condition |
|---|---|---|
| Démonstration en recette | ✅ Oui | Avec les 2 migrations appliquées et `MODULE_EVALUATION=true` |
| Formation des utilisateurs | ✅ Oui | Rappeler le caractère indicatif des capacités |
| Décision institutionnelle réelle | ⛔ Non | Décisions D-01…D-05 et ratios non tranchés |
| Publication officielle automatisée | ⛔ Non | Circuit 4 yeux obligatoire, jamais automatisable |
| Garantie de concurrence | ⛔ Non | Validation MySQL requise |

---

## 8. Verdict

**PASS_WITH_RESERVATIONS.**  
La chaîne est vérifiée maillon par maillon, avec des refus explicites et une
lecture seule respectée. Aucune preuve intégrée de bout en bout n'existe :
les 30 tests transversaux restent ignorés faute de fixtures, et les
assertions méta ne remplacent pas un scénario métier complet. Cette limite
est documentée, jamais masquée.
