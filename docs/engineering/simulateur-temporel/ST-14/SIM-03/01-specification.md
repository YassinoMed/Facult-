# SIM-03 — 01 · Spécification

**Scénario :** SIM-03 — Gel des admissions  
**Famille :** 1 — Démographie et admissions  
**Lot :** A  
**Objectif :** Simuler l'interruption temporaire des nouvelles admissions universitaires pendant une durée définie, analyser l'assèchement progressif des cohortes, la résorption de la saturation physique et les conditions d'une éventuelle reprise.

---

## A. Acteurs et gouvernance

| Acteur | Rôle dans SIM-03 |
|---|---|
| Responsable de scolarité / Doyen | Paramètre la durée du moratoire, l'horizon et les taux de progression des cohortes existantes. |
| Direction académique | Consulte les projections de diplomation et d'effectifs résiduels. |
| Conseil de faculté / Gouvernance | Examine la simulation avant tout arbitrage sur la fermeture temporaire d'une filière. |

---

## B. Périmètre et population

- Faculté spécifique ou périmètre global (`faculte_id`).
- Niveau optionnel (`niveau_id`) ou groupes optionnels (`groupes`).
- Cohorte d'entrée : premier niveau ordonné (ex: L1), base d'admissions $baseL1$.
- Année universitaire de référence : obligatoire.
- Horizon de simulation $H \in [1, 6]$ années.
- Durée du gel $D \in [1, H]$ années.

---

## C. Paramètres contractuels

| Paramètre | Type | Requis | Domaine | Description |
|---|---|---|---|---|
| `duree_gel_annees` | integer | Oui | $[1, H]$ | Nombre d'années consécutives d'interruption totale des admissions à compter du pas 1. |
| `horizon_annees` | integer | Oui | $[1, 6]$ | Horizon total de simulation en années. |
| `delta_admissions` | number | Oui | $[-0.5, 2.0]$ | Taux annuel de variation appliqué lors de la reprise post-gel (ou $0.0$ si reprise au niveau initial). |
| `bornes.min` | number | Oui | $[-0.5, delta]$ | Borne inférieure d'exploration des variantes. |
| `bornes.max` | number | Oui | $[delta, 2.0]$ | Borne supérieure d'exploration des variantes. |
| `taux.passage` | number | Oui | $[0, 1]$ | Taux annuel de passage au niveau supérieur. |
| `taux.redoublement` | number | Oui | $[0, 1]$ | Taux annuel de redoublement dans le même niveau. |
| `taux.abandon` | number | Oui | $[0, 1]$ | Taux annuel d'abandon / sortie non diplômante. |
| `niveau_id` | integer | Non | $\ge 1$ | Filtre optionnel sur un niveau. |
| `groupes` | array | Non | IDs $\ge 1$ | Filtre optionnel sur des groupes (exige `niveau_id`). |

---

## D. Règles de domaine strictes

1. **Durée de gel positive et non nulle :** $D \ge 1$. Un gel de 0 an relève de `SIM-01` (stabilité).
2. **Durée de gel bornée par l'horizon :** $D \le H$. Une interruption plus longue que l'horizon est rejetée par une `DomainException`.
3. **Gel complet sur l'horizon ($D = H$) :** les admissions sont nulles ($0$) à chaque période de la simulation.
4. **Cohérence des flux :** $passage + redoublement + abandon \le 1.0001$.
5. **Absence de valeurs par défaut :** tous les paramètres obligatoires doivent être explicitement fournis.

---

## E. Résultats attendus

- Trajectoire d'admissions par période `admissions_par_periode` : $0$ pour $p \in [1, D]$, et valeur calculée pour $p > D$.
- Évolution des effectifs globaux `totaux` : constatation de la baisse due aux sorties sans renouvellement.
- Décroissance ou résorption des besoins en groupes pédagogiques et saturation.
- Conservation arithmétique rigoureuse des effectifs à chaque pas.

