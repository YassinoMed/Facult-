# SIM-09 — 08 · Guide d'utilisation

**Scénario :** SIM-09 — Hausse du redoublement  
**Public :** directions, services de scolarité, pilotage pédagogique  
**Rappel :** appui à la décision uniquement — **aucune** décision réelle, **aucune** écriture métier

---

## H.1 À quoi sert SIM-09

SIM-09 répond à une question : **si le redoublement augmente, combien
d'étudiants s'accumulent et quel est l'effet sur les sortants, à admissions
identiques ?**

Exemples d'usage :

- une réforme rend le redoublement plus fréquent de 10 points : combien
  d'étudiants supplémentaires restent bloqués sur 2 ou 3 ans ?
- la hausse est absorbée par une baisse de l'abandon : les effectifs
  augmentent-ils quand même ?
- un plan de remédiation veut chiffrer l'impact d'une hausse avant qu'elle
  ne survienne.

---

## H.2 Paramètres à saisir

| Paramètre | Unité | Obligatoire | Exemple |
|---|---|---|---|
| `taux` (référence) | ratios | **oui** | `0.6 / 0.2 / 0.2` |
| `taux_modifie` | ratios | **oui** | `0.6 / 0.3 / 0.1` |
| `axe_variation` | texte | **oui** | `redoublement` |
| `sens_variation` | texte | **oui** | `hausse` |
| `delta_admissions` | ratio annuel | oui | `0.0` |
| `horizon_annees` | années | oui | `2` |
| `bornes.min` / `bornes.max` | ratios | oui | `0.0 / 0.0` |
| `niveau_id`, `groupes` | identifiants | non | restreindre le périmètre |

**Règles à connaître avant de saisir :**

1. Les **deux** jeux de taux sont complets : le moteur ne compense rien.
2. `taux_modifie.redoublement` doit être **strictement supérieur** au
   redoublement de référence.
3. Chaque jeu respecte `passage + redoublement + abandon ≤ 1`.
4. L'axe `redoublement` et le sens `hausse` sont **épinglés** par le contrat
   SIM-09 ; tout autre couple est refusé par le catalogue.
5. Gel, choc, cible de croissance, creux, amélioration ou dégradation du
   passage (SIM-07/08) sont incompatibles.
6. Sans descripteur, aucune comparaison n'est produite.

---

## H.3 Exemple complet

```json
{
  "taux":          { "passage": 0.6, "redoublement": 0.2, "abandon": 0.2 },
  "taux_modifie":  { "passage": 0.6, "redoublement": 0.3, "abandon": 0.1 },
  "axe_variation": "redoublement",
  "sens_variation": "hausse",
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

`hausse_redoublement = 0.1` ; `effet_sortants_cumules = +2` ;
`écart final = +8`.

**Lecture :** le redoublement plus élevé **retient** 8 étudiants de plus en
fin d'horizon ; le pipeline aval reçoit davantage d'étudiants au pas 2
(+2 sortants cumulés). L'effet sur les sortants dépend de l'horizon : il
peut être **nul** à horizon 1.

---

## H.4 Comment lire les indicateurs

| Indicateur | Signification | Piège à éviter |
|---|---|---|
| `hausse_redoublement` | Hausse déclarée du redoublement (magnitude positive) | Ce n'est pas une prévision : c'est l'hypothèse du scénario |
| `totaux_reference_reussite` | Effectifs si les taux de référence s'appliquaient | À comparer aux `totaux` du même pas |
| `ecarts_reussite_par_periode` | Écart signé par période | Écart **positif** = étudiants retenus en plus |
| `ecart_reussite_final` | Écart final | Positif dans le cas standard ; dépend de l'origine déclarée |
| `sortants_par_periode` | Sortants (dernier niveau du périmètre) | Si `niveau_id` filtre un niveau intermédiaire, ce sont des sortants de ce niveau |
| `effet_sortants_cumules` | Effet total sur les sortants | Peut être nul à horizon court : l'aval est lent |

---

## H.5 Limites connues

| Limite | Conséquence pratique |
|---|---|
| Résultat = effet **dans le modèle déclaré** | La conversion d'une politique réelle en taux modifiés reste une expertise humaine |
| Taux **globaux** | Pas de taux différenciés par niveau (LOT B) |
| « Sortants » = dernier niveau du **périmètre** | Pas nécessairement tout le programme si un niveau est filtré |
| Aucune combinaison multi-axes | SIM-13 traitera les combinaisons |
| Capacité estimée au pas final | L'accumulation peut créer des pics intermédiaires (Famille 4) |

---

## H.6 Ce que SIM-09 ne fait jamais

- Il ne modifie **aucune** note, **aucune** délibération, **aucun** taux
  officiel : les taux sont des hypothèses de scénario.
- Il ne réaffecte **aucun** étudiant, **aucune** cohorte, **aucun** groupe.
- Il ne décide pas si la hausse est acceptable ni comment y répondre.
- Il ne publie rien : la gouvernance reste un circuit humain séparé.
- Il n'invente aucune origine à la hausse : le jeu modifié est déclaré en
  entier, jamais compensé.
