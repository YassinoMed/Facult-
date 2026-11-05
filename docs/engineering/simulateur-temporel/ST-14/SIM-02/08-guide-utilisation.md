# SIM-02 — 08 · Guide d'utilisation

**Scénario :** SIM-02 — Baisse des admissions
**Destinataire :** administrateur du module Simulation temporelle

---

## A. Accès

SIM-02 est disponible dans le **formulaire existant** du module Simulation temporelle.
Aucune nouvelle route, aucun nouveau menu, aucune nouvelle permission n'a été
ajoutée.

```
Module Simulation temporelle → Nouvelle simulation → Scénario "Baisse des admissions"
```

---

## B. Paramètres

Le formulaire SIM-02 hérite du schéma de SIM-01 : `delta_admissions`,
`bornes`, `horizon_annees` et `taux` (tous REQUIS : passage/redoublement/
abandon).

| paramètre | type | valeur par défaut | plage autorisée | obligatoire |
|-----------|------|-------------------|-----------------|-------------|
| `delta_admissions` | float | — | `[-0.5, 0[` (bornes `min = -0.5, max = 0`) | **oui** |
| `bornes` | object | — | `{min: -0.5, max: 0}` | **oui** |
| `horizon_annees` | int | 6 | `[1, 20]` | oui |
| `taux.passage` | float | — | `[0, 1]` | **oui** |
| `taux.redoublement` | float | — | `[0, 1]` | **oui** |
| `taux.abandon` | float | — | `[0, 1]` | **oui** |
| `niveau_id` | int | — | (optionnel : restreint à un niveau) | non |
| `graine` | int | aléatoire | tout int | non |

**Rappel** : `passage + redoublement <= 1`.

---

## C. Procédure

### C.1. Via l'interface web

1. Ouvrir le module **Simulation temporelle**.
2. Choisir le scénario **« Baisse des admissions »** (SIM-02).
3. Saisir :
   - `delta` = la baisse annuelle souhaitée (ex. `−0.1` pour −10 %).
   - `horizon` = nombre d'années à projeter (ex. `6`).
   - `passage`, `redoublement`, `abandon` = taux de transition (ex. `0.5`, `0.3`, `0.2`).
4. Lancer la simulation.

### C.2. Interprétation des résultats

- `admissions(pas n) = round(baseL1 × (1+delta)^n)` : les entrées par année.
- `total(n)` : population cumulée au pas `n`.
- Les sorties (diplômés, abandons) ne sont pas affichées mais sont déductibles :
  `sorties(n) = total(n-1) + admissions(n) − total(n)`.

### C.3. Cas de validation rejetée

Si le formulaire affiche :

- *« SIM-02 exige une baisse strictement négative (delta < 0) »* → `delta >= 0`
  fourni : c'est SIM-01 (gel / stabilité).
- *« SIM-02 est borné à −0.5 max (borne moteur) »* → `delta < -0.5`.
- *« Le taux 'X' est requis et ∈ [0,1] »* → un taux manquant ou hors plage.
- *« passage + redoublement <= 1 »* → la somme dépasse 100 %.

---

## D. Scénarios connexes (catalogue)

| code | nom | delta | statut |
|------|-----|-------|--------|
| SOCLE-EFFECTIFS | Socle effectifs | 0 | disponible |
| SIM-01 | Stabilité (stabilité admissions) | 0 | disponible |
| **SIM-02** | **Baisse des admissions** | **[-0.5, 0[** | **disponible** |
| SIM-03 | Gel des admissions | 0 (gel explicite) | planifié |
| SIM-04… | Scénarios stratégiques | variable | 42 planifiés |

**45 scénarios au total** : 3 disponibles, 42 planifiés.

---

## E. Limites à connaître

1. **Taux de transition obligatoires** — sans `passage/redoublement/abandon`
   le moteur remet les cohortes à zéro (anomalie héritée de SIM-01).
2. **Base L1 par défaut** — `reset($cohortes)` (premier niveau observé).
   Spécifier `baseL1` explicitement si l'année de référence n'est pas le L1.
3. **Capacité par défaut 35** — règle de modélisation, pas une règle institutionnelle.
4. **Aucune interaction** entre scénarios — chaque exécution est isolée.
5. **Sorties non affichées** — déductibles uniquement.

---

## F. Exemple concret

**Contexte** : école avec 60 étudiants (10 L1, 20 L2, 30 L3).
**Demande** : baisser les admissions de 10 % par an pendant 6 ans.

```
Paramètres :
  delta        = -0.1
  horizon      = 6
  passage      = 0.5
  redoublement = 0.3
  abandon      = 0.2

Résultats :
  admissions   = [9, 8, 7, 6, 6, 5]
  total        = [60, 69, 77, 84, 90, 96, 101]

Interprétation : la population passe de 60 à 25 après 6 ans si la baisse
structurelle des admissions se maintient.
```

---

## G. Questions fréquentes

**Q : Peut-on faire une baisse de plus de 50 % ?**
R : Non — la borne moteur est `−0.5`. Une baisse plus forte doit être
modélisée par une séquence de scénarios.

**Q : Le gel des admissions est-il SIM-02 ?**
R : Non — le gel est SIM-03 (planifié). `delta = 0` est SIM-01 (stabilité).

**Q : SIM-02 modifie-t-il les inscriptions ?**
R : Non — SIM-02 est une lecture seule de simulation. Aucune inscription,
groupe, note ou affectation n'est touchée.

---

*Document de référence : `docs/engineering/simulateur-temporel/ST-14/SIM-02/08-guide-utilisation.md`*
*Version : 1.0 — figé après validation Lot 0 + SIM-01*
