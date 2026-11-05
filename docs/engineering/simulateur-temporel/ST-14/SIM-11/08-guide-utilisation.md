# SIM-11 — 08 · Guide d'utilisation

**Scénario :** SIM-11 — Hausse de l'abandon  
**Public :** directions, services de scolarité, pilotage pédagogique  
**Rappel :** appui à la décision uniquement — **aucune** décision réelle, **aucune** écriture métier

---

## H.1 À quoi sert SIM-11

SIM-11 répond à une question : **si l'abandon augmente, que deviennent les
effectifs et les sortants, compte tenu du mouvement joint déclaré qui finance
cette hausse ?**

Exemples d'usage :

- une réforme rend l'abandon plus fréquent de 10 points, financé par une
  baisse du redoublement : combien d'étudiants restent dans le système ?
- la hausse est absorbée par le « reste » (somme des taux < 1) : les effectifs
  changent-ils ?
- la hausse est financée par une baisse du passage : quel est l'effet aval sur
  les sortants ?

**Point clé — à lire avant toute interprétation :** dans le moteur,
`abandon` **n'est pas réinjecté** dans la progression. Seuls `passage` et
`redoublement` alimentent le pas suivant. Une hausse de l'abandon **seule**,
absorbée par le reste, est donc **exactement neutre** (cas B). Les effets
observés proviennent du taux qui finance la hausse (`redoublement↓`,
`passage↓`), jamais d'une causalité isolée de l'abandon. SIM-11 mesure un
**mouvement joint déclaré** ; il ne juge pas et ne recommande rien.

---

## H.2 Paramètres à saisir

| Paramètre | Unité | Obligatoire | Exemple |
|---|---|---|---|
| `taux` (référence) | ratios | **oui** | `0.6 / 0.2 / 0.2` |
| `taux_modifie` | ratios | **oui** | `0.6 / 0.1 / 0.3` |
| `axe_variation` | texte | **oui** | `abandon` |
| `sens_variation` | texte | **oui** | `hausse` |
| `delta_admissions` | ratio annuel | oui | `0.0` |
| `horizon_annees` | années | oui | `2` |
| `bornes.min` / `bornes.max` | ratios | oui | `0.0 / 0.0` |
| `niveau_id`, `groupes` | identifiants | non | restreindre le périmètre |

**Règles à connaître avant de saisir :**

1. Les **deux** jeux de taux sont complets : le moteur ne compense rien.
2. `taux_modifie.abandon` doit être **strictement supérieur** à l'abandon de
   référence.
3. Chaque jeu respecte `passage + redoublement + abandon ≤ 1`.
4. L'axe `abandon` et le sens `hausse` sont **épinglés** par le contrat
   SIM-11 ; tout autre couple est refusé par le catalogue.
5. Gel, choc, cible de croissance, creux, amélioration ou dégradation du
   passage (SIM-07/08) et variations du redoublement (SIM-09/10) sont
   incompatibles.
6. Sans descripteur, aucune comparaison n'est produite.
7. **Déclarez toujours le mouvement joint** : la hausse de l'abandon doit
   être financée explicitement (redoublement↓, passage↓, ou reste). Le moteur
   ne l'invente pas.

---

## H.3 Exemple complet

```json
{
  "taux":          { "passage": 0.6, "redoublement": 0.2, "abandon": 0.2 },
  "taux_modifie":  { "passage": 0.6, "redoublement": 0.1, "abandon": 0.3 },
  "axe_variation": "abandon",
  "sens_variation": "hausse",
  "delta_admissions": 0.0,
  "bornes": { "min": 0.0, "max": 0.0 },
  "horizon_annees": 2
}
```

Lecture du résultat (L1=10, L2=20, L3=30) :

| Pas | Total modifié | Total référence | Écart | Sortants | Sortants réf. |
|---|---|---|---|---|---|
| 1 | 34 | 40 | −6 | 18 | 18 |
| 2 | 26 | 31 | −5 | 9 | 11 |

