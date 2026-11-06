# SIM-17 — 01 · Spécification

**Scénario :** Cohorte exceptionnelle
**Famille :** 3 — Cohortes et progression LMD
**Objectif :** mesurer l'impact d'une cohorte externe à effectif inhabituel, ajoutée aux admissions ordinaires et suivie comme partition des agrégats.

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
| `cohortes_identifiees` | liste | oui | exactement 1 entrée : `{code, niveau_entree, pas_entree, effectif}` | — |
| `cohortes_identifiees[].code` | chaîne | oui | non vide, unique | — |
| `cohortes_identifiees[].niveau_entree` | chaîne | oui | existant dans le périmètre | — |
| `cohortes_identifiees[].pas_entree` | entier | oui | `[1, horizon]` | années |
| `cohortes_identifiees[].effectif` | entier | oui | ≥ 1 | étudiants |

Aucune valeur par défaut n'est injectée.

---

## D. Règles de domaine (contrat SIM-17)

1. **Exactement une cohorte** : plusieurs cohortes relèvent de SIM-18.
2. **Niveau d'entrée existant** : contrôlé sur le périmètre réel à l'exécution.
3. **Pas d'entrée dans l'horizon** : `pas_entree ∈ [1, horizon]`.
4. **Effectif ≥ 1** : entier saisi, jamais déduit.
5. **Effectif ≠ génération de référence** : l'effectif déclaré doit DIFFÉRER de la cohorte de référence du niveau d'entrée (contrôle à l'exécution) — sinon génération complète (SIM-18).
6. **Additivité** : l'entrée externe s'ajoute aux admissions ordinaires du pas, ne les remplace jamais.
7. **Exclusivité** : incompatible avec gel, choc, cible, creux, `taux_par_niveau`, `niveau_focal`, descripteurs de réussite.

Ces règles découlent de l'objectif et ne créent aucun seuil institutionnel.

---

## E. Hypothèses

- Jeu de taux global COMPLET et saisi : trajectoire témoin.
- Une cohorte externe déclarée : code, niveau d'entrée, pas d'entrée et effectif entiers.
- L'entrée externe est ADDITIVE aux admissions ordinaires du pas.
- La cohorte est suivie comme PARTITION des agrégats : aucune formule de flux dupliquée.
- Admissions ordinaires identiques dans les deux trajectoires : l'écart isole l'effet de la cohorte.
- Une seule cohorte : aucune coexistence de générations (`duree_coexistence = 0`, `pic_coexistence = 0`).

---

## F. Résultats attendus

- `cohortes_identifiees_appliquees` rappelée.
- `suivi_cohortes` (par pas et par niveau : effectifs identifiés + non identifiés).
- `bilan_cohortes` (conservation : `effectif_entree = effectif_final + sortants + autres_sorties`).
- `entrees_cohortes_par_periode`, `sortants_cohortes_par_periode`.
- `ecarts_cohortes_par_periode` (signés) et `ecart_cohortes_final`.
- `poids_cohortes_par_periode`, `coexistence_par_periode`, `duree_coexistence`, `pic_coexistence`.
- Effectifs, total final, capacités et alertes (moteur inchangé).

---

## G. Cas limites

| Cas | Comportement attendu |
|---|---|
| Liste vide | Refusé (liste non vide requise) |
| Code dupliqué | Refusé (identité unique) |
| Niveau d'entrée absent du périmètre | Refusé à l'exécution |
| Pas d'entrée hors [1, horizon] | Refusé |
| Effectif < 1 | Refusé |
| Effectif = référence du niveau d'entrée | Refusé — relève de SIM-18 |
| Plusieurs cohortes | Refusé — relève de SIM-18 |
| Gel / choc / cible / creux / `taux_par_niveau` | Refusés (clés exclues) |

---

## H. Critères d'acceptation

1. Référence calculée hors moteur et vérifiée par test (conservation `entrée = final + sortants + autres`).
2. Exactly one cohorte ; effectif ≠ référence du niveau d'entrée.
3. Entrée additive aux admissions ordinaires.
4. Cohorte suivie comme partition des agrégats (pas de formule dupliquée).
5. Règles de domaine testées (moteur + catalogue).
6. Aucune écriture métier ; permissions et isolation facultaire.
7. Documentation et registre à jour.
