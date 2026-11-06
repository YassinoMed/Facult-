# SIM-16 — 05 · Sécurité

**Scénario :** Flux déséquilibrés entre niveaux
**Données métier :** lecture seule, zéro écriture, aucune PII dans les logs.

## A. Permissions

Consulter le catalogue `simulation.view` ; définir `simulation.gerer` ; exécuter `simulation.executer`. Isolement par faculté (403 si scénario d'une autre faculté).

## B. Périmètre

Aucun identifiant d'étudiant exposé ; agrégats d'effectifs uniquement. Zéro écriture dans les dossiers étudiants.

## C. Idempotence

Idempotence par graine : même scénario + même graine → une seule exécution, même `hash_resultat`.

## D. Absence d'effet de bord

SIM-16 ne modifie aucun référentiel : lecture du jeu de saisie et production d'une projection.
