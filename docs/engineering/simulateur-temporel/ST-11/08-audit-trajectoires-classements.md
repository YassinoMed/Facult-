# ST-11 — 08 · Audit trajectoires et classements

Analyse complète dans `10-audit-monte-carlo.md` (§08 consolidé). Points propres :

- Snapshot 0 = référence réelle (adaptateur), jamais recalculée ; snapshots 1..N = projections horodatées.
- Conservation des flux : garde P1-1 ajoutée (partent + redoublants ≤ effectif) — test ST11-MATH-014 (61+11−15=57 exact).
- Classement : pénalités de contraintes dominantes (non-conforme jamais premier) ; score explicable par contribution.
- Écart relatif : 0 explicite si référence nulle (jamais de division par zéro, jamais NaN).

**Verdict domaine : PASS** (après P1-1).
