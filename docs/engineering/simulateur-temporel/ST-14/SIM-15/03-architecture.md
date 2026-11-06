# SIM-15 — 03 · Architecture

**Scénario :** Blocage en L2
**Intégration :** catalogue uniquement (moteur inchangé, `taux_par_niveau` déjà présent)

## A. Contrat entrée / sortie

**Entrée** : `criteres` avec `delta_admissions`, `bornes`, `horizon_annees`, `taux` (référence), `taux_par_niveau` (clé `L2` requise), optionnellement `niveau_focal = 'L2'`.

**Sortie** : `taux_par_niveau_appliques`, `niveaux_surcharges`, `ecarts_par_niveau`, `ecarts_niveaux_finaux`, `niveau_focal`, `ecart_niveau_focal_final`, plus champs communs (`totaux`, `totaux_reference_reussite`, `sortants_par_periode`, `effet_sortants_cumules`).

## B. Déterminisme

Temps construit, jamais `now()`. Hash déterministe par graine. Idempotence par graine.

## C. Gestion d'erreurs

`DomainException` sur paramètre invalide. Validation exclusive (`taux_par_niveau` exclu de tout autre descripteur de réussite). Zéro écriture métier en cas d'erreur.

## D. Versionnement

`VERSION_MOTEUR = 2.0`. Schéma `schemaSim15()`, règle `validerReglesSim15()`, dépend SIM-14.
