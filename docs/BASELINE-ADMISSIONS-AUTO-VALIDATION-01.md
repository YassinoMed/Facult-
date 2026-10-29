# BASELINE — LOOP-ADMISSIONS-AUTO-VALIDATION-01 (LOOP 00)

## Cartographie

- Routes (`routes/web.php:959-971`) : `index` (view), `create`+`store`
  (manage, throttle 20/min), `destroy` (manage, throttle 10/min). Aucune
  route de modification (pas d'update — rien à tester).
- Contrôleur `Admin\AdmissionController` (index/create/store/destroy),
  vues `admin/admissions/{index,creer}`, service `AdmissionService`
  (accorder/lister/supprimer), consommation dans
  `AcademicProgressionService::appliquer()` (lecture acte admis* +
  re-vérification `lockForUpdate` + inscription + marquage
  `consumed_at`/`consumed_inscription_id` + audits, le tout en une
  transaction ; notification via `CommunicationDispatcher`).
- Modèle `Admission` (DECISIONS 3, fillable, `estActive()`), migration
  `2026_10_05_000001` (FK, enum, motif 500, index `idx_admissions_garde`
  — PAS d'index unique), policy `AdmissionPolicy` (permissions par
  action, périmètre au contrôleur → 404).
- Audit : `historique_academique` (`admission_accordee/refusee/
  supprimee/consommee` + `creation_inscription`), sans PII. Refus
  métier → erreurs de validation, non journalisés (convention du
  module : seuls les succès sont tracés).
- Droits : view+manage groupés (super_admin, admin_scolarite,
  admin_faculte) ; agent/consultation : aucun. Combinaison
  « view sans manage » inexistante en matrice.
- Tests initiaux : `AdmissionTest` 8/8 (39 assertions).

## Anomalie confirmée (A1)

Un acte `refuse` (non actif selon `estActive()`) bloque toute décision
ultérieure sur le triplet — démontré par test jetable (supprimé) :
refus puis admis = `DomainException`, 1 seul acte. Cause : garde
d'unicité sans filtre décision. Fenêtre de course concurrente résiduelle
(`lockForUpdate` sur ensemble vide) documentée, sans contrainte unique
globale (interdirait historique refus + actes consommés ; MySQL sans
index partiel).

## Non vérifié à ce stade

Comportement MySQL en concurrence réelle (SQLite ne prouve pas les
verrous) ; parcours navigateur ; rollback sur échec d'inscription
mid-transaction (aucun déclencheur sans casser d'autres modules).
