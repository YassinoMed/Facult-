# LOOP C — Audit technique (12/10/2026)

Fichiers consultés : `app/Services/ParametreService.php` (`set`
transactionnel, `get`, `restaurer`), `app/Models/Parametre.php`
(`valeurTypee`, `auteur`, `versions`), `app/Models/ParametreVersion.php`,
`app/Services/SettingDefinition.php`, `app/Http/Controllers/Admin/
ParametreController.php` (`index`/`update`/`restaurer`),
`app/Policies/ParametrePolicy.php`, `routes/web.php:920-925`,
migrations `2026_09_30_000004`, `2026_10_07_110016`, `2026_10_10_102835`,
`2026_10_12_205213`, `SauvegardesController.php:168-200`,
`BackupSchedulerService.php:40-85`, `ExecuterSauvegardeJob.php:79-80,166`,
`SauvegardeFraicheurCheck.php:54`, `PurgerNotifications.php`,
`config/cache.php`, `phpunit.xml:26-28`.
Commandes exécutées : lecture seule (`grep`/`sed`) + 6 suites Settings
existantes sur sqlite `:memory:` (env vérifié `phpunit.xml:26-28`).

## C1 — Cache et transactions : `POTENTIAL_RACE` (P0)

Séquence `set()` : `lockForUpdate` → check → `update` → version →
**`Cache::forget()` intra-transaction** → log → commit en sortie de closure.
Scénario : T1 `forget` (non commité) → T2 `get()` → miss → lecture DB
pré-commit → **re-remplit l'ancienne valeur** → T1 committe → `rememberForever`
ressert l'obsolète **indéfiniment**. Non reproduit (tests mono-processus ;
aucun test MySQL deux connexions pour paramètres). Correctif candidat (LOOP D,
non implémenté) : `DB::afterCommit(fn () => Cache::forget(...))`.
Drivers : `array` (tests, jamais en panne) ; `database`/`redis` (prod :
panne Redis = exception au `get`, fail-closed 500 ; `forget` ignoré en
retour — échec silencieux possible). Restauration : même stratégie, même
risque (via `set()`).

## C2 — Concurrence : optimiste prouvé en séquentiel, réel non démontré

Présents et verts (sqlite sûr) : `lockForUpdate` (code relu, effet
sérialisant non mesuré), `attendu`→erreur `concurrence` (test à horodatage
figé −5 min, anti-flaky seconde), no-op sans version ni audit, restauration
=nouv. version, 404 version croisée, append-only. 22/22 verts le 12/10.
**Concurrence réelle MySQL deux transactions : NOT_RUN** (aucun test dédié ;
un unitaire ne prouve pas la course). Restauration utilise `set(valeur)` sans
`attendu` : deux restaurations concurrentes = dernière gagnante (documenté,
non bloquant : versions chaînées).

## C3 — Clés sauvegarde : cartographie

Origines : `2026_09_30_000004` (6 institutionnelles), `2026_10_07_110016`
(`sauvegarde.*` simples : `conserver`, `notifier_echec`,
`alerte_age_heures`, `compression`, `frequence`, `heure`, `jours`, …),
`2026_10_10_102835` (10 `sauvegarde.plan.*`). Écritures : `Sauvegardes-
Controller:190-200` (`set()` direct, validation propre au contrôleur,
**hors registre `SettingDefinition`**) + `ParametreController::update`
(repli par type). Lectures : `BackupSchedulerService` (6× `plan.*`),
`ExecuterSauvegardeJob` (2×), `SauvegardeFraicheurCheck` (`alerte_age_...`),
`SauvegardesController:168`. `sentinel.*` lus (`file_echecs_seuil`,
`notif_cooldown_minutes`) mais **jamais seedés** → défauts codés seuls.
Types `sauvegarde.echec|job_echec|...` = **types de notification, pas des
paramètres** (collision de nommage documentée, aucune fusion).
Conclusion par clé : toutes `UTILISÉE` sauf `sentinel.*` =
`ABSENCE_D_USAGE_NON_PROUVÉE` (lus avec défaut, seed absent — bénin).

## C4 — Registre vs réel : divergences

6 clés registre ≙ 6 seeds institutionnels (type/défaut/portée/propriétaire
vérifiés). Divergences : (a) 13+ clés `sauvegarde.*` hors registre →
repli par type (ex. `frequence:horaire` texte libre, `jours` CSV libre) ;
(b) `interface.pagination` indicative vs préférences effectives (doublon
potentiel, inchangé) ; (c) `academique.seuil_validation` indicative vs
règles Évaluation autoritatives (garde anti-confusion en description).
Options : (1) statu quo + repli (zéro risque, validation faible) ;
(2) étendre le registre aux `sauvegarde.*` (cohérence, risque : conflit avec
l'UI dédiée — décision d'architecture ouverte) ; (3) migrer l'UI dédiée vers
le registre (refonte, refusé sans preuve de besoin).

## C5 — Autorisations : conformes observées

Lecture/historique : `settings.view` (`index`, `viewAny`) ; écriture/
restauration : `settings.manage` (`update`/`restaurer`, throttle 20/1 et
10/1) ; `configuration.manage` distinct et non requis ici (séparation
propre). Observé : restreint 403 lecture+écriture+restauration (tests
LOOP B/F verts) ; `settings.*` = super_admin seul (matrice). Aucun
élargissement effectué ni proposé.

## C6 — Portée facultaire : confirmée absente

Zéro occurrence `faculte` dans les 7 fichiers du noyau (grep vide).
Prérequis d'admission B inchangés (justification, propriétaire, résolution,
serveur, isolation, invalidation, IDOR/BOLA). Aucune colonne/migration/clé B.
