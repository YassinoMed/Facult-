# SIM-18 — 02 · Formules

**Scénario :** Double génération
**Version formules :** 1.0 (aucune formule de flux nouvelle — lecture de la partition)

Aucune formule de flux n'est recalculée : l'identité de cohorte est une **lecture de la partition** du moteur agrégé (`VERSION_MOTEUR = 2.0`).

- Flux (moteur) : passage, redoublement, progression, sortants (SIM-01 → SIM-13).
- Répartition identités : `repartirIdentites()` (plus fort reste sur l'effectif agrégé).
- Conservation par cohorte : `effectif_entree = effectif_final + sortants + autres_sorties`.
- Coexistence : comptage des cohortes simultanées dans un même niveau au même pas (`duree_coexistence`, `pic_coexistence`).
- Écart à la trajectoire témoin : `simulé − témoin` par pas et au final.

**Hash de définition** : déterministe par graine (`VERSION_MOTEUR = 2.0`). Les `hash_entrees` historiques (`1.x`) ne matchent plus.
