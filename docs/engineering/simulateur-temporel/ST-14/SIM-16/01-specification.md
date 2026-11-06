# SIM-16 — 01 · Spécification

**Scénario :** Flux déséquilibrés entre niveaux
**Famille :** 3 — Cohortes et progression LMD
**Objectif :** mesurer l'effet d'un déséquilibre entre plusieurs niveaux (surcharge `taux_par_niveau` sur ≥ 2 niveaux, ≥ 2 triplets distincts) sur la progression des cohortes et les sortants.

---

## A. Acteurs

| Acteur | Rôle |
|---|---|
| Responsable autorisé | Configure et exécute le scénario |
| Consultation | Consulte les résultats et le catalogue |
| Gouvernance | Propose / décide selon le circuit à quatre yeux existant |
| Autorité institutionnelle | Valide les taux de référence et les surcharges (non fournis par le code) |

---

## B. Population et périmètre

- Faculté réelle ou périmètre global, selon `faculte_id`.
- Niveaux du périmètre simulé (≥ 2 niveaux surchargés).
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
| `taux_par_niveau` | objet | oui | ≥ 2 niveaux, chaque valeur = triplet complet | ratios annuels |
| `niveau_focal` | chaîne | non | présent ou absent | — |

Aucune valeur par défaut n'est injectée.

---

## D. Règles de domaine (contrat SIM-16)

1. **≥ 2 niveaux surchargés** : `taux_par_niveau` contient au moins 2 clés de niveaux.
2. **≥ 2 triplets distincts** : au moins deux niveaux doivent avoir des triplets différents (déséquilibre réel).
3. **Invariant par triplet** : passage + redoublement + abandon ≤ 1.0001 pour chaque niveau surchargé.
4. **Exclusivité** : incompatible avec gel, choc, cible, creux et descripteurs de réussite.

Ces règles découlent de l'objectif (« déséquilibre multi-niveaux ») et ne créent aucun seuil institutionnel.

---

## E. Hypothèses

- Jeu de taux global COMPLET et saisi : trajectoire témoin.
- Surcharges multiples COMPLÈTES et saisies : trajectoire principale.
- Admissions identiques dans les deux trajectoires (même `delta`).
- Les niveaux non surchargés conservent le jeu global.
- Le moteur plafonne le redoublement si `partants + redoublants > effectif` (conservation ST-11 P1-1).

---

## F. Résultats attendus

- `taux_par_niveau_appliques`, `niveaux_surcharges`, `ecarts_par_niveau`, `ecarts_niveaux_finaux`.
- `niveau_focal` et `ecart_niveau_focal_final` (si déclaré).
- `totaux`, `totaux_reference_reussite`, `ecarts_reussite_par_periode`, `ecart_reussite_final`.
- `sortants_par_periode` et `effet_sortants_cumules`.

---

## G. Cas limites

| Cas | Comportement attendu |
|---|---|
| Un seul niveau surchargé | Refusé — relève de SIM-14 (L1) ou SIM-15 (L2) |
| Triplets identiques sur tous les niveaux surchargés | Refusé (pas de déséquilibre, ≥ 2 triplets distincts requis) |
| Triplet incomplet / taux hors [0,1] / somme > 1.0001 | Refusé |

---

## H. Critères d'acceptation

1. Référence calculée hors moteur et vérifiée par test.
2. ≥ 2 niveaux surchargés, ≥ 2 triplets distincts.
3. Admissions identiques dans les deux trajectoires.
4. Règles de domaine testées (moteur + catalogue).
5. Aucune écriture métier ; permissions et isolation facultaire.
6. Documentation et registre à jour.
