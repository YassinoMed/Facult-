# SIM-15 — 01 · Spécification

**Scénario :** Blocage en L2
**Famille :** 3 — Cohortes et progression LMD
**Objectif :** mesurer l'effet d'un blocage en deuxième année (redoublement L2 strictement supérieur au taux global, sans amélioration du passage ni de l'abandon) sur la progression des cohortes et les sortants.

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
- Niveaux du périmètre simulé (L1, L2, L3, … selon le référentiel).
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
| `taux_par_niveau` | objet | oui | au moins un niveau ; chaque valeur = triplet complet | ratios annuels |
| `taux_par_niveau[L2]` | objet | oui (SIM-15) | `passage/redoublement/abandon ∈ [0,1]`, somme ≤ 1.0001 | ratios annuels |
| `niveau_focal` | chaîne | non | `'L2'` (seule valeur admise SIM-15) | — |

Aucune valeur par défaut n'est injectée.

---

## D. Règles de domaine (contrat SIM-15)

1. **Surcharge strictement localisée sur L2** : `taux_par_niveau` contient la clé `L2` ; tout autre niveau relève de SIM-14 (L1), SIM-16 (multi-niveaux) ou SIM-19 (rééquilibrage).
2. **Blocage en L2** : `taux_par_niveau[L2].redoublement > taux.redoublement` (strict).
3. **Aucune amélioration** :
   - `taux_par_niveau[L2].passage ≤ taux.passage`
   - `taux_par_niveau[L2].abandon ≥ taux.abandon`
4. **Invariant par triplet** : passage + redoublement + abandon ≤ 1.0001.
5. **Exclusivité** : incompatible avec gel, choc, cible, creux et descripteurs de réussite.

Ces règles découlent de l'objectif et ne créent aucun seuil institutionnel.

---

## E. Hypothèses

- Jeu de taux global COMPLET et saisi : trajectoire témoin.
- Surcharge L2 COMPLÈTE et saisie : trajectoire principale.
- Admissions identiques dans les deux trajectoires (même `delta`).
- Les autres niveaux conservent le jeu global.
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
| Surcharge sur un niveau autre que L2 | Refusé — relève de SIM-14/16/19 |
| Redoublement L2 ≤ redoublement global | Refusé (doit être strictement supérieur) |
| Passage L2 > passage global ou abandon L2 < abandon global | Refusé (aucune amélioration) |
| Triplet incomplet / taux hors [0,1] / somme > 1.0001 | Refusé |

---

## H. Critères d'acceptation

1. Référence calculée hors moteur et vérifiée par test.
2. Redoublement L2 strictement supérieur ; aucune amélioration.
3. Admissions identiques dans les deux trajectoires.
4. Règles de domaine testées (moteur + catalogue).
5. Aucun niveau surchargé en dehors de L2.
6. Aucune écriture métier ; permissions et isolation facultaire.
7. Documentation et registre à jour.
