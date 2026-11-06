# SIM-02 — 02 · Formules et références mathématiques

**Scénario :** SIM-02 — Baisse des admissions
**Formules :** version 1.0 (réutilisées du moteur, aucune modification)

---

## A. Formule des admissions

```text
admissions(pas n) = round(baseL1 × (1 + delta)^n)
```

avec :

- `baseL1` = effectif observé du premier niveau de la cohorte (`reset($cohortes)`).
- `delta ∈ [−0.5, 0[` (strictement négatif pour SIM-02).
- `n` = pas annuel, 1..horizon.

Exemple (delta = −0.1, baseL1 = 10) :

| pas | (1+delta)^n | admissions |
|---|---|---|
| 1 | 0.9000 | 9 |
| 2 | 0.8100 | 8 |
| 3 | 0.7290 | 7 |
| 6 | 0.5314 | 5 |

La trajectoire est **composée**, pas linéaire.

### Preuve de non-négativité

- `baseL1 ≥ 0` (effectif observé).
- `1 + delta ≥ 0.5` (borne moteur `−0.5`), donc `(1+delta)^n > 0`.
- `round(x)` avec `x ≥ 0` est `≥ 0`.

Conclusion : `admissions(pas n) ≥ 0` pour tout pas. Aucune admission
négative ne peut résulter d'une exécution SIM-02.

---

## B. Borne de la baisse

`delta ≥ −0.5` impose que la baisse ne soit jamais complète d'un coup.
Sur 6 ans, la trajectoire s'approche de zéro par décotes successives.

| pas | admissions (delta=−0.5) |
|---|---|
| 1 | 5 |
| 2 | 3 |
| 3 | 1 |
| 4 | 1 |
| 5 | 0 |
| 6 | 0 |

Les admissions nulles apparaissent par arrondi sur petits effectifs. Ce
n'est pas un gel explicite (SIM-03) : c'est l'extinction d'une trajectoire.

---

## C. Conservation des effectifs

Pour chaque pas `n` :

```text
cohortes_niveaux(n) = redoublants(n) + admissions(n) + arrivants(n)
total(n) = Σ cohortes_niveaux(n)
```

Avec taux `{passage: 0.5, redoublement: 0.3, abandon: 0.2}` :

| pas | admissions | L1 | L2 | L3 | total | Conservation |
|---|---|---|---|---|---|---|
| 0 | — | 10 | 20 | 30 | 60 | — |
| 1 | 8 | 11 | 11 | 19 | 41 | 60 + 8 − 15 − 12 |
| 2 | 6 | 9 | 9 | 12 | 30 | 41 + 6 − 10 − 7 |

Les sorties (diplômés, abandons) ne sont pas stockées explicitement mais
sont déductibles : `entrées − sorties = Δ total`.

---

## D. Références vérifiées indépendamment

Calculs effectués avec la primitive PHP `round()` (référence arithmétique,
sans appel au moteur) :

- `delta = −0.1, horizon = 3, gel` : admissions `[9, 8, 7]`, totaux
  `[60, 69, 77, 84]`.
- `delta = −0.5, horizon = 6, gel` : admissions `[5, 3, 1, 1, 0, 0]`,
  totaux `[60, 65, 68, 69, 70, 70, 70]`.
- `delta = −0.2, horizon = 2, taux 0.5/0.3/0.2` : totaux
  `[60, 41, 30]`, cohortes p1 `{L1:11,L2:11,L3:19}`, p2 `{L1:9,L2:9,L3:12}`.

Toutes ces valeurs sont assertées dans les tests SIM-02.

---

## E. Contrôles d'arrondi

- `passage + redoublement + abandon > 1.0001` est exclu par la validation
  commune du catalogue (tolérance d'arrondi `1.0001`).
- `partent + redoublants > effectif` est plafonné côté moteur (pas de
  création de population).
- Arrondis vers le plus proche ; les pertes dues à l'arrondi sont
  absorbées implicitement dans le reste (jamais signalées comme sorties).

---

## F. Absence de décision institutionnelle

`round()`, la borne `−0.5`, les taux d'exemple et les cohortes de test
sont des choix de modélisation. Aucune capacité, aucun taux de passage,
aucun coût ou aucun seuil de cette fiche ne constitue une règle
institutionnelle validée.
