# Registre des contradictions (ne rien supprimer avant preuve)

| ID | Contradiction | État |
|---|---|---|
| C1 | Rapport Pint « sans violation » vs mesure « 7 fichiers en échec » (§1) | Ouverte — trancher par `vendor/bin/pint --test` daté (interdit en LOOP 1, à rejouer en LOOP 6/7) |
| C2 | Stats FSEGS 01/10/2026 vs base actuelle 12/10/2026 (4 958 étudiants, 162 groupes…) | Ouverte — rejouer `fsegs:validate-dataset` et comparer |
| C3 | Variations tests/skips (ex. 775→777 routes mesurées le 12/10, +2 vs référence) | Ouverte — expliquer le delta (routes `archives.statut` et sœurs probables) puis figer |
| C4 | `.env` dev (`APP_DEBUG=true`, `SESSION_ENCRYPT=false`) vs `.env.example` | Assumée dev-only — ne jamais copier en prod (checklist) |
| C5 | Modules actifs en dev mais `MODULE_*=false` par défaut (`.env.example`) | Documentée — activation = décision d'exploitation |
| C6 | Tests gatés esquivés (MySQL, OCR, bench) | Structurelle — skip = absence de preuve, jamais un succès |
| C7 | Backlog B/C/D/E : définitions d'origine introuvables en dépôt | Ouverte — exiger le document source avant tout chiffrage |
| C8 | Git absent : attribution des modifications impossible | Structurelle — toute affirmation d'auteur est non vérifiable |
