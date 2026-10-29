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
transitions définies par `WorkflowDemandeEtudianteDefinition`
(moteur de workflows universitaires, domaine `demande` — la façade
statique `RequestWorkflowService` délègue depuis LOOP 01 ;
draft→submitted→received→in_review→{waiting_student|approved|rejected}→completed ;
`rejected → completed` impossible par construction). Colonne
`version_workflow` épinglée à la création (LOOP 01) : une demande
créée sous la version N conserve les règles de la version N.

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

## §8. Validation configurable, quatre yeux et délégations (LOOP 01)

Source unique : `App\Services\Workflow` (moteur générique) — définition
versionnée `WorkflowDemandeEtudianteDefinition` (domaine `demande`),
permissions réelles par transition (`demandes.approve/reject/process` ;
les actions du portail étudiant sont libres), et séparation stricte des
fonctions sur l'approbation : l'agent assigné à l'examination
(`assigned_to`) ne peut jamais approuver sa propre évaluation. Le
contrôleur transmet l'acteur, le service re-vérifie sous verrou
(topologie, permission, quatre yeux, isolation) — la policy reste la
première barrière côté HTTP.

Délégations de l'autorité de validation (`request_delegations`) : le
titulaire de `demandes.approve` délègue approbation ET rejet à un
collègue pour une fenêtre bornée (≤ 90 jours), révocable, jamais
auto-accordée, jamais chaînable (le délégataire ne peut pas
re-déléguer), éteinte dès que le délégant perd le pouvoir. Résolution
unique par Gate — `demandes.approve`/`demandes.reject` = RBAC OU
délégation active — : routes, policies, FormRequests et service
convergent. Endpoints `admin/demandes/delegations` (POST/DELETE,
`can:demandes.assign`, zéro permission nouvelle), audit
`delegation_validation.creee/revoquee` via le canal référentiel
(sans étudiant). Le quatre yeux s'applique pleinement au délégataire.
UI de gestion à venir (réserve).
