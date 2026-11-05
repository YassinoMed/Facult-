# SIM-12 — 08 · Guide d'utilisation

**Scénario :** SIM-12 — Réduction de l'abandon  
**Public :** directions, services de scolarité, pilotage pédagogique  
**Rappel :** appui à la décision uniquement — **aucune** décision réelle, **aucune** écriture métier

---

## H.1 À quoi sert SIM-12

SIM-12 répond à une question : **si l'abandon baisse, que deviennent les
effectifs et les sortants, compte tenu du mouvement joint déclaré qui finance
cette baisse ?**

Exemples d'usage :

- une politique de rétention réduit l'abandon de 10 points, financée par une
  hausse du redoublement : combien d'étudiants restent dans le système ?
- la baisse est absorbée par le « reste » (somme des taux < 1) : les effectifs
  changent-ils ?
- la baisse est financée par une hausse du passage : quel est l'effet aval sur
  les sortants ?

**Point clé — à lire avant toute interprétation :** dans le moteur,
`abandon` **n'est pas réinjecté** dans la progression. Seuls `passage` et
`redoublement` alimentent le pas suivant. Une baisse de l'abandon **seule**,
absorbée par le reste, est donc **exactement neutre** (cas B). Les effets
observés proviennent du taux qui finance la baisse (`redoublement↑`,
`passage↑`), jamais d'une causalité isolée de l'abandon. SIM-12 mesure un
**mouvement joint déclaré** ; il ne juge pas et ne recommande rien.

---

## H.2 Paramètres à saisir

| Paramètre | Unité | Obligatoire | Exemple |
|---|---|---|---|
| `taux` (référence) | ratios | **oui** | `0.6 / 0.2 / 0.2` |
| `taux_modifie` | ratios | **oui** | `0.6 / 0.3 / 0.1` |
| `axe_variation` | texte | **oui** | `abandon` |
| `sens_variation` | texte | **oui** | `baisse` |
| `delta_admissions` | ratio annuel | oui | `0.0` |
| `horizon_annees` | années | oui | `2` |
| `bornes.min` / `bornes.max` | ratios | oui | `0.0 / 0.0` |
| `niveau_id`, `groupes` | identifiants | non | restreindre le périmètre |

**Règles à connaître avant de saisir :**

1. Les **deux** jeux de taux sont complets : le moteur ne compense rien.
2. `taux_modifie.abandon` doit être **strictement inférieur** à l'abandon de
   référence.
3. Chaque jeu respecte `passage + redoublement + abandon ≤ 1`.
4. L'axe `abandon` et le sens `baisse` sont **épinglés** par le contrat
   SIM-12 ; tout autre couple est refusé par le catalogue.
5. Gel, choc, cible de croissance, creux, amélioration ou dégradation du
   passage (SIM-07/08) et variations du redoublement (SIM-09/10) sont
   incompatibles.
6. Sans descripteur, aucune comparaison n'est produite.
7. **Déclarez toujours le mouvement joint** : la baisse de l'abandon doit
   être financée explicitement (redoublement↑, passage↑, ou reste). Le moteur
   ne l'invente pas.

---

## H.3 Exemple complet

```json
{
  "taux":          { "passage": 0.6, "redoublement": 0.2, "abandon": 0.2 },
  "taux_modifie":  { "passage": 0.6, "redoublement": 0.3, "abandon": 0.1 },
  "axe_variation": "abandon",
  "sens_variation": "baisse",
  "delta_admissions": 0.0,
  "bornes": { "min": 0.0, "max": 0.0 },
  "horizon_annees": 2
}
```

Lecture du résultat (L1=10, L2=20, L3=30) :

| Pas | Total modifié | Total référence | Écart | Sortants | Sortants réf. |
|---|---|---|---|---|---|
| 1 | 46 | 40 | +6 | 18 | 18 |
| 2 | 39 | 31 | +8 | 13 | 11 |

`baisse_abandon = 0.1` ; `ecart_reussite_final = 8` ;
`effet_sortants_cumules = +2`.

