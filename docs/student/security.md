# Sécurité dossier (Phase 8 §34-35, §107)

Étudiant : session seule (`etudiant_auth_id`), ownership par requête
(404 sinon) — jamais de policy côté étudiant. Back-office :
permission + `peutAccederFaculte` (403/404). Enseignants : espace
propre depuis le 09/10/2026 (session `enseignant_auth_id`, ownership
`teacher_id` par requête, 404 sinon — même patron, jamais de
permission back-office). Tests : A→B (dossier, document,
demande, note, archive, notif), cross-faculté web+API+search+export,
pièces, timeline. Non-fuite vérifiée par URL/ID/API/search/download.
