# SIM-07 — 08 · Guide d'utilisation

**Scénario :** SIM-07 — Amélioration de la réussite  
**Public :** directions, services de scolarité, pilotage pédagogique  
**Rappel :** appui à la décision uniquement — **aucune** décision réelle, **aucune** écriture métier

---

## H.1 À quoi sert SIM-07

SIM-07 répond à une question : **si le taux de passage progresse, quel est
l'effet sur les effectifs et sur les sortants (diplômés), à admissions
identiques ?**

Exemples d'usage :

- un plan de soutien vise +15 points de passage : combien de diplômés
  supplémentaires sur 3 ans ?
- la hausse vient de la réduction du redoublement : quelle baisse
  d'effectifs en résulte (progression plus rapide) ?
- la hausse vient de la réduction des abandons : quels effectifs et quels
  sortants supplémentaires ?

---

## H.2 Paramètres à saisir

| Paramètre | Unité | Obligatoire | Exemple |
|---|---|---|---|
| `taux` (référence) | ratios | **oui** | `0.5 / 0.3 / 0.2` |
| `taux_ameliore` | ratios | **oui** | `0.7 / 0.1 / 0.2` |
| `delta_admissions` | ratio annuel | oui | `0.0` |
| `horizon_annees` | années | oui | `2` |
| `bornes.min` / `bornes.max` | ratios | oui | `0.0 / 0.0` |
| `niveau_id`, `groupes` | identifiants | non | restreindre le périmètre |

**Règles à connaître avant de saisir :**

1. Les **deux** jeux de taux sont complets : le moteur ne compense rien.
2. `taux_ameliore.passage` doit être **strictement supérieur** au passage de
   référence.
3. Chaque jeu respecte `passage + redoublement + abandon ≤ 1`.
4. Gel, choc, cible de croissance et creux sont **incompatibles**.
5. Sans `taux_ameliore`, aucune comparaison n'est produite.

---

## H.3 Exemple complet

```json
{
  "taux":            { "passage": 0.5, "redoublement": 0.3, "abandon": 0.2 },
  "taux_ameliore":   { "passage": 0.7, "redoublement": 0.1, "abandon": 0.2 },
  "delta_admissions": 0.0,
  "bornes": { "min": 0.0, "max": 0.0 },
  "horizon_annees": 2
}
```

Lecture du résultat (L1=10, L2=20, L3=30) :

| Pas | Total simulé | Total référence | Écart | Sortants | Sortants réf. |
|---|---|---|---|---|---|
| 1 | 37 | 43 | −6 | 21 | 15 |
| 2 | 28 | 36 | −8 | 12 | 10 |

`hausse_passage = 0.2` ; `effet_sortants_cumules = +8` ; `écart final = −8`.

**Lecture :** la progression plus rapide vide les amphis (moins de
redoublants en attente) et **augmente les sortants** : +8 diplômés cumulés.

---

## H.4 Comment lire les indicateurs

| Indicateur | Signification | Piège à éviter |
|---|---|---|
| `hausse_passage` | Hausse déclarée du passage | Ce n'est pas une prévision : c'est l'hypothèse du scénario |
| `totaux_reference_reussite` | Effectifs si les taux de référence s'appliquaient | À comparer aux `totaux` du même pas |
| `ecarts_reussite_par_periode` | Écart signé par période | Un écart négatif ≠ échec : progression plus rapide |
| `ecart_reussite_final` | Écart final | Peut être négatif **avec** plus de sortants |
| `sortants_par_periode` | Diplômés (dernier niveau du périmètre) | Si `niveau_id` filtre un niveau intermédiaire, ce sont des sortants de ce niveau |
| `effet_sortants_cumules` | Effet total sur les sortants | L'indicateur clé de la réussite |

---

## H.5 Limites connues

| Limite | Conséquence pratique |
|---|---|
| Résultat = effet **dans le modèle déclaré** | La conversion d'une politique réelle en taux reste une expertise humaine |
| Taux **globaux** | Pas de taux différenciés par niveau (LOT B) |
| « Sortants » = dernier niveau du **périmètre** | Pas nécessairement tout le programme si un niveau est filtré |
| Aucune combinaison avec d'autres profils | SIM-13/CR-02 traiteront les combinaisons |
| Capacité estimée au pas final | La progression accélérée peut créer des pics intermédiaires (Famille 4) |

---

## H.6 Ce que SIM-07 ne fait jamais

- Il ne modifie **aucune** note, **aucune** délibération, **aucun** taux
  officiel : les taux sont des hypothèses de scénario.
- Il ne réaffecte **aucun** étudiant, **aucune** cohorte, **aucun** groupe.
- Il ne décide pas si l'objectif de réussite est atteignable.
- Il ne publie rien : la gouvernance reste un circuit humain séparé.
- Il n'invente aucune origine à la hausse : le jeu amélioré est déclaré en
  entier, jamais compensé.
