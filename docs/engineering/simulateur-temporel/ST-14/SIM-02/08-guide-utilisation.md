# SIM-02 — 08 · Guide d'utilisation

**Scénario :** SIM-02 — Baisse des admissions
**Destinataire :** administrateur du module Simulation temporelle

---

## A. Accès

SIM-02 est disponible via le **formulaire existant** du module Simulation
temporelle. Aucune nouvelle route, aucun nouveau menu, aucune nouvelle
permission n'a été ajoutée.

```
Module Simulation temporelle → Nouvelle simulation → Scénario "Baisse des admissions"
```

---

## B. Paramètres

Le scénario hérite du schéma de SIM-01 (`schemaAvecTauxRequis`) : `taux` est
REQUIS, comme `delta_admissions`, `bornes` et `horizon_annees`.

| paramètre | type | obligatoire | domaine | unité |
|-----------|------|-------------|---------|-------|
| `delta_admissions` | nombre | **oui** | `[-0.5, 0[` | ratio annuel composé |
| `bornes.min` | nombre | oui | `[-0.5, delta]` | ratio |
| `bornes.max` | nombre | oui | `[delta, 0]` | ratio |
| `horizon_annees` | entier | oui | `[1, 6]` (plafond moteur) | années |
| `taux.passage` | nombre | **oui** | `[0, 1]` | ratio annuel |
| `taux.redoublement` | nombre | **oui** | `[0, 1]` | ratio annuel |
| `taux.abandon` | nombre | **oui** | `[0, 1]` | ratio annuel |
| `niveau_id` | entier | non | ≥ 1, existant | identifiant |
| `groupes` | liste d'entiers | non | exige `niveau_id` | identifiants |

**Rappel** : `passage + redoublement + abandon ≤ 1` (tolérance d'arrondi
`1.0001`). Aucune valeur par défaut n'est injectée par le validateur.

---

## C. Procédure

### C.1. Via l'interface web

1. Ouvrir le module **Simulation temporelle**.
2. Choisir le scénario **« Baisse des admissions »** (SIM-02).
3. Saisir :
   - `delta_admissions` = la baisse annuelle souhaitée (ex. `−0.1` pour −10 %),
   - `borne max` = `0` (le scénario interdit toute variante de croissance),
   - `horizon_annees` = nombre d'années à projeter (ex. `3`),
   - `taux` = `passage`, `redoublement`, `abandon` (ex. `0.5 / 0.3 / 0.2`).
4. Lancer la simulation.

### C.2. Interprétation des résultats

- `admissions(pas n) = round(baseL1 × (1 + delta_admissions)^n)` : les entrées
  par année — baisse **composée**, pas linéaire.
- `total(n)` : population cumulée au pas `n`.
- Les sorties (diplômés, abandons) ne sont pas affichées mais sont déductibles :
  `sorties(n) = total(n-1) + admissions(n) − total(n)`.

### C.3. Cas de validation rejetée

Messages réellement renvoyés par le catalogue :

- *« SIM-02 (baisse) : delta_admissions nul ou positif refusé — utiliser SIM-01
  pour la croissance/stabilité, SIM-03 pour le gel explicite. »* → `delta ≥ 0`.
- *« SIM-02 (baisse) : borne maximale positive refusée — une variante de
  croissance relève de SIM-01. »* → `bornes.max > 0`.
- *« passage + redoublement + abandon > 1 : incohérent. »* → la somme des taux
  dépasse `1.0001`.
- *« Valeur inconnue…  »* → clé non reconnue (ex. `delta_admission` au singulier).
- *« Delta d'admissions hors de ses propres bornes. »* → `delta` hors `[min, max]`.

---

## D. Scénarios connexes (catalogue, état 2026-11-05)

| code | nom | delta | statut |
|------|-----|-------|--------|
| SOCLE-EFFECTIFS | Socle effectifs | 0 | disponible |
| SIM-01 | Croissance des admissions | ≥ 0 | disponible |
| **SIM-02** | **Baisse des admissions** | **[-0.5, 0[** | **disponible** |
| SIM-03 | Gel des admissions | 0 (gel explicite, `duree_gel_annees`) | disponible |
| SIM-04 → SIM-12 | Admissions exceptionnelles → Réduction de l'abandon | variable | disponibles |
| SIM-13 → SIM-30, CR-01 → CR-06, UNC-01 → UNC-08 | reste du plan | variable | planifiés |

**45 scénarios au total** : 13 disponibles (SOCLE + SIM-01 → SIM-12),
32 planifiés.

---

## E. Limites à connaître

1. **Taux de transition obligatoires** — sans `passage/redoublement/abandon`
   le moteur remet les cohortes à zéro (anomalie héritée de SIM-01).
2. **Base d'admissions** — `reset($cohortes)` (premier niveau observé).
   Ce n'est pas un paramètre modifiable : la base suit le périmètre simulé.
3. **Capacité par défaut 35** (si aucun groupe réel) — règle de modélisation,
   pas une règle institutionnelle.
4. **Aucune interaction** entre scénarios — chaque exécution est isolée
   (idempotence par graine).
5. **Sorties non affichées** — déductibles uniquement.
6. **Plafond d'horizon = 6 ans** (`PLAFOND_HORIZON`).
7. **Borne de baisse = −0.5** — une baisse plus forte n'est pas simulable en
   une fois.

---

## F. Exemple concret (vérifié par les tests)

**Contexte** : école avec 60 étudiants (10 L1, 20 L2, 30 L3).
**Demande** : baisser les admissions de 10 % par an pendant 3 ans
(progression gelée pour isoler l'effet des admissions).

```
Paramètres :
  delta_admissions = -0.1
  bornes           = {min: -0.5, max: 0}
  horizon_annees   = 3
  taux             = {passage: 0, redoublement: 1, abandon: 0}

Résultats (référence calculée à la main) :
  admissions = [9, 8, 7]        (round(10×0.9^n))
  totaux     = [60, 69, 77, 84]

Interprétation : chaque année la cohorte d'admissions décroît de 10 %
par rapport à l'année précédente ; le total continue de croître tant que
les admissions dépassent les sorties.
```

Avec des taux de transition réels (`0.5 / 0.3 / 0.2`) et `delta = -0.2` :

```
  admissions = [8, 6]
  totaux     = [60, 41, 30]
  p1 : {L1: 11, L2: 11, L3: 19}   p2 : {L1: 9, L2: 9, L3: 12}
```

---

## G. Questions fréquentes

**Q : Peut-on faire une baisse de plus de 50 % ?**
R : Non — la borne moteur est `−0.5` par pas. Une baisse plus forte doit être
modélisée par une séquence.

**Q : Le gel des admissions est-il SIM-02 ?**
R : Non — le gel **explicite** (durée déclarée) est SIM-03, disponible. Le
`delta = 0` constant est SIM-01. SIM-02 exige `delta < 0`.

**Q : SIM-02 modifie-t-il les inscriptions ?**
R : Non — SIM-02 est une lecture seule de simulation. Aucune inscription,
groupe, note ou affectation n'est touchée ; seules les tables techniques du
module sont écrites.

**Q : Les admissions peuvent-elles devenir négatives ?**
R : Non — prouvé : `baseL1 ≥ 0` et `1 + delta ≥ 0.5`, donc
`round(·) ≥ 0`. À `delta = −0.5`, les admissions s'éteignent par arrondi
(sans devenir négatives).

---

*Document de référence : `docs/engineering/simulateur-temporel/ST-14/SIM-02/08-guide-utilisation.md`*
*Version : 1.0 — cohérence vérifiée contre le code (2026-11-05)*
