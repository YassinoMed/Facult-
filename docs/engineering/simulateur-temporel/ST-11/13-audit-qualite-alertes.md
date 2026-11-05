# ST-11 — 13 · Audit qualité des données et alertes

Analyse complète dans `10-audit-monte-carlo.md` (§13). Points propres :

- QUAL_* : codes + gravité + source ; blocage sans inscription valide = DomainException explicite (jamais zéro silencieux).
- Complétude [0,1] sur les données lues ; pas de prétention d'exactitude.
- Alertes : seuils = paramètres du scénario (plafond/bornes) — aucun seuil inventé.

**Verdict domaine : PASS**.