**Lecture :** la baisse de l'abandon est ici financée par une hausse du
redoublement. Les effectifs augmentent de 8 étudiants en fin d'horizon et le
pipeline aval reçoit **2 sortants de plus**. La baisse de l'abandon n'a pas
produit à elle seule ce résultat : c'est le redoublement réinjecté qui
maintient les étudiants dans le système.

### Contre-exemple indispensable — baisse absorbée par le reste

```json
{
  "taux":         { "passage": 0.6, "redoublement": 0.2, "abandon": 0.2 },
  "taux_modifie": { "passage": 0.6, "redoublement": 0.2, "abandon": 0.1 },
  "axe_variation": "abandon",
  "sens_variation": "baisse"
}
```

Résultat : totaux `[60, 40, 31]` **identiques** dans les deux trajectoires,
écarts `[0, 0]`, effet sortants `0`. **C'est normal :** aucun flux réinjecté
n'a changé. Une baisse de l'abandon « absorbée » ne produit aucun effet dans
ce modèle, même si elle paraît intuitivement favorable.

---

## H.4 Comment lire les indicateurs

| Indicateur | Signification | Piège à éviter |
|---|---|---|
| `baisse_abandon` | Baisse déclarée de l'abandon (magnitude **positive**) | Ce n'est pas une prévision ni un effet : c'est l'hypothèse du scénario |
| `totaux_reference_reussite` | Effectifs si les taux de référence s'appliquaient | À comparer aux `totaux` du même pas |
| `ecarts_reussite_par_periode` | Écart signé par période | Écart **positif** = effectifs accrus |
| `ecart_reussite_final` | Écart final | Ne résume pas l'effet aval |
| `sortants_par_periode` | Sortants (dernier niveau du périmètre) | Si `niveau_id` filtre un niveau intermédiaire, ce sont des sortants de ce niveau |
| `effet_sortants_cumules` | Effet total sur les sortants | **Peut être négatif, nul ou positif** ; nul à horizon 1 si l'aval n'est pas atteint |

---

## H.5 Limites connues

| Limite | Conséquence pratique |
|---|---|
| **`abandon` non réinjecté** | Une baisse seule est neutre ; aucun effet causal isolé n'est mesuré |
| Résultat = effet **dans le modèle déclaré** | La conversion d'une politique réelle en taux modifiés reste une expertise humaine |
| Taux **globaux** | Pas de taux différenciés par niveau (LOT B) |
| « Sortants » = dernier niveau du **périmètre** | Pas nécessairement tout le programme si un niveau est filtré |
| Aucune combinaison multi-axes | SIM-13 traitera les combinaisons |
| Capacité estimée au pas final | L'accumulation peut créer des pics intermédiaires (Famille 4) |
| Aucune sensibilité OAT à l'abandon | L'abandon n'étant pas un flux réinjecté, aucune ligne `taux_abandon` n'existe ; ce n'est pas un oubli |
| Aucun gain de rétention supposé | Une baisse peut accroître les effectifs et les sortants, ou rien du tout ; le scénario ne recommande rien |
| Aucun coût évité calculé | Aucun coût unitaire validé (LOT D) |

---

## H.6 Ce que SIM-12 ne fait jamais

- Il ne modifie **aucune** note, **aucune** délibération, **aucun** taux
  officiel : les taux sont des hypothèses de scénario.
- Il ne réaffecte **aucun** étudiant, **aucune** cohorte, **aucun** groupe.
- Il ne décide pas si la baisse est souhaitable ni comment l'obtenir.
- Il ne publie rien : la gouvernance reste un circuit humain séparé.
- Il n'invente aucune origine à la baisse : le jeu modifié est déclaré en
  entier, jamais compensé.
- Il n'attribue jamais à l'abandon seul un effet produit par un autre taux.
- Il ne transforme jamais une baisse de l'abandon en « amélioration » par
  défaut : le scénario mesure, il ne juge pas.
