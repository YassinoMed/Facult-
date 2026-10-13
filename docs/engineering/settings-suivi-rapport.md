# SETTINGS — Suivi post-LOOP E (12/10/2026)

## 1. Mitigation panne cache (E1.6)

`ParametreService::set()` : `try/catch (Throwable)` autour du `forget`
post-commit → warning `parametre.cache_invalidation_echec` (clé + erreur,
sans valeur). Preuve : `SettingsCachePanneMysqlTest` 1/1 (store dégradé au
2ᵉ `forget`, scratch MySQL) — écriture committée malgré la panne + warning
sans secret ni valeur.

## 2. `attendu` obligatoire

UI (`index.blade.php` : hidden `attendu` par formulaire) + serveur
(`required|string|max:64`) ; restauration inchangée (intention explicite).
8 fichiers de tests réalignés (PATCH valides avec `attendu` frais).

## 3. Clés `sentinel.*`

Migration additive `2026_10_12_231817_ajouter_parametres_sentinel`
(`updateOrInsert`, réversible, appliquée dev) : 3 clés aux défauts codés
(5/1000/60) + registre (`min:1`) + test pilotage/refus (`SettingsSentinelLoopTest`).

## 4. Audit intégré

Lecture registres/rapports avant codage ; aucun B sans preuve ; D-C-04
soldée par les seeds (décision propriétaire restante pour tout changement
de valeur métier).

## Validation

Settings **24 (23+1 skip) stables ×2**, voisins **28/28**, Pint vert,
migration dev `DONE`. Verdict : `PASS`.
