# SIM-04 — 08 · Guide d'utilisation et interprétation

**Scénario :** SIM-04 — Admissions exceptionnelles  
**Module :** Simulation Temporelle  
**Public cible :** Doyens, Vice-Doyens aux études, responsables de scolarité, commission des capacités  

---

## H.1 Finalité métier

SIM-04 modélise un **afflux ponctuel** d'étudiants en première année :
transferts, contingent rectoral supplémentaire, retour de suspension,
convention de partenariat ou décision de rattrapage.

Il répond aux questions suivantes :

- Quel est l'effet immédiat d'un accueil exceptionnel sur les effectifs ?
- Cet afflux se propage-t-il aux niveaux supérieurs via les taux de passage ?
- Combien de groupes et de places supplémentaires faut-il ouvrir ?
- La trajectoire des années suivantes revient-elle à la normale, ou le choc
  déforme-t-il durablement la projection ?

---

## H.2 Paramètres clés

1. **`admissions_exceptionnelles`** — nombre d'étudiants supplémentaires.
   - Unité : étudiants.
   - Doit être $\ge 1$ ; un choc nul n'a pas de sens (utiliser SIM-01/SIM-02).
2. **`pas_exceptionnel`** — année du choc.
   - `1` = première année projetée.
   - Doit être comprise entre `1` et `horizon_annees`.
   - Le choc n'est appliqué **qu'une seule fois**.
3. **`delta_admissions`** — trajectoire de fond.
   - Le choc ne modifie pas ce taux : les années suivantes retrouvent la
     trajectoire de référence.
4. **`taux`** — `passage`, `redoublement`, `abandon` (obligatoires).
   - Ils déterminent la propagation du choc vers les niveaux supérieurs.
5. **`niveau_id` / `groupes`** — périmètre optionnel.
   - Utile pour concentrer l'analyse sur une année ou un groupe.

---

## H.3 Lecture des résultats

- **`admissions_par_periode`** : la valeur du pas $p^*$ est la seule majorée.
  Si le pas suivant affiche la valeur de fond, le choc est bien ponctuel.
- **`totaux`** : le pic dépend du pas du choc et des taux de flux.
- **`groupes_theoriques_requis_total`**, **`deficit_groupes`**,
  **`places_manquantes`** : estimés sur l'effectif **final**. Un choc
  intermédiaire peut produire un pic supérieur à l'état final ; dans ce cas,
  l'estimation de capacité est un minorant du besoin réel (réserve
  `SIM04-R3`).
- **`depassement_capacite`** : signale que l'effectif final dépasse la
  capacité installée. Ce n'est pas un blocage : c'est un signal d'arbitrage.

---

## H.4 Exemple d'interprétation

Avec L1 = 10, L2 = 20, L3 = 30, un choc de 5 étudiants au pas 2 et une
progression gelée :

| Pas | Admissions | Total |
|---|---|---|
| 0 | — | 60 |
| 1 | 10 | 70 |
| 2 | **15** | **85** |
| 3 | 10 | 95 |

Le pas 3 retrouve 10 admissions : l'afflux n'a pas gonflé la trajectoire de
fond. L'écart de 5 étudiants reste néanmoins présent dans les effectifs, car
les étudiants admis poursuivent leur parcours.

---

## H.5 Avertissement institutionnel

> **Outil d'aide à la décision.**  
> Les projections sont indicatives. Aucune inscription, aucun groupe et
> aucune capacité ne sont créés ou modifiés dans le système d'information
> académique réel. Toute décision d'accueil exceptionnel relève des instances
> compétentes.
