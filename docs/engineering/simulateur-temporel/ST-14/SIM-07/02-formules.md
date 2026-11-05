# SIM-07 — 02 · Formules, conventions et cas de référence

**Version de formules :** 1.0  
**Moteur :** `ServiceSimulationTemporelle` (version `1.6`)  
**Type d'extension :** `analyse_nouvelle`  

---

## C.1 Formules mathématiques

### Double trajectoire à admissions identiques

Les admissions ne dépendent ni du passage, ni du redoublement, ni de
l'abandon : pour une variante de delta donnée,

$$
\text{admissions}_p = \text{round}\left(baseL1 \times (1+\delta)^p\right)
$$

est **identique** dans la trajectoire simulée (taux améliorés) et la
trajectoire de référence (taux déclarés). Seule la progression par niveaux
diffère.

### Progression par niveaux (existante, appliquée aux deux jeux)

Pour chaque niveau $i$ et chaque pas $p$ :

$$
\text{partent}_{i,p} = \text{round}\left(\text{effectif}_{i,p-1} \times \tau^{amé}_{passage}\right)
\quad\text{ou}\quad \text{round}\left(\text{effectif}_{i,p-1} \times \tau^{ref}_{passage}\right)
$$

$$
\text{redoublants}_{i,p} = \text{round}\left(\text{effectif}_{i,p-1} \times \tau_{redoublement}\right)
$$

avec le plafond de conservation existant :
$\text{partent} + \text{redoublants} \le \text{effectif}$ (sinon
$\text{redoublants} = \max(0, \text{effectif} - \text{partent})$).

Nouvelles cohortes :

$$
\text{effectif}_{1,p} = \text{redoublants}_{1,p} + \text{admissions}_p
\qquad
\text{effectif}_{i,p} = \text{redoublants}_{i,p} + \text{partent}_{i-1,p}
$$

### Sortants

$N$ = nombre de niveaux du périmètre :

$$
\text{sortants}_p = \text{partent}_{N,p}
$$

(restitution nouvelle : auparavant calculés puis jetés).

### Restitutions

$$
\text{hausse\_passage} = \tau^{amé}_{passage} - \tau^{ref}_{passage}
$$

$$
\text{ecart}_p = \text{total}^{amé}_p - \text{total}^{ref}_p
\qquad
\text{ecart\_final} = \text{total}^{amé}_H - \text{total}^{ref}_H
$$

$$
\text{effet\_sortants} = \sum_{p=1}^{H} \left(\text{sortants}^{amé}_p - \text{sortants}^{ref}_p\right)
$$

**Aucune compensation :** les trois taux améliorés sont déclarés ; le moteur
n'invente ni origine ni redistribution de la hausse. Une hausse puisée dans
le redoublement et une hausse puisée dans le « reste » (somme < 1) peuvent
produire des effets **opposés** sur les effectifs — c'est une information,
pas une anomalie.

---

## C.2 Cas de référence calculés hors moteur

Périmètre synthétique : $L1 = 10$, $L2 = 20$, $L3 = 30$ (total 60).

### Cas A — Hausse puisée dans le redoublement

- Paramètres : $\delta = 0$, $H = 2$ ;
  référence $\tau^{ref} = \{0.5 ; 0.3 ; 0.2\}$ ;
  amélioré $\tau^{amé} = \{0.7 ; 0.1 ; 0.2\}$ (somme 1.0 chacun).
- **Admissions identiques :** $[10, 10]$.
- **Référence :**
  - p1 : L3 30 → 15 / 9 / 6 ; L2 20 → 10 / 6 / 4 ; L1 10 → 5 / 3 / 2 ;
    L1 = 3+10 = 13 ; L2 = 6+5 = 11 ; L3 = 9+10 = 19 ; **total 43** ;
    conservation 60 + 10 − 15 − 12 = 43.
  - p2 : L3 19 → 10 / 6 / 3 ; L2 11 → 6 / 3 / 2 ; L1 13 → 7 / 4 / 2 ;
    L1 = 4+10 = 14 ; L2 = 3+7 = 10 ; L3 = 6+6 = 12 ; **total 36** ;
    conservation 43 + 10 − 10 − 7 = 36.
  - `totaux_ref = [60, 43, 36]` ; `sortants_ref = [15, 10]`.
