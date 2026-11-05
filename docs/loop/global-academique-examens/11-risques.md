# LOOP GLOBAL MASTER — ACADÉMIQUE & EXAMENS · 11 · Registre des risques

**Date :** 2026-11-03  
**Règle :** un risque non tranché reste ouvert ; il n'est jamais converti en hypothèse de code  

---

## 1. Risques métier ouverts

| ID | Risque | Probabilité | Impact | Mitigation actuelle | Statut |
|---|---|---|---|---|---|
| `R-01` | Décisions de cycle de vie D-01…D-05 non tranchées (réouverture, motif, notifications, propagation d'annulation, complétude `completed`) | Moyenne | Élevé | Comportements non inventés ; les transitions non décidées restent hors contrat | **Ouvert — décision métier** |
| `R-02` | Ratios de capacité (dont `EXAM_CAPACITE_RATIO = 0.50`) et valeurs budgétaires non validés (ST-10) | Moyenne | Élevé | Paramétrage configurable ; aucune valeur présentée comme institutionnelle | **Ouvert — décision métier** |
| `R-03` | Revue humaine G40 (réglementation inconnue) non bloquante | Certaine | Moyen | Avertissement explicite, jamais masqué ; décision humaine requise | **Accepté par conception** |
| `R-04` | Formation `LIC-ECO` absente du référentiel réglementaire | Certaine | Moyen | Traitée en revue humaine, pas en autorisation implicite | **Accepté — donnée à compléter** |
| `R-05` | Décisions D-11/D-12 d'industrialisation non tranchées | Moyenne | Moyen | Périmètre documenté ; pas de contournement | **Ouvert** |

---

## 2. Risques techniques

| ID | Risque | Probabilité | Impact | Mitigation | Statut |
|---|---|---|---|---|---|
| `R-06` | Absence de `mfa.obligatoire` sur les routes modules (`ANOM-A9`) | Certaine | Moyen | Constat systémique documenté ; correction transverse à planifier | **Ouvert — architecture** |
| `R-07` | Concurrence multi-processus non prouvée localement (`pcntl` absent, `lockForUpdate` no-op SQLite) | Certaine | Moyen | Limite d'environnement explicite ; validation MySQL requise avant production | **Ouvert — environnement** |
| `R-08` | 27 requêtes SQL par évaluation d'éligibilité | Moyenne | Moyen | Observation mesurée ; optimisation possible hors LOOP | **Surveillé** |
| `R-09` | Valeur par défaut de 40 places (ST-10) utilisée en l'absence de donnée | Faible | Moyen | Hypothèse explicite ; réserve documentée | **Surveillé** |
| `R-10` | 30 tests de portails ignorés (simulation/campagne) | Certaine | Faible | Non comptés comme réussis ; périmètre documenté | **Accepté** |
| `R-11` | Dérive documentaire entre LOOP antérieurs et registre global | Moyenne | Faible | Le registre référence les preuves, ne duplique pas les règles | **Surveillé** |

---

## 3. Risques de régression

| ID | Risque | Mitigation | Preuve |
|---|---|---|---|
| `R-12` | Modification d'un service canonique cassant un consommateur | Contrats A–D figés ; tests transverses | 201/201 Acquis, 32/32 Éligibilité, 143/143 Planification, 76/76 Gouvernance |
| `R-13` | Affaiblissement d'assertion pour verdir | Interdit ; corrections côté services uniquement | `02-corrections-acq-red-01.md` |
| `R-14` | Écriture accidentelle sur données officielles | Lecture seule ; tests de non-mutation | Suites acquis/groupes virtuels |

---

## 4. Échelle et gouvernance

- **Élevé** : bloque une décision institutionnelle ou une mise en production.
- **Moyen** : dégrade la confiance ou la performance, sans bloquer le calcul.
- **Faible** : gêne documentaire ou opérationnelle.

Tout risque `Élevé` reste ouvert tant que la décision métier n'est pas
formellement tranchée. Aucun risque n'est clos par simple absence de test.

---

## 5. Verdict

**PASS_WITH_RESERVATIONS.** Les risques sont identifiés, localisés et
mitigés sans invention de règles. Les risques `R-01`, `R-02`, `R-06` et
`R-07` conditionnent une mise en production sans réserve.
