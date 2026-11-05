# SIM-05 — 02 · Formules, conventions et cas de référence

**Version de formules :** 1.0  
**Moteur :** `ServiceSimulationTemporelle` (version `1.4`)  
**Type d'extension :** `analyse_nouvelle`  

---

## C.1 Formules mathématiques

### Trajectoire réelle (existante, inchangée)

Soit $p \in \{1, \dots, H\}$ le pas simulé et $baseL1$ l'effectif observé du
premier niveau au pas 0 :

$$
\text{admissions}_p = \text{round}\left(baseL1 \times (1+\delta)^p\right)
$$

où $\delta = $ `delta_admissions` est le taux **saisi** du scénario.

### Trajectoire cible (nouvelle capacité)

$$
\text{admissions\_cible}_p = \text{round}\left(baseL1 \times (1+\tau)^p\right)
$$

où $\tau = $ `taux_cible_croissance` est le taux **saisi** de la cible.

La même formule de composition s'applique aux deux trajectoires : la cible
n'est ni un total final, ni une courbe linéaire inventée. Une cible de
croissance composée est le seul profil reconstituable sans donnée annuelle
supplémentaire.

### Écart, cumul et dépassement

$$
\text{ecart}_p = \text{admissions}_p - \text{admissions\_cible}_p
$$

$$
\text{ecart\_cumule} = \sum_{p=1}^{H} \text{ecart}_p
$$

$$
\text{depassement} = \exists\, p \text{ tel que } \text{ecart}_p > 0
$$

**Convention d'arrondi :** les écarts sont calculés sur les **entiers
arrondis** (admissions réellement produites), jamais sur les valeurs exactes
avant arrondi. Un écart exact positif peut donc être annulé par l'arrondi ;
`depassement` reste alors `false`. C'est un choix de fidélité : le moteur
compare ce qu'il produit, pas ce qu'il produirait en arithmétique exacte.

**Aucune division :** `baseL1 = 0` est accepté sans cas particulier
(cible nulle, écarts égaux aux admissions réelles).

### Invariance

Les champs de cible sont **descriptifs** : ils ne modifient ni $baseL1$, ni
$\delta$, ni les taux, ni les cohortes, ni les capacités. Ils s'ajoutent aux
mesures de chaque variante après le calcul des snapshots.

---

## C.2 Cas de référence calculés hors moteur

Périmètre synthétique d'étalonnage : $L1 = 10$, $L2 = 20$, $L3 = 30$,
total initial $= 60$, faculté A.

### Cas 1 — Croissance 10 % contre cible 5 % (progression gelée)

- Paramètres : $\delta = 0.10$, $\tau = 0.05$, $H = 3$,
  $\tau_{flux} = \{passage: 0, redoublement: 1, abandon: 0\}$.
- **Admissions réelles :**
  - $p=1$ : round(10 × 1.10) = **11**
  - $p=2$ : round(10 × 1.21) = round(12.1) = **12**
  - $p=3$ : round(10 × 1.331) = round(13.31) = **13**
- **Admissions cibles :**
  - $p=1$ : round(10 × 1.05) = round(10.5) = **11**
  - $p=2$ : round(10 × 1.1025) = round(11.025) = **11**
  - $p=3$ : round(10 × 1.157625) = round(11.57625) = **12**
- **Écarts :** 0 ; 1 ; 1 → **cumul = 2**, `depassement = true`.
  - Le pas 1 ne montre aucun dépassement numérique : les arrondis sont
    égaux. C'est le cas limite documenté par `SIM05-A6`.
- **Totaux (L1 accumule) :** L1 = 10 + 11 = 21 → **71** ;
  L1 = 21 + 12 = 33 → **83** ; L1 = 33 + 13 = 46 → **96**.
  `totaux = [60, 71, 83, 96]`.

### Cas 2 — Croissance 20 % contre cible 10 % (écarts croissants)

