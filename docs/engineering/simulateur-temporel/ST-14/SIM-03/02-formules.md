# SIM-03 — 02 · Formules, conventions et cas de référence

**Version de formules :** 1.0  
**Moteur :** `ServiceSimulationTemporelle` (version `1.2`)  
**Type d'extension :** `extension_temporelle`  

---

## C.1 Formules mathématiques

### Trajectoire temporelle des admissions

Soit $p \in \{1, \dots, H\}$ l'indice de l'année simulée, $baseL1$ l'effectif observé du premier niveau au pas initial 0, $D = \text{duree\_gel\_annees}$ la durée de gel, et $\delta = \text{delta\_admissions}$ le taux de reprise.

$$
\text{admissions}_p = 
\begin{cases} 
0 & \text{si } 1 \le p \le D \\
\text{round}\left(baseL1 \times (1 + \delta)^{p - D}\right) & \text{si } p > D 
\end{cases}
$$

### Conservation exacte des cohortes

Pour chaque niveau d'études ordonné $i \in \{1, \dots, N\}$ (avec $1 = \text{L1}$, $N = \text{L3}$) :

1. Flux sortants :
   - $\text{partent}_{i, p} = \text{round}(\text{effectif}_{i, p-1} \times \tau_{\text{passage}})$
   - $\text{redoublants}_{i, p} = \text{round}(\text{effectif}_{i, p-1} \times \tau_{\text{redoublement}})$
   - Plafond de conservation : si $\text{partent}_{i, p} + \text{redoublants}_{i, p} > \text{effectif}_{i, p-1}$, alors $\text{redoublants}_{i, p} = \max(0, \text{effectif}_{i, p-1} - \text{partent}_{i, p})$.
   - $\text{autres}_{i, p} = \text{effectif}_{i, p-1} - \text{partent}_{i, p} - \text{redoublants}_{i, p}$ (abandons et résidus d'arrondi).

2. Nouvelle population :
   - Pour le premier niveau ($i = 1$) :
     $$\text{effectif}_{1, p} = \text{redoublants}_{1, p} + \text{admissions}_p$$
   - Pour les niveaux intermédiaires et finaux ($i > 1$) :
     $$\text{effectif}_{i, p} = \text{redoublants}_{i, p} + \text{partent}_{i-1, p}$$

3. Total global et bilan de conservation :
   $$\text{total}_p = \sum_{i=1}^N \text{effectif}_{i, p}$$
   $$\text{total}_p = \text{total}_{p-1} + \text{admissions}_p - \text{diplômés}_p - \sum_{i=1}^N \text{autres}_{i, p}$$
   avec $\text{diplômés}_p = \text{partent}_{N, p}$.

---

## C.2 Cas de référence calculés hors moteur

Périmètre synthétique d'étalonnage : $L1 = 10$, $L2 = 20$, $L3 = 30$, total initial = $60$.

### Cas 1 — Gel complet sur tout l'horizon (3 ans), progression gelée
- Paramètres : $baseL1 = 10$, $D = 3$, $H = 3$, $\delta = 0.0$, $\tau = \{0, 1, 0\}$.
- **Pas 1 ($p=1 \le 3$) :**
  - $\text{admissions}_1 = 0$.
  - L1 = 10, L2 = 20, L3 = 30. Total = 60.
- **Pas 2 ($p=2 \le 3$) :**
  - $\text{admissions}_2 = 0$.
  - L1 = 10, L2 = 20, L3 = 30. Total = 60.
- **Pas 3 ($p=3 \le 3$) :**
  - $\text{admissions}_3 = 0$.
  - L1 = 10, L2 = 20, L3 = 30. Total = 60.
- Attendu : $\text{admissions} = [0, 0, 0]$, $\text{totaux} = [60, 60, 60, 60]$.

### Cas 2 — Gel temporaire (2 ans) suivi d'une reprise (+10 %), progression gelée
- Paramètres : $baseL1 = 10$, $D = 2$, $H = 4$, $\delta = 0.1$, $\tau = \{0, 1, 0\}$.
- **Pas 1 ($p=1 \le 2$) :** $\text{admissions}_1 = 0$. L1 = 10. Total = 60.
- **Pas 2 ($p=2 \le 2$) :** $\text{admissions}_2 = 0$. L1 = 10. Total = 60.
- **Pas 3 ($p=3 > 2$, $p - D = 1$) :**
  - $\text{admissions}_3 = \text{round}(10 \times 1.1^1) = 11$.
  - L1 = 10 (redoublants) + 11 (admis) = 21. Total = $21 + 20 + 30 = 71$.
- **Pas 4 ($p=4 > 2$, $p - D = 2$) :**
  - $\text{admissions}_4 = \text{round}(10 \times 1.1^2) = \text{round}(12.1) = 12$.
  - L1 = 21 (redoublants) + 12 (admis) = 33. Total = $33 + 20 + 30 = 83$.
- Attendu : $\text{admissions} = [0, 0, 11, 12]$, $\text{totaux} = [60, 60, 60, 71, 83]$.

### Cas 3 — Gel temporaire (2 ans) avec flux actifs de transition
- Paramètres : $baseL1 = 10$, $D = 2$, $H = 2$, $\tau = \{passage: 0.5, redoublement: 0.3, abandon: 0.2\}$.
- **Pas 1 ($p=1$) :**
  - $\text{admissions}_1 = 0$.
  - L3 (30) : 15 diplômés, 9 redoublants, 6 sortants.
  - L2 (20) : 10 vers L3, 6 redoublants, 4 sortants.
  - L1 (10) : 5 vers L2, 3 redoublants, 2 sortants.
  - Nouvelles cohortes :
    - L1 = 3 + 0 = 3
    - L2 = 6 + 5 = 11
    - L3 = 9 + 10 = 19
  - $\text{Total}_1 = 3 + 11 + 19 = 33$.
  - Bilan conservation : $60 + 0 - 15 (\text{diplômés}) - 12 (\text{sortants}) = 33$.
- **Pas 2 ($p=2$) :**
  - $\text{admissions}_2 = 0$.
  - L3 (19) : $\text{round}(19 \times 0.5) = 10$ diplômés, $\text{round}(19 \times 0.3) = 6$ redoublants, 3 sortants.
  - L2 (11) : $\text{round}(11 \times 0.5) = 6$ vers L3, $\text{round}(11 \times 0.3) = 3$ redoublants, 2 sortants.
  - L1 (3) : $\text{round}(3 \times 0.5) = 2$ vers L2, $\text{round}(3 \times 0.3) = 1$ redoublant, 0 sortant.
  - Nouvelles cohortes :
    - L1 = 1 + 0 = 1
    - L2 = 3 + 2 = 5
    - L3 = 6 + 6 = 12
  - $\text{Total}_2 = 1 + 5 + 12 = 18$.
  - Bilan conservation : $33 + 0 - 10 (\text{diplômés}) - 5 (\text{sortants}) = 18$.
- Attendu : $\text{admissions} = [0, 0]$, $\text{totaux} = [60, 33, 18]$.

