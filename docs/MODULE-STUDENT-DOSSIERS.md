# Module StudentDossiers — dossier administratif permanent + documents

Démonstration grandeur nature de l'architecture CORE + MODULES (voir
`docs/ARCHITECTURE-MODULES.md`, procédure `docs/ADDING-A-MODULE.md`).
Activation serveur : `MODULE_STUDENT_DOSSIERS` (défaut `false`).

## §1. Architecture

```text
app/Modules/StudentDossiers/
├── Models/         StudentDossier, StudentDocument, StudentDocumentVersion
├── Contracts/      IdentityDocumentExtractorInterface (OCR futur),
│                   AntivirusScannerInterface (+ NullAntivirusScanner)
├── Services/       StudentDossierService, StudentDocumentService,
│                   DocumentStorageService
├── Policies/       StudentDossierPolicy, StudentDocumentPolicy
├── Requests/       StoreStudentDossierRequest, UploadStudentDocumentRequest,
│                   ReplaceStudentDocumentRequest, RejectStudentDocumentRequest,
│                   TeleverserMonDocumentRequest, RemplacerMonDocumentRequest
├── Controllers/    StudentDossierController, StudentDocumentController (admin),
│                   MonDossierController (étudiant, session-scopé)
├── Events/         DocumentUploaded/Validated/Rejected/Replaced/Deleted/
│                   Expired/Archived (+ OCRProcessingJob)
├── Listeners/      AuditDocument* (6) + LancerExtractionOcr
├── Routes/web.php  (verrou `module:student-dossiers` → 404 si inactif)
├── Resources/      StudentDossierResource, StudentDocumentResource (API future)
└── Views/          resources/views/modules/student-dossiers/*
```

Le CORE ne dépend pas du module : `ModuleServiceProvider` branche
contrat antivirus, policies, listeners, routes et view composers ;
`ModuleRegistry` expose nav + widgets filtrés par activation.

Choix assumé : FK vers l'étudiant nommée `etudiant_id` (convention du
cœur, cf. `presences`), FK internes du module en anglais
(`student_dossier_id`, `student_document_id`). Rôle `student` inexistant
dans la matrice RBAC : l'accès étudiant est session-scopé (ownership),
pas un rôle `users` — créer un rôle `student` exigerait d'altérer
l'enum des rôles et mélangerait les deux authentifications.

## §2. Tables et relations

`Etudiant hasOne StudentDossier` (UNIQUE `etudiant_id`, référence
`DOS-AAAA-NNNNNN` unique) — aucune donnée personnelle dupliquée.
`StudentDossier hasMany StudentDocument` (statuts pending/validated/
rejected/expired/archived) ; `StudentDocument hasMany
StudentDocumentVersion` (UNIQUE document/version). Index :
(dossier,status), (dossier,category), status, sha256.

## §3. Permissions

`student_dossiers.view/create/update/delete` ;
`student_documents.view/upload/download/validate/reject/delete/replace/audit`.
Matrice : super_admin + admin_scolarite = tout ; agent = sans
validate/reject/delete ni dossiers.delete ; consultation = lecture +
audit ; admin_faculte = tout sauf dossiers.delete et documents.delete
(même philosophie que `archives.delete` réservée au central).

## §4. Stockage et sécurité

Disque `private` (`storage/app/private`, `serve=false`) :
`student-dossiers/{annee}/{etudiant_id}/{categorie}/{uuid}.{ext}`.
Nom d'origine conservé en métadonnée uniquement. Validation profonde
(extension + MIME réel finfo + cohérence + taille 10 Mo + signatures
magiques PDF/JPEG/PNG/WebP), SHA-256, hook antivirus remplaçable.
`storage_path` en `hidden` modèle + exclu des Resources.
Téléchargement : auth → policy → scope faculté → audit → download ;
cohérence parent/enfant vérifiée (404 sinon).

## §5. Workflow upload / validation / versionnage

Upload (pending, v1) → `DocumentUploaded` → audit + OCR éventuel →
validate (validated) / reject (**motif obligatoire**) → replace
(nouvelle version, historique conservé, retour pending) → archive /
delete (fichiers + lignes en transaction, audit `deleted`).
Upload étudiant autorisé sur son dossier (mot de passe jamais demandé
ni exposé). Remplacement étudiant idem, re-validation requise.

