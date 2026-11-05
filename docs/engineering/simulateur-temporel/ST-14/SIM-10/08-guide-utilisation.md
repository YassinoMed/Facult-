# SIM-10 — 08 · Guide d'utilisation

**Scénario :** SIM-10 — Réduction du redoublement  
**Public :** directions, services de scolarité, pilotage pédagogique  
**Rappel :** appui à la décision uniquement — **aucune** décision réelle, **aucune** écriture métier

---

## H.1 À quoi sert SIM-10

SIM-10 répond à une question : **si le redoublement baisse, que deviennent
les effectifs et les sortants, compte tenu de l'origine déclarée de cette
baisse ?**

Exemples d'usage :

- un plan de remédiation réduit le redoublement de 10 points : combien
  d'étudiants restent dans le système, combien en sortent ?
- la baisse est absorbée par une hausse de l'abandon : les effectifs
  diminuent-ils ?
- la baisse est absorbée par le « reste » (somme des taux < 1) : quel effet
  à horizon court ?

**Point clé :** une baisse du redoublement n'est **pas** automatiquement une
bonne nouvelle. Si les étudiants ne redoublent plus mais quittent le système
ou ne progressent pas, les effectifs et les sortants peuvent **diminuer**.
SIM-10 mesure ; il ne juge pas.

---

## H.2 Paramètres à saisir

| Paramètre | Unité | Obligatoire | Exemple |
|---|---|---|---|
| `taux` (référence) | ratios | **oui** | `0.6 / 0.2 / 0.2` |
| `taux_modifie` | ratios | **oui** | `0.6 / 0.1 / 0.3` |
| `axe_variation` | texte | **oui** | `redoublement` |
| `sens_variation` | texte | **oui** | `baisse` |
| `delta_admissions` | ratio annuel | oui | `0.0` |
| `horizon_annees` | années | oui | `2` |
| `bornes.min` / `bornes.max` | ratios | oui | `0.0 / 0.0` |
| `niveau_id`, `groupes` | identifiants | non | restreindre le périmètre |

**Règles à connaître avant de saisir :**

1. Les **deux** jeux de taux sont complets : le moteur ne compense rien.
2. `taux_modifie.redoublement` doit être **strictement inférieur** au
   redoublement de référence.
3. Chaque jeu respecte `passage + redoublement + abandon ≤ 1`.
4. L'axe `redoublement` et le sens `baisse` sont **épinglés** par le contrat
   SIM-10 ; tout autre couple est refusé par le catalogue.
5. Gel, choc, cible de croissance, creux, amélioration ou dégradation du
   passage (SIM-07/08) et hausse du redoublement (SIM-09) sont incompatibles.
6. Sans descripteur, aucune comparaison n'est produite.

---

## H.3 Exemple complet

```json
{
  "taux":          { "passage": 0.6, "redoublement": 0.2, "abandon": 0.2 },
  "taux_modifie":  { "passage": 0.6, "redoublement": 0.1, "abandon": 0.3 },
  "axe_variation": "redoublement",
  "sens_variation": "baisse",
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

`baisse_redoublement = 0.1` ; `effet_sortants_cumules = -2` ;
`écart final = -5`.

**Lecture :** la baisse du redoublement **réduit** les effectifs de 5
étudiants en fin d'horizon ; comme elle est ici absorbée par une hausse de
l'abandon, le pipeline aval reçoit **2 sortants de moins**. La baisse du
redoublement n'a donc pas produit de diplômés supplémentaires : elle a
déplacé les étudiants hors du système.

---

## H.4 Comment lire les indicateurs

| Indicateur | Signification | Piège à éviter |
|---|---|---|
| `baisse_redoublement` | Baisse déclarée du redoublement (magnitude **positive**) | Ce n'est pas une prévision : c'est l'hypothèse du scénario |
| `totaux_reference_reussite` | Effectifs si les taux de référence s'appliquaient | À comparer aux `totaux` du même pas |
| `ecarts_reussite_par_periode` | Écart signé par période | Écart **négatif** = effectifs réduits |
| `ecart_reussite_final` | Écart final | Négatif dans les cas standards ; dépend de l'origine déclarée |
| `sortants_par_periode` | Sortants (dernier niveau du périmètre) | Si `niveau_id` filtre un niveau intermédiaire, ce sont des sortants de ce niveau |
| `effet_sortants_cumules` | Effet total sur les sortants | **Peut être négatif, nul ou positif** ; nul à horizon court si l'aval n'est pas atteint |

---

## H.5 Limites connues

| Limite | Conséquence pratique |
|---|---|
| Résultat = effet **dans le modèle déclaré** | La conversion d'une politique réelle en taux modifiés reste une expertise humaine |
| Taux **globaux** | Pas de taux différenciés par niveau (LOT B) |
| « Sortants » = dernier niveau du **périmètre** | Pas nécessairement tout le programme si un niveau est filtré |
| Aucune combinaison multi-axes | SIM-13 traitera les combinaisons |
| Capacité estimée au pas final | L'accumulation peut créer des pics intermédiaires (Famille 4) |
| Aucun signe favorable supposé | Une baisse peut réduire les sortants ; le scénario ne recommande rien |

---

## H.6 Ce que SIM-10 ne fait jamais

- Il ne modifie **aucune** note, **aucune** délibération, **aucun** taux
  officiel : les taux sont des hypothèses de scénario.
- Il ne réaffecte **aucun** étudiant, **aucune** cohorte, **aucun** groupe.
- Il ne décide pas si la baisse est souhaitable ni comment l'obtenir.
- Il ne publie rien : la gouvernance reste un circuit humain séparé.
- Il n'invente aucune origine à la baisse : le jeu modifié est déclaré en
  entier, jamais compensé.
- Il ne transforme jamais une baisse du redoublement en « amélioration »
  par défaut.
