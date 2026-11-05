# SIM-08 — 08 · Guide d'utilisation

**Scénario :** SIM-08 — Dégradation de la réussite  
**Public :** directions, services de scolarité, pilotage pédagogique  
**Rappel :** appui à la décision uniquement — **aucune** décision réelle, **aucune** écriture métier

---

## H.1 À quoi sert SIM-08

SIM-08 répond à une question : **si le taux de passage recule, quel est
l'effet sur les effectifs (étudiants bloqués) et sur les sortants
(diplômés perdus), à admissions identiques ?**

Exemples d'usage :

- une crise pédagogique fait chuter le passage de 20 points : combien
  d'étudiants s'accumulent et combien de diplômés sont perdus sur 3 ans ?
- la baisse touche d'abord les abandons et le « reste » : les effectifs
  baissent-ils au lieu d'augmenter ?
- un plan de contingence veut chiffrer l'impact d'une dégradation avant
  qu'elle ne survienne.

---

## H.2 Paramètres à saisir

| Paramètre | Unité | Obligatoire | Exemple |
|---|---|---|---|
| `taux` (référence) | ratios | **oui** | `0.7 / 0.1 / 0.2` |
| `taux_degrade` | ratios | **oui** | `0.5 / 0.3 / 0.2` |
| `delta_admissions` | ratio annuel | oui | `0.0` |
| `horizon_annees` | années | oui | `2` |
| `bornes.min` / `bornes.max` | ratios | oui | `0.0 / 0.0` |
| `niveau_id`, `groupes` | identifiants | non | restreindre le périmètre |

**Règles à connaître avant de saisir :**

1. Les **deux** jeux de taux sont complets : le moteur ne compense rien.
2. `taux_degrade.passage` doit être **strictement inférieur** au passage de
   référence.
3. Chaque jeu respecte `passage + redoublement + abandon ≤ 1`.
4. Gel, choc, cible de croissance, creux **et amélioration** (SIM-07) sont
   incompatibles.
5. Sans `taux_degrade`, aucune comparaison n'est produite.

---

## H.3 Exemple complet

```json
{
  "taux":          { "passage": 0.7, "redoublement": 0.1, "abandon": 0.2 },
  "taux_degrade":  { "passage": 0.5, "redoublement": 0.3, "abandon": 0.2 },
  "delta_admissions": 0.0,
  "bornes": { "min": 0.0, "max": 0.0 },
  "horizon_annees": 2
}
```

Lecture du résultat (L1=10, L2=20, L3=30) :

| Pas | Total dégradé | Total référence | Écart | Sortants | Sortants réf. |
|---|---|---|---|---|---|
| 1 | 43 | 37 | +6 | 15 | 21 |
| 2 | 36 | 28 | +8 | 10 | 12 |

`baisse_passage = 0.2` ; `effet_sortants_cumules = −8` ; `écart final = +8`.

**Lecture :** le passage plus faible **bloque** 8 étudiants de plus en fin
d'horizon et fait perdre **8 sortants** cumulés — effet miroir exact de
SIM-07.

---

## H.4 Comment lire les indicateurs

| Indicateur | Signification | Piège à éviter |
|---|---|---|
| `baisse_passage` | Baisse déclarée du passage (magnitude positive) | Ce n'est pas une prévision : c'est l'hypothèse du scénario |
| `totaux_reference_reussite` | Effectifs si les taux de référence s'appliquaient | À comparer aux `totaux` du même pas |
| `ecarts_reussite_par_periode` | Écart signé par période | Écart **positif** = étudiants bloqués en plus (souvent indésirable) |
| `ecart_reussite_final` | Écart final | Peut être **négatif** si la baisse est absorbée par le « reste » |
| `sortants_par_periode` | Sortants (dernier niveau du périmètre) | Si `niveau_id` filtre un niveau intermédiaire, ce sont des sortants de ce niveau |
| `effet_sortants_cumules` | Effet total sur les sortants (négatif = perdus) | L'indicateur clé de l'impact |

---

## H.5 Limites connues

| Limite | Conséquence pratique |
|---|---|
| Résultat = effet **dans le modèle déclaré** | La conversion d'une crise réelle en taux dégradés reste une expertise humaine |
| Taux **globaux** | Pas de taux différenciés par niveau (LOT B) |
| « Sortants » = dernier niveau du **périmètre** | Pas nécessairement tout le programme si un niveau est filtré |
| Aucune combinaison avec d'autres profils | SIM-13/CR-02 traiteront les combinaisons |
| Capacité estimée au pas final | L'accumulation peut créer des pics intermédiaires (Famille 4) |

---

## H.6 Ce que SIM-08 ne fait jamais

- Il ne modifie **aucune** note, **aucune** délibération, **aucun** taux
  officiel : les taux sont des hypothèses de scénario.
- Il ne réaffecte **aucun** étudiant, **aucune** cohorte, **aucun** groupe.
- Il ne décide pas si la dégradation est acceptable ni comment y répondre.
- Il ne publie rien : la gouvernance reste un circuit humain séparé.
- Il n'invente aucune origine à la baisse : le jeu dégradé est déclaré en
  entier, jamais compensé.
