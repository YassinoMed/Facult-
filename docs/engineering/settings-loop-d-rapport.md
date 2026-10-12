# LOOP D — Rapport (12/10/2026)

## 1. Verdict : `PASS`

R-C-01 reproduit (RED) puis corrigé (GREEN) via le service réel sur MySQL
deux connexions ; suites et voisins verts ; Pint vert.

## 2. Correction R-C-01 (unique changement applicatif)

`ParametreService::set()` : `Cache::forget()` conservé (comportement + tests)
**plus** `DB::afterCommit(fn () => Cache::forget(...))` — purge post-commit
du re-scellement concurrent. Fichier : `app/Services/ParametreService.php`.

## 3. Preuve RED→GREEN (`SettingsCacheRaceMysqlTest`, gate `SETTINGS_CONC_MYSQL=1`, scratch)

T1 (hold) `set()` via service ; listener deux phases (INSERT version →
`fresh()`) rejoue T2 (autre PDO, lit l'ancienne valeur, re-scelle) ;
lecture finale : **RED** `Université` (afterCommit neutralisé) puis
**GREEN** `Course` (fix restauré). Dataset dev jamais touché (garde-fou base).

## 4. D-C-03 tranchée : statu quo

`sauvegarde.*` restent hors registre (repli par type + UI dédiée
propriétaire). Motif : aucun incident de validation démontré ; migration
coûterait la cohérence avec `SauvegardesController:190-200` sans bénéfice
prouvé. Réversible sur preuve.

## 5. Tests

Settings B→G + race : **23 (22 verts + 1 skip gaté)** ; `ServicesTransverses`
+ `BackupPlanning` **20/20** ; Pint vert (2 fichiers cosmétiques).

## 6. Non fait

D-C-04 (`sentinel.*`) : pas de changement (décision propriétaire requise) ;
première clé B : toujours refusée sans preuve ; R-C-06 accepté (restauration
sans `attendu`, versions chaînées).
