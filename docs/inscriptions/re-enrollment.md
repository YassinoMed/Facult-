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

## Réutilisation des documents (20/10/2026)

À chaque nouvelle inscription annuelle (première ou réinscription), les
pièces permanentes validées du dossier (`student_documents` et leurs
versions) sont associées automatiquement à l'inscription via
`inscription_documents` : aucun fichier n'est copié ni re-téléversé.
Seules les pièces `validated`, non expirées, physiquement présentes, de
format/MIME conforme et d'empreinte cohérente sont réutilisées ; un
téléversement `pending` n'est jamais considéré comme validé. Les pièces
manquantes, refusées, expirées ou non conformes sont demandées à
l'étudiant. Les années clôturées restent figées et ne sont jamais
réécrites.

Rattrapage idempotent des inscriptions ouvertes :
`php artisan documents:associer-inscriptions --dry-run` (puis sans
`--dry-run`). Détail et preuves :
`docs/engineering/LOOP-UNIREGISTRE-DOCUMENTS-REUTILISABLES-01.md`.
