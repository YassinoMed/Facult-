# ST-12 — 06 · Tests UX

| ID | Scénario | Preuve |
|---|---|---|
| ST12-UX-001 | Accès module selon permissions | ST11-SEC-011 (403 consultation) + index 200 habilité |
| ST12-UX-002 | Module désactivé → 404 | test_isolation_perimetre_et_module |
| ST12-UX-003..005 | Périmètre + cascade niveaux/groupes | tests existants (options filtrées, groupes compatibles) |
| ST12-UX-006/007 | Horizon/taux invalides → erreurs claires | tests existants (criteres invalides, somme taux) |
| ST12-UX-009 | Double clic exécution | idempotence (une seule exécution) |
| ST12-UX-010..013 | Onglets (trajectoire/capacités/sensibilité/Monte-Carlo) | lots 2/5/7/10 ST-10 |
| ST12-UX-014 | **Masquage décision à l'auteur + mention budget** | **nouveau test dédié** (utilisateurId, conditions x-if, bandeau) |
| ST12-UX-015 | Pareto lisible | lot 9 |
| ST12-UX-016 | Budget sans valeur comptable | ST12-UX-014 (bandeau) |
| ST12-UX-017 | IA explication sources | lot 12 |
| ST12-UX-018 | Audit qualité codes | lot 1 |
| ST12-UX-019/020 | Exports CSV/PDF | lot 8 |
| ST12-UX-023 | État vide | écran de configuration avant résultat |
| ST12-UX-024 | Erreur serveur contrôlée | messages DomainException (jamais de trace) |
| ST12-UX-025 | Résultat obsolète | gouvernance : hash figé → invalidation |
| ST12-UX-028 | Accès hors faculté | 403 (isolation) |
