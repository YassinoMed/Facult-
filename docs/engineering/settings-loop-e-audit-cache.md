# LOOP E — Audit du correctif cache (12/10/2026)

Correctif : `ParametreService::set()` — `Cache::forget()` immédiat conservé
+ `DB::afterCommit(fn () => Cache::forget(...))`
(`app/Services/ParametreService.php`, diff vérifié : +5 lignes dont commentaire).

| Point | Statut | Preuve |
|---|---|---|
| 1. Purge immédiate conservée | TESTED | Suites 14/14 + 22/22 vertes (RefreshDatabase ne committe jamais : seul le `forget` immédiat agit) |
| 2. Purge post-commit enregistrée | TESTED | `SettingsCacheRaceMysqlTest` 1/1 : lecture finale `Course` après re-scellement T2 |
| 3. Commit réussi | TESTED | Race test + suites (valeur + version + audit) |
| 4. Rollback | VERIFIED_CODE | `DatabaseTransactionsManager::rollback()` jette les transactions pending (`removeAllTransactionsForConnection`) : callbacks jamais exécutés, cache intact, DB intacte |
| 5. Transactions imbriquées | VERIFIED_CODE | Callbacks exécutés au commit racine seul (`commit()` : niveaux > 0 ignorés) ; `addCallback` hors transaction exécute immédiatement — impossible ici (`set()` ouvre toujours sa transaction) |
| 6. Erreur cache post-commit | VERIFIED_CODE + NOT_TESTED | Retour `forget()` ignoré ; si le driver lève (Redis indisponible), l'exception sort de `set()` **après** commit DB → 500 malgré écriture réussie. Mitigation candidate (non implémentée) : try/catch + log + alerte |
| 7. Compatibilité RefreshDatabase | TESTED | Outer-tx jamais committée → seul le `forget` immédiat agit ; 22 suites vertes |

Limite : la preuve couvre le scénario forget→relecture→commit ; un
re-scellement *après* le `forget` post-commit exigerait une écriture cache
hors service (impossible par construction : seule `get()` écrit, toujours
depuis la DB committée).

**Mise à jour du 13/10 (vérification indépendante)** : la mitigation du
point 6 est **désormais implémentée** — le `forget` post-commit est sous
`try/catch (Throwable)`, journalisé en `parametre.cache_invalidation_echec`,
sans faire échouer une écriture déjà committée. Point 6 passe à
`VERIFIED_CODE` (implémenté) + `NOT_TESTED` (aucun test d'injection de
panne cache). Course MySQL re-exécutée le 13/10 : **1/1 vert, 3 assertions**
sur scratch (base supprimée après preuve) ; suites B/C/D/E/F/G **22/22 verts,
124 assertions**.
