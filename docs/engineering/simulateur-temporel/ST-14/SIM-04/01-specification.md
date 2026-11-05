# SIM-04 — 01 · Spécification

**Scénario :** SIM-04 — Admissions exceptionnelles  
**Famille :** 1 — Démographie et admissions  
**Lot :** A  
**Objectif :** Simuler un afflux ponctuel d'admissions en première année, mesurer son effet sur les effectifs, les flux et les besoins en groupes, et prouver que la trajectoire de fond des années suivantes reste inchangée.

---

## A. Acteurs et gouvernance

| Acteur | Rôle dans SIM-04 |
|---|---|
| Responsable de scolarité / Doyen | Déclare le pas du choc, son volume et les taux de flux. |
| Direction académique | Consulte l'effet immédiat sur les effectifs et la diplomation. |
| Commission des capacités | Arbitre les groupes et places à ouvrir pour absorber le choc. |
| Gouvernance / Conseil de faculté | Examine la simulation avant toute décision d'accueil exceptionnel. |

---

## B. Périmètre et population

- Faculté spécifique ou périmètre global (`faculte_id`).
- Niveau optionnel (`niveau_id`) ou groupes optionnels (`groupes`).
- Cohorte d'entrée : premier niveau ordonné (ex. L1), base d'admissions `baseL1`.
- Année universitaire de référence obligatoire.
- Horizon de simulation $H \in [1, 6]$ années.
- Pas exceptionnel $p^* \in [1, H]$ : **un seul** choc par scénario.

---

## C. Paramètres contractuels

| Paramètre | Type | Requis | Domaine | Description |
|---|---|---|---|---|
| `admissions_exceptionnelles` | integer | Oui | $\ge 1$ | Nombre d'admissions **supplémentaires**, ajoutées une seule fois au pas exceptionnel. |
| `pas_exceptionnel` | integer | Oui | $[1, H]$ | Pas de simulation (1 = première année projetée) où le choc s'applique. |
| `horizon_annees` | integer | Oui | $[1, 6]$ | Horizon total de projection. |
| `delta_admissions` | number | Oui | $[-0.5, 2.0]$ | Taux annuel de la trajectoire de fond ; **non modifié** par le choc. |
| `bornes.min` | number | Oui | $[-0.5, delta]$ | Borne inférieure d'exploration des variantes. |
| `bornes.max` | number | Oui | $[delta, 2.0]$ | Borne supérieure d'exploration des variantes. |
| `taux.passage` | number | Oui | $[0, 1]$ | Taux annuel de passage au niveau supérieur. |
| `taux.redoublement` | number | Oui | $[0, 1]$ | Taux annuel de redoublement. |
| `taux.abandon` | number | Oui | $[0, 1]$ | Taux annuel d'abandon / sortie non diplômante. |
| `niveau_id` | integer | Non | $\ge 1$ | Filtre optionnel sur un niveau. |
| `groupes` | array | Non | IDs $\ge 1$ | Filtre optionnel sur des groupes (exige `niveau_id`). |

---

## D. Règles de domaine strictes

1. **Indissociabilité :** `pas_exceptionnel` et `admissions_exceptionnelles`
   doivent être fournis ensemble. Un seul des deux ⇒ `DomainException`.
2. **Pas réellement simulé :** $1 \le p^* \le H$. Un pas hors horizon ne serait
   jamais appliqué et produirait un résultat trompeur.
3. **Choc strictement positif :** $C \ge 1$. Un choc nul relève de la
   trajectoire de fond (SIM-01/SIM-02) et est refusé.
4. **Un seul choc :** le contrat SIM-04 ne modélise ni chocs multiples ni
   chocs récurrents (réservés à SIM-06 / CR-05 / CR-06).
5. **Cohérence des flux :** $passage + redoublement + abandon \le 1.0001$.
6. **Absence de valeurs par défaut :** taux, horizon, delta, bornes et choc
   sont explicitement fournis par l'appelant.
7. **Non-composition :** le choc n'entre pas dans le calcul de la trajectoire
   de fond ; il est ajouté au pas $p^*$ uniquement.

---

## E. Résultats attendus

- `admissions_par_periode` : trajectoire de fond, **plus** le choc au pas
  déclaré, puis retour exact à la trajectoire de fond.
- `totaux` : effet immédiat du choc, propagation normale via les taux de flux.
- `total_final`, `variations_annuelles`, `ecart_absolu`, `ecart_pourcentage`.
- `groupes_theoriques_requis_total`, `deficit_groupes`,
  `taux_saturation_global_pct`, `places_manquantes` — estimés **au pas final**
  par l'estimateur existant ; le pic intermédiaire relève de la Famille 4.
- Conservation arithmétique stricte des effectifs à chaque pas.
- Aucune écriture sur les données métier officielles.
