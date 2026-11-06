# SIM-14 — 01 · Spécification

**Scénario :** Blocage en L1
**Famille :** 3 — Cohortes et progression LMD
**Objectif :** mesurer l'effet d'un blocage en première année (redoublement L1 strictement supérieur au taux global, sans amélioration du passage ni de l'abandon) sur la progression des cohortes et les sortants.

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
| `taux_par_niveau[L1]` | objet | oui (SIM-14) | `passage/redoublement/abandon ∈ [0,1]`, somme ≤ 1.0001 | ratios annuels |
| `niveau_focal` | chaîne | non | `'L1'` (seule valeur admise SIM-14) | — |

Aucune valeur par défaut n'est injectée. Chaque niveau surchargé doit fournir ses trois taux.

---

## D. Règles de domaine (contrat SIM-14)

1. **Surcharge strictement localisée sur L1** : `taux_par_niveau` contient la clé `L1` ; tout autre niveau relève de SIM-15 (L2), SIM-16 (multi-niveaux) ou SIM-19 (rééquilibrage).
2. **Blocage en L1** : `taux_par_niveau[L1].redoublement > taux.redoublement` (strict).
3. **Aucune amélioration** :
   - `taux_par_niveau[L1].passage ≤ taux.passage`
   - `taux_par_niveau[L1].abandon ≥ taux.abandon`
4. **Invariant par triplet** : passage + redoublement + abandon ≤ 1.0001 (comme le jeu global).
5. **Exclusivité** : incompatible avec gel, choc, cible, creux, décriv
teurs de réussite (`taux_modifié`, `axe_variation`, `sens_variation`) et tout autre profil de succès.

Ces règles découlent de l'objectif (« blocage en L1 ») et ne créent aucun seuil institutionnel.

---

## E. Hypothèses

- Jeu de taux global COMPLET et saisi : trajectoire témoin.
- Surcharge L1 COMPLÈTE et saisie : trajectoire principale. Aucune valeur par défaut.
- Admissions identiques dans les deux trajectoires (même `delta`) : l'écart isole strictement l'effet du taux par niveau.
- Les autres niveaux conservent le jeu global : la surcharge est strictement locale.
- Le moteur plafonne le redoublement si `partants + redoublants > effectif` (conservation ST-11 P1-1) : aucune population n'est créée.
- Incompatible avec gel, choc, cible, creux et descripteurs globaux de réussite.

---

## F. Résultats attendus

- `taux_par_niveau_appliques` et `niveaux_surcharges` rappelés.
- `totaux` simulés et `totaux_reference_reussite`, périodes 0..horizon.
- `ecarts_reussite_par_periode` (signés) et `ecart_reussite_final`.
- `ecarts_par_niveau` (par niveau et par pas) et `ecarts_niveaux_finaux`.
- `niveau_focal` et `ecart_niveau_focal_final` (si déclaré).
- `sortants_par_periode` et `effet_sortants_cumules`.
- Effectifs, total final, capacités et alertes (moteur inchangé).
- OAT / Monte-Carlo conservant le profil par niveau déclaré.

---

## G. Cas limites

| Cas | Comportement attendu |
|---|---|
| `taux_par_niveau` vide | Refusé (objet non vide) |
| Triplet incomplet (2 taux) | Refusé (doit fournir passage, redoublement, abandon) |
| Taux hors [0,1] | Refusé |
| Somme triplet > 1.0001 | Refusé |
| Surcharge sur un niveau autre que L1 | Refusé — relève de SIM-15/16/19 |
| Passage L1 > passage global | Refusé (aucune amélioration) |
| Abandon L1 < abandon global | Refusé (aucune amélioration) |
| Redoublement L1 ≤ redoublement global | Refusé (doit être strictement supérieur) |
| Gel / choc / cible / creux / taux modifié | Refusés (clés exclues du schéma SIM-14) |

---

## H. Critères d'acceptation

1. Référence calculée hors moteur et vérifiée par test.
2. Redoublement L1 strictement supérieur au redoublement global ; aucune amélioration.
3. Admissions identiques dans les deux trajectoires.
4. Règles de domaine testées (acceptation et refus, moteur + catalogue).
5. Aucun niveau surchargé en dehors de L1.
6. Aucune écriture métier ; permissions et isolation facultaire.
7. Documentation et registre à jour.
