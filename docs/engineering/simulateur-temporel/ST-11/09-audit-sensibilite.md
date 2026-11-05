# ST-11 — 09 · Audit sensibilité OAT

Analyse complète dans `10-audit-monte-carlo.md` (§09). Points propres :

- OAT strict : un paramètre perturbé à la fois, autres constants.
- Taux absents : jamais substitués (gel + avertissement) — testé.
- Élasticité non calculable signalée (référence nulle), jamais NaN.
- Limite méthodologique affichée : pas d'interactions inter-paramètres.

**Verdict domaine : PASS** (limites documentées).
