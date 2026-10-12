# Matrice RBAC (code : `app/Support/Permissions.php`)

Rôles : `super_admin` (= TOUTES, ~143) > `admin_scolarite` (~tout sauf
`archives.delete`/`notes.unlock` partiels) > `admin_faculte` (large, SANS
`configuration.manage`, `notes.unlock`, `archives.delete`,
`deliberations.lock|publish`, scoping faculté obligatoire) >
`agent_scolarite` (lecture+création, sans delete/lock/publish, sans sensible)
> `consultation` (view/download/audit seul, sans exports sensibles).

Sensible (`sensitive/medical/disciplinary.*`) : super_admin +
admin_scolarite UNIQUEMENT. Détail : `docs/PROFILS-ET-SCOPING.md`,
`docs/student-archive/confidentiality.md`.

## Pipeline imposé

`auth → Authorization (can:) → Faculty Scope (404 anti-énumération) →
Validation → Business Rules (DomainException) → Transaction →
Audit (sans PII) → Notification (sans sensible)`.

Gates auto (`AppServiceProvider:87`), rôle non mass-assignable,
`module:xxx` → 404, throttle (`10,1`/`5,1`, API `60/5/10/30`),
`CheckApiScope`, sessions chiffrées + cookies sécurisés.

## IDOR

Stratégie mixte normalisée : scope-first 404 puis policy 403
(`EtudiantPolicy`, `NotificationController`, downloads GED).
Tests : `tests/Feature/Security/` (6 fichiers : Idor, Authorization, Api,
FileUpload, ImportExport, XssCsrf) + IDOR par domaine
(archive, datasets, progression, sensible).
