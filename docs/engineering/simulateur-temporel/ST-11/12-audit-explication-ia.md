# ST-11 — 12 · Audit explication IA

Analyse complète dans `10-audit-monte-carlo.md` (§12). Points propres :

- Moteur **local, déterministe, sans fournisseur externe** (aucun HTTP client) — aucune donnée personnelle ou confidentielle transmise.
- Explications = interpolation de valeurs du résultat (aucun chiffre inventé possible).
- hash_preuve lie l'explication à l'exécution.
- Test de contradiction : les avertissements reflètent l'état réel (taux absents, budget hypothèse, non-conformité) — jamais transformés en fait favorable.

**Verdict domaine : PASS**.
