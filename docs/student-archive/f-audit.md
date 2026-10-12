# F-AUDIT — données sensibles (Phase F, sans modification)

## 1. Données médicales existantes

- `student_documents(category=medical, document_type=certificat_medical|autre, title/description/original_filename LIBRES)` (`config/student-dossiers.php:18,30`), disque `private`, `storage_path` hidden. Mêmes gates que tout document.
- `attendance_justifications(motif TEXT libre ≤1000, piece_nom ORIGINAL client, piece_chemin)` — upload faible (extension seule, pas de MIME réel/finfo, pas d'antivirus), **jamais servi** (aucune route download, jamais lu).
- `situations_etudiant(type=suspension, motif VARCHAR 255 libre)` — motif médical possible en clair, aucun flag.
- AUCUNE table/colonne `medical|diagnostic|pathologie|handicap`, AUCUNE migration médicale.

## 2. Données sensibles existantes

- Titres/descriptions libres partout (GED cœur + dossiers + demandes) ; `rejected_reason`, `commentaire_validation` libres.
- `NotifyDocumentValide` écrit le **titre libre en `notifications`** (titre/message/data).
- `AuditDocumentUpload/Deletion` écrit **titre + `categorie=medical`** en `historique_academique` (lisible via `student_documents.audit`, rôle `consultation` inclus).

## 3. Situations existantes

`TYPES = statut,groupe,programme,suspension,transfert,reinscription,note` (append-only). Pas de type disciplinaire/sensible, pas de flag médical.

## 4. Documents concernés

`documents_academiques` (6 types, pas de colonne version ni confidentialité), `student_documents` (catégories incl. `medical`, statuts, versions, `expires_at`), `request_attachments`, `online_request_documents` (attestations versionnées), `attendance_justifications.piece_*` (orphelines).

## 5. Sources GED

`DocumentService` (disk `local` → `storage/app/private`, MIME réel + signatures + antivirus hook + SHA-256), `DocumentStorageService` (disk `private`, même discipline), `RequestAttachmentService` (faible : extension seule), `JustificationController` (faible, jamais servi). Aucun chiffrement au repos (0 usage `Crypt::`) ; backups en clair (SQL + zip sans mot de passe).

## 6. Policies existantes

`DocumentAcademiquePolicy`, `StudentDocumentPolicy`, `JustificationPolicy`, `OnlineRequestPolicy` — permission + `peutAccederFaculte`, JAMAIS par catégorie. `JustificationController::deposer` n'appelle pas `deposit` (session-scope seule).

## 7. Permissions existantes

`documents.*`, `student_documents.*` (view/upload/download/validate/reject/delete/replace/audit), `attendance.*`. **ZÉRO `medical.*`, `disciplin*`, `sensitive:*`, `confidentiel*`** (un commentaire).

## 8. Routes existantes

Downloads GED audités (404/403 scope+permission). **Aucune route pièce justificatif** (non-exposition par construction).

## 9. Recherche globale

Sections : étudiants, enseignants/groupes/matières/salles/plannings, demandes (référence+objet), utilisateurs, annonces. **AUCUNE indexation documents/pièces/justificatifs/médical** — à CONSERVER ainsi.

## 10. API

`students`, `academic-record`, `grades`, `documents` (métadonnées cœur, jamais de binaire). Scopes sans `medical/sensitive`. **AUCUN endpoint demandes/justificatifs/pièces.**

## 11. Audit

`AuditService::tracer` + `HistoriqueAcademique` (immuable, 15 codes avec `archive_view`), `AuditController` filtrable + export CSV, `IntegrationLog`. Listeners sobres (`Log::info` : ids uniquement).

## 12. Notifications

Génériques + 1 fuite (titre libre en notif). À corriger, pas à étendre.

## 13. Données disciplinaires

**NÉANT total** (0 hit) : pas de table, pas d'incident/commission/sanction.

## 14. Données réellement absentes

1. Niveau de confidentialité gradué (colonne) ; 2. permissions/policies/scopes médicaux et disciplinaires ; 3. dossier métier sensible (incident/commission/décision/aménagement avec dates/statuts/auteurs) ; 4. motif d'accès/break-glass (absent à 100 %, disproportionné — audit renforcé suffit) ; 5. rétention documentaire globale (seuls `expires_at` + rapports 168 h) ; 6. soft delete (hard partout) ; 7. chiffrement repos/backups ; 8. purge pièces orphelines ; 9. recherche/export/API sensibles (absents — à ne PAS créer).

## 15. Structure minimale nécessaire

1. Colonne `niveau_confidentialite` ENUM(`standard`,`restreint`,`confidentiel`), défaut `standard`, + index, sur `documents_academiques` et `student_documents` (dimension absente, pas de duplication).
2. **UNE** table `student_sensitive_records` (type ENUM `medical|disciplinaire|situation_particuliere|administratif_restreint`, titre NEUTRE, description faits administratifs uniquement — JAMAIS diagnostic/traitement, statut, niveau, dates, acteur, lien GED `(document_source, document_id)`, `retention_until`/`retention_policy` NULL, soft delete). Justification §7 : incident/commission/décision/aménagement n'existent NULLE PART ; fichiers restent GED (lien, pas copie) ; principe minimum-necessary.
3. Permissions `student_archive.sensitive.view|medical.view|medical.download|disciplinary.view|disciplinary.download|sensitive.manage` (+ audit existant) ; matrices : super_admin + admin_scolarite complets, autres rôles RIEN (Archive ≠ Sensitive).
4. `SensitiveRecordPolicy` (type→permission + faculté) ; onglet `Confidentiel` masqué sans perm, 404 direct.
5. Timeline `sensible` séparée (provider gated, jamais dans la standard) ; recherche/API/exports : RIEN (exclusion par construction) ; notifs minimales sans contenu ; logs/audit : ids + résultat uniquement.
6. Corrections fuites existantes (sans migration) : `NotifyDocumentValide` (titre neutre si `medical`), `AuditDocumentUpload/Deletion` (pas de titre si `medical`), masquage `motif` justificatif sans `attendance.justify`.
