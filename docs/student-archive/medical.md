# Médical (Phase F)

AUCUN dossier médical complet : l'université conserve uniquement le
nécessaire administratif (minimum-necessary, §9, §12).

## Existant réutilisé

- `student_documents(category=medical, certificat_medical|autre)` : stockage
  privé, SHA-256, versions, statuts, `expires_at` — AUCUNE colonne ajoutée
  sauf `niveau_confidentialite`.
- `attendance_justifications(motif, piece_*)` : motif masqué sans
  `attendance.justify`, pièces jamais servies.
- `situations(suspension, motif libre)` : aucun flag médical — volontaire
  (pas de stigmatisation requise par un processus).

## Nouveau (justifié F-AUDIT §15)

`student_sensitive_records(type=medical)` : titre NEUTRE imposé (min 3,
jamais de diagnostic), description = faits administratifs (certificat reçu
le…, période, aménagement), statut, niveau (défaut confidentiel), dates,
lien GED optionnel, rétention extensible, soft delete.

INTERDIT : colonnes diagnostic/traitement/antécédents (jamais créées).

## Accès

`medical.view` (liste + onglet) / `medical.download` (pièce GED via politiques
sources) — super_admin + admin_scolarite uniquement. Chaque accès audité
(`medical.view/download`). Pas d'API, pas de recherche, pas d'export.

## Fuites corrigées (sans migration)

- `NotifyDocumentValide` : libellé neutre si `medical`.
- `AuditDocumentUpload/Deletion` : sans titre si `medical`.
- PDF dossier : titres médicaux visibles uniquement via
  `student_dossiers.pdf` existant (permission-gated, risque résiduel
  documenté — Phase G : exclure du PDF standard).
