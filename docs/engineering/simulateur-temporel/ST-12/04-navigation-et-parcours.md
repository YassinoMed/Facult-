# ST-12 — 04 · Navigation et parcours

Parcours complet (R5) couvert par la suite automatisée :

1. Accès module (auth+admin+verrou+module active+permission) — ST11-SEC-001..004
2. Création scénario (étapes 1→3, validations taux/plafond/graine) — tests existants
3. Exécution déterministe + idempotence — tests existants
4. Résultats : 5 onglets (trajectoire/capacités/sensibilité+Pareto/Monte-Carlo/gouvernance+qualité+IA) — lots 1-13 ST-10
5. Exports CSV (anti-formules, négatifs préservés) / PDF — lot 8
6. Gouvernance 4 yeux — lots 14 + ST11-SEC-012 + ST12-UX-014 (masquage auteur)
7. Historique (index/détail exécutions) — routes GET protégées

Aucune régression de parcours (52/52).
