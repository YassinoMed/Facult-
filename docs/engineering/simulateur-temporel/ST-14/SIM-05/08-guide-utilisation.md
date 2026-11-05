# SIM-05 — 08 · Guide d'utilisation

**Scénario :** SIM-05 — Croissance supérieure à la cible  
**Public :** directions, services de scolarité, planification  
**Rappel :** appui à la décision uniquement — **aucune** décision réelle, **aucune** écriture métier

---

## H.1 À quoi sert SIM-05

SIM-05 répond à une question simple : **la croissance projetée dépasse-t-elle
la croissance visée, et de combien, année par année ?**

Exemples d'usage :

- un contrat d'établissement vise +3 % d'admissions par an ; le scénario
  projette +8 % : où et quand l'écart se creuse-t-il ?
- une cible rectorale de +5 % est annoncée ; la projection à +12 % montre un
  dépassement de 42 étudiants sur 3 ans : quelles années portent ce volume ?
- une cible nulle (stabilisation) est souhaitée ; quelle serait la croissance
  au-delà ?

---

## H.2 Paramètres à saisir

| Paramètre | Unité | Obligatoire | Exemple |
|---|---|---|---|
| `delta_admissions` | ratio annuel | oui | `0.10` (croissance projetée +10 %/an) |
| `taux_cible_croissance` | ratio annuel | **oui** | `0.05` (cible +5 %/an) |
| `horizon_annees` | années | oui | `3` |
| `taux.passage` / `redoublement` / `abandon` | ratios | oui | `0.6 / 0.3 / 0.1` |
| `bornes.min` / `bornes.max` | ratios | oui | `0.0 / 0.5` |
| `niveau_id`, `groupes` | identifiants | non | restreindre le périmètre |

**Règles à connaître avant de saisir :**

1. Le delta doit être **strictement supérieur** à la cible.
2. Les deux taux doivent être **positifs ou nuls** (scénario de croissance).
3. Le gel (SIM-03) et le choc ponctuel (SIM-04) sont **incompatibles** avec
   la cible de croissance.
4. Aucune valeur par défaut : si la cible n'est pas saisie, aucune
   comparaison n'est produite.

---

## H.3 Exemple complet

```json
{
  "delta_admissions": 0.10,
  "bornes": { "min": 0.0, "max": 0.5 },
  "horizon_annees": 3,
  "taux_cible_croissance": 0.05,
  "taux": { "passage": 0.6, "redoublement": 0.3, "abandon": 0.1 }
}
```

Lecture du résultat (L1 observé = 10) :

| Pas | Admissions réelles | Admissions cibles | Écart |
|---|---|---|---|
| 1 | 11 | 11 | 0 |
| 2 | 12 | 11 | +1 |
| 3 | 13 | 12 | +1 |
| **Cumul** | | | **+2** |

`depassement_cible = true` : au moins une période dépasse la cible.

---

## H.4 Comment lire les indicateurs

| Indicateur | Signification | Piège à éviter |
|---|---|---|
| `admissions_cible_par_periode` | Ce que la cible impliquerait chaque année | Ce n'est pas une prévision : c'est une **intention** |
| `ecarts_cible_par_periode` | Écart signé réel − cible, par année | Un écart `0` peut cacher une différence exacte annulée par l'arrondi |
| `ecart_cible_cumule` | Somme des écarts annuels | Un cumul nul peut masquer des années positives et négatives |
| `depassement_cible` | Au moins un écart **strictement positif** | `true` ne dit pas si le dépassement est durable ou ponctuel |
| `ecart_cible` (existant) | Écart du **total d'effectifs** à un objectif `cible_total` constant | Indicateur différent : ne pas confondre total constant et trajectoire |

---

## H.5 Limites connues

| Limite | Conséquence pratique |
|---|---|
| Écarts sur les **admissions**, pas sur les effectifs | Un dépassement d'admissions peut être absorbé par les abandons ; croiser avec les totaux |
| Arrondis entiers | Un dépassement marginal peut ne pas apparaître (`SIM05-A6`) |
| Taux cible **global** | Pas de cible différenciée par niveau (LOT B) |
| Cible composée uniquement | Pas de palier ni de cible non linéaire (SIM-06 / CR-05) |
| Capacité estimée au pas final | Un pic intermédiaire peut sous-estimer le besoin (Famille 4) |
| Valeurs de capacité par défaut | 35 si aucun groupe réel — paramètre non institutionnel |

---

## H.6 Ce que SIM-05 ne fait jamais

- Il ne réduit, ne plafonne et ne réaffecte **aucune** admission.
- Il ne modifie **aucune** cohorte, **aucun** groupe, **aucune** capacité.
- Il ne décide pas si un dépassement est acceptable : c'est une lecture
  politique, pas un calcul.
- Il ne publie rien : la gouvernance reste un circuit humain séparé.
- Il n'invente aucune cible : une cible non saisie vaut une absence de
  comparaison, jamais une valeur par défaut.
