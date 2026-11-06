# SIM-15 — 02 · Formules

**Scénario :** Blocage en L2
**Version formules :** 1.0 (aucune formule nouvelle — réutilise le moteur)

Les formules de flux sont celles du moteur `ServiceSimulationTemporelle` (SIM-01 → SIM-13) :

- Admissions par pas : `admissions = round(base_L1 × (1 + delta_admissions)^pas)`
- Passage : `partants = round(effectif_niveau × passage_niveau)`
- Redoublement : `redoublants = round(effectif_niveau × redoublement_niveau)` (plafonné si `partants + redoublants > effectif`)
- `taux_niveau = taux_par_niveau[code] ?? taux`

Aucune formule nouvelle. L'écart `simulé − témoin` isole l'effet de la surcharge L2.

**Hash de définition** : déterministe par graine (`VERSION_MOTEUR = 2.0`). Les `hash_entrees` historiques (`1.x`) ne matchent plus.
