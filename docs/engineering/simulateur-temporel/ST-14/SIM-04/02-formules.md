# SIM-04 — 02 · Formules, conventions et cas de référence

**Version de formules :** 1.0  
**Moteur :** `ServiceSimulationTemporelle` (version `1.3`)  
**Type d'extension :** `extension_temporelle`  

---

## C.1 Formules mathématiques

### Trajectoire des admissions avec choc ponctuel

Soit $p \in \{1, \dots, H\}$ l'indice du pas simulé, $baseL1$ l'effectif
observé du premier niveau au pas 0, $\delta$ le taux de la trajectoire de
fond, $C$ le nombre d'admissions exceptionnelles et $p^*$ le pas déclaré.

Pour le contrat SIM-04 (sans gel) :

$$
\text{admissions}_p =
\text{round}\left(baseL1 \times (1+\delta)^p\right)
+ C \cdot \mathbf{1}[p = p^*]
$$

Le terme de fond est calculé **avant** l'ajout ; le choc ne modifie ni
$\delta$, ni $baseL1$, ni les pas suivants. Forme équivalente :

$$
\text{admissions}_p =
\begin{cases}
\text{round}\left(baseL1 \times (1+\delta)^p\right) + C & \text{si } p = p^* \\
\text{round}\left(baseL1 \times (1+\delta)^p\right) & \text{sinon}
\end{cases}
$$

### Interaction avec le gel (hors contrat SIM-04)

Le moteur applique d'abord la règle de gel (SIM-03), puis le choc :

$$
\text{admissions}_p =
\begin{cases}
0 & \text{si } duree\_gel \neq \varnothing \text{ et } p \le duree\_gel \\
\text{round}\left(baseL1 \times (1+\delta)^{p-duree\_gel}\right) & \text{si } p > duree\_gel \\
\text{round}\left(baseL1 \times (1+\delta)^p\right) & \text{si gel absent}
\end{cases}
+ C \cdot \mathbf{1}[p = p^*]
$$

Le schéma SIM-04 n'expose pas `duree_gel_annees` : la combinaison n'est pas
contractuelle et reste réservée à CR-06.

### Conservation exacte des cohortes

Pour chaque niveau ordonné $i \in \{1, \dots, N\}$ :

