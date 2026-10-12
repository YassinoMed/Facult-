# LOOP E — Matrice des preuves (12/10/2026)

## Concurrence (`SettingsCacheRaceMysqlTest`, gate `SETTINGS_CONC_MYSQL=1`)

- Connexions : `settings_conc` (T2 + lectures finales) et
  `settings_conc_hold` (T1 `set()` via service), base scratch
  `settings_conc_scratch` (garde-fou `assertSame` base, `migrate --force`,
  sans `RefreshDatabase`, pas de rollback global — lignes taggées par run).
- Drivers : base MySQL 9.2, cache `array` (phpunit, partagé au processus —
  condition même de la démonstration).
- RED : `afterCommit` neutralisé → finale `Université` (re-scellée par T2).
  Neutralisation effectuée **sur base isolée uniquement**, restaurée aussitôt
  (diff final = fix seul, vérifié §E1 du rapport).
- GREEN : finale `Course` + scénario T2 déclenché (assertion dédiée).
- Garantie dev : aucun accès à `inscription-etudiants` (connexions nommées,
  base créée `IF NOT EXISTS`).
- Statut suite standard : **skip** sans la gate (jamais un succès).

## Versionnement / restauration (`SettingsVersionsLoopFTest`, sqlite sûr)

- Version par écriture effective, no-op sans version ni audit, restauration
  = nouvelle version chaînée, 404 version croisée, 403 restreint : **6/6**.
- Restauration sans `attendu` (dernière gagnante) : documenté, versions
  chaînées rendant l'écrasement traçable ; `attendu` exigible côté UI
  si besoin (convention `concurrence`, sac nommé).
- Append-only : aucune route/méthode UPDATE/DELETE sur `parametre_versions`
  (grep : seul `ParametreVersion::create` + `delete()` de nettoyage en tests).

## Non couvert

Course vraie sans le listener (ordonnancement OS réel) ; panne Redis ;
`attendu` obligatoire ; purge `attendu` périmé côté UI.

**Re-exécution indépendante du 13/10** : course `SETTINGS_CONC_MYSQL=1`
re-jouée — **1/1 vert, 3 assertions** (scratch créée puis supprimée,
zéro résidu) ; suites B/C/D/E/F/G **22/22 verts, 124 assertions** ;
`attendu` désormais requis côté contrôleur (critère point 2 implémenté) ;
`sentinel.*` en cours d'ajout (critère point 3, valeurs = défauts codés) ;
Pint : 9 fichiers en échec au 13/10 (mention « vert » du 12/10 non
reproduite — chantier archives en vol). Le « `attendu` obligatoire »
listé ci-dessus en non-couvert est levé pour `update()` ; il reste
intentionnellement absent de `restaurer()` (D-C-06 : intention explicite
+ nouvelle version chaînée).
