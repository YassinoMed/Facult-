# SIM-14 — 05 · Sécurité

**Scénario :** Blocage en L1
**Données métier :** lecture seule, zéro écriture, aucune PII dans les logs.

---

## A. Permissions

| Action | Permission requise |
|---|---|
| Consulter le catalogue | `simulation.view` |
| Définir un scénario | `simulation.gerer` |
| Exécuter un scénario | `simulation.executer` |
| Exporter / expliquer | `simulation.export` / `simulation.explication` |
| Gouvernance | `simulation.gouvernance.proposer` / `simulation.gouvernance.decider` |

## B. Périmètre

- L'exécution est isolée à la faculté du scénario (`faculte_id`) : une faculté B ne peut exécuter un scénario de la faculté A (403).
- Aucun identifiant d'étudiant, CIN ou donnée personnelle n'est exposé : agrégats d'effectifs uniquement.
- Zéro écriture dans les dossiers étudiants ; les fichiers scannés (OCR) ne concernent pas ce scénario.

## C. Idempotence et throttling

- Idempotence par graine : même scénario + même graine → une seule exécution, même `hash_resultat`.
- Pas de taux de requête spécifique au-delà des limites applicatives générales.

## D. Absence d'effet de bord

- SIM-14 ne modifie aucun référentiel (niveaux, taux, effectifs) : il lit le jeu de saisie et produit une projection.
