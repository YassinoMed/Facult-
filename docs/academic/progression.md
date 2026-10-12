# Progression LMD (Phase 6)

`AcademicProgressionService::evaluer` (dry-run) exige une décision
**publiée** liée à l'inscription source : `admis*` → `progresser`
(`niveauApres`, intra-programme, jamais N+1 aveugle), `ajourne /
rattrapage / redouble` → `redoubler` (même niveau), `exclu /
a_examiner` → `bloque`, fin de cycle → rien sans admission explicite.
`appliquer` : idempotent (existante → retour), transactionnel,
cibles explicites (Master) jamais devinées et exigeant `admis*`,
audit `creation_inscription` + notif `inscription.progression`.

Redoublement explicite (§30) : inscription `type=reinscription` +
`observation` « Redoublement — décision … jury » (enum partagé
SQLite/MySQL inchangé par non-duplication), décision et audit liés.
L3→M1 : licence validée ≠ admission — `appliquer` avec cibles
explicites uniquement. Dette : UE non validées identifiables via
`detail_ues` + API `results.dettes`, jamais confondues avec
l'inscription active.
