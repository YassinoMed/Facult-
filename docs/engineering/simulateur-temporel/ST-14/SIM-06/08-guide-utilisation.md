# SIM-06 — 08 · Guide d'utilisation

**Scénario :** SIM-06 — Récupération démographique  
**Public :** directions, services de scolarité, planification  
**Rappel :** appui à la décision uniquement — **aucune** décision réelle, **aucune** écriture métier

---

## H.1 À quoi sert SIM-06

SIM-06 répond à une question : **combien d'admissions sont perdues pendant
un creux, et quand la référence est-elle retrouvée ?**

Exemples d'usage :

- un partenariat est suspendu pour 2 ans : quel creux, et combien de temps
  pour revenir au niveau actuel ?
- la démographie régionale baisse de 20 % en 2 ans puis se rétablit en
  2 ans : quel déficit total d'admissions ?
- un plan de reconquête étale le retour sur 3 ans alors que le creux dure
  1 an : le retour est-il observé dans l'horizon ?

---

## H.2 Paramètres à saisir

| Paramètre | Unité | Obligatoire | Exemple |
|---|---|---|---|
| `delta_admissions` | ratio annuel | oui | `0.10` (tendance de fond) |
| `profondeur_baisse` | ratio | **oui** | `0.20` (creux 20 % sous la référence) |
| `duree_baisse_annees` | années | **oui** | `2` (2 ans pour atteindre le creux) |
| `duree_recuperation_annees` | années | **oui** | `2` (2 ans pour revenir) |
| `horizon_annees` | années | oui | `4` (≥ baisse + récupération) |
| `taux.passage` / `redoublement` / `abandon` | ratios | oui | `0.6 / 0.3 / 0.1` |
| `bornes.min` / `bornes.max` | ratios | oui | selon la tendance |
| `niveau_id`, `groupes` | identifiants | non | restreindre le périmètre |

**Règles à connaître avant de saisir :**

1. Le trio `profondeur` + `durées` est **indissociable**.
2. `baisse + récupération ≤ horizon` : sinon le retour ne serait pas
   observé.
3. `profondeur ∈ ]0 ; 1]` : 1 = creux total (admissions nulles), jamais de
   valeurs négatives.
4. Gel (SIM-03), choc (SIM-04) et cible (SIM-05) sont **incompatibles**.
5. Aucune valeur par défaut : un creux non saisi n'existe pas.

---

## H.3 Exemple complet

```json
{
  "delta_admissions": 0.10,
  "bornes": { "min": 0.0, "max": 0.5 },
  "horizon_annees": 4,
  "profondeur_baisse": 0.20,
  "duree_baisse_annees": 2,
  "duree_recuperation_annees": 2,
  "taux": { "passage": 0.6, "redoublement": 0.3, "abandon": 0.1 }
}
```

Lecture du résultat (L1 observé = 10, tendance +10 %) :

| Pas | Référence | Facteur | Admissions | Écart | Phase |
|---|---|---|---|---|---|
| 1 | 11 | 0.9 | 10 | −1 | baisse |
| 2 | 12 | 0.8 | 10 | −2 | baisse |
| 3 | 13 | 0.9 | 12 | −1 | récupération |
| 4 | 15 | 1.0 | 15 | 0 | récupération |

Déficit cumulé : **−4 admissions** ; creux maximal : **−2** ; retour exact
au pas 4 (`d + r`).

---

## H.4 Comment lire les indicateurs

| Indicateur | Signification | Piège à éviter |
|---|---|---|
| `admissions_reference_par_periode` | Tendance sans creux | Ce n'est pas une prévision météo : c'est la trajectoire de fond saisie |
| `ecarts_recuperation_par_periode` | Écart signé réel − référence, avec phase et facteur | Un écart 0 ne signifie pas « pas de creux » : il marque le retour |
| `ecart_recuperation_cumule` | Déficit total d'admissions vs référence | Un cumul peut rester négatif même après retour (le creux a coûté) |
| `creux_recuperation` | Pire écart d'une période (0 si aucun) | Ne pas confondre avec la profondeur saisie : arrondis compris |
| `phase_recuperation` | `baisse` / `recuperation` / `reference` | Après `d + r`, la phase est `reference` |

---

## H.5 Limites connues

| Limite | Conséquence pratique |
|---|---|
| Rampe **linéaire** | La forme progressive est régulière ; un profil en S exigerait un autre paramètre |
| Écarts sur les **admissions** | Un creux peut être partiellement absorbé par les abandons ; croiser avec `totaux` |
| Arrondis à deux étages | Écarts exacts possibles, jamais négatifs en admissions |
| Taux globaux | Pas de creux différencié par niveau (LOT B) |
| Capacité estimée au pas final | Un creux puis retour peut masquer un besoin intermédiaire (Famille 4) |

---

## H.6 Ce que SIM-06 ne fait jamais

- Il ne réduit, ne suspend et ne réaffecte **aucune** admission réelle.
- Il ne modifie **aucune** cohorte, **aucun** groupe, **aucune** capacité.
- Il ne décide pas si le creux est acceptable ni si le retour est suffisant.
- Il ne publie rien : la gouvernance reste un circuit humain séparé.
- Il n'invente aucune profondeur : un creux non saisi vaut une absence de
  creux, jamais une valeur par défaut.