`hausse_abandon = 0.1` ; `effet_sortants_cumules = -2` ;
`écart final = -5`.

**Lecture :** la hausse de l'abandon est ici financée par une baisse du
redoublement. Les effectifs diminuent de 5 étudiants en fin d'horizon et le
pipeline aval reçoit **2 sortants de moins**. La hausse de l'abandon n'a pas
produit de diplômés supplémentaires : elle a déplacé les étudiants hors du
système.

### Contre-exemple indispensable — hausse absorbée par le reste

```json
{
  "taux":         { "passage": 0.6, "redoublement": 0.2, "abandon": 0.1 },
  "taux_modifie": { "passage": 0.6, "redoublement": 0.2, "abandon": 0.2 },
  "axe_variation": "abandon",
  "sens_variation": "hausse"
}
```

Résultat : totaux `[60, 40, 31]` **identiques** dans les deux trajectoires,
écarts `[0, 0]`, effet sortants `0`. **C'est normal :** aucun flux réinjecté
n'a changé. Une hausse de l'abandon « absorbée » ne produit aucun effet dans
ce modèle.

---

## H.4 Comment lire les indicateurs

| Indicateur | Signification | Piège à éviter |
|---|---|---|
| `hausse_abandon` | Hausse déclarée de l'abandon (magnitude **positive**) | Ce n'est pas une prévision ni un effet : c'est l'hypothèse du scénario |
| `totaux_reference_reussite` | Effectifs si les taux de référence s'appliquaient | À comparer aux `totaux` du même pas |
| `ecarts_reussite_par_periode` | Écart signé par période | Écart **négatif** = effectifs réduits |
| `ecart_reussite_final` | Écart final | Ne résume pas l'effet aval |
| `sortants_par_periode` | Sortants (dernier niveau du périmètre) | Si `niveau_id` filtre un niveau intermédiaire, ce sont des sortants de ce niveau |
| `effet_sortants_cumules` | Effet total sur les sortants | **Peut être négatif, nul ou positif** ; nul à horizon 1 si l'aval n'est pas atteint |

---

## H.5 Limites connues

| Limite | Conséquence pratique |
|---|---|
| **`abandon` non réinjecté** | Une hausse seule est neutre ; aucun effet causal isolé n'est mesuré |
| Résultat = effet **dans le modèle déclaré** | La conversion d'une politique réelle en taux modifiés reste une expertise humaine |
| Taux **globaux** | Pas de taux différenciés par niveau (LOT B) |
| « Sortants » = dernier niveau du **périmètre** | Pas nécessairement tout le programme si un niveau est filtré |
| Aucune combinaison multi-axes | SIM-13 traitera les combinaisons |
| Capacité estimée au pas final | L'accumulation peut créer des pics intermédiaires (Famille 4) |
| Aucune sensibilité OAT à l'abandon | L'abandon n'étant pas un flux réinjecté, aucune ligne `taux_abandon` n'existe ; ce n'est pas un oubli |
| Aucun signe favorable supposé | Une hausse peut réduire les sortants ; le scénario ne recommande rien |

---

## H.6 Ce que SIM-11 ne fait jamais

- Il ne modifie **aucune** note, **aucune** délibération, **aucun** taux
  officiel : les taux sont des hypothèses de scénario.
- Il ne réaffecte **aucun** étudiant, **aucune** cohorte, **aucun** groupe.
- Il ne décide pas si la hausse est souhaitable ni comment l'obtenir.
- Il ne publie rien : la gouvernance reste un circuit humain séparé.
- Il n'invente aucune origine à la hausse : le jeu modifié est déclaré en
  entier, jamais compensé.
- Il n'attribue jamais à l'abandon seul un effet produit par un autre taux.
- Il ne transforme jamais une hausse de l'abandon en « dégradation » par
  défaut : le scénario mesure, il ne juge pas.