- **Amélioré :**
  - p1 : L3 30 → 21 / 3 / 6 ; L2 20 → 14 / 2 / 4 ; L1 10 → 7 / 1 / 2 ;
    L1 = 1+10 = 11 ; L2 = 2+7 = 9 ; L3 = 3+14 = 17 ; **total 37** ;
    conservation 60 + 10 − 21 − 12 = 37.
  - p2 : L3 17 → 12 / 2 / 3 ; L2 9 → 6 / 1 / 2 ; L1 11 → 8 / 1 / 2 ;
    L1 = 1+10 = 11 ; L2 = 1+8 = 9 ; L3 = 2+6 = 8 ; **total 28** ;
    conservation 37 + 10 − 12 − 7 = 28.
  - `totaux_amé = [60, 37, 28]` ; `sortants_amé = [21, 12]`.
- **Restitutions :** `hausse_passage = 0.2` ;
  `ecarts = [−6, −8]` ; `ecart_final = −8` ;
  `effet_sortants = (21+12) − (15+10) = +8`.
- **Lecture :** progression plus rapide ⇒ effectifs **plus faibles** dans le
  système et **plus de sortants** : +8 sortants cumulés, −8 étudiants au
  final.

### Cas B — Hausse puisée dans le « reste » (somme < 1 en référence)

- Paramètres : $\delta = 0$, $H = 1$ ;
  référence $\tau^{ref} = \{0.5 ; 0.2 ; 0.1\}$ (somme 0.8) ;
  amélioré $\tau^{amé} = \{0.7 ; 0.2 ; 0.1\}$ (somme 1.0).
- **Référence :** L3 30 → 15 / 6 / autres 9 ; L2 20 → 10 / 4 / 6 ;
  L1 10 → 5 / 2 / 3.
  L1 = 2+10 = 12 ; L2 = 4+5 = 9 ; L3 = 6+10 = 16 ; **total 37** ;
  `sortants_ref = [15]`.
- **Amélioré :** L3 30 → 21 / 6 / autres 3 ; L2 20 → 14 / 4 / 2 ;
  L1 10 → 7 / 2 / 1.
  L1 = 2+10 = 12 ; L2 = 4+7 = 11 ; L3 = 6+14 = 20 ; **total 43** ;
  `sortants_amé = [21]`.
- **Restitutions :** `hausse_passage = 0.2` ; `ecarts = [+6]` ;
  `ecart_final = +6` ; `effet_sortants = +6`.
- **Lecture :** ici les effectifs **augmentent** (+6) car moins d'étudiants
  sont perdus en « autres issues » ; les sortants augmentent aussi (+6).
  L'effet dépend entièrement de l'origine **déclarée** de la hausse — le
  moteur ne la suppose jamais.

### Cas C — Déterminisme du mode double taux

- Paramètres : Cas A.
- Deux exécutions identiques (mêmes critères, graine, année, variantes)
  retournent la **même** exécution (`id`, `hash_resultat`) ; la trajectoire
  de référence parallèle est recalculée à chaque itération de variante mais
  reste **déterministe** et n'est jamais persistée en doubles snapshots.
- La variante 0 porte le delta de base ; les variantes suivantes varient le
  delta, **jamais les taux**.

---

## C.3 Ce que SIM-07 n'est pas

| Confusion possible | Distinction |
|---|---|
| Sensibilité OAT | L'OAT fait varier un paramètre ±5 pts autour d'une base ; SIM-07 compare **deux jeux déclarés** et restitue l'effet. |
| SIM-08 (dégradation) | Passage en baisse ; contrat distinct. |
| SIM-13 (crise combinée) | Plusieurs dégradations simultanées ; dépend de SIM-07→12. |
| Prévision de réussite | Aucune probabilité : les taux sont des hypothèses saisies. |
| Décision pédagogique | Aucune écriture, aucune affectation, aucune note. |
