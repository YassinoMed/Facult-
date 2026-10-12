# Confidentialité (Phase F)

Niveaux (`NiveauConfidentialite`, centralisé) : `standard` (défaut),
`restreint`, `confidentiel` — colonnes sur `documents_academiques` et
`student_documents` (indexées). AUCUN 4e niveau : médical/disciplinaire =
permissions, pas un niveau.

## Gradient d'accès

```text
Archive (student_archive.view) ≠ Sensitive (sensitive.view/manage)
≠ Medical (medical.view/download) ≠ Disciplinary (disciplinary.view/download)
```

Matrices : super_admin + admin_scolarite complets ; agent, consultation,
admin_faculte : RIEN de sensible (même même faculté).

## Chaîne obligatoire (§17)

Authentication → Faculty Scope (404) → Student Scope (paire) →
Archive Permission → Sensitive Permission (404 si absente : ne pas révéler)
→ Resource Permission (GED source) → Action → Audit.

## Non-divulgation

- Onglet `Confidentiel` masqué sans `sensitive.view` ; direct → 404.
- Timeline standard : jamais de `sensible` (flag explicite réservé à l'onglet).
- Recherche globale/instantanée : rien d'indexé (verrouillé par tests).
- Exports standards, DataExchange, API : exclus par construction (tests).
- Notifications : jamais de titre/contenu sensible (libellés neutres).
- Logs/audit : ids + résultat ; titres omis si `medical`.
- Justificatifs : motif masqué sans `attendance.justify` ; pièces jamais
  servies (aucune route — limite documentée).

## Audit (§23)

`sensitive.view/download/create/update/delete`, `medical.view/download`,
`disciplinary.view/download` via `AuditService` (IP/UA/request_id).
Pas de motif d'accès : disproportionné, audit renforcé suffit (choix
documenté). Pas de chiffrement colonne : disque privé + scopes + audit
(impacts recherche/backup/rotation) ; backups en clair = risque infra connu,
hors scope applicatif.
