# Documents officiels (Phase 7)

Catalogue `config/documents-officiels.php` : `releve_notes` (REL-),
`attestation_reussite` (ATT-, décision publiée admis* exigée).
Génération `OfficialDocumentService` via `PdfDocumentService`
(registre `GeneratedDocument` : référence UNIQUE, version, `sha256`,
`contenu_hash`, disque privé) : anti-régénération à contenu égal,
nouvelle version sinon (anciennes `remplace`, jamais supprimées),
retry concurrence (§64), audit `document.genere` + notif.

Cycle de vie `valide → remplace | revoque` (migration `statut`,
`revoquer` motivé + audit + notif, téléchargement 410 si révoqué).
Gates réutilisées `student_dossiers.pdf` (+ faculté), zéro permission
créée. Archive : 4e source `officiel` (fix `toBase()` d'un crash
latent dès qu'une source secondaire est non vide). Masse :
`GenerateOfficialDocumentsJob` (délibération, chunk 200, ImportReport,
notif). Non implémentés (documentés) : QR (dépendance à approuver),
multilingue (FR), PV/dossier (exports existants), signature crypto.
