# SIM-14 — 03 · Architecture

**Scénario :** Blocage en L1
**Intégration :** catalogue uniquement (aucun changement de moteur requis au-delà de `taux_par_niveau` déjà présent)

---

## A. Contrat entrée / sortie

**Entrée** : `criteres` contenant `delta_admissions`, `bornes`, `horizon_annees`, `taux` (référence), `taux_par_niveau` (clé `L1` requise), optionnellement `niveau_focal = 'L1'`.

**Sortie** (champs `champsSpecifiques()`) : `taux_par_niveau_appliques`, `niveaux_surcharges`, `ecarts_par_niveau`, `cohortes_reference_finales`, `ecarts_niveaux_finaux`, `niveau_focal`, `ecart_niveau_focal_final`, plus les champs communs (`totaux`, `totaux_reference_reussite`, `ecarts_reussite_par_periode`, `ecart_reussite_final`, `sortants_par_periode`, `effet_sortants_cumules`).

---

## B. Déterminisme

- Temps construit (`annee_base + pas`), jamais `now()`.
- Hash de déterministe par graine : mêmes entrées → même `hash_resultat`.
- Idempotence : même scénario + même graine → même exécution (une seule `ExecutionTemporelle`).

---

## C. Gestion d'erreurs

- `DomainException` sur tout paramètre invalide (triplet incomplet, taux hors [0,1], somme > 1.0001, niveau absent du périmètre, amélioration interdite).
- Zéro écriture métier en cas d'erreur : l'exécution n'est pas créée.
- Validation exclusive : `taux_par_niveau` exclu de tout autre descripteur de réussite.

---

## D. Versionnement

- `VERSION_MOTEUR = 2.0` (inchangé par SIM-14, hérité de SIM-17/18).
- Schéma : `schemaSim14()`, règle : `validerReglesSim14()`.
