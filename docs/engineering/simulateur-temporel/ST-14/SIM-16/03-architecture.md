# SIM-16 — 03 · Architecture

**Scénario :** Flux déséquilibrés entre niveaux
**Intégration :** catalogue uniquement (moteur inchangé)

## A. Contrat entrée / sortie

**Entrée** : `criteres` avec `delta_admissions`, `bornes`, `horizon_annees`, `taux` (référence), `taux_par_niveau` (≥ 2 niveaux), optionnellement `niveau_focal`.

**Sortie** : `taux_par_niveau_appliques`, `niveaux_surcharges`, `ecarts_par_niveau`, `ecarts_niveaux_finaux`, `niveau_focal`, `ecart_niveau_focal_final`, plus champs communs (`totaux`, `totaux_reference_reussite`, `sortants_par_periode`, `effet_sortants_cumules`).

## B. Déterminisme

Temps construit, jamais `now()`. Hash déterministe par graine. Idempotence par graine.

## C. Gestion d'erreurs

`DomainException` sur paramètre invalide (un seul niveau, triplets identiques, triplet incomplet, taux hors [0,1], somme > 1.0001). Validation exclusive. Zéro écriture métier en cas d'erreur.

## D. Versionnement

`VERSION_MOTEUR = 2.0`. Schéma `schemaSim16()`, règle `validerReglesSim16()`, dépendances SIM-14, SIM-15.