1. Flux sortants au pas $p$ :
   - $\text{partent}_{i,p} = \text{round}(\text{effectif}_{i,p-1} \times \tau_{passage})$
   - $\text{redoublants}_{i,p} = \text{round}(\text{effectif}_{i,p-1} \times \tau_{redoublement})$
   - Si $\text{partent}_{i,p} + \text{redoublants}_{i,p} > \text{effectif}_{i,p-1}$,
     alors $\text{redoublants}_{i,p} = \max(0, \text{effectif}_{i,p-1} - \text{partent}_{i,p})$.
   - $\text{autres}_{i,p} = \text{effectif}_{i,p-1} - \text{partent}_{i,p} - \text{redoublants}_{i,p}$
     (abandons et résidus d'arrondi).

2. Nouvelles cohortes :
   - Premier niveau : $\text{effectif}_{1,p} = \text{redoublants}_{1,p} + \text{admissions}_p$
   - Niveaux supérieurs : $\text{effectif}_{i,p} = \text{redoublants}_{i,p} + \text{partent}_{i-1,p}$

3. Total et bilan :
   $$\text{total}_p = \sum_{i=1}^{N} \text{effectif}_{i,p}$$
   $$\text{total}_p = \text{total}_{p-1} + \text{admissions}_p - \text{diplômés}_p - \sum_{i=1}^{N} \text{autres}_{i,p}$$

---

## C.2 Cas de référence calculés hors moteur

Périmètre synthétique d'étalonnage : $L1 = 10$, $L2 = 20$, $L3 = 30$,
total initial $= 60$, faculté A.

### Cas 1 — Choc ponctuel sans effet sur la trajectoire de fond

- Paramètres : $baseL1 = 10$, $\delta = 0.0$, $H = 3$, $C = 5$, $p^* = 2$,
  $\tau = \{passage: 0, redoublement: 1, abandon: 0\}$ (progression gelée).
- **Pas 1** ($p \neq 2$) : $\text{admissions}_1 = \text{round}(10 \times 1^1) = 10$.
  - L1 = 10 (redoublants) + 10 = 20 ; total = $20 + 20 + 30 = 70$.
- **Pas 2** ($p = 2$) : $\text{admissions}_2 = 10 + 5 = 15$.
  - L1 = 20 + 15 = 35 ; total = $35 + 20 + 30 = 85$.
- **Pas 3** ($p \neq 2$) : $\text{admissions}_3 = \text{round}(10 \times 1^3) = 10$
  — **pas 15** : le choc n'est pas composé.
  - L1 = 35 + 10 = 45 ; total = $45 + 20 + 30 = 95$.
- Attendu : $\text{admissions} = [10, 15, 10]$,
  $\text{totaux} = [60, 70, 85, 95]$.

### Cas 2 — Choc ponctuel avec flux actifs et conservation

- Paramètres : $baseL1 = 10$, $\delta = 0.0$, $H = 2$, $C = 5$, $p^* = 2$,
  $\tau = \{passage: 0.5, redoublement: 0.3, abandon: 0.2\}$.
- **Pas 1** ($p \neq 2$) : $\text{admissions}_1 = 10$.
  - L3 (30) : 15 diplômés, 9 redoublants, 6 sortants.
  - L2 (20) : 10 vers L3, 6 redoublants, 4 sortants.
  - L1 (10) : 5 vers L2, 3 redoublants, 2 sortants.
  - L1 = 3 + 10 = 13 ; L2 = 6 + 5 = 11 ; L3 = 9 + 10 = 19.
  - Total = 43 ; conservation : $60 + 10 - 15 - 12 = 43$.
- **Pas 2** ($p = 2$) : $\text{admissions}_2 = 10 + 5 = 15$.
  - L3 (19) : $\text{round}(9.5) = 10$ diplômés, $\text{round}(5.7) = 6$ redoublants, 3 sortants.
  - L2 (11) : $\text{round}(5.5) = 6$ vers L3, $\text{round}(3.3) = 3$ redoublants, 2 sortants.
  - L1 (13) : $\text{round}(6.5) = 7$ vers L2, $\text{round}(3.9) = 4$ redoublants, 2 sortants.
  - L1 = 4 + 15 = 19 ; L2 = 3 + 7 = 10 ; L3 = 6 + 6 = 12.
  - Total = 41 ; conservation : $43 + 15 - 10 - 7 = 41$.
- Attendu : $\text{admissions} = [10, 15]$, $\text{totaux} = [60, 43, 41]$.

### Cas 3 — Capacité : le choc crée un besoin supplémentaire de groupe

- Périmètre restreint à L1 (`niveau_id`), un groupe réel de capacité 15,
  $\tau = \{0, 1, 0\}$, $H = 2$, $\delta = 0.0$.
- **Sans choc** : pas 1 = 10 + 10 = 20 ; pas 2 = 20 + 10 = 30.
  - Capacité unitaire = 15 ; groupes requis = $\lceil 30/15 \rceil = 2$ ;
    déficit = $2 - 1 = 1$ ; places manquantes = $30 - 15 = 15$.
- **Avec choc** $C = 5$, $p^* = 2$ : pas 1 = 20 ; pas 2 = 20 + 15 = 35.
  - Groupes requis = $\lceil 35/15 \rceil = 3$ ; déficit = $3 - 1 = 2$ ;
    places manquantes = $35 - 15 = 20$.
- Attendu : 30 puis 35 en effectif final ; 2 puis 3 groupes requis ;
  1 puis 2 groupes de déficit ; 15 puis 20 places manquantes.

> L'estimateur de capacité travaille sur le **dernier snapshot** de la
> variante. Le cas 3 place volontairement le choc au dernier pas pour que
> pic et état final coïncident.
