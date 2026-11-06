# SIM-17 — 02 · Formules

**Scénario :** Cohorte exceptionnelle
**Version formules :** 1.0 (aucune formule de flux nouvelle — lecture de la partition)

Aucune formule de flux n'est recalculée : l'identité de cohorte est une **lecture de la partition** du moteur agrégé (`VERSION_MOTEUR = 2.0`).

- Flux (moteur) : passage, redoublement, progression, sortants (SIM-01 → SIM-13).
- Répartition identités : `repartirIdentites()` (plus fort reste sur l'effectif agrégé ; l'effectif non identifié participe au départage mais ne reçoit jamais d'unité au nom d'une cohorte).
- Conservation par cohorte : `effectif_entree = effectif_final + sortants + autres_sorties` (bilan lu dans les parts identifiées finales).
- Écart à la trajectoire témoin : `simulé − témoin` par pas et au final.

**Hash de définition** : déterministe par graine (`VERSION_MOTEUR = 2.0`). Les `hash_entrees` historiques (`1.x`) ne matchent plus.
