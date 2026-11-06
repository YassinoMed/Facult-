# SIM-18 — 01 · Spécification

**Scénario :** Double génération
**Famille :** 3 — Cohortes et progression LMD
**Objectif :** simuler la coexistence de deux cohortes externes importantes dans un même niveau, à des pas distincts, et mesurer leur coexistence.

---

## A. Acteurs

| Acteur | Rôle |
|---|---|
| Responsable autorisé | Configure et exécute le scénario |
| Consultation | Consulte les résultats et le catalogue |
| Gouvernance | Propose / décide selon le circuit à quatre yeux existant |
| Autorité institutionnelle | Valide les effectifs et niveaux d'entrée (non fournis par le code) |

---

## B. Population et périmètre

- Faculté réelle ou périmètre global, selon `faculte_id`.
- Niveaux du périmètre simulé.
- Année universitaire de référence obligatoire à l'exécution.
- Horizon de 1 à 6 ans (`PLAFOND_HORIZON`).
- Aucune donnée personnelle : agrégats uniquement.

---

## C. Paramètres

| Paramètre | Type | Obligatoire | Domaine | Unité |
|---|---|---|---|---|
| `delta_admissions` | nombre | oui | `[bornes.min, bornes.max]` | ratio annuel composé |
| `bornes.min` / `bornes.max` | nombre | oui | cohérentes, incluant `delta` | ratio |
| `horizon_annees` | entier | oui | `[1, 6]` | années |
| `taux` (référence) | objet | oui | `passage/redoublement/abandon ∈ [0,1]`, somme ≤ 1.0001 | ratios annuels |
| `cohortes_identifiees` | liste | oui | ≥ 2 entrées : `{code, niveau_entree, pas_entree, effectif}` | — |
| `cohortes_identifiees[].code` | chaîne | oui | non vide, unique | — |
| `cohortes_identifiees[].niveau_entree` | chaîne | oui | existant dans le périmètre | — |
| `cohortes_identifiees[].pas_entree` | entier | oui | `[1, horizon]`, distincts entre cohortes | années |
| `cohortes_identifiees[].effectif` | entier | oui | ≥ 1, ≥ référence du niveau | étudiants |

Aucune valeur par défaut n'est injectée.

---

## D. Règles de domaine (contrat SIM-18)

1. **≥ 2 cohortes** : une seule cohorte relève de SIM-17.
2. **Même niveau d'entrée** : toutes les cohortes entrent dans le même niveau (contrôlé).
3. **Pas d'entrée distincts** : deux cohortes du même niveau doivent entrer à des pas différents (aucune coexistence simultanée à l'entrée).
4. **Effectif ≥ référence** : chaque effectif ≥ génération de référence du niveau d'entrée (contrôle à l'exécution sur le périmètre réel).
5. **Additivité** : chaque entrée s'ajoute aux admissions ordinaires de son pas.
6. **Partition des agrégats** : chaque cohorte suivie comme partition ; aucune formule de flux dupliquée.
7. **Exclusivité** : incompatible avec gel, choc, cible, creux, `taux_par_niveau`, `niveau_focal`, descripteurs de réussite.

Ces règles découlent de l'objectif et ne créent aucun seuil institutionnel.

---

## E. Hypothèses

- Jeu de taux global COMPLET et saisi : trajectoire témoin.
- ≥ 2 cohortes externes déclarées, même niveau d'entrée, pas distincts.
- Chaque entrée est ADDITIVE aux admissions ordinaires de son pas.
- Chaque cohorte suivie comme PARTITION des agrégats.
- La coexistence est MESURÉE par pas et par niveau ; jamais supposée.
- Admissions ordinaires identiques dans la trajectoire témoin : l'écart isole l'effet des générations.

---

## F. Résultats attendus

- `cohortes_identifiees_appliquees` rappelée.
- `suivi_cohortes`, `bilan_cohortes` (conservation par cohorte).
- `entrees_cohortes_par_periode`, `sortants_cohortes_par_periode`.
- `ecarts_cohortes_par_periode` (signés) et `ecart_cohortes_final`.
- `poids_cohortes_par_periode`, `coexistence_par_periode`, `duree_coexistence`, `pic_coexistence`.
- Effectifs, total final, capacités et alertes (moteur inchangé).

---

## G. Cas limites

| Cas | Comportement attendu |
|---|---|
| Une seule cohorte | Refusé — relève de SIM-17 |
| Pas d'entrée identiques | Refusé (pas de coexistence de générations) |
| Niveaux d'entrée distincts | Refusé (même niveau requis) |
| Effectif < référence du niveau | Refusé à l'exécution |
| Effectif < 1 / liste vide / code dupliqué | Refusé |
| Gel / choc / cible / creux / `taux_par_niveau` | Refusés (clés exclues) |

---

## H. Critères d'acceptation

1. Référence calculée hors moteur et vérifiée par test (conservation par cohorte).
2. ≥ 2 cohortes, même niveau d'entrée, pas distincts, effectif ≥ référence.
3. Entrées additives aux admissions ordinaires.
4. Coexistence mesurée (pas supposée).
5. Règles de domaine testées (moteur + catalogue).
6. Aucune écriture métier ; permissions et isolation facultaire.
7. Documentation et registre à jour.
