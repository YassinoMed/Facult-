# Catalogue des services (Phase 9 §41-42)

`request_types` (code UNIQUE, nom, description, schéma, pièces,
SLA jours, document final, actif) + graine 6 types. Admin CRUD
(`demandes.types.*`, `configuration.manage`, suppression refusée si
utilisé). Types PDF : `types_pdf` (attestation scolarité). Formulaires
dynamiques : `form_schema` (text/select/date/checkbox) + validation
serveur + rendu Alpine ; pas de builder (DB/migration). Éligibilité :
`requires_attachment` (soumission bloquée sinon), `generates_document`.
