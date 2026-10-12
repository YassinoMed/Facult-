# Audit final — moteur d'export et impression (2026-09-30)

```text
Datasets disponibles : listes étudiants multi-axes (12 filtres combinables,
tri ×5) + 15 entités DataExchange (PDF ajouté partout)
Formats : CSV (BOM, `;`, anti-injection), XLSX (texte pur, OpenSpout),
PDF (institutionnel + générique), impression navigateur
Exports PDF : listes (+émargement/signature), toutes entités DataExchange
Exports XLSX : listes + entités
Exports CSV : listes + entités
Exports asynchrones : non (plafonds synchrones documentés : 10k lignes,
1k PDF) — jobs existants réservés aux exports académiques lourds
Profils : ExportTemplate réutilisé (type=listes), CRUD propriétaire testé
Permissions : archives.view + data.export + exports.personal (CIN/email/tél)
Tests : 13 (ExportListesTest), tous verts
Risques : PDF >1000 lignes refusé avec message (pas de file) ; XLSX sans
mise en forme (données brutes volontaires)
```