- Paramètres : $\delta = 0.20$, $\tau = 0.10$, $H = 3$, flux gelés.
- **Admissions réelles :** 12 ; 14 ; 17
  (round(12), round(14.4), round(17.28)).
- **Admissions cibles :** 11 ; 12 ; 13
  (round(11), round(12.1), round(13.31)).
- **Écarts :** 1 ; 2 ; 4 → **cumul = 7**, `depassement = true`.
- **Totaux :** L1 = 10 + 12 = 22 → 72 ; 22 + 14 = 36 → 86 ;
  36 + 17 = 53 → 103. `totaux = [60, 72, 86, 103]`.

### Cas 3 — Flux actifs et conservation stricte

- Paramètres : $\delta = 0.10$, $\tau = 0.05$, $H = 2$,
  $\tau_{flux} = \{passage: 0.5, redoublement: 0.3, abandon: 0.2\}$.
- **Pas 1** (admissions 11, cible 11, écart 0) :
  - L3 (30) : 15 partent, 9 redoublent, 6 abandons ;
  - L2 (20) : 10 partent vers L3, 6 redoublent, 4 abandons ;
  - L1 (10) : 5 partent vers L2, 3 redoublent, 2 abandons.
  - L1 = 3 + 11 = 14 ; L2 = 6 + 5 = 11 ; L3 = 9 + 10 = 19 ; total = **44**.
  - Conservation : 60 + 11 − 15 − 12 = 44.
- **Pas 2** (admissions 12, cible 11, écart 1) :
  - L3 (19) : round(9.5) = 10 partent, round(5.7) = 6 redoublent, 3 abandons ;
  - L2 (11) : round(5.5) = 6 partent, round(3.3) = 3 redoublent, 2 abandons ;
  - L1 (14) : round(7) = 7 partent, round(4.2) = 4 redoublent, 3 abandons.
  - L1 = 4 + 12 = 16 ; L2 = 3 + 7 = 10 ; L3 = 6 + 6 = 12 ; total = **38**.
  - Conservation : 44 + 12 − 10 − 8 = 38.
- `totaux = [60, 44, 38]` ; écarts `[0, 1]` → **cumul = 1**,
  `depassement = true`.

### Cas 4 — L'arrondi annule un dépassement exact marginal

- Paramètres : $\delta = 0.02$, $\tau = 0.01$, $H = 1$, flux gelés.
- Exact : 10 × 1.02 = 10.2 > 10.1 = 10 × 1.01.
- Arrondis : `round(10.2) = 10` ; `round(10.1) = 10` → **écart = 0**,
  `ecart_cible_cumule = 0`, `depassement = false`.
- Ce cas est **conforme au contrat** : la règle de domaine garantit
  $\delta > \tau$ sur les taux, pas un écart entier positif sur chaque pas.
  Aucune assertion de test n'exige un dépassement à chaque période.

### Cas 5 — Cible de stagnation ($\tau = 0$)

- Paramètres : $\delta = 0.10$, $\tau = 0.0$, $H = 3$, flux gelés.
- Réelles : 11 ; 12 ; 13. Cibles : 10 ; 10 ; 10.
- Écarts : 1 ; 2 ; 3 → **cumul = 6**, `depassement = true`.
- Une cible nulle est autorisée : « croissance supérieure à la
  stagnation » reste une comparaison de croissance interprétable.

---

## C.3 Ce que la cible SIM-05 n'est pas

| Confusion possible | Distinction |
|---|---|
| `cible_total` (objectif existant) | Scalaire constant comparé au **total** de chaque pas ; SIM-05 compare les **admissions** à une trajectoire. |
| Prévision démographique | La cible est une **intention saisie**, pas une projection statistique. |
| Seuil réglementaire | Aucune valeur par défaut, aucun seuil institutionnel : tout vient du scénario. |
| Contrainte dure | La cible ne bloque rien : elle informe. Les contraintes (`plafond_total`) restent seules bloquantes. |
