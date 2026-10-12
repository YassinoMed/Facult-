# Module OnlineRequests — demandes administratives en ligne

Deuxième module vertical de la plateforme (après Attendance et
StudentDossiers) : cycle complet brouillon → soumission → traitement →
décision → document PDF, pour l'étudiant comme la scolarité.

## §1. Tables

`request_types` (catalogue + `form_schema` JSON + seed en migration),
`online_requests` (référence `REQ-AAAA-NNNNNN` serveur, `status` machine
à états, `form_data` JSON, snapshot `faculte_id` à la soumission,
horodatages `submitted/processed/completed/rejected_at`),
`request_attachments` (privé + SHA-256), `online_request_documents`
(PDF générés, registre dédié — aucune dépendance StudentDossiers).

`status` n'est JAMAIS mass-assignable : défaut modèle `draft`,
transitions via `RequestWorkflowService::TRANSITIONS`
(draft→submitted→received→in_review→{waiting_student|approved|rejected}→completed ;
`rejected → completed` impossible par construction).

## §2. Workflow et pièces

`OnlineRequestService` (création, transitions, référence avec retry),
`RequestAttachmentService` (même discipline que StudentDossiers :
UUID, MIME réel, signatures, 10 Mo). Dépôt étudiant en
`waiting_student` → retour auto `in_review`. Pièce exigée par type
(`requires_attachment`) contrôlée AVANT transition (garde atomique).

## §3. Permissions et isolation

`demandes.view/create/submit/cancel/process/assign/approve/reject/
request_documents/documents.view/upload/download/pdf/audit` —
scolarité complète, agent sans approve/reject, faculte avec
approve/reject dans son périmètre, consultation lecture. Étudiant =
session ownership (aucun ID client libre, `status`/`assigned_to`
non injectables). `OnlineRequestPolicy` : permission + faculté
(snapshot, repli étudiant).

## §4. PDF

`RequestDocumentService` réutilise le contrat `PdfGeneratorInterface`
(dompdf branché) — aucun nouveau moteur. Attestation générée
d'office à l'approbation si le type le prévoit ; données lues depuis
`Etudiant` + `InscriptionAcademique` (§18 : jamais saisies).
Stockage `private/online-requests/{annee}/{demande}/{uuid}.pdf`,
référence `PDF-REQ-…`, SHA-256, `nosniff`, nom sécurisé.

## §5. Délais et formulaires dynamiques

`processing_days` par type → `echeance()` + `enRetard()` (badge
dépassée côté admin). `form_schema` par type → champs rendus en
Alpine + règles serveur générées (`validerSchema`).

## §6. Audit et events

`online_request.created/submitted/approved/rejected/completed/
document_generated/viewed/piece_downloaded/document_downloaded` via
`AuditService` (+ `AuditDemandes`), historique complet sur la fiche.
`request_history` dédiée non créée : l'audit central couvre le besoin
(acteur, IP/UA, avant/après) sans nouvelle table. Notifications
(email/SMS) : events prêts, canaux à brancher.

## §7. Navigation et dashboards

Registre `online-requests` (`MODULE_ONLINE_REQUESTS`, défaut false) :
liens Demandes / Mes demandes (permission puis session), widgets
scolarité (nouvelles/en cours/retard) et étudiant (en cours/documents
prêts) via view composers — le cœur ne charge rien.
