# SIM-09 — 02 · Formules et cas de référence

**Version de formules :** 1.0 · **Moteur :** `ServiceSimulationTemporelle` (version `1.8`)  
**Type d'extension :** `analyse_nouvelle` (descripteur d'axe générique)

---

## C.1 Formules

Les formules de progression, de sortants et de comparaison sont celles de
SIM-07 (`02-formules.md`) : rôles **témoin = `taux`**, **principal =
`taux_modifie`**, admissions identiques (même delta).

Variation déclarée :

$$
\text{hausse\_redoublement} = \tau^{mod}_{redoublement} - \tau^{ref}_{redoublement} > 0
$$

Écarts et effet sortants : identiques SIM-07/08. **Signes attendus :** une
hausse du redoublement accumule des effectifs (écarts positifs) ; l'effet
sur les sortants est **indirect** (pipeline aval) et peut être nul à
horizon court.

**Généralisation d'axe :** `$cleVariation = sens_axe`
(`hausse_redoublement`, `baisse_abandon`, ...) ; le descripteur
`axe_variation`/`sens_variation` est normalisé en interne — les alias
`taux_ameliore`/`taux_degrade` (axe passage) sont strictement préservés.

---

## C.2 Cas de référence calculés hors moteur

Périmètre : $L1=10$, $L2=20$, $L3=30$ (total 60).

### Cas A — Hausse du redoublement à passage constant (abandon en baisse)

- Paramètres : $\delta=0$, $H=2$ ; ref `{0.6, 0.2, 0.2}` ;
  mod `{0.6, 0.3, 0.1}`.
- **Référence :** p1 : 18/6/6, 12/4/4, 6/2/2 → L1=12, L2=10, L3=18, **40**, sortants 18 ;
  p2 : L3 18 : 11/4/3, L2 10 : 6/2/2, L1 12 : 7/2/3 → L1=12, L2=9, L3=10, **31**, sortants 11.
  `[60, 40, 31]` · sortants `[18, 11]`.
- **Modifié :** p1 : 18/9/3, 12/6/2, 6/3/1 → L1=13, L2=12, L3=21, **46**, sortants 18 ;
  p2 : L3 21 : 13/6/2, L2 12 : 7/4/1, L1 13 : 8/4/1 → L1=14, L2=12, L3=13, **39**, sortants 13.
  `[60, 46, 39]` · sortants `[18, 13]`.
- **Restitutions :** `hausse_redoublement = 0.1` ; écarts `[+6, +8]` ;
  effet sortants `+2` (pipeline aval plus fourni au pas 2).

### Cas B — Hausse puisée dans le « reste » (somme ref 0.9)

- Ref `{0.6, 0.2, 0.1}` ; mod `{0.6, 0.3, 0.1}` ; $\delta=0$, $H=1$.
- Ref `[60, 40]` (sortants 18) ; mod `[60, 46]` (sortants 18) ;
  écart `[+6]` ; **effet sortants 0** (passage inchangé, aval non atteint
  à l'horizon 1) ; `hausse_redoublement = 0.1`.

### Cas C — Même cas A avec tendance +10 %

- Admissions `[11, 12]` (identiques aux deux trajectoires).
- Ref `[60, 41, 35]` · sortants `[18, 11]`.
- Mod `[60, 47, 41]` · sortants `[18, 13]`.
- Écarts `[+6, +6]` ; effet sortants `+2`.

---

## C.3 Ce que SIM-09 n'est pas

| Confusion | Distinction |
|---|---|
| SIM-10 | Sens opposé (baisse du redoublement) ; exclusion mutuelle. |
| SIM-07/08 | Axe passage ; contrats publiés préservés (alias). |
| SIM-13 | Combinaisons multi-axes ; dépend de SIM-07→12. |
| Prévision | Hypothèses saisies, aucune probabilité. |
