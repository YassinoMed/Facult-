# Inscriptions — réinscription (Phase 3)

Réinscription = étudiant connu + nouvelle année universitaire. Elle ne
signifie PAS niveau+1 (redoublement : 2025/2026 L2 AJOURNÉ → 2026/2027 L2
RÉINSCRIPTION, historique conservé, ancienne décision intacte).

`InscriptionService::reinscrire` : refuse si suspendu ; nouvelle ligne
`type=reinscription` (+ S1/S2 + Situation + audit `creation_inscription` +
notif `inscription.reinscrite`) ; niveau = explicite ou `niveauApres()`
(même programme, `ordre`, dernier → lui-même, jamais de saut deviné).
Idempotent : garde interne + verrou + unique → double submit = 422, une
seule ligne. `AcademicProgressionService::appliquer` (Phase 6) réutilise
`reinscrire(notifier=false)` + audit + notif `inscription.progression`.

Première inscription : `ArchiveService::creerInscription`
(type `premiere` défaut, programme/niveau actifs vérifiés par la Request).
