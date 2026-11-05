# SIM-06 — 02 · Formules, conventions et cas de référence

**Version de formules :** 1.0  
**Moteur :** `ServiceSimulationTemporelle` (version `1.5`)  
**Type d'extension :** `extension_temporelle`  

---

## C.1 Formules mathématiques

### Trajectoire de référence (fond, inchangée)

$$
A_{ref}(p) = \text{round}\left(baseL1 \times (1+\delta)^p\right)
\quad p \in \{1, \dots, H\}
$$

$\delta = $ `delta_admissions` : le creux **dévie** cette trajectoire, il ne
la remplace pas.

### Facteur de creux et de récupération

Avec $d = $ `duree_baisse_annees`,
$r = $ `duree_recuperation_annees`,
$\pi = $ `profondeur_baisse` :

$$
f(p) =
\begin{cases}
1 - \pi \cdot \dfrac{p}{d} & 1 \le p \le d \quad \text{(baisse)} \\[6pt]
(1 - \pi) + \pi \cdot \dfrac{p - d}{r} & d < p \le d + r \quad \text{(récupération)} \\[6pt]
1 & p > d + r \quad \text{(référence)}
\end{cases}
$$

Propriétés **exactes** (indépendantes des arrondis de taux) :

- $f(0) = 1$ (départ à la référence) ;
- $f(d) = 1 - \pi$ (creux atteint exactement au pas $d$) ;
- $f(d + r) = 1$ (retour exact à la référence au pas $d + r$) ;
- $f$ décroissante puis croissante : un seul creux, aucune oscillation.

### Admissions avec creux

$$
\text{admissions}(p) = \text{round}\left(A_{ref}(p) \times f(p)\right)
$$

**Arrondis à deux étages :** $A_{ref}$ est arrondi d'abord (comme dans
toutes les trajectoires existantes), puis le produit est arrondi. Cette
convention est explicite et testée.

### Restitutions

$$
\text{ecart}(p) = \text{admissions}(p) - A_{ref}(p)
$$

$$
\text{ecart\_cumule} = \sum_{p=1}^{H} \text{ecart}(p)
\qquad
\text{creux} = \min_{p} \text{ecart}(p)
$$

`creux` vaut 0 si aucun écart n'est négatif (cas d'arrondi). Un cumul
négatif mesure le **déficit total d'admissions** par rapport au fond.

---

## C.2 Cas de référence calculés hors moteur

Périmètre synthétique : $L1 = 10$, $L2 = 20$, $L3 = 30$ (total 60).

### Cas 1 — Creux 20 % sur référence plate (retour en 2 + 2)

- Paramètres : $\delta = 0$, $\pi = 0.2$, $d = 2$, $r = 2$, $H = 4$,
  flux gelés (`redoublement = 1`).
- $A_{ref} = [10, 10, 10, 10]$ ; $f = [0.9, 0.8, 0.9, 1.0]$ ;
  **admissions $= [9, 8, 9, 10]$**.
- **Écarts $= [-1, -2, -1, 0]$**, cumul $= -4$, creux $= -2$.
- Phases : `[baisse, baisse, recuperation, recuperation]`.
- Totaux (L1 accumule) : $10+9=19 \to 69$ ; $+8=27 \to 77$ ;
  $+9=36 \to 86$ ; $+10=46 \to 96$.
  `totaux = [60, 69, 77, 86, 96]`.

### Cas 2 — Creux 20 % sur tendance +10 %

- Paramètres : $\delta = 0.10$, $\pi = 0.2$, $d = 2$, $r = 2$, $H = 4$.
- $A_{ref} = [11, 12, 13, 15]$ ; même facteur.
- admissions : round(9.9)=**10** ; round(9.6)=**10** ; round(11.7)=**12** ;
  round(15.0)=**15** → `[10, 10, 12, 15]`.
- **Écarts $= [-1, -2, -1, 0]$**, cumul $= -4$, creux $= -2$ — le déficit
  est identique au cas plat : la déviation est **relative** au fond.
- Totaux : $10+10=20 \to 70$ ; $+10=30 \to 80$ ; $+12=42 \to 92$ ;
  $+15=57 \to 107$. `totaux = [60, 70, 80, 92, 107]`.
- Au pas 4 = $d+r$ : admissions = $A_{ref}(4)$ = 15 — **retour exact**.

### Cas 3 — Flux actifs et conservation stricte

- Paramètres : $\delta = 0$, $\pi = 0.2$, $d = 1$, $r = 1$, $H = 2$,
  $\tau_{flux} = \{0.5, 0.3, 0.2\}$.
- admissions $= [8, 10]$ ; référence $= [10, 10]$ ; écarts $= [-2, 0]$ ;
  cumul $-2$ ; creux $-2$.
- **Pas 1** : L3 (30) → 15 partent / 9 redoublent / 6 abandons ;
  L2 (20) → 10 / 6 / 4 ; L1 (10) → 5 / 3 / 2.
  L1 = 3 + 8 = 11 ; L2 = 6 + 5 = 11 ; L3 = 9 + 10 = 19 ; total = **41**.
  Conservation : 60 + 8 − 15 − 12 = 41.
- **Pas 2** : L3 (19) → 10 / 6 / 3 ; L2 (11) → 6 / 3 / 2 ;
  L1 (11) → 6 / 3 / 2.
  L1 = 3 + 10 = 13 ; L2 = 3 + 6 = 9 ; L3 = 6 + 6 = 12 ; total = **34**.
  Conservation : 41 + 10 − 10 − 7 = 34.
- `totaux = [60, 41, 34]`.

### Cas 4 — Profondeur maximale (creux total, borne incluse)

- Paramètres : $\delta = 0$, $\pi = 1$, $d = 1$, $r = 1$, $H = 2$, flux
  gelés.
- $f = [0, 1]$ ; admissions $= [0, 10]$ ; écarts $= [-10, 0]$ ;
  cumul $-10$ ; creux $-10$.
- Totaux : L1 = 10 + 0 = 10 → 60 ; 10 + 10 = 20 → 70.
  `totaux = [60, 60, 70]`.
- Aucune admission négative : la borne $\pi \le 1$ garantit
  $f \ge 0$.

### Cas 5 — Convention d'arrondi (frontière .5)

- Paramètres : $\delta = 0.05$, $\pi = 0.5$, $d = 1$, $r = 1$, $H = 2$.
- $A_{ref} = [11, 11]$ ; $f = [0.5, 1.0]$ ;
  admissions $= [\text{round}(5.5), 11] = [6, 11]$.
- Écarts $= [-5, 0]$ ; cumul $-5$ ; creux $-5$.
- L'arrondi standard s'applique (5.5 → 6) : documenté, jamais contourné.

---

## C.3 Ce que le creux SIM-06 n'est pas

| Confusion possible | Distinction |
|---|---|
| Gel SIM-03 | Interruption **plate** de N années (admissions = 0), sans rampe ni retour progressif. |
| Baisse SIM-02 | Tendance composée **continue** à la baisse, sans creux ni récupération. |
| Choc SIM-04 | Ajout **ponctuel** positif, sans phase de retour. |
| Cible SIM-05 | Comparaison à une trajectoire d'intention, sans modification des admissions. |
| Cible `cible_total` | Scalaire constant comparé au total, indépendant du creux. |
