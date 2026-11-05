# SIM-08 — 02 · Formules, conventions et cas de référence

**Version de formules :** 1.0  
**Moteur :** `ServiceSimulationTemporelle` (version `1.7`)  
**Type d'extension :** `analyse_nouvelle`  

---

## C.1 Formules mathématiques

Les formules de progression, de sortants et de comparaison sont
**exactement** celles de SIM-07 (`02-formules.md`), avec les rôles
inversés : la trajectoire **principale** utilise `taux_degrade`, la
trajectoire **témoin** utilise `taux`.

### Variation déclarée

$$
\text{baisse\_passage} = \tau^{ref}_{passage} - \tau^{dég}_{passage} > 0
$$

(magnitude positive de la baisse, symétrique de `hausse_passage`).

### Comparaisons (identiques à SIM-07)

$$
\text{ecart}_p = \text{total}^{dég}_p - \text{total}^{ref}_p
\qquad
\text{ecart\_final} = \text{total}^{dég}_H - \text{total}^{ref}_H
$$

$$
\text{effet\_sortants} = \sum_{p=1}^{H} \left(\text{sortants}^{dég}_p - \text{sortants}^{ref}_p\right)
$$

**Signes attendus :** une dégradation typique produit des écarts
**positifs** (accumulation d'étudiants bloqués) et un effet sortants
**négatif** (diplômés perdus). Ces signes ne sont pas imposés par le
moteur : ils résultent du jeu déclaré.

**Aucune compensation :** les trois taux dégradés sont déclarés ; le moteur
n'invente ni origine ni redistribution de la baisse.

---

## C.2 Cas de référence calculés hors moteur

Périmètre synthétique : $L1 = 10$, $L2 = 20$, $L3 = 30$ (total 60).

### Cas A — Baisse puisée dans la hausse du redoublement

- Paramètres : $\delta = 0$, $H = 2$ ;
  référence $\tau^{ref} = \{0.7 ; 0.1 ; 0.2\}$ ;
  dégradé $\tau^{dég} = \{0.5 ; 0.3 ; 0.2\}$ (somme 1.0 chacun).
- **Admissions identiques :** $[10, 10]$.
- **Trajectoire dégradée (principale) :** $[60, 43, 36]$ ;
  sortants $[15, 10]$ ; snapshots p1 $\{13, 11, 19\}$, p2 $\{14, 10, 12\}$.
- **Trajectoire de référence (témoin) :** $[60, 37, 28]$ ;
  sortants $[21, 12]$.
- **Restitutions :** `baisse_passage = 0.2` ; `ecarts = [+6, +8]` ;
  `ecart_final = +8` ; `effet_sortants = (15+10) − (21+12) = −8`.
- **Lecture :** passage plus faible ⇒ **+8 étudiants bloqués** en fin
  d'horizon et **−8 diplômés** cumulés. Effet exactement opposé au cas A de
  SIM-07 (rôles inversés).

### Cas B — Baisse absorbée par le « reste » (somme < 1)

- Paramètres : $\delta = 0$, $H = 1$ ;
  référence $\tau^{ref} = \{0.7 ; 0.2 ; 0.1\}$ (somme 1.0) ;
  dégradé $\tau^{dég} = \{0.5 ; 0.2 ; 0.1\}$ (somme 0.8).
- **Dégradé :** L3 30 → 15 / 6 / autres 9 ; L2 20 → 10 / 4 / 6 ;
  L1 10 → 5 / 2 / 3.
  L1 = 2+10 = 12 ; L2 = 4+5 = 9 ; L3 = 6+10 = 16 ; **total 37** ;
  sortants $[15]$.
- **Référence :** L3 30 → 21 / 6 / 3 ; L2 20 → 14 / 4 / 2 ;
  L1 10 → 7 / 2 / 1.
  L1 = 2+10 = 12 ; L2 = 4+7 = 11 ; L3 = 6+14 = 20 ; **total 43** ;
  sortants $[21]$.
- **Restitutions :** `baisse_passage = 0.2` ; `ecarts = [−6]` ;
  `ecart_final = −6` ; `effet_sortants = −6`.
- **Lecture :** ici la dégradation laisse **filtrer** des étudiants vers le
  « reste » : les effectifs **baissent** (−6) et les sortants baissent
  aussi (−6). L'effet dépend entièrement de l'origine déclarée.

### Cas C — Déterminisme avec tendance d'admissions +10 %

- Paramètres : Cas A avec $\delta = 0.10$ → admissions $[11, 12]$
  (identiques aux deux trajectoires).
- **Dégradé :** $[60, 44, 38]$ ; sortants $[15, 10]$.
- **Référence :** $[60, 38, 30]$ ; sortants $[21, 12]$.
- **Écarts $= [+6, +8]$** ; effet sortants $= −8$.

---

## C.3 Ce que SIM-08 n'est pas

| Confusion possible | Distinction |
|---|---|
| SIM-07 (amélioration) | Direction opposée ; exclusion mutuelle des deux contrats. |
| SIM-09 (hausse du redoublement) | Axe redoublement dédié ; SIM-08 est l'axe passage (l'origine de la baisse reste déclarée). |
| SIM-11 (hausse de l'abandon) | Axe abandon dédié. |
| SIM-13 / CR-02 | Combinaisons de dégradations ; dépendent de SIM-07→12. |
| Prévision de crise | Aucune probabilité : les taux sont des hypothèses saisies. |
