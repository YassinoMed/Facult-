# SIM-17 — 03 · Architecture

**Scénario :** Cohorte exceptionnelle
**Intégration :** moteur v2.0 (identité de cohorte) + catalogue

## A. Contrat entrée / sortie

**Entrée** : `criteres` avec `delta_admissions`, `bornes`, `horizon_annees`, `taux` (référence), `cohortes_identifiees` (1 entrée).

**Sortie dédiée** : `cohortes_identifiees_appliquees`, `suivi_cohortes`, `bilan_cohortes`, `entrees_cohortes_par_periode`, `sortants_cohortes_par_periode`, `ecarts_cohortes_par_periode`, `ecart_cohortes_final`, `poids_cohortes_par_periode`, `coexistence_par_periode`, `duree_coexistence`, `pic_coexistence`.

## B. Déterminisme

Temps construit, jamais `now()`. Hash déterministe par graine. Idempotence par graine.

## C. Gestion d'erreurs

`DomainException` sur : liste vide, code dupliqué/vide, niveau absent du périmètre, pas hors [1, horizon], effectif < 1, effectif = référence (SIM-17), clés exclues (gel/choc/cible/creux/`taux_par_niveau`/descripteurs). Zéro écriture métier en cas d'erreur.

## D. Versionnement

`VERSION_MOTEUR = 2.0`. Schéma `schemaSim17()`, règle `validerReglesSim17()`, dépendance SOCLE-EFFECTIFS.