## §6. Versionnage

`replace()` en transaction : nouveau fichier + `version = max + 1`,
ancien fichier **conservé**. Contrainte UNIQUE(document, version).

## §7. Audit

Actions `student_document.uploaded/validated/rejected/replaced/
archived/deleted/downloaded` via `AuditService` (acteur, IP, UA,
ancienne/nouvelle valeurs). Section d'audit de la fiche dossier
gated par `student_documents.audit`. Jamais : contenu, mot de passe,
token, secret.

## §8. Extension future

- OCR : `DocumentUploaded` → `LancerExtractionOcr` (catégories
  `identity`, flag `student-dossiers.ocr.enabled=false`) →
  `OCRProcessingJob` (queue, fail-soft sans implémentation) →
  `IdentityDocumentExtractorInterface` → validation humaine
  obligatoire, jamais de preuve automatique.
- API : `/api/v1/student-dossiers[/{dossier}[/documents]]` —
  Resources prêtes (scopes, pagination, filtres whitelistés,
  throttle à ajouter à l'implémentation, cf. §16 de la mission).
- Expiration : event `DocumentExpired` prêt pour un job de balayage
  (`expires_at` dépassé → `expired`).

## §9. PDF et impression

Moteur : **dompdf/dompdf ^3.1** (choix documenté : PHP 8.4 natif, sans
wrapper Laravel donc compatible Laravel 13, A4, UTF-8, DejaVu Sans,
compteurs page/pages, `isRemoteEnabled=false` — aucun appel réseau).
Écarté : Browsershot/wkhtmltopdf (binaire externe requis, inadapté).

- Services : `Services/Pdf/` (`StudentDossierPdfService` données
  whitelistées + HTML Blade, `PdfDocumentService` persistance/SHA/stale,
  `DompdfPdfGenerator` via contrat `PdfGeneratorInterface`).
- Registre `generated_documents` (référence `PDF-DOS-…`, version,
  `sha256`, `contenu_hash`) ; stockage
  `private/student-dossiers/generated/{annee}/{etudiant}/{type}/{uuid}.pdf`.
- Anti-régénération : hash snapshot (identité + documents + versions) ;
  volumineux (> `pdf.job_threshold`, défaut 200) → `GenerateStudentDossierPdfJob`.
- Routes admin + étudiant : `pdf.generer` (POST), `pdf.preview` (page
  HTML + iframe + boutons Télécharger/Imprimer), `pdf.fichier`
  (inline), `pdf.download` (attachment), `pdf.print` (audit d'intention
  + redirect ; 204 en AJAX). Content-Type servi depuis le type stocké
  (`application/pdf`), `nosniff`, nom sécurisé.
- Policy `StudentDossierPdfPolicy` (hérite `StudentDossierPolicy`) +
  permission `student_dossiers.pdf` ; étudiant = dossier propre.
- Audit `student_dossier.pdf.generated/previewed/downloaded/printed`
  (référence uniquement, jamais le contenu).
- Config `student-dossiers.pdf` : paper A4, orientation, marges,
  logo, institution, footer, locale, seuil job.
- Limites honnêtes : arabe = glyphes DejaVu Sans **sans ligatures**
  (pas de shaping dompdf) — texte préservé, jamais translittéré ;
  contenu PDF vérifié au niveau HTML source (flux en glyphes) ;
  `print` = intention (impression physique = action navigateur).

- OCR : `DocumentUploaded` → `LancerExtractionOcr` (catégories
  `identity`, flag `student-dossiers.ocr.enabled=false`) →
  `OCRProcessingJob` (queue, fail-soft sans implémentation) →
  `IdentityDocumentExtractorInterface` → validation humaine
  obligatoire, jamais de preuve automatique.
- API : `/api/v1/student-dossiers[/{dossier}[/documents]]` —
  Resources prêtes (scopes, pagination, filtres whitelistés,
  throttle à ajouter à l'implémentation, cf. §16 de la mission).
- Expiration : event `DocumentExpired` prêt pour un job de balayage
  (`expires_at` dépassé → `expired`).
